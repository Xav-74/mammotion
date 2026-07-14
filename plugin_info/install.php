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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function mammotion_install() {

    // Création du cron avec valeur par défaut
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

    message::add('mammotion', 'Merci pour l\'installation du plugin Mammotion. Lisez bien la documentation avant utilisation et n\'hésitez pas à laisser un avis sur le Market Jeedom !');

}

function mammotion_update() {

    // Mise à jour du cron
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
        log::add('mammotion', 'debug', 'Update cron pull');
    }

    // Mise à jour de l'ensemble des commandes pour chaque équipement
    log::add('mammotion', 'debug', 'Update mammotion plugin commands');
    foreach (eqLogic::byType('mammotion') as $eqLogic) {
        $eqLogic->save();
        log::add('mammotion', 'debug', 'Updated commands for equipment '. $eqLogic->getHumanName());
    }

	message::add('mammotion', 'Merci pour la mise à jour du plugin Mammotion. Consultez les notes de version avant utilisation et n\'hésitez pas à laisser un avis sur le Market Jeedom !');

 }

function mammotion_remove() {

    // Suppression du cron
    $cron = cron::byClassAndFunction('mammotion', 'pull');
    if (is_object($cron)) {
        $cron->remove();
        log::add('mammotion', 'debug', 'Remove cron pull');
    }

    message::add('mammotion', 'Le plugin Mammotion a été correctement désinstallé. N\'hésitez pas à laisser un avis sur le Market Jeedom !');

}

?>
