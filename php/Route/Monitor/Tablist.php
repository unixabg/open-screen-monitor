<?php
namespace OSM\Route\Monitor;

class Tablist extends \OSM\Tools\Route {
	public function action(){
		$this->requireLogin();

		$groupID = $_GET['groupID'] ?? '';
		$group = $_SESSION['groups'][$groupID] ?? false;
		if ($group === false){
			http_response_code(403);
			die('Access Denied: Invalid Group');
		}

		$deviceNames = $this->deviceNames();
		if ($groupID == 'osmshowall'){
			$clients = [];
			foreach(\OSM\Tools\TempDB::scan('ping-device/*/*') as $clientID => $empty){
				$clientID = explode('/',$clientID);
				$clientID = $clientID[1] ?? '';
				$clientID = hex2bin($clientID);
				if ($clientID != ''){
					$clients[$clientID] = $clientID;
				}
			}
			$groupType = 'device';
		} else {
			$clients = $group['clients'];
			$groupType = $group['type'];
		}

		$tabs = [];
		foreach($clients as $clientID => $clientName){
			$scanRoot = 'ping-'.$groupType.'/'.bin2hex($clientID).'/';
			foreach(\OSM\Tools\TempDB::scan($scanRoot.'*') as $sessionID => $empty){
				$sessionID = str_replace($scanRoot,'',$sessionID);

				$email = \OSM\Tools\TempDB::get('email/'.$sessionID);
				$deviceID = \OSM\Tools\TempDB::get('deviceID/'.$sessionID);
				$device = $deviceNames[$deviceID] ?? $deviceID;

				$json = \OSM\Tools\TempDB::get('tabs/'.$sessionID);
				if ($json != '' && $json = json_decode($json,true)){
					foreach($json as $tab){
						$tab['sessionID'] = $sessionID;
						$tab['email'] = $email;
						$tab['deviceID'] = $deviceID;
						$tab['device'] = $device;
						$tabs[] = $tab;
					}
				}
			}
		}




		$this->css = '
			.tabList h1 {text-align:center;padding:20px;background-color:#b7b7b7;}
			.tabList table {margin:auto;width:100%;}
			.tabList td {word-break:break-word;max-width:30vw;}
			.closeTab {cursor:pointer;}
		';

		$this->js = '
			window.osm = {
				closeTab: function(span){
					console.log(span.dataset);
					$.post("/?route=Monitor\\\\API",{action:"closetab",sessionID:span.dataset.sessionid,tabid:span.dataset.tabid});
					span.parentElement.parentElement.remove();
				}
			};
		';

		echo '<div class="tabList">';
		echo '<h1>Tab List: '.htmlentities($group['name']).'</h1>';
		echo '<table>';
		echo '<tr>';
			echo '<th><a href="/?route=Monitor\\Tablist&groupID='.htmlentities($groupID).'&sort=email">Email</a></th>';
			echo '<th><a href="/?route=Monitor\\Tablist&groupID='.htmlentities($groupID).'&sort=device">Device</a></th>';
			echo '<th><a href="/?route=Monitor\\Tablist&groupID='.htmlentities($groupID).'&sort=title">Title</a></th>';
			echo '<th><a href="/?route=Monitor\\Tablist&groupID='.htmlentities($groupID).'&sort=url">URL</a></th>';
			echo '<th></th>';
		echo '</tr>';

		$sortKey = 'email';
		if (isset($_GET['sort']) && in_array($_GET['sort'],['email','device','title','url'])){
			$sortKey = $_GET['sort'];
		}

		usort($tabs,function($a,$b) use ($sortKey){
			return strcasecmp($a[$sortKey], $b[$sortKey]);
		});

		foreach($tabs as $tab){
			echo '<tr>';
			echo '<td>'.htmlentities($tab['email']).'</td>';
			echo '<td title="'.htmlentities($tab['deviceID']).'">'.htmlentities($tab['device']).'</td>';
			echo '<td>'.htmlentities($tab['title']).'</td>';
			echo '<td>'.htmlentities($tab['url']).'</td>';
			echo '<td><span class="material-symbols-outlined closeTab" onclick="window.osm.closeTab(this);" data-tabid="'.htmlentities($tab['id']).'" data-sessionid="'.htmlentities($tab['sessionID']).'">cancel</span></td>';
			echo '</tr>';
		}



		echo '</table>';
		echo '</div>';
	}
}
