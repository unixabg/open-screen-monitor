<?php
require('config.php');

$data = isset($_POST['data']) ? json_decode($_POST['data'],true) : array();
if (isset($data['text']) && $data['text'] != '' && isset($data['username']) && isset($data['domain']) && isset($data['deviceID']) && isset($data['url'])){
	$data['username'] = preg_replace("/[^a-zA-Z0-9-_\.]/","",$data['username']);
	if ($data['username'] == '') $data['username'] = 'unknown';

	$data['domain'] = preg_replace("/[^a-zA-Z0-9-_\.]/","",$data['domain']);
	if ($data['domain'] == '') $data['domain'] = 'unknown';

	$data['deviceID'] = preg_replace("/[^a-z0-9-]/","",$data['deviceID']);
	if ($data['deviceID'] == '') $data['deviceID'] = 'unknown';

	$data['url'] = str_replace("\t","",$data['url']);
	$data['url'] = str_replace("\n","",$data['url']);

	//debug
	//file_put_contents('/tmp/screenscrape.txt',$data['text']);

	$toReturn = array();
	if (file_exists($dataDir.'/screenscrape.txt')){
		$file = fopen($dataDir.'/screenscrape.txt',"r");
		while (($line = fgets($file)) !== false){
			$line = rtrim($line);
			$line = explode("\t",$line);

			$actionType = $_config['filterviaserverShowBlockPage'] ? 'BLOCKPAGE' : 'BLOCKNOTIFY';
			$word = '';
			$count = 1;
			switch(count($line)){
				case 1:
					if ($line[0] != '') $word = $line[0];
					break;
				case 2:
					if ($line[0] != '') $word = $line[0];
					if ($line[1] != '') $count = intval($line[1]);
					break;
				case 3:
					if ($line[0] != '') $actionType = $line[0];
					if ($line[1] != '') $word = $line[1];
					if ($line[2] != '') $count = intval($line[2]);
					break;
			}

			//case insensitive search
			$data['text'] = strtolower($data['text']);
			$word = strtolower($word);

			if ($word != '' && substr_count($data['text'],$word) >= $count){
				if ($actionType == 'BLOCKPAGE'){
					$toReturn['commands'][] = array(
						'action'=>'BLOCKPAGE',
						'data'=>'url_host='.urlencode($data['url']).'&data_type='.urlencode($data['type']).'&data_username='.urlencode($data['username']).'&filter_keyword='.urlencode($word),
					);
				} elseif ($actionType == 'BLOCKNOTIFY') {
					//show notification instead
					$toReturn['commands'][] = array('action'=>'BLOCK');
					$toReturn['commands'][] = array('action'=>'NOTIFY','data'=>array(
						'requireInteraction'=>false,
						'type'=>'basic',
						'iconUrl'=>'icon.png',
						'title'=>'Blocked Tab',
						'message'=>'Tab was blocked from the screenscraper with a filter_keyword on the url '.$data['url'].' by OSM admin filter.',
					));
				} elseif ($actionType == 'BLOCK') {
					$toReturn['commands'][] = array('action'=>'BLOCK');
				}

				//log it
				$logentry = "KEYWORDBLOCK\t".date('YmdHis',time())."\t".$word."\t".$data['url']."\n";
				$logDir .= date('Ymd')."/";
				if (!file_exists($logDir)) mkdir($logDir,0755,true);
				$logDir .= $data['username']."_".$data['domain']."/";
				if (!file_exists($logDir)) mkdir($logDir,0755,true);
				$logDir .= $data['deviceID']."/";
				if (!file_exists($logDir)) mkdir($logDir,0755,true);
				$logFile = $logDir.str_replace(".",'-',$_SERVER['REMOTE_ADDR']).".tsv";
				if (!file_exists($logFile)) touch($logFile);
				file_put_contents($logFile, $logentry, FILE_APPEND | LOCK_EX);

				break;
			}
		}
		fclose($file);
	}

	//look for email triggers
	if (file_exists($dataDir.'/triggerlist.txt')){
		$file = fopen($dataDir.'/triggerlist.txt',"r");
		while (($line = fgets($file)) !== false){
			$line = rtrim($line);
			$line = explode("\t",$line);

			$types = $_config['filterviaserverDefaultTriggerTypes'];
			$word = '';
			$email = '';
			switch(count($line)){
				case 2:
					if ($line[0] != '') $email = $line[0];
					if ($line[1] != '') $word = $line[1];
					break;
				case 3:
					if ($line[0] != '') $email = $line[0];
					if ($line[1] != '') $types = explode(',',$line[1]);
					if ($line[2] != '') $word = $line[2];
					break;
			}

			if ($email != '' && $word != '' && in_array('screenscrape',$types) && (stripos($data['text'],$word) !== false)){
				$header = "From: Open Screen Monitor <".$email.">\r\n";
				$raw = "User: ".$data['username']."_".$data['domain']
					."\nURL: ".$data['url']
					."\nDevice: ".$data['deviceID']
					."\nDevice Address: ".str_replace(".",'-',$_SERVER['REMOTE_ADDR'])
					."\n\n------------------\n".$data['text'];
				mail($email, "OSM Trigger Alert", $raw, $header);
			}
		}
		fclose($file);
	}

	//send it back
	die(json_encode($toReturn));
}
//if we get here, there has been a problem
die("Error in request");
