import asyncio
import ssl
import time

if not hasattr(ssl, 'OP_IGNORE_UNEXPECTED_EOF'):
    ssl.OP_IGNORE_UNEXPECTED_EOF = 0

from jeedomdaemon import BaseDaemon, BaseConfig

from pymammotion.aliyun.exceptions import CheckSessionException, DeviceOfflineException, GatewayTimeoutException
from pymammotion.client import MammotionClient
from pymammotion.data.model import GenerateRouteInformation
from pymammotion.data.model.device_config import OperationSettings, create_path_order
from pymammotion.data.model.pool_state import SpinoWorkMode
from pymammotion.transport.base import CommandTimeoutError
from pymammotion.utility.constant.device_constant import WorkMode, device_connection, device_mode
from pymammotion.utility.device_type import DeviceType


STATE_THROTTLE = 2  # secondes minimum entre deux envois d'état vers Jeedom

EVENT_LABELS = {
    WorkMode.MODE_WORKING: 'Tonte démarrée',
    WorkMode.MODE_MANUAL_MOWING: 'Tonte manuelle démarrée',
    WorkMode.MODE_PAUSE: 'Tonte en pause',
    WorkMode.MODE_CHARGING_PAUSE: 'Tonte en pause (charge)',
    WorkMode.MODE_RETURNING: 'Retour à la station',
    WorkMode.MODE_CHARGING: 'Charge à la station',
    WorkMode.MODE_READY: 'Prêt',
    WorkMode.MODE_OFFLINE: 'Hors ligne',
    WorkMode.MODE_POWER_OFF: 'Éteint',
    WorkMode.MODE_UPDATING: 'Mise à jour du firmware en cours',
    WorkMode.MODE_UPDATE_SUCCESS: 'Mise à jour du firmware terminée',
    WorkMode.MODE_OTA_UPGRADE_FAIL: 'Échec de la mise à jour du firmware',
    WorkMode.MODE_LOCK: 'Verrouillé',
    WorkMode.MODE_LOCATION_ERROR: 'Erreur de localisation',
}


class DaemonConfig(BaseConfig):

    def __init__(self):
        super().__init__()

        self.add_argument("--email", help="Mammotion account email", type=str)
        self.add_argument("--password", help="Mammotion account password", type=str)

    @property
    def email(self): return str(self._args.email)
    @property
    def password(self): return str(self._args.password)


