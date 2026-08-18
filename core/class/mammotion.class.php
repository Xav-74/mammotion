<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';


class mammotion extends eqLogic {

    /*     * *************************Attributs****************************** */

	const PYTHON_PATH = __DIR__ . '/../../resources/venv/bin/python3';

	public static $_encryptConfigKey = array('email', 'password');

	public static $_widgetPossibility = array(
		'custom' => true,
		'parameters' => array(),
	);


    /*     * ***********************Methode static*************************** */

    public static function pull()
	{
		log::add('mammotion', 'debug', 'Cron '.config::byKey('cronJob', 'mammotion', '*/15 * * * *'));
		foreach (eqLogic::byType('mammotion', true) as $mammotion) {		// type = mammotion et eqLogic enable
			$cmdRefresh = $mammotion->getCmd(null, 'refresh');
			if (!is_object($cmdRefresh) ) {								// Si la commande n'existe pas
				continue; 												// continue la boucle
			}
			$cmdRefresh->execCmd();
		}
	}

	public static function dependancy_info()
	{
    	$pythonBin = __DIR__ . '/../../resources/venv/bin/python3';
        $pythonReq = __DIR__ . '/../../resources/requirements.txt';

		$return = array();
        $return['log'] = log::getPathToLog(__CLASS__ . '_update');
        $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependance';
        $return['state'] = 'ok';
        if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependance')) {
            $return['state'] = 'in_progress';
        } elseif (!file_exists($pythonBin)) {
            $return['state'] = 'nok';
        } elseif (!self::pythonRequirementsInstalled($pythonBin, $pythonReq)) {
            $return['state'] = 'nok';
        }
        return $return;
    }

	public static function dependancy_install()
	{
        log::remove(__CLASS__ . '_update');
        return array('script' => __DIR__ . '/../../resources/install_#stype#.sh', 'log' => log::getPathToLog(__CLASS__ . '_update'));
    }

	private static function pythonRequirementsInstalled(string $pythonPath, string $requirementsPath)
	{
        if (!file_exists($pythonPath) || !file_exists($requirementsPath)) {
        	return false;
        }
        exec("{$pythonPath} -m pip freeze", $packages_installed);
        $packages = join("||", $packages_installed);
        exec("cat {$requirementsPath}", $packages_needed);
        foreach ($packages_needed as $line) {
          if (preg_match('/([^\s]+)[\s]*([>=~]=)[\s]*([\d+\.?]+)$/', $line, $need) === 1) {
            if (preg_match('/' . $need[1] . '==([\d+\.?]+)/', $packages, $install) === 1) {
              if ($need[2] == '==' && $need[3] != $install[1]) {
                return false;
              } elseif (version_compare($need[3], $install[1], '>')) {
                return false;
              }
            } else {
              return false;
            }
          }
        }
        return true;
    }

	public static function backupExclude()
	{
        return ['resources/venv'];
    }

	public static function deamon_info()
	{
        $return = array();
        $return['log'] = __CLASS__;
        $return['state'] = 'nok';
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
        if (file_exists($pid_file)) {
            if (@posix_getsid(trim(file_get_contents($pid_file)))) {
                $return['state'] = 'ok';
            } else {
                shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
            }
        }

        $return['launchable'] = 'ok';
        $email = config::byKey('email', __CLASS__);
        $password = config::byKey('password', __CLASS__);
		if ($email == '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = 'Email not configured';
        } elseif ($password == '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = 'Password not configured';
        }

		return $return;
    }

    public static function deamon_start()
	{
		self::deamon_stop();
       	$deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception('Please check the configuration');
        }

		$email = config::byKey('email', __CLASS__);
		$password = config::byKey('password', __CLASS__);
		$socketPort = (int) (config::byKey('socketPort', __CLASS__, 44090));

		$path = realpath(__DIR__ . '/../../resources');
        $cmd = self::PYTHON_PATH . " {$path}/mammotion.py";
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
        $cmd .= ' --email ' . escapeshellarg(trim($email));
        $cmd .= ' --password ' . escapeshellarg(trim($password));
		$cmd .= ' --socketport ' . $socketPort;
		$cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/mammotion/core/php/jeeMammotion.php';
        $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__);
        $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
        log::add(__CLASS__, 'debug', 'Lancement démon');
		$result = exec($cmd . ' >> ' . log::getPathToLog(__CLASS__ . '_daemon') . ' 2>&1 &');

		$i = 0;
        while ($i < 60) {			
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($i >= 60) {
            log::add(__CLASS__, 'error', 'Unable to start daemon', 'unableStartDeamon');
            return false;
        }
        message::removeAll(__CLASS__, 'unableStartDeamon');
		return true;
	}

	public static function deamon_stop()
	{
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            system::kill($pid);
        }
        sleep(1);
        system::kill('mammotion.py');
        sleep(1);
    }

	public static function sendToDaemon($action, $device = null, $args = null)
	{
        $deamon_info = self::deamon_info();
        if ($deamon_info['state'] != 'ok') {
            throw new Exception('Daemon is not started');
        }
		$payload = array(
            'apikey' => jeedom::getApiKey(__CLASS__),
			'action' => $action,
            'device' => $device,
			'args' => $args
        );
        $msg = json_encode($payload);

		$socket = socket_create(AF_INET, SOCK_STREAM, 0);
        socket_connect($socket, '127.0.0.1', config::byKey('socketPort', __CLASS__, 44090));
        socket_write($socket, $msg, strlen($msg));
        socket_close($socket);
        log::add(__CLASS__, 'debug', '| Send to daemon : ' . $msg);
    }

	public static function synchronize()
	{
		log::add('mammotion', 'debug', '┌─Command execution : synchronize');
		self::sendToDaemon('synchronize');
		log::add('mammotion', 'debug', '└─End of synchronize (equipments will be created / updated on daemon answer)');
		$result = array();
		$result['res'] = "OK";
		return $result;
	}

	public static function scheduleCron($cronJob)
	{
		$cron = cron::byClassAndFunction('mammotion', 'pull');
		if (!is_object($cron)) {
			$cron = new cron();
			$cron->setClass('mammotion');
			$cron->setFunction('pull');
			$cron->setEnable(1);
			$cron->setDeamon(0);
			$cron->setSchedule('*/15 * * * *');
			$cron->setTimeout(5);
			$cron->save();
			log::add('mammotion', 'debug', 'Create cron pull');
		}
		if ($cronJob == '' || $cronJob == null) {
			$cron->setSchedule('*/15 * * * *');
		}
		else { $cron->setSchedule($cronJob); }
		$cron->save();
		log::add('mammotion', 'debug', 'Update cron pull - setSchedule : '.$cronJob);
		return;
	}

	public static function getConfigForCommunity()
	{
		$index = 1;
		$CommunityInfo = "```\n";
		if ( !empty(config::byKey('email', 'mammotion')) ) { $CommunityInfo = $CommunityInfo . 'Email configured' . "\n"; }
        else { $CommunityInfo = $CommunityInfo . 'Email missing' . "\n"; }
		if ( !empty(config::byKey('password', 'mammotion')) ) { $CommunityInfo = $CommunityInfo . 'Password configured' . "\n"; }
        else { $CommunityInfo = $CommunityInfo . 'Password missing' . "\n"; }
		$CommunityInfo = $CommunityInfo . 'SocketPort : ' . config::byKey('socketPort', 'mammotion', 44090) . "\n";
		$CommunityInfo = $CommunityInfo . 'Cron : ' . config::byKey('cronJob', 'mammotion', '*/15 * * * *') . "\n";
		foreach (eqLogic::byType('mammotion', true) as $mammotion)  {
			$CommunityInfo = $CommunityInfo . "Robot #" . $index . " - Name : " . $mammotion->getConfiguration('device_name') . " - Model : " . $mammotion->getConfiguration('device_model') . " - Type : ". $mammotion->getConfiguration('device_type') . " - Firmware : ". $mammotion->getConfiguration('device_swversion') . "\n";
			$index++;
		}
		$CommunityInfo = $CommunityInfo . "```";
		return $CommunityInfo;
	}

	public static function getMammotionEqLogic($device_name)
	{
		return eqLogic::byLogicalId($device_name, 'mammotion');
	}

	/* Création / mise à jour des équipements à partir de la liste envoyée par le démon */
	public static function syncDevices($devices)
	{
		foreach ($devices as $device) {
			$eqLogic = self::getMammotionEqLogic($device['name']);
			if (!is_object($eqLogic)) {
				log::add('mammotion', 'info', 'New device found : '.$device['name'].' ('.$device['model'].')');
				$eqLogic = new mammotion();
				$eqLogic->setName($device['name']);
				$eqLogic->setLogicalId($device['name']);
				$eqLogic->setEqType_name('mammotion');
				$eqLogic->setIsEnable(1);
				$eqLogic->setIsVisible(1);
				event::add('jeedom::alert', array(
					'level' => 'success',
					'message' => __('Nouveau robot détecté : ', __FILE__).$device['name'],
				));
			}
			
			$eqLogic->setConfiguration('device_name', $device['name']);
			$eqLogic->setConfiguration('device_type', $device['device_type']);
			if ($device['model'] != '') { $eqLogic->setConfiguration('device_model', $device['model']); }
			if ($device['swversion'] != '') { $eqLogic->setConfiguration('device_swversion', $device['swversion']); }
			$eqLogic->setConfiguration('has_blade_control', $device['has_blade_control']);
			
			if (isset($device['blade_height_max']) && $device['blade_height_max'] > 0) {
				$eqLogic->setConfiguration('blade_height_min', $device['blade_height_min']);
				$eqLogic->setConfiguration('blade_height_max', $device['blade_height_max']);
			}
			if (isset($device['speed_max']) && $device['speed_max'] > 0) {
				$eqLogic->setConfiguration('speed_min', $device['speed_min']);
				$eqLogic->setConfiguration('speed_max', $device['speed_max']);
			}
			
			$eqLogic->save();
			$eqLogic->updateSliderBounds();
		}
	}

	/* Met à jour les bornes min/max des sliders hauteur/vitesse depuis la configuration */
	public function updateSliderBounds()
	{
		$map = array(
			'set_blade_height' => array('blade_height_min', 30, 'blade_height_max', 70),
			'set_speed'        => array('speed_min', 0.2, 'speed_max', 0.6),
		);
		foreach ($map as $cmdName => $conf) {
			$cmd = $this->getCmd(null, $cmdName);
			if (!is_object($cmd)) { continue; }
			$cmd->setConfiguration('minValue', $this->getConfiguration($conf[0], $conf[1]));
			$cmd->setConfiguration('maxValue', $this->getConfiguration($conf[2], $conf[3]));
			$cmd->save();
			log::add('mammotion', 'debug', 'Update command '.$cmd->getName().' (LogicalId : '.$cmd->getLogicalId().') - min : '.$this->getConfiguration($conf[0], $conf[1]).' / max : '.$this->getConfiguration($conf[2], $conf[3]));
		}
	}


    /*     * *********************Méthodes d'instance************************* */

    /* fonction appelée pendant la séquence de sauvegarde avant l'insertion 
     * dans la base de données pour une nouvelle entrée */
	public function preInsert()
	{
	}

    /* fonction appelée pendant la séquence de sauvegarde après l'insertion 
     * dans la base de données pour une nouvelle entrée */
    public function postInsert()
	{
    }

    /* fonction appelée avant le début de la séquence de sauvegarde */
    public function preSave()
	{
    }

    public function postSave()
	{
		$order = 1;
		$deviceType = $this->getConfiguration('device_type', 'mower');

		if ($deviceType == 'pool') {
			$this->createCmd('online', 'En ligne', $order, 'info', 'binary');
			$order++;
			$this->createCmd('battery', 'Batterie', $order, 'info', 'numeric', 1, 1);
			$order++;
			$this->createCmd('charging', 'En charge', $order, 'info', 'binary');
			$order++;
			$this->createCmd('work_mode', 'Statut', $order, 'info', 'string');
			$order++;
			$this->createCmd('speed', 'Vitesse', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('clean_mode', 'Mode de nettoyage', $order, 'info', 'string');
			$order++;
			$this->createCmd('wifi_rssi', 'Signal Wifi', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('wifi_connected', 'Wifi connecté', $order, 'info', 'binary');
			$order++;
			
			$this->createCmd('refresh', 'Rafraichir', $order, 'action', 'other');
			$order++;
			$this->createCmd('start', 'Démarrer', $order, 'action', 'other');
			$order++;
			$this->createCmd('dock', 'Retour station', $order, 'action', 'other');
			$order++;
			$this->createCmd('set_clean_mode', 'Lancer un nettoyage', $order, 'action', 'select', 1, 0, [], array('listValue' => '1|Complet;2|Fond;3|Parois;4|Eco;5|Ligne d\'eau'));
			$order++;
			$this->createCmd('set_floor_speed', 'Régler vitesse au sol (cm/s)', $order, 'action', 'slider', 1, 0, [], array('minValue' => 10, 'maxValue' => 100));
			$order++;			
		}

		if ($deviceType == 'mower') {
			$this->createCmd('online', 'En ligne', $order, 'info', 'binary');
			$order++;
			$this->createCmd('battery', 'Batterie', $order, 'info', 'numeric', 1, 1);
			$order++;
			$this->createCmd('work_mode', 'Statut', $order, 'info', 'string');
			$order++;
			$this->createCmd('speed', 'Vitesse', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('charging', 'En charge', $order, 'info', 'binary');
			$order++;
			$this->createCmd('docked', 'Sur la base', $order, 'info', 'binary');
			$order++;
			$this->createCmd('work_progress', 'Progression', $order, 'info', 'numeric', 1, 1);
			$order++;
			$this->createCmd('work_area', 'Surface tondue', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('current_area', 'Zone courante', $order, 'info', 'string');
			$order++;
			$this->createCmd('left_time', 'Temps restant', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('elapsed_time', 'Temps écoulé', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('blade_height', 'Hauteur de lame', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('blade_status', 'Lames actives', $order, 'info', 'binary');
			$order++;
			$this->createCmd('rain_detection', 'Détection de pluie', $order, 'info', 'binary');
			$order++;
			$this->createCmd('gps_coordinates', 'Coordonnées GPS', $order, 'info', 'string');
			$order++;
			$this->createCmd('orientation', 'Orientation', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('wifi_rssi', 'Signal Wifi', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('ble_rssi', 'Signal Bluetooth', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('mnet_rssi', 'Signal cellulaire', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('blade_used_time', 'Temps d\'utilisation des lames', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('blade_used_warn_time', 'Seuil d\'usure des lames', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('blade_used_left_time', 'Temps restant d\'utilisation des lames', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('total_mileage', 'Distance totale', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('total_work_time', 'Temps de travail total', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('bat_cycles', 'Cycles batterie', $order, 'info', 'numeric');
			$order++;
			$this->createCmd('firmware', 'Firmware', $order, 'info', 'string');
			$order++;
			$this->createCmd('error', 'Erreurs', $order, 'info', 'string');
			$order++;
			$this->createCmd('connect_type', 'Connexion', $order, 'info', 'string');
			$order++;
			$this->createCmd('last_event', 'Dernier événement', $order, 'info', 'string');
			$order++;
			
			$this->createCmd('refresh', 'Rafraichir', $order, 'action', 'other');
			$order++;
			$this->createCmd('start', 'Démarrer', $order, 'action', 'other');
			$order++;
			$this->createCmd('dock', 'Retour station', $order, 'action', 'other');
			$order++;
			$this->createCmd('pause', 'Pause', $order, 'action', 'other');
			$order++;
			$this->createCmd('resume', 'Reprendre', $order, 'action', 'other');
			$order++;
			$this->createCmd('cancel', 'Annuler la tâche', $order, 'action', 'other');
			$order++;
			$this->createCmd('leave_dock', 'Quitter la station', $order, 'action', 'other');
			$order++;
			$this->createCmd('start_zone', 'Tondre une zone', $order, 'action', 'select');
			$order++;
			$this->createCmd('start_activity', 'Lancer une activité', $order, 'action', 'select');
			$order++;
			
			// Réglages hauteur de lame / vitesse (non supportés par la gamme Yuka, uniquement Luba)
			if ($this->getConfiguration('has_blade_control', 1) == 1) {
				$bhMin = $this->getConfiguration('blade_height_min', 30);
				$bhMax = $this->getConfiguration('blade_height_max', 70);
				$spMin = $this->getConfiguration('speed_min', 0.2);
				$spMax = $this->getConfiguration('speed_max', 0.6);
				$this->createCmd('blade_height_target', 'Consigne hauteur de lame', $order, 'info', 'numeric', 1, 1);
				$order++;
				$this->createCmd('speed_target', 'Consigne vitesse', $order, 'info', 'numeric', 1, 1);
				$order++;
				
				$this->createCmd('set_blade_height', 'Régler hauteur de lame', $order, 'action', 'slider', 1, 0, [], array('minValue' => $bhMin, 'maxValue' => $bhMax), $this->getCmd(null, 'blade_height_target')->getId());
				$order++;
				$this->createCmd('set_speed', 'Régler vitesse', $order, 'action', 'slider', 1, 0, [], array('minValue' => $spMin, 'maxValue' => $spMax), $this->getCmd(null, 'speed_target')->getId());
				$order++;
			}
		}
		
		$this->createCmd('lastUpdate', 'Dernière mise à jour', $order, 'info', 'string');
	}

    /* fonction appelée pendant la séquence de sauvegarde avant l'insertion 
     * dans la base de données pour une mise à jour d'une entrée */
    public function preUpdate()
	{
	}

    /* fonction appelée pendant la séquence de sauvegarde après l'insertion 
     * dans la base de données pour une mise à jour d'une entrée */
    public function postUpdate()
	{
	}

    /* fonction appelée avant l'effacement d'une entrée */
    public function preRemove()
	{
    }

    /* fonnction appelée aprés l'effacement d'une entrée */
    public function postRemove()
	{
    }

    /* Non obligatoire mais permet de modifier l'affichage du widget si vous en avez besoin */
    public function toHtml($_version = 'dashboard') {
    	
		if ( $this->getConfiguration('device_type') != 'mower') {
			return parent::toHtml($_version);
		}

		$this->emptyCacheWidget(); 		//vide le cache. Pratique pour le développement
				
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) {
			return $replace;
		}
		
		$version = jeedom::versionAlias($_version);
		$replace['#version#'] = $_version;

		//Traitement des des options de configuration
		$replace['#device_name'.$this->getId().'#'] = $this->getConfiguration('device_name');
		$replace['#blade_height_min'.$this->getId().'#'] = $this->getConfiguration('blade_height_min',30);
		$replace['#blade_height_max'.$this->getId().'#'] = $this->getConfiguration('blade_height_max',70);
		$replace['#speed_min'.$this->getId().'#'] = $this->getConfiguration('speed_min',0.2);
		$replace['#speed_max'.$this->getId().'#'] = $this->getConfiguration('speed_max',0.4);
				
		// Traitement des commandes infos
		foreach ($this->getCmd('info') as $cmd) {
			$replace['#' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
			$replace['#' . $cmd->getLogicalId() . '_name#'] = $cmd->getName();
			$replace['#' . $cmd->getLogicalId() . '#'] = $cmd->execCmd();
			$replace['#' . $cmd->getLogicalId() . '_visible#'] = $cmd->getIsVisible();
			$replace['#' . $cmd->getLogicalId() . '_collect#'] = $cmd->getCollectDate();
			if ($cmd->getIsHistorized() == 1) { $replace['#' . $cmd->getLogicalId() . '_history#'] = 'history cursor'; }
		}

		// Traitement des commandes actions
		foreach ($this->getCmd('action') as $cmd) {
			$replace['#' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
			$replace['#' . $cmd->getLogicalId() . '_visible#'] = $cmd->getIsVisible();
			if ($cmd->getSubType() == 'select') {
				$listValue = '<option value="" disabled selected>' . 'Aucune' . '</option>';
				$listValueArray = explode(';', $cmd->getConfiguration('listValue'));
				foreach ($listValueArray as $value) {
					if (strpos($value, '|') === false) { continue; }
					list($id, $name) = explode('|', $value);
					$listValue = $listValue . '<option value="' . $id . '">' . $name . '</option>';
				}
				$replace['#' . $cmd->getLogicalId() . '_listValue#'] = $listValue;
			}
		}
		
		// On definit le template à appliquer
		$template = 'mammotion_mower_dashboard_flatdesign';
		$replace['#template#'] = $template;

		$filepath = 'plugins/'.__CLASS__.'/core/template/'.$version.'/'.$template.'.html';
       	$html = template_replace($replace, getTemplate('core', $version, $template, 'mammotion'));
       	$html = translate::exec($html, $filepath);
   		return $this->postToHtml($_version, $html);
	}

	/* Non obligatoire mais ca permet de déclencher une action après modification de variable de configuration
    public static function postConfig_<Variable>() {
    } */

    /* Non obligatoire mais ca permet de déclencher une action avant modification de variable de configuration
    public static function preConfig_<Variable>() {
    } */

	private function createCmd($commandName, $commandDescription, $order, $type, $subType, $isVisible = 1, $isHistorized = 0, $template = [], $configuration = [], $idCmd = null)
	{
		$cmd = $this->getCmd(null, $commandName);
        if (!is_object($cmd)) {
            $cmd = new mammotionCmd();
            $cmd->setOrder($order);
			$cmd->setName(__($commandDescription, __FILE__));
			$cmd->setEqLogic_id($this->getId());
			$cmd->setLogicalId($commandName);
			$cmd->setType($type);
			$cmd->setSubType($subType);
			$cmd->setIsVisible($isVisible);
			$cmd->setIsHistorized($isHistorized);
			if (!empty($template)) { $cmd->setTemplate($template[0], $template[1]); }
			foreach ($configuration as $key => $value) { $cmd->setConfiguration($key, $value); }
			$cmd->setValue($idCmd);
			$cmd->save();
			log::add('mammotion', 'debug', 'Add command '.$cmd->getName().' (LogicalId : '.$cmd->getLogicalId().')');
        }
    }


    /*     * **********************Getteur Setteur*************************** */


	/* Mise à jour des commandes infos */
	public function handleState($data)
	{
		$this->checkAndUpdateCmd('lastUpdate', date('d/m/Y H:i:s'));

		if (isset($data['last_event'])) {
			$cmd = $this->getCmd(null, 'last_event');
			if (is_object($cmd) && $data['last_event'] != $cmd->execCmd()) {
				$this->sendNotification($data['last_event']);
			}
		}

		foreach ($data as $key => $value) {
			$this->checkAndUpdateCmd($key, $value);
		}
		log::add('mammotion', 'debug', 'State updated for '.$this->getName().' : '.json_encode($data));
		
		$usedCmd = $this->getCmd(null, 'blade_used_time');
		$warnCmd = $this->getCmd(null, 'blade_used_warn_time');
		$used_time = (float) $usedCmd->execCmd();
		$warn_time = (float) $warnCmd->execCmd();
		$this->checkAndUpdateCmd('blade_used_left_time', max(0, $warn_time - $used_time));
	}
	
	/* Mise à jour de la liste des zones */
	public function handleAreas($areas)
	{
		$listValue = array();
		foreach ($areas as $area) {
			$listValue[] = $area['hash'].'|'.$area['name'];
		}
		$this->setConfiguration('areas', implode(';', $listValue));
		$this->save(true);

		$cmd = $this->getCmd(null, 'start_zone');
		if (is_object($cmd)) {
			$cmd->setConfiguration('listValue', implode(';', $listValue));
			$cmd->save();
		}
		log::add('mammotion', 'debug', 'Areas updated for '.$this->getName().' : '.json_encode($areas));
	}

	/* Mise à jour de la liste des activités (commande select start_activity) */
	public function handlePlans($plans)
	{
		$listValue = array();
		foreach ($plans as $plan) {
			$listValue[] = $plan['plan_id'].'|'.$plan['name'];
		}
		$this->setConfiguration('plans', implode(';', $listValue));
		$this->save(true);

		$cmd = $this->getCmd(null, 'start_activity');
		if (is_object($cmd)) {
			$cmd->setConfiguration('listValue', implode(';', $listValue));
			$cmd->save();
		}
		log::add('mammotion', 'debug', 'Plans updated for '.$this->getName().' : '.json_encode($plans));
	}

	/* Consignes hauteur/vitesse à appliquer au lancement d'une tonte (start / start_zone) */
	public function getStartSettings()
	{
		$settings = array();
		$heightCmd = $this->getCmd(null, 'blade_height_target');
		$speedCmd = $this->getCmd(null, 'speed_target');
		if (is_object($heightCmd)) {
			$settings['height'] = (int) $heightCmd->execCmd();
		}
		if (is_object($speedCmd)) {
			$settings['speed'] = (float) $speedCmd->execCmd();
		}
		return $settings;
	}

	/* Envoi des notifications */
	public function sendNotification($event) {

        // Information
        $name = $this->getName();
		$lastEvent = $event;
		$lastUpdate = $this->getCmd('info','lastUpdate')->execCmd();
		$workMode = $this->getCmd('info','work_mode')->execCmd();
        		        
        // Cmd
        $cmdNotifications = $this->getConfiguration('cmdNotifications');
        
		if ( $cmdNotifications != null) {
            $title = 'Mammotion';
            //$message = $name . ' - ' . $lastEvent . ' - (Mode ' . $workMode . ') - '. $lastUpdate;
			$message = $name . ' - ' . $lastEvent . ' - '. $lastUpdate;
            $data = [
                'title' => $title,
                'message' => $message,
            ];
            
            if (strpos($cmdNotifications, '&&') !== false) {
                $cmds = explode(' && ', $cmdNotifications);
                $cmds = array_map('trim', $cmds);                           // On supprime les espaces autour de chaque valeur
                foreach ($cmds as $cmd) {
                    cmd::byString($cmd)->execCmd($data);
                    log::add('mammotion', 'debug', 'Send notification - cmdId : '.$cmd.' - title : '.$title. ' - message : '.$message );
                }
            }
            else {
                cmd::byString($cmdNotifications)->execCmd($data);
                log::add('mammotion', 'debug', 'Send notification - cmdId : '.$cmdNotifications.' - title : '.$title. ' - message : '.$message );
            }
        }        
    }
}


class mammotionCmd extends cmd {

    /*     * *************************Attributs****************************** */


    /*     * ***********************Methode static*************************** */


    /*     * *********************Methode d'instance************************* */

	// Exécution d'une commande
	public function execute($_options = array())
	{
		$eqLogic = $this->getEqLogic();
		$device = $eqLogic->getLogicalId();

		$taskCommands = array('pause', 'resume', 'cancel', 'dock', 'leave_dock');

		$action = $this->getLogicalId();
		log::add('mammotion', 'debug', '┌─Command execution : '.$action.' ('.$eqLogic->getName().')');

		switch ($action) {

			case 'refresh':
				mammotion::sendToDaemon('refresh', $device);
				break;

			case 'start':
				mammotion::sendToDaemon('start', $device, $eqLogic->getStartSettings());
				break;

			case 'start_zone':
				mammotion::sendToDaemon('start', $device, array_merge(array('hash' => $_options['select']), $eqLogic->getStartSettings()));
				break;

			case 'start_activity':
				mammotion::sendToDaemon('start_plan', $device, array('plan_id' => $_options['select']));
				break;

			case 'set_blade_height':
				$eqLogic->checkAndUpdateCmd('blade_height_target', $_options['slider']);
				mammotion::sendToDaemon('command', $device, array('key' => 'set_blade_height', 'kwargs' => array('height' => (int) $_options['slider'])));
				break;

			case 'set_speed':
				$eqLogic->checkAndUpdateCmd('speed_target', $_options['slider']);
				mammotion::sendToDaemon('command', $device, array('key' => 'set_speed', 'kwargs' => array('speed' => (float) $_options['slider'])));
				break;

			case 'set_clean_mode':
				mammotion::sendToDaemon('command', $device, array('key' => 'clean_mode', 'kwargs' => array('work_mode' => (int) $_options['select'])));
				break;

			case 'set_floor_speed':
				mammotion::sendToDaemon('command', $device, array('key' => 'set_floor_speed', 'kwargs' => array('speed' => $_options['slider'] / 100)));
				break;

			default:
				if (!in_array($action, $taskCommands)) {
					throw new Exception(__('Commande inconnue : ', __FILE__).$action);
				}
				mammotion::sendToDaemon('command', $device, array('key' => $action));
				break;
		}

		log::add('mammotion', 'debug', '└─End of command execution');
	}

    /*     * **********************Getteur Setteur*************************** */

}

?>
