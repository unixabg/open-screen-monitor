<?php
namespace OSM\Route\Extension;

class Filter extends \OSM\Tools\Route {
	public $renderRaw = true;

	private function testURL($data, $value){
		if (substr($value,0,7) == 'simple:') {
			$value = substr($value,7);

			$value = str_replace('.','\.',$value);
			$value = '/^https?:\/\/([a-z0-9\-\.]*\.)?'.$value.'\//';
			return preg_match($value,$data['url']);
		} elseif (substr($value,0,6) == 'regex:') {
			$value = substr($value,6);
			$value = str_replace('/','\/',$value);
			$value = '/'.$value.'/';
			return preg_match($value,$data['url']);
		} else {
			return (stripos($data['url'],$value) === 0);
		}
	}

	private function testString($data, $value){
		if (substr($value,0,6) == 'regex:') {
			$value = substr($value,6);
			$value = str_replace('/','\/',$value);
			$value = '/'.$value.'/';
			return preg_match($value,$data);
		} else {
			return ($data == $value);
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

		//get group settings
		//this may need to be switched to a cache file like entries below
		$group = \OSM\Tools\Config::getGroup($data['filterID']);

		//first check whitelist
		$filter = \OSM\Tools\Config::getFilter();
		foreach($filter['entries'] as $entry){
			if (!in_array($entry['action'],['ALLOW','BLOCK','BLOCKPAGE','BLOCKNOTIFY'])){continue;}

			if ($entry['resourceType'] == '') {
				if (!in_array($data['type'],$defaultTypes)){continue;}
			} else {
				if ($entry['resourceType'] != $data['type']){continue;}
			}

			if ($entry['username'] != '' && !$this->testString($data['email'], $entry['username'])){continue;}

			if ($entry['initiator'] != '' && !$this->testString($data['initiator'], $entry['initiator'])){continue;}

			if ($entry['appName'] != ''){
				//app list is only for defaultdeny
				if ($group['filtermode'] == 'defaultallow'){continue;}
				//app isn't enabled for group
				if (!in_array($data['filterID'], ($filter['apps'][$entry['appName']]??[]))){continue;}
			}

			if ($this->testURL($data,$entry['url'])){
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
				$list = $group['filterlist-'.$group['filtermode']] ?? '';
				$list = explode("\n",$list);
				$found = false;
				foreach($list as $url){
					if ($url != '' && $this->testURL($data,$url)){$found = true;break;}
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

		//send it back
		$this->sendAndClose(json_encode($toReturn));

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
		foreach($filter['entries'] as $entry){
			if (!in_array($entry['action'],['TRIGGER','TRIGGER_EXEMPT'])){continue;}

			if ($entry['resourceType'] == '') {
				if (!in_array($data['type'],$defaultTypes)){continue;}
			} else {
				if ($entry['resourceType'] != $data['type']){continue;}
			}

			if (!in_array($entry['username'],['',$data['email']])){continue;}

			if (!in_array($entry['initiator'],['',$entry['initiator']])){continue;}

			if ($this->testURL($data,$entry['url'])){
				// Log action to log file
				\OSM\Tools\DB::insert('tbl_filter_log',[
					'date' => date('Y-m-d',$now),
					'time' => date('H:i:s',$now),
					'ip' => $_SERVER['REMOTE_ADDR'],
					'username' => $data['email'],
					'deviceid' => $data['deviceID'],
					'action' => $entry['action'],
					'type' => 'trigger word: '.$entry['url'],
					'url' => $data['url'],
				]);

				if ($entry['action'] == 'TRIGGER'){
					$email = $data['appName'];

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
		}
	}
}
