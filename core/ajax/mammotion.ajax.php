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

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect()) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

	if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

	if (init('action') == 'synchronize') {
		$result = mammotion::synchronize();
		ajax::success($result);
	}

	if (init('action') == 'scheduleCron') {
		$result = mammotion::scheduleCron(init('cronJob'));
		ajax::success($result);
	}

    if (init('action') == 'testNotifications') {
		$cmdNotifications = init('cmdNotifications');
        $data = [
            'title' => 'Mammotion',
            'message' => 'Ceci est un test des notifications du plugin Mammotion',
        ];
        if (strpos($cmdNotifications, '&&') !== false) {
            $cmds = explode(' && ', $cmdNotifications);
            $cmds = array_map('trim', $cmds);
            foreach ($cmds as $cmd) {
                cmd::byString($cmd)->execCmd($data);
                log::add('mammotion', 'debug', 'Test notification - cmdId : '.$cmd.' - title : Mammotion - message : Ceci est un test des notifications du plugin Mammotion');
            }
        }
        else {
            cmd::byString($cmdNotifications)->execCmd($data);
            log::add('parcelTracking', 'debug', 'Test notification - cmdId : '.$cmdNotifications.' - title : Mammotion - message : Ceci est un test des notifications du plugin Mammotion');
        }
		ajax::success();
	}

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
    /*     * *********Catch exeption*************** */
}

catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}

?>