class MammotionDaemon(BaseDaemon):
    def __init__(self) -> None:
        self._config = DaemonConfig()
        super().__init__(self._config, self.on_start, self.on_message, self.on_stop)
        self._client = None
        self._subscriptions = []
        self._last_sent = {}
        self._pending = {}
        self._last_mode = {}


    async def on_start(self):
        self._logger.info("Starting Mammotion daemon...")
        self._client = MammotionClient()
        self._logger.info("Connecting to Mammotion cloud...")
        try:
            await self._client.login_and_initiate_cloud(self._config.email, self._config.password)
        except Exception as e:
            self._logger.error(f"Failed to connect to Mammotion cloud: {e}")
            return

        self._client.setup_all_mower_watchers()
        for handle in self._devices():
            self._subscriptions.append(handle.subscribe_state_changed(self._make_state_handler(handle.device_name)))
            if not DeviceType.is_swimming_pool(handle.device_name):
                self._subscriptions.append(handle.subscribe_map_updated(self._make_map_handler(handle.device_name)))
            await handle.start()
        self._logger.info(f"Connected to Mammotion cloud - {len(self._devices())} device(s) found")


    async def on_message(self, message: dict):
        action = message.get('action')
        device = message.get('device')
        args = message.get('args') or {}

        try:
            if action == 'synchronize':
                for handle in self._devices():
                    await self._sync_device_info(handle.device_name)
                await self._send_devices()
                for handle in self._devices():
                    await self._sync_areas(handle.device_name)
                    await self._send_state(handle.device_name)

            elif action == 'refresh':
                if DeviceType.is_swimming_pool(device):
                    await self._client.send_command_with_args(device, 'get_report_cfg_spino', count=1)
                else:
                    await self._client.ensure_fresh_state(device, max_age_s=0)
                await self._send_state(device)

            elif action == 'start':
                if DeviceType.is_swimming_pool(device):
                    await self._command(device, 'clean_mode', {'work_mode': SpinoWorkMode.AUTO.value})
                else:
                    await self._start(device, args.get('hash'))

            elif action == 'start_plan':
                await self._client.send_command_and_wait(device, 'single_schedule', 'todev_planjob_set', plan_id=args['plan_id'])

            elif action == 'command':
                self._logger.info(f"Command {args['key']} for {device} : {args.get('kwargs') or {}}")
                await self._command(device, args['key'], args.get('kwargs') or {})

        except DeviceOfflineException:
            self._logger.warning(f"{device} is offline - command dropped")
            if device:
                await self.send_to_jeedom({'event': 'state', 'device': device, 'data': {'online': 0}})
        except (GatewayTimeoutException, CommandTimeoutError) as e:
            self._logger.warning(f"Command timeout for {device} (robot busy or asleep ?) : {e}")
        except CheckSessionException as e:
            self._logger.error(f"Mammotion session expired : {e} - restart the daemon if the problem persists")
        except Exception as e:
            self._logger.error(f"Error handling message from Jeedom ({action}) : {e}")


    async def on_stop(self):
        self._logger.info("Stopping Mammotion daemon...")
        for sub in self._subscriptions:
            sub.cancel()
        self._subscriptions.clear()
        if self._client:
            await self._client.stop()
        self._logger.info("Daemon stopped")


    def _devices(self):
        # RTK exclus : seuls les robots (tondeuses et piscine) sont remontés dans Jeedom
        return [h for h in self._client.device_registry.all_devices if not DeviceType.is_rtk(h.device_name)]


    def _make_state_handler(self, name: str):
        async def _handler(snapshot):
            await self._send_state(name)
        return _handler


    def _make_map_handler(self, name: str):
        async def _handler():
            await self._send_areas(name)
            await self._send_plans(name)
        return _handler


    async def _command(self, name: str, key: str, kwargs: dict):
        if DeviceType.is_swimming_pool(name):
            if key == 'clean_mode':
                await self._client.send_command_with_args(name, 'set_swimming_work_mode', work_mode=int(kwargs['work_mode']))
            elif key == 'dock':
                await self._client.send_command_with_args(name, 'set_swimming_work_mode', work_mode=SpinoWorkMode.RECHARGE.value)
            elif key == 'set_floor_speed':
                await self._client.send_command_with_args(name, 'sp_speed_update', speed=float(kwargs['speed']))
            else:
                self._logger.warning(f"Command '{key}' is not supported by pool cleaners - dropped")
                return
            await self._client.send_command_with_args(name, 'get_report_cfg_spino', count=1)
            return        
        
        device = self._client.get_device_by_name(name)
        mode = device.report_data.dev.sys_status

        if key == 'pause':
            if mode == WorkMode.MODE_RETURNING:            # en retour station, pause = annulation du retour
                await self._client.send_command_with_args(name, 'cancel_return_to_dock')
            else:
                await self._client.send_command_with_args(name, 'pause_execute_task')

        elif key == 'resume':
            await self._client.send_command_with_args(name, 'resume_execute_task')

        elif key == 'cancel':
            await self._client.send_command_and_wait(name, 'cancel_job', 'todev_taskctrl_ack')

        elif key == 'dock':
            if mode == WorkMode.MODE_RETURNING:
                self._logger.info(f"{name} is already returning to dock")
                return
            if mode == WorkMode.MODE_WORKING:              # pause de la tâche avant le retour station
                await self._client.send_command_with_args(name, 'pause_execute_task')
            await self._client.send_command_with_args(name, 'return_to_dock')

        elif key == 'leave_dock':
            await self._client.send_command_and_wait(name, 'leave_dock', 'todev_taskctrl_ack')

        elif key == 'set_blade_height':
            await self._client.send_command_and_wait(name, 'set_blade_height', 'toapp_knife_status_change', **kwargs)

        elif key == 'set_speed':
            await self._client.send_command_and_wait(name, 'set_speed', 'bidire_speed_read_set', **kwargs)

        else:
            await self._client.send_command_with_args(name, key, **kwargs)

        await self._client.ensure_fresh_state(name, max_age_s=0)


    async def _send_devices(self):
        # Robots pré-2025 : product_model et product_image.
        # Robots post-2025 : model_id
        aliyun = {d.device_name: d for d in self._client.aliyun_device_list}
        devices = []
        for handle in self._devices():
            device = self._client.get_device_by_name(handle.device_name)
            is_pool = DeviceType.is_swimming_pool(handle.device_name)
            mower_state = getattr(device, 'mower_state', None)
            cloud = aliyun.get(handle.device_name)
            devices.append({
                'name': handle.device_name,
                'device_type': 'pool' if is_pool else 'mower',
                'model': (cloud.product_model if cloud else '') or (mower_state.model_id if mower_state else '') or (mower_state.model if mower_state else ''),
                'swversion': mower_state.swversion if mower_state else '',
                'has_blade_control': int(not is_pool and not DeviceType.is_yuka(handle.device_name)),
            })
        self._logger.info(f"Sending device list to Jeedom : {[d['name'] for d in devices]}")
        await self.send_to_jeedom({'event': 'devices', 'data': devices})


    async def _sync_device_info(self, name: str):
        # Interroge le robot (le réveille si besoin) pour récupérer modèle et firmware
        if DeviceType.is_swimming_pool(name):
            return
        device = self._client.get_device_by_name(name)

        checks = [
            ('get_device_version_main', 'toapp_devinfo_resp', bool(device.mower_state.swversion)),
            ('get_device_base_info', 'toapp_devinfo_resp', bool(device.device_firmwares.device_version)),
            ('get_device_product_model', 'device_product_type_info', bool(device.mower_state.model_id)),
        ]
        for command, expected_field, already_set in checks:
            if already_set:
                continue
            try:
                await self._client.send_command_and_wait(name, command, expected_field)
            except Exception as e:
                self._logger.warning(f"Device info request '{command}' failed for {name} (robot offline ?) : {e}")
                break

        # Repli : version firmware via le check OTA cloud, disponible même robot endormi
        if not device.mower_state.swversion:
            try:
                handle = self._client.device_registry.get_by_name(name)
                ota_info = await self._client.mammotion_http.get_device_ota_firmware([handle.iot_id])
                for check in (ota_info.data or []):
                    if check.device_id == handle.iot_id:
                        device.apply_version_check(check)
            except Exception as e:
                self._logger.warning(f"OTA version check failed for {name} : {e}")

        # Force une remontée d'état
        await self._client.ensure_fresh_state(name, max_age_s=0)


    async def _sync_areas(self, name: str):
        if DeviceType.is_swimming_pool(name):
            return
        try:
            await self._client.start_map_sync(name)
        except Exception as e:
            self._logger.warning(f"Map sync failed for {name}: {e}")


    async def _send_areas(self, name: str):
        device = self._client.get_device_by_name(name)
        areas = [{'name': a.name or str(a.hash), 'hash': a.hash} for a in device.map.area_name]
        self._logger.info(f"Sending areas to Jeedom for {name} : {[a['name'] for a in areas]}")
        await self.send_to_jeedom({'event': 'areas', 'device': name, 'data': areas})


    async def _send_plans(self, name: str):
        # Activités (plans) créées par l'utilisateur dans l'application
        device = self._client.get_device_by_name(name)
        plans = [{'plan_id': p.plan_id, 'name': p.task_name or p.plan_id} for p in device.map.plan.values()]
        self._logger.info(f"Sending plans to Jeedom for {name} : {[p['name'] for p in plans]}")
        await self.send_to_jeedom({'event': 'plans', 'device': name, 'data': plans})


    async def _send_state(self, name: str):
        now = time.monotonic()
        if now - self._last_sent.get(name, 0) < STATE_THROTTLE:
            if name not in self._pending:
                self._pending[name] = asyncio.create_task(self._delayed_send(name))
            return
        self._last_sent[name] = now

        device = self._client.get_device_by_name(name)
        if device is None:
            return

        if DeviceType.is_swimming_pool(name):
            pool = device.pool_state
            data = {
                'online': int(device.online),
                'battery': pool.battery,
                'work_mode': pool.sys_status.name,
                'clean_mode': pool.work_mode.name,
                'speed': pool.floor_speed,
            }
        else:
            rpt = device.report_data
            mode = rpt.dev.sys_status

            if mode == WorkMode.MODE_NOT_ACTIVE and device.online:
                self._logger.debug(f"Ignoring unreliable MODE_NOT_ACTIVE frame for {name}")
                return

            # Journal d'événements : détection des transitions d'état
            event = None
            last_mode = self._last_mode.get(name)
            if last_mode is not None and mode != last_mode:
                if last_mode == WorkMode.MODE_WORKING and mode == WorkMode.MODE_RETURNING and rpt.work.mow_percent >= 100:
                    event = 'Tonte terminée - retour à la station'
                else:
                    event = EVENT_LABELS.get(mode, device_mode(mode))
                self._logger.info(f"Event for {name} : {event}")
            self._last_mode[name] = mode

            data = {
                'online': int(device.online),
                'battery': rpt.dev.battery_val,
                'charging': int(rpt.dev.charge_state in (1, 2)),
                'docked': int(rpt.dev.charge_state != 0),
                'work_mode': device_mode(rpt.dev.sys_status),
                'connect_type': device_connection(rpt.connect),
                'work_progress': rpt.work.mow_percent,
                'work_area': rpt.work.area_mowed,
                'current_area': next((a.name for a in device.map.area_name if a.hash == rpt.work.ub_zone_hash), ''),
                'left_time': rpt.work.progress >> 16,
                'elapsed_time': max(0, (rpt.work.progress & 0xFFFF) - (rpt.work.progress >> 16)),
                'blade_height': rpt.work.knife_height,
                'speed': rpt.work.man_run_speed / 100,
                'blade_status': int(device.mower_state.blade_status),
                'rain_detection': int(device.mower_state.rain_detection),
                'gps_coordinates': f"{device.location.device.latitude},{device.location.device.longitude}" if device.location.RTK.latitude != 0.0 else '',
                'orientation': device.location.orientation,
                'wifi_rssi': rpt.connect.wifi_rssi,
                'blade_used_time': round(rpt.maintenance.blade_used_time.blade_used_time / 3600, 1),
                'total_mileage': round((rpt.maintenance.mileage or rpt.dev.mileage) / 1000, 1),
                'total_work_time': round((rpt.maintenance.work_time or rpt.dev.work_time_sec) / 3600, 1),
                'bat_cycles': rpt.maintenance.bat_cycles if rpt.maintenance.bat_cycles != 65535 else 0,
                'firmware': device.mower_state.swversion,
                'error': ','.join(str(code) for code in device.errors.err_code_list),
            }
            if event:
                data['last_event'] = event
            if not data['firmware']:
                data.pop('firmware')
        
        self._logger.debug(f"Sending state to Jeedom for {name} : {data}")
        await self.send_to_jeedom({'event': 'state', 'device': name, 'data': data})


    async def _delayed_send(self, name: str):
        await asyncio.sleep(STATE_THROTTLE)
        self._pending.pop(name, None)
        await self._send_state(name)


    async def _start(self, name: str, area_hash=None):
        await self._client.ensure_fresh_state(name)
        device = self._client.get_device_by_name(name)
        mode = device.report_data.dev.sys_status
        breakpoint_info = device.report_data.work.bp_info

        # Tâche en pause -> reprise
        if mode == WorkMode.MODE_PAUSE and breakpoint_info != 0:
            self._logger.info(f"Resuming paused task for {name}")
            await self._client.send_command_with_args(name, 'resume_execute_task')
            await self._client.send_command_and_wait(name, 'query_generate_route_information', 'bidire_reqconver_path')
            return

        # Tâche interrompue (point d'arrêt) -> reprise du job planifié
        #if breakpoint_info != 0 and area_hash is None:
        #    self._logger.info(f"Restarting planned task from breakpoint for {name}")
        #    await self._client.send_command_and_wait(name, 'query_generate_route_information', 'bidire_reqconver_path')
        #    await self._client.send_command_with_args(name, 'start_job')
        #    return

        # Aucune reprise en cours et pas de zone imposée -> lancer l'activité par défaut
        #if area_hash is None:
        #    default_plan = next((p for p in device.map.plan.values() if p.is_enabled()), None)
        #    if default_plan is not None:
        #        self._logger.info(f"Starting default activity '{default_plan.task_name}' for {name}")
        #        await self._client.send_command_and_wait(name, 'single_schedule', 'todev_planjob_set', plan_id=default_plan.plan_id)
        #        return

        # Nouvelle tâche -> planification de la route puis démarrage
        settings = OperationSettings()
        settings.areas = [int(area_hash)] if area_hash is not None else [a.hash for a in device.map.area_name]
        if not settings.areas:
            self._logger.warning(f"No area known for {name} - launch a synchronization first")
        if device.work.speed:
            settings.speed = device.work.speed
        if device.work.knife_height:
            settings.blade_height = device.work.knife_height
        if device.report_data.dev.collector_status.collector_installation_status == 0:
            settings.is_dump = False
        if DeviceType.is_yuka(name):
            settings.blade_height = -10

        route = GenerateRouteInformation(
            one_hashs=settings.areas,
            rain_tactics=settings.rain_tactics,
            speed=settings.speed,
            ultra_wave=settings.ultra_wave,
            toward=settings.toward,
            toward_included_angle=settings.toward_included_angle if settings.channel_mode == 1 else 0,
            toward_mode=settings.toward_mode,
            blade_height=settings.blade_height,
            channel_mode=settings.channel_mode,
            channel_width=settings.channel_width,
            job_mode=settings.job_mode,
            edge_mode=settings.mowing_laps,
            path_order=create_path_order(settings, name),
            obstacle_laps=settings.obstacle_laps,
        )
        if DeviceType.is_luba1(name):
            route.toward_mode = 0
            route.toward_included_angle = 0

        self._logger.info(f"Planning route for {name} (areas : {settings.areas}) and starting job")
        await self._client.send_command_and_wait(name, 'generate_route_information', 'bidire_reqconver_path', generate_route_information=route)
        await self._client.send_command_and_wait(name, 'start_job', 'todev_taskctrl_ack')


MammotionDaemon().run()
