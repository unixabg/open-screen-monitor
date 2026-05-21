<?php
namespace OSM\Route\Extension;

class Screenscrape extends \OSM\Tools\Route {
	public $renderRaw = true;

	public function action(){
		//in case the http timeout kills the connection, still log it
		ignore_user_abort(1);

		$now = time();

		//validate data
		$post = json_decode($_POST['data'] ?? '[]',true);
		$data = [];
		$fields = ['text','url','sessionID','email','deviceID'];
		foreach($fields as $field){
			$data[$field] = $post[$field] ?? '';
		}
		$data['text'] = strtolower($data['text']);

		//validate sessionID
		$data['sessionID'] = preg_replace('/[^0-9a-z\-]/','',$data['sessionID']);

		if ($data['url'] == '' || $data['text'] == ''){
			http_response_code(404);
			die();
		}

		//determine action
		$toReturn = [];
		$action = '';
		$parameters = '';
		$search = '';
		$word = '';
		$count = 1;

		//go through filter for block actions
		$filter = \OSM\Tools\Config::getFilter();
		foreach($filter['entries'] as $entry){
			if ($entry['resourceType'] != 'SCREENSCRAPE'){continue;}
			if (!in_array($entry['action'],['BLOCK','BLOCKPAGE','BLOCKNOTIFY'])){continue;}

			if ($entry['username'] != '' && !$this->testString($data['email'], $entry['username'])){continue;}

			if (!$this->testURL($data,$entry['url'])){continue;}

			$count = $entry['initiator'];
			$count = explode(',',$count,2);
			$word = strtolower($count[1] ?? '');
			$count = $count[0];

			if ($word != '' && substr_count($data['text'],$word) >= $count){
				$action = $entry['action'];
				$search = $entry['url'];
				break;
			}
		}


		//handle action
		if ($action == 'BLOCK') {
			$toReturn['commands'][] = ['action'=>'BLOCK'];
		} elseif ($action == 'BLOCKPAGE'){
			$toReturn['commands'][] = [
				'action'=>'BLOCKPAGE',
				'data'=>$this->urlRoot().'?block&data='.urlencode(base64_encode(gzcompress(json_encode([
					'url' => $data['url'],
					'username' => $data['email'],
					'search' => $search,
					'deviceID' => $data['deviceID'],
					'screenscrape' => $word,
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
			'type' => '',
			'url' => substr($data['url'],0,2047),
		]);

		//look for email triggers
		foreach($filter['entries'] as $entry){
			if ($entry['resourceType'] != 'SCREENSCRAPE'){continue;}
			if (!in_array($entry['action'],['TRIGGER','TRIGGER_EXEMPT'])){continue;}

			if ($entry['username'] != '' && !$this->testString($data['email'], $entry['username'])){continue;}

			if (!$this->testURL($data,$entry['url'])){continue;}

			$count = $entry['initiator'];
			$count = explode(',',$count,2);
			$word = strtolower($count[1] ?? '');
			$count = $count[0];

			if ($word != '' && substr_count($data['text'],$word) >= $count){
				// Log action to log file
				\OSM\Tools\DB::insert('tbl_filter_log',[
					'date' => date('Y-m-d',$now),
					'time' => date('H:i:s',$now),
					'ip' => $_SERVER['REMOTE_ADDR'],
					'username' => $data['email'],
					'deviceid' => $data['deviceID'],
					'action' => $entry['action'],
					'type' => 'trigger word: '.$word,
					'url' => $data['url'],
				]);

				if ($entry['action'] == 'TRIGGER_EXEMPT'){
					break;
				} elseif ($entry['action'] == 'TRIGGER'){
					$email = $entry['appName'];

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
						."\nDevice: ".$this->niceName($data['deviceID'])
						."\nDevice Address: ".str_replace(".",'-',$_SERVER['REMOTE_ADDR'])
						."\nTriggered on keyword: ".$word
						."\nURL: ".$data['url']
						."\r\n\r\n";
					$screenshot = \OSM\Tools\TempDB::get('screenshot/'.$data['sessionID']);
					if ($screenshot != '') {
						$raw .= "--".$uid."\r\n";
						$raw .= "Content-Type: image/jpeg; name=\"screenshot.jpg\"\r\n";
						$raw .= "Content-Transfer-Encoding: base64\r\n";
						$raw .= "Content-Disposition: attachment; filename=\"screenshot.jpg\"\r\n\r\n";
						$raw .= chunk_split(base64_encode($screenshot))."\r\n\r\n";
					}
					$raw .= "--".$uid."--";
					mail($email, 'OSM Trigger Alert', $raw, $header);
					break;
				}
			}
		}
	}
}
