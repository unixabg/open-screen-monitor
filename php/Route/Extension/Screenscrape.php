<?php
namespace OSM\Route\Extension;

class Screenscrape extends \OSM\Tools\Route {
	public $renderRaw = true;

	public function action(){
		//in case the http timeout kills the connection, still log it
		ignore_user_abort(1);

		$now = time();

		//validate data
		$post = (\OSM\Tools\ExtensionData::decode($_POST['data'] ?? '[]') ?? []);
		$data = [];
		$fields = ['text','url','sessionID','email','deviceID'];
		foreach($fields as $field){
			$data[$field] = $post[$field] ?? '';
		}
		$rawText = (string)$data['text'];
		$data['text'] = strtolower($data['text']);

		// check allowed user domains
		$allowedUserDomains = \OSM\Tools\Config::get('allowedUserDomains');
		if ($allowedUserDomains != '') {
			$domains = array_map('trim', explode(',', $allowedUserDomains));
			$emailDomain = substr($data['email'], strrpos($data['email'], '@') + 1);
			if ($data['email'] == 'unknown' || !in_array($emailDomain, $domains)) {
				\OSM\Tools\Log::add('screenscrape.denied', $data['email']);
				http_response_code(403);
				die();
			}
		}

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
			$words = array_filter(array_map('trim',explode('|',strtolower($count[1] ?? ''))));
			$count = intval($count[0]);
			$word = '';
			foreach($words as $w){
				if ($w != '' && substr_count($data['text'],$w) >= $count){
					$word = $w;
					break;
				}
			}

			if ($word != ''){
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

		$this->watch($data, $rawText);

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
			$words = array_filter(array_map('trim',explode('|',strtolower($count[1] ?? ''))));
			$count = intval($count[0]);
			$word = '';
			foreach($words as $w){
				if ($w != '' && substr_count($data['text'],$w) >= $count){
					$word = $w;
					break;
				}
			}

			if ($word != ''){
				// Log action to log file
				\OSM\Tools\DB::insert('tbl_filter_log',[
					'date' => date('Y-m-d',$now),
					'time' => date('H:i:s',$now),
					'ip' => $_SERVER['REMOTE_ADDR'],
					'username' => $data['email'],
					'deviceid' => $data['deviceID'],
					'action' => $entry['action'],
					'type' => substr('trigger word: '.$word,0,63),
					//which rule fired, shown with the rule's comment in the browsing history
					'initiator' => 'rule:'.$entry['id'],
					'url' => substr($data['url'],0,2047),
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

	//For the users in screenscrapeWatchUsers, keep the first screenscrapeWatchWords words of
	//the text in a tab whose address is blank-type (screenscrapeWatchURLs, e.g. about:blank),
	//as a screenscrape.watch entry in the admin log. A cloaked tab shows about:blank while a
	//site is loaded in a frame inside it; the text includes every frame, so this shows what
	//is really there. Only for listed users and only for those addresses, nothing else is kept.
	private function watch($data, $rawText){
		$users = array_map('strtolower', array_map('trim', (array)\OSM\Tools\Config::get('screenscrapeWatchUsers')));
		if (empty($users) || !in_array(strtolower($data['email']), $users)){return;}

		$blank = false;
		foreach ((array)\OSM\Tools\Config::get('screenscrapeWatchURLs') as $prefix){
			if ($prefix !== '' && stripos($data['url'], $prefix) === 0){$blank = true; break;}
		}
		if (!$blank){return;}

		$words = preg_split('/\s+/u', trim($rawText), -1, PREG_SPLIT_NO_EMPTY);
		if ($words === false){$words = preg_split('/\s+/', trim($rawText), -1, PREG_SPLIT_NO_EMPTY);}
		if (empty($words)){return;}
		$excerpt = implode(' ', array_slice($words, 0, max(1, intval(\OSM\Tools\Config::get('screenscrapeWatchWords')))));

		//the same page is scanned again every few seconds; log it once an hour per session unless it changes
		$key = 'screenscrape-watch/'.md5($data['sessionID'].'|'.$excerpt);
		if (\OSM\Tools\TempDB::get($key) !== null){return;}
		\OSM\Tools\TempDB::set($key, '1', 3600);

		\OSM\Tools\Log::add('screenscrape.watch', $data['email'], [
			'url' => substr($data['url'], 0, 200),
			'deviceID' => $data['deviceID'],
			'words' => $excerpt,
		]);
	}
}
