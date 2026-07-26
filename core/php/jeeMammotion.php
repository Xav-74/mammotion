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
try
{
    require_once __DIR__ . "/../../../../core/php/core.inc.php";

    if (!jeedom::apiAccess(init('apikey'), 'mammotion')) {
        echo __('Vous n\'etes pas autorisé à effectuer cette action', __FILE__);
        die();
    }

    if (init('test') != '') {
        echo 'OK';
        log::add('mammotion', 'debug', 'Test from daemon');
        die();
    }

    $input = file_get_contents('php://input');
    $message = json_decode($input, true);

    if (!is_array($message)) {
        die();
    }
    
    else {
        if (isset($message['event']) && $message['event'] == 'devices') {
            log::add('mammotion', 'debug', 'Devices list received from daemon : ' . json_encode($message['data']));
            mammotion::syncDevices($message['data']);
        }
        elseif (isset($message['event']) && $message['event'] == 'state') {
            $eqLogic = mammotion::getMammotionEqLogic($message['device']);
            if (is_object($eqLogic)) {
                $eqLogic->handleState($message['data']);
            }
        }
        elseif (isset($message['event']) && $message['event'] == 'areas') {
            $eqLogic = mammotion::getMammotionEqLogic($message['device']);
            if (is_object($eqLogic)) {
                $eqLogic->handleAreas($message['data']);
            }
        }
        elseif (isset($message['event']) && $message['event'] == 'plans') {
            $eqLogic = mammotion::getMammotionEqLogic($message['device']);
            if (is_object($eqLogic)) {
                $eqLogic->handlePlans($message['data']);
            }
        }
    }

    echo 'OK';

} catch (Exception $e)
{
    log::add('mammotion', 'error', displayException($e));
}
