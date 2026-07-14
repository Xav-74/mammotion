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


$("#table_cmd").sortable({axis: "y", cursor: "move", items: ".cmd", placeholder: "ui-state-highlight", tolerance: "intersect", forcePlaceholderSize: true});


/*
 * Fonction pour l'ajout de commande, appellé automatiquement par plugin.template
 */

function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }

	var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
	tr += '<td class="hidden-xs" style="width:5%">';
	tr += '<span class="cmdAttr" data-l1key="id"></span>';
	tr += '</td>';
	tr += '<td style="width:20%">';
	tr += '<input class="cmdAttr form-control input-sm" style="width:80%" data-l1key="name" placeholder="{{Nom de la commande}}">';
	tr += '</td>';
	tr += '<td style="width:10%; padding:5px 0px">';
	tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
	tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
	tr += '</td>';
	tr += '<td style="width:20%">';
	tr += '<input class="cmdAttr form-control input-sm" style="width:80%" data-l1key="logicalId" readonly=true>';
	tr += '</td>';
	tr += '<td style="width:10%">';
	if (init(_cmd.type) == 'info') {
		tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label>';
		tr += '</br><label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label>';
		if (init(_cmd.subType) == 'binary') {
			tr += '</br><label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label>';
		}
	}
	if (init(_cmd.type) == 'action') {
		tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label>';
	}
	tr += '</td>';
	tr += '<td style="width:25%">';
	tr += '<span class="cmdAttr" data-l1key="htmlstate" placeholder="{{Valeur}}">';
	tr += '</td>';
	tr += '<td style="width:10%">';
	if (is_numeric(_cmd.id)) {
		tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fa fa-cogs"></i></a> ';
		tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fa fa-rss"></i> {{Tester}}</a>';
	}
	tr += '<i class="fa fa-minus-circle pull-right cmdAction cursor" data-action="remove" style="margin-top:4px;"></i>';
	tr += '</td>';
	tr += '</tr>';

	$('#table_cmd tbody').append(tr);
	$('#table_cmd tbody tr:last').setValues(_cmd, '.cmdAttr');
	if (isset(_cmd.type)) {
		$('#table_cmd tbody tr:last .cmdAttr[data-l1key=type]').value(init(_cmd.type));
	}
	jeedom.cmd.changeType($('#table_cmd tbody tr:last'), init(_cmd.subType));
};


function printEqLogic(_eqLogic) {

	var image = '';
	var device = ($('.eqLogicAttr[data-l2key=device_name]').value() || '').toLowerCase();
	['yuka', 'luba', 'spino'].forEach(function (family) {
		if (!image && device.indexOf(family) !== -1) {
			image = 'plugins/mammotion/data/mammotion_' + family + '.png';
		}
	});
 
	var img = document.getElementById('robot_img');
	img.onerror = function() {
		this.onerror = null;
		this.src = 'plugins/mammotion/data/image_robot_not_found.png';
	};
	img.src = image || 'plugins/mammotion/data/image_robot_not_found.png';
};


document.getElementById('bt_selectCmdNotifications').addEventListener('click', function() {
    jeedom.cmd.getSelectModal({ cmd: { type: 'action', subType: 'message' } }, function(result) {
        document.querySelector('.eqLogicAttr[data-l1key=configuration][data-l2key=cmdNotifications]').value = document.querySelector('.eqLogicAttr[data-l1key=configuration][data-l2key=cmdNotifications]').value + result.human;
    });
});


$('#bt_synchronize').on('click', function () {

	$('#div_alert').showAlert({message: '{{Synchronisation en cours - Les équipements seront créés ou mis à jour dans quelques instants}}', level: 'warning'});
	$.ajax({
		type: "POST",
		url: "plugins/mammotion/core/ajax/mammotion.ajax.php",
		data: {
			action: "synchronize",
		},
		dataType: 'json',
			error: function (request, status, error) {
			handleAjaxError(request, status, error);
			},
		success: function (data) {

			if (data.state != 'ok') {
				$('#div_alert').showAlert({message: '{{Erreur lors de la synchronisation - Vérifiez que le démon est bien démarré}}', level: 'danger'});
				return;
			}
			else  {
				$('#div_alert').showAlert({message: '{{Demande de synchronisation envoyée au démon - Rechargez la page dans quelques instants}}', level: 'success'});
			}
		}
	});
});


$('#bt_testNotifications').on('click', function () {

    $('#div_alert').showAlert({message: '{{Envoi de la notification}}', level: 'warning'});	
    $.ajax({
        type: "POST",
        url: "plugins/mammotion/core/ajax/mammotion.ajax.php",
        data: {
            action: "testNotifications",
            cmdNotifications: $('#cmdNotifications').value(),
            },
        dataType: 'json',
            error: function (request, status, error) {
            handleAjaxError(request, status, error);
            },
        success: function (data) { 			

            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: '{{Erreur lors de l\'envoi de la notification}}', level: 'danger'});
                return;
            }
            else  {
                $('#div_alert').showAlert({message: '{{Notification envoyée avec succès}}', level: 'success'});
            }
        }
    });
});


$('.eqLogicAction[data-action=createCommunityPost]').on('click', function (event) {
    
	jeedom.plugin.createCommunityPost({
      type: eqType,
      error: function(error) {
        domUtils.hideLoading()
        jeedomUtils.showAlert({
          message: error.message,
          level: 'danger'
        })
      },
      success: function(data) {
        let element = document.createElement('a');
        element.setAttribute('href', data.url);
        element.setAttribute('target', '_blank');
        element.style.display = 'none';
        document.body.appendChild(element);
        element.click();
        document.body.removeChild(element);
      }
    });
    return;

});
