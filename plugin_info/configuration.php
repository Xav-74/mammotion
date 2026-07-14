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
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>

<form class="form-horizontal">
    <fieldset>

    <legend><i class="fas fa-user"></i> {{Compte Mammotion}}</legend>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{Email}}
            <sup><i class="fas fa-question-circle tooltips" title="{{Renseignez l'adresse email de votre compte Mammotion. Conseil : utilisez un compte secondaire dédié à Jeedom (partage depuis l'application officielle) pour éviter les déconnexions de l'application mobile}}"></i></sup>
        </label>
        <div class="col-sm-4">
            <input id="email" class="configKey form-control" data-l1key="email"/>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{Mot de passe}}
            <sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le mot de passe de votre compte Mammotion}}"></i></sup>
        </label>
        <div class="col-sm-4">
            <div class="input-group">
                <input id="password" class="inputPassword configKey form-control" data-l1key="password"/>
                <span class="input-group-btn">
					<a class="btn btn-default form-control bt_showPass"><i class="fas fa-eye"></i></a>
                </span>
            </div>
        </div>
    </div>
    <br/>

    <legend><i class="fas fa-university"></i> {{Démon}}</legend>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{Port socket interne}}
            <sup><i class="fas fa-question-circle tooltips" title="{{Laissez la valeur par défaut, sauf si demande du développeur}}"></i></sup>
        </label>
        <div class="col-sm-4">
            <input id="socketPort" class="configKey form-control" data-l1key="socketPort" placeholder="44090" />
        </div>
    </div>
    <br/>

    <legend><i class="fas fa-sync-alt"></i> {{Rafraîchissement forcé}}</legend>

    <div class="form-group pull_class">
        <label class="col-sm-2 control-label" >{{Cron personnalisé}}
            <sup><i class="fas fa-question-circle tooltips" title="{{Fréquence de rafraîchissement forcé des robots lorsqu'ils sont en veille. Par défaut : toutes les 15 minutes.<br/>Les données remontent automatiquement en temps réel via MQTT lorsque le robot est actif}}"></i></sup>
        </label>
        <div class="col-sm-4">
		    <div class="input-group">
                <input id="cronJob" class="form-control configKey" data-l1key="cronJob" placeholder="*/15 * * * *"/>
                <span class="input-group-btn">
                    <a class="btn btn-primary jeeHelper" data-helper="cron" title="{{Assistant cron}}"><i class="fas fa-question-circle"></i></a>
                </span>
            </div>
        </div>
    </div>
    <br/><br/>

    </fieldset>
</form>

<script>

    var CommunityButton = document.querySelector('#createCommunityPost > span');
    if(CommunityButton) {CommunityButton.innerHTML = "{{Community}}";}

    /* Fonction permettant la modification du cron */
    document.getElementById('bt_savePluginConfig').addEventListener('click', function() {
        scheduleCron();
    });

    function scheduleCron()  {

        var cronJob = document.getElementById('cronJob').value;
        if (!cronJob || cronJob.trim() === '') {
            cronJob = '*/15 * * * *';
        }
        const cronRegex = /(^((\*\/)?([0-5]?[0-9])((\,|\-|\/)([0-5]?[0-9]))*|\*) ((\*\/)?((2[0-3]|1[0-9]|[0-9]|00))((\,|\-|\/)(2[0-3]|1[0-9]|[0-9]|00))*|\*) ((\*\/)?([1-9]|[12][0-9]|3[01])((\,|\-|\/)([1-9]|[12][0-9]|3[01]))*|\*) ((\*\/)?([1-9]|1[0-2])((\,|\-|\/)([1-9]|1[0-2]))*|\*|(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|des)) ((\*\/)?[0-6]((\,|\-|\/)[0-6])*|\*|00|(sun|mon|tue|wed|thu|fri|sat))\s*$)|@(annually|yearly|monthly|weekly|daily|hourly|reboot)/;

        if ( cronRegex.test(cronJob) == true ) {
            $.ajax({
                type: "POST",
                url: "plugins/mammotion/core/ajax/mammotion.ajax.php",
                data: {
                    action: "scheduleCron",
                    cronJob: cronJob,
                    },
                dataType: 'json',
                    error: function (request, status, error) {
                    handleAjaxError(request, status, error);
                    },
                success: function (data) {

                    if (data.state != 'ok') {
                        $('#div_alert').showAlert({message: '{{Erreur lors de la mise à jour du cron}}'+' ('+cronJob+')', level: 'danger'});
                        return;
                    }
                    else  {
                        $('#div_alert').showAlert({message: '{{Mise à jour du cron réalisée avec succès}}'+' ('+cronJob+')', level: 'success'});
                    }
                }
            });
        }
        else { $('#div_alert').showAlert({message: '{{Expression cron erronée}}', level: 'danger'}); }
    };

</script>
