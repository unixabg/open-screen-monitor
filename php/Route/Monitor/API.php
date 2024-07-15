<?php
namespace OSM\Route\Monitor;

class API extends \OSM\Tools\Route {
	private function validate($sessionID){
		//Validate actions that require clientID and sessionID
		$valid = true;
		if ($sessionID == '') {$valid = false;}
		if ($sessionID != preg_replace('/[^0-9a-z\-]/','',$sessionID)){$valid = false;}
		if (!$_SESSION['admin']){
			//validate that sessionID belongs to clientID
			$deviceID = \OSM\Tools\TempDB::get('deviceID/'.$sessionID);
			$email = \OSM\Tools\TempDB::get('email/'.$sessionID);

			if (
				!isset($_SESSION['clients']['devices'][$deviceID]) &&
				!isset($_SESSION['clients']['users'][$email])
			){$valid = false;}
		}

		if (!$valid){
			http_response_code(401);
			die('Invalid Request: Access Denied');
		}
	}

	function sendCommand($sessionID, $cmd){
		$sessionID = preg_replace('/[^0-9a-z\-]/','',$sessionID);

		static $now;
		$now = is_null($now) ? time() : $now;
		static $counter;
		$counter = is_null($counter) ? 0 : $counter + 1;

		//commands expire after 5 minutes
		\OSM\Tools\TempDB::set('command/'.$sessionID.'/'.$now.'cmd'.$counter, json_encode($cmd), 300);
	}


	public function getRequest(){
		$action = $_GET['action'] ?? '';
		$sessionID = $_GET['sessionID'] ?? '';
		$groupID = $_GET['groupID'] ?? '';

		if ($action == 'online'){
			//if $groupID ....
			if (!isset($_SESSION['groups'][$groupID])){
				http_response_code(400);
				die('Invalid Request: Group Access Denied');
			}

			$clients = $_SESSION['groups'][$groupID]['clients'] ?? [];
			$groupType = $_SESSION['groups'][$groupID]['type'] ?? 'unknowntype';
			$showInactive = true;

			if ($groupID == 'osmshowall'){
				$clients = [];
				$groupType = 'device';
				$showInactive = false;

				$rows = \OSM\Tools\TempDB::scan('ping-device/*/*');
				foreach($rows as $clientID => $empty){
					$clientID = explode('/',$clientID);
					if ($clientID = ($clientID[1] ?? false)){
						$clientID = hex2bin($clientID);
						$clients[$clientID] = $_SESSION['niceNames'][$clientID] ?? $clientID;
					}
				}
			}

			$data = [];
			$data['inactive'] = '';
			$data['sessions'] = [];
			foreach ($clients as $clientID => $clientName) {
				$scanRoot = 'ping-'.$groupType.'/'.bin2hex($clientID).'/';
				$rows = \OSM\Tools\TempDB::scan($scanRoot.'*');
				if (count($rows) == 0){
					if ($showInactive){
						$data['inactive'] .= '<div class="dev">'.htmlentities($clientName).'</div>';
					}
				} else {
					foreach($rows as $key => $empty){
						$sessionID = str_replace($scanRoot,'',$key);
						$email = \OSM\Tools\TempDB::get('email/'.$sessionID);
						if($email == ''){$email = '-- not signed in --';}

						$data['sessions'][$sessionID] = [];
						$data['sessions'][$sessionID]['clientID'] = $clientID;
						$data['sessions'][$sessionID]['title'] = $email.'<br />('.$clientName.')';
						$data['sessions'][$sessionID]['locked'] = \OSM\Tools\TempDB::get('lock/'.$sessionID) != '';
						$data['sessions'][$sessionID]['groupID'] = \OSM\Tools\TempDB::get('groupID/'.$sessionID);
					}
				}
			}

			uasort($data['sessions'], function($a,$b){
				return strcmp($a['title'],$b['title']);
			});

			header('Content-Type: application/json');
			die(json_encode($data));
		}

		$this->validate($sessionID);

		if ($action == 'getImage'){
			$img = \OSM\Tools\TempDB::get('screenshot/'.$sessionID);
			if ($img != ''){
				header('Content-Type: image/jpeg');
				header('Content-Length: '.strlen($img));
				echo $img;
			} else {
				header('Location: unavailable.jpg');
			}
			die();
		}


		if ($action == 'info'){
			$html = '';
			$html .= '<b>IP: '.htmlentities(\OSM\Tools\TempDB::get('ip/'.$sessionID)).'</b>';

			$tabs = \OSM\Tools\TempDB::get('tabs/'.$sessionID);
			$tabs = json_decode($tabs,true);

			if (is_array($tabs)){
				foreach($tabs as $tab){
					if (!isset($tab['id'])){continue;}
					$tabid = $tab['id'];
					$title = $tab['title'] ?? '';
					$url = $tab['url'] ?? '';

					$html .= '<br /><br /><a href="#" onmousedown="javscript:window.osm.actions.closeTab('.htmlentities(json_encode(['sessionID'=>$sessionID,'tabid'=>$tabid])).');return false;">'.
							'<span class="material-symbols-outlined" title="Close this tab.">cancel</span>'.
						'</a> <b>'.
						htmlentities($title).
						'</b><br />'.substr(htmlentities($url),0,500);
				}
			}
			die($html);
		}
	}


