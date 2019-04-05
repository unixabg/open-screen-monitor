<?php
require('config.php');

$data = isset($_POST['data']) ? json_decode($_POST['data'],true) : array();
if (isset($data['text']) && $data['text'] != '' && isset($data['username']) && isset($data['domain']) && isset($data['deviceID']) && isset($data['url'])){
	$data['username'] = preg_replace("/[^a-z0-9-_\.]/","",$data['username']);
	if ($data['username'] == '') $data['username'] = 'unknown';

	$data['domain'] = preg_replace("/[^a-z0-9-_\.]/","",$data['domain']);
	if ($data['domain'] == '') $data['domain'] = 'unknown';

	$data['deviceID'] = preg_replace("/[^a-z0-9-]/","",$data['deviceID']);
	if ($data['deviceID'] == '') $data['deviceID'] = 'unknown';

	$data['url'] = str_replace("\t","",$data['url']);
	$data['url'] = str_replace("\n","",$data['url']);


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
						'message'=>'Tab was blocked with a filter_keyword on the url '.$data['url'].' by OSM admin filter.',
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

	//send it back
	die(json_encode($toReturn));
}
//if we get here, there has been a problem
die("Error in request");
