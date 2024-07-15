<?php
namespace OSM\Route\Extension;

class Filter extends \OSM\Tools\Route {
	private function testURL($url, $value){
		if (substr($value,0,6) == 'regex:') {
			$value = substr($value,6);
			$value = str_replace('/','\/',$value);
			$value = '/'.$value.'/';
			return preg_match($value,$url);
		} else {
			return (stripos($url,$value) !== false);
		}
	}

	public function action(){
		//in case the http timeout kills the connection, still log it
		ignore_user_abort(1);

		$now = time();
		$dataDir = $GLOBALS['dataDir'];

		//allow custom hooking here
		//make sure to set restrictive permissions on this file
		if (file_exists($dataDir.'/custom/filter-prepend.php')){
			include($dataDir.'/custom/filter-prepend.php');
		}

		//validate data
		$post = json_decode($_POST['data'] ?? '[]',true);
		$data = [];
		$fields = ['email','deviceID','sessionID','type','url','initiator','filterID'];
		foreach($fields as $field){
			$data[$field] = $post[$field] ?? '';
		}

		//remove later
		if ($data['email'] == ''){$data['email'] = ($post['username']??'unknown').'@'.($post['domain']??'unknown');}


		//validate sessionID
		$data['sessionID'] = preg_replace('/[^0-9a-z\-]/','',$data['sessionID']);

		if ($data['url'] == ''){
			http_response_code(404);
			die();
		}


		//determine action
		$toReturn = [];
		$action = '';
		$parameters = '';
		$search = '';

		$defaultTypes = \OSM\Tools\Config::get('filterviaserverDefaultFilterTypes');

		//first check whitelist
		$entries = \OSM\Tools\DB::selectRaw(
			'select a.action, a.url, a.resourceType'.
			' from tbl_filter_entry a'.
			' left join tbl_filter_entry_group b on a.appName = b.appName'.
			' where a.enabled = 1 and a.action in ("ALLOW","BLOCK","BLOCKPAGE","BLOCKNOTIFY")'.
				' and (a.resourceType = :custom0 or a.resourceType = "")'.
				' and (:custom1 like a.username or a.username = "")'.
				' and (:custom2 like a.initiator or a.initiator = "")'.
				' and (a.appName = "" || b.filterID = :custom3)'.
			'order by a.priority desc, a.appName asc, a.id asc',
			[
				':custom0' => $data['type'],
				':custom1' => $data['email'],
				':custom2' => $data['initiator'],
				':custom3' => $data['filterID'],
			]
		);
		foreach($entries as $entry){
			if ($entry['resourceType'] == '' && !in_array($data['type'],$defaultTypes)){continue;}

			if ($this->testURL($data['url'],$entry['url'])){
				$action = $entry['action'];
				$search = $entry['url'];
				break;
			}
		}

		//then check for default
		if ($action == ''){
			if ($data['filterID'] == '' || $data['filterID'] == '__OSMDEFAULT__'){
				$action = 'ALLOW';
			} else {
				$group = \OSM\Tools\Config::getGroup($data['filterID']);
				$list = $group['filterlist-'.$group['filtermode']] ?? '';
				$list = explode("\n",$list);
				$found = false;
				foreach($list as $url){
					if ($url != '' && $this->testURL($data['url'],$url)){$found = true;break;}
				}
				if ($group['filtermode'] == 'defaultdeny'){
					$action = ($found  ? 'ALLOW' : 'BLOCKPAGE');
				} else {
					$action = ($found ? 'BLOCKPAGE' : 'ALLOW');
				}
			}
		}

		//handle action
		if ($action == 'ALLOW'){
			//do nothing
		} elseif ($action == 'BLOCK') {
			$toReturn['commands'][] = ['action'=>'BLOCK'];
		} elseif ($action == 'BLOCKPAGE'){
			$toReturn['commands'][] = [
				'action'=>'BLOCKPAGE',
				'data'=>$this->urlRoot().'?block&data='.urlencode(base64_encode(gzcompress(json_encode([
					'url' => $data['url'],
					'type' => $data['type'],
					'username' => $data['email'],
					'search' => $search,
					'deviceID' => $data['deviceID'],
					'filterID' => $data['filterID'],
				]),9))),
			];
			$toReturn['return']['cancel'] = true;
		} elseif ($action == 'BLOCKNOTIFY') {
			//show notification instead
			$toReturn['commands'][] = ['action'=>'BLOCK'];
			$toReturn['commands'][] = ['action'=>'NOTIFY','data'=>[
				'requireInteraction'=>false,
				'type'=>'basic',
				'iconUrl'=>'icon.png',
				'title'=>'Blocked Tab',
				'message'=>'Tab was blocked with the url '.$data['url'].' by OSM filter.',
			]];
		}


		//log it
		\OSM\Tools\DB::insert('tbl_filter_log',[
			'date' => date('Y-m-d',$now),
			'time' => date('H:i:s',$now),
			'ip' => $_SERVER['REMOTE_ADDR'],
			'username' => $data['email'],
			'deviceid' => $data['deviceID'],
			'action' => $action,
			'type' => $data['type'],
			'url' => substr($data['url'],0,2047),
			'initiator' => substr($data['initiator'],0,1023),
		]);

		//look for email triggers
		$defaultTypes = \OSM\Tools\Config::get('filterviaserverDefaultTriggerTypes');
		$entries = \OSM\Tools\DB::select('tbl_filter_entry',[
			'where' => 'enabled = 1 and action in ("TRIGGER")'.
				' and (resourceType = :custom0 or resourceType = "")'.
				' and (:custom1 like username or username = "")'.
				' and (:custom2 like initiator or initiator = "")'.
				' and (appName = "")',
			'bindings' => [
				':custom0' => $data['type'],
				':custom1' => $data['email'],
				':custom2' => $data['initiator'],
			],
			'order' => 'priority desc, id asc',
		]);
		foreach($entries as $entry){
			if ($this->testURL($data['url'],$entry['url'])){
				$email = $data['action'];

				// First test if trigger_exempt was passed
				if ($entry['resourceType'] == 'trigger_exempt'){
					// Log exempt action to log file
					\OSM\Tools\DB::insert('tbl_filter_log',[
						'date' => date('Y-m-d',$now),
						'time' => date('H:i:s',$now),
						'ip' => $_SERVER['REMOTE_ADDR'],
						'username' => $data['email'],
						'deviceid' => $data['deviceID'],
						'action' => 'TRIGGER_EXEMPTION',
						'type' => 'trigger word: '.$entry['url'],
						'url' => $data['url'],
					]);
					break;
				}

				if ($entry['resourceType'] == '' && !in_array($data['type'],$defaultTypes)){continue;}

				// Log action to log file
				\OSM\Tools\DB::insert('tbl_filter_log',[
					'date' => date('Y-m-d',$now),
					'time' => date('H:i:s',$now),
					'ip' => $_SERVER['REMOTE_ADDR'],
					'username' => $data['email'],
					'deviceid' => $data['deviceID'],
					'action' => 'TRIGGER',
					'type' => 'trigger word: '.$url,
					'url' => $data['url'],
				]);

				$uid = md5(uniqid(time()));
				// header
				$header = "From: Open Screen Monitor <".$email.">\r\n";
				$header .= "MIME-Version: 1.0\r\n";
				$header .= "Content-Type: multipart/mixed; boundary=\"".$uid."\"\r\n\r\n";
				// message & attachment
				$raw = "--".$uid."\r\n";
				$raw .= "Content-type:text/plain; charset=iso-8859-1\r\n";
				$raw .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
				$raw .= "User: ".$data['email']
					."\nDevice: ".$niceDeviceName
					."\nDevice Address: ".str_replace(".",'-',$_SERVER['REMOTE_ADDR'])
					."\nTriggered on keyword or url of: $url"
					."\n".str_replace("\t","\n",$logentry)
					."\r\n\r\n";
				$screenshot = \OSM\Tools\TempDB::get('screenshot/'.$sessionID);;
				if ($screenshot != '') {
					$raw .= "--".$uid."\r\n";
					$raw .= "Content-Type: image/jpeg; name=\"screenshot.jpg\"\r\n";
					$raw .= "Content-Transfer-Encoding: base64\r\n";
					$raw .= "Content-Disposition: attachment; filename=\"screenshot.jpg\"\r\n\r\n";
					$raw .= chunk_split(base64_encode($screenshot))."\r\n\r\n";
				}
				$raw .= "--".$uid."--";
				mail($email, 'OSM Trigger Alert', $raw, $header);
			}
		}

		//send it back
		die(json_encode($toReturn));
	}
}