	public function postRequest(){
		$now = time();
		$action = $_POST['action'] ?? '';
		$sessionID = $_POST['sessionID'] ?? '';
		$groupID = $_POST['groupID'] ?? '';
		$logData = ['sessionID'=>$sessionID];

		//Validate Action
		if ($action == ''){
			http_response_code(400);
			die('Invalid Request');
		}

		if ($action == 'takeOverClass'){
			//if $groupID ....
			if (!isset($_SESSION['groups'][$groupID])){
				http_response_code(400);
				die('Invalid Request: Group Access Denied');
			}

			$groupType = $_SESSION['groups'][$groupID]['type'] ?? 'unknowntype';
			if ($groupType != 'user'){
				http_response_code(400);
				die('Invalid Request: Not User Group');
			}

			$clients = $_SESSION['groups'][$groupID]['clients'] ?? [];
			foreach($clients as $clientID => $name){
				$scanRoot = 'ping-'.$groupType.'/'.bin2hex($clientID).'/';
				$rows = \OSM\Tools\TempDB::scan($scanRoot.'*');
				foreach($rows as $key => $empty){
					$sessionID = str_replace($scanRoot,'',$key);
					\OSM\Tools\TempDB::set('groupID/'.$sessionID, $groupID, \OSM\Tools\Config::get('userGroupTimeout'));
				}
			}
			\OSM\Tools\Log::add('takeOverClass',$groupID,$clients);
			die();
		}

		if ($action == 'filter'){
			//authtenticate this request
			$groupID = $_POST['groupID'] ?? '';
			if (!isset($_SESSION['groups'][$groupID])){http_repsonse_code(400);die('Invalid Request');}

			$filtermode = $_POST['filtermode'] ?? '';
			if (!in_array($filtermode,['defaultallow','defaultdeny'])){http_response_code(400);die('Invalid Request');}

			$defaultdeny = $_POST['filterlist-defaultdeny'] ?? '';
			$defaultallow = $_POST['filterlist-defaultallow'] ?? '';
			//only allow printable characters and new lines
			$defaultdeny = preg_replace('/[\x00-\x09\x20\x0B-\x1F\x7F-\xFF]/', '', $defaultdeny);
			$defaultallow= preg_replace('/[\x00-\x09\x20\x0B-\x1F\x7F-\xFF]/', '', $defaultallow);
			//let us do a second pass to drop empty lines and correctly format
			$defaultdeny = trim(preg_replace('/\n+/', "\n", $defaultdeny));
			$defaultallow = trim(preg_replace('/\n+/', "\n", $defaultallow));


			\OSM\Tools\DB::beginTransaction();

			\OSM\Tools\DB::delete('tbl_filter_entry_group',['fields'=>['filterID'=>$groupID]]);
			foreach(($_POST['apps'] ?? []) as $app){
				\OSM\Tools\DB::insert('tbl_filter_entry_group',['filterID'=>$groupID,'appName'=>$app]);
			}

			\OSM\Tools\DB::updateInsert('tbl_group_config',['groupid'=>$groupID,'name'=>'filtermode'],['value'=>$filtermode]);
			\OSM\Tools\DB::updateInsert('tbl_group_config',['groupid'=>$groupID,'name'=>'filterlist-defaultdeny'],['value'=>$defaultdeny]);
			\OSM\Tools\DB::updateInsert('tbl_group_config',['groupid'=>$groupID,'name'=>'filterlist-defaultallow'],['value'=>$defaultallow]);
			\OSM\Tools\DB::updateInsert('tbl_group_config',['groupid'=>$groupID,'name'=>'lastUpdated'],['value'=>time()]);

			\OSM\Tools\Log::add('monitor.filter',$groupID,[
				'filtermode'=>$filtermode,
				'defaultdeny'=>$defaultdeny,
				'defaultallow'=>$defaultallow,
			]);

			\OSM\Tools\DB::commit();

			die("<h1>Filter updated</h1><script type=\"text/javascript\">setTimeout(function(){window.close();},1500);</script>");
		}

		//Validate actions that require clientID and sessionID
		$this->validate($sessionID);
		$logTarget = \OSM\Tools\TempDB::get('email/'.$sessionID).' <=> '.\OSM\Tools\TempDB::get('deviceID/'.$sessionID);

		if ($action == 'tts'){
			if (isset($_POST['tts'])) {
				$this->sendCommand($sessionID,[
					'action'=>'ttsSpeak',
					'data'=>$_POST['tts'],
				]);
				$logData['text'] = $_POST['tts'];
				\OSM\Tools\Log::add('monitor.tts',$logTarget,$logData);
			}
			die();
		}

		if (in_array($action,['openurl','closetab','closeAllTabs'])){
			$tabs = \OSM\Tools\TempDB::get('tabs/'.$sessionID);
			$tabs = json_decode($tabs,true);
			if ($action == 'openurl'){
				if (isset($_POST['url']) && filter_var($_POST['url'],\FILTER_VALIDATE_URL)) {
					$this->sendCommand($sessionID,[
						'action'=>(count($tabs) > 0 ? 'tabsCreate' : 'windowsCreate'),
						'data'=>['url'=>$_POST['url']],
					]);
					$this->sendCommand($sessionID,[
						'action'=>'ttsSpeak',
						'data'=>'O S M is opening a new tab',
					]);
					$logData['url'] = $_POST['url'];
					\OSM\Tools\Log::add('monitor.openurl',$logTarget,$logData);
				}
				die();
			}
			if ($action == 'closetab' || $action == 'closeAllTabs') {
				foreach ($tabs as $tab) {
					$logData['title'] = $tab['title'];
					$logData['url'] = $tab['url'];

					if ($action == 'closetab' && isset($_POST['tabid']) && $tab['id'] == $_POST['tabid']){
						$this->sendCommand($sessionID,[
							'action'=>'tabsRemove',
							'tabId'=>intval($tab['id']),
						]);
						\OSM\Tools\Log::add('monitor.closetab',$logTarget,$logData);
						break;
					}
					if ($action == 'closeAllTabs'){
						$this->sendCommand($sessionID,[
							'action'=>'tabsRemove',
							'tabId'=>intval($tab['id']),
						]);
						\OSM\Tools\Log::add('monitor.closeAllTabs',$logTarget,$logData);
					}
				}
				die();
			}
		}


		if ($action == 'lock'){
			\OSM\Tools\TempDB::set('lock/'.$sessionID, json_encode($now), \OSM\Tools\Config::get('lockTimeout'));
			\OSM\Tools\Log::add('monitor.lock',$logTarget,$logData);
			die();
		}

		if ($action == 'unlock'){
			\OSM\Tools\TempDB::del('lock/'.$sessionID);
			\OSM\Tools\Log::add('monitor.unlock',$logTarget,$logData);
			die();
		}

		if ($action == 'sendmessage'){
			if (isset($_POST['message'])) {
				$this->sendCommand($sessionID,[
					'action'=>'sendNotification',
					'data'=>[
						'requireInteraction'=>true,
						'type'=>'basic',
						'iconUrl'=>'icon.png',
						'title'=> $_SESSION['name'].' says ...',
						'message'=> $_POST['message'],
					],
				]);
				$logData['message'] = $_POST['message'];
				\OSM\Tools\Log::add('monitor.message',$logTarget,$logData);
			}
			die();
		}

		if ($action == 'screenshot'){
			$screenshot = \OSM\Tools\TempDB::get('screenshot/'.$sessionID);
			if ($screenshot != ''){
				\OSM\Tools\Log::add('monitor.screenshot',$logTarget,$logData);

				$text = "Screenshot: ".date("Y-m-d h:i a")."\r\n\r\n";
				$username = \OSM\Tools\TempDB::get('username/'.$sessionID);
				$text .= "Username: ".$username."\r\n";
				$tabs = \OSM\Tools\TempDB::get('tabs/'.$sessionID);
				$tabs = json_decode($tabs,true);
				foreach ($tabs as $tab){
					$text .= "Open Tab: <".$tab['title']."> ".$tab['url']."\r\n";
				}

				$uid = md5(uniqid(time()));

				// header
				$header = "From: Open Screen Monitor <".$_SESSION['email'].">\r\n";
				$header .= "MIME-Version: 1.0\r\n";
				$header .= "Content-Type: multipart/mixed; boundary=\"".$uid."\"\r\n\r\n";

				// message & attachment
				$raw = "--".$uid."\r\n";
				$raw .= "Content-type:text/plain; charset=iso-8859-1\r\n";
				$raw .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
				$raw .= "$text\r\n\r\n";
				$raw .= "--".$uid."\r\n";
				$raw .= "Content-Type: image/jpeg; name=\"screenshot.jpg\"\r\n";
				$raw .= "Content-Transfer-Encoding: base64\r\n";
				$raw .= "Content-Disposition: attachment; filename=\"screenshot.jpg\"\r\n\r\n";
				$raw .= chunk_split(base64_encode($screenshot))."\r\n\r\n";
				$raw .= "--".$uid."--";

				echo mail($_SESSION['email'], "OSM Screenshot", $raw, $header) ? "Successfully Sent Screenshot To ".$_SESSION['email'] : "Error Sending Screenshot";
			} else {
				echo "No Screenshot to send";
			}
			die();
		}
	}



	public function action(){
		$this->requireLogin(false);

		if (isset($_POST['action'])){
			$this->postRequest();
		} elseif (isset($_GET['action'])){
			$this->getRequest();
		}

		die();
	}
}
