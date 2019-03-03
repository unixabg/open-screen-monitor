<?php
require('config.php');

$data = isset($_POST['data']) ? json_decode($_POST['data'],true) : array();
if (isset($data['username']) && isset($data['domain']) && isset($data['deviceID']) && isset($data['url']) && $data['url'] != ''){
	$data['username'] = preg_replace("/[^a-z0-9-_\.]/","",$data['username']);
	if ($data['username'] == '') $data['username'] = 'unknown';

	$data['domain'] = preg_replace("/[^a-z0-9-_\.]/","",$data['domain']);
	if ($data['domain'] == '') $data['domain'] = 'unknown';

	$data['deviceID'] = preg_replace("/[^a-z0-9-]/","",$data['deviceID']);
	if ($data['deviceID'] == '') $data['deviceID'] = 'unknown';

	if (!isset($data['type']))$data['type'] = 'unknown';
	$data['type'] = preg_replace("/[^a-z0-9_]/","",$data['type']);
	if ($data['type'] == '') $data['type'] = 'unknown';


	$data['url'] = str_replace("\t","",$data['url']);
	$data['url'] = str_replace("\n","",$data['url']);


	//determine action
	$toReturn = array();
	$action = 'SENTRY'; //set to SENTRY for whitelist and blacklist testing
	$parameters = '';
	$sendEmail = false;

	if (file_exists($dataDir.'/filter_whitelist.txt') && file_exists($dataDir.'/filter_blacklist.txt')){
		//first check whitelist
		$file = fopen($dataDir.'/filter_whitelist.txt',"r");
		while (($line = fgets($file)) !== false){
			$line = rtrim($line);
			//pass if domain equal or if a subdomain of domain
			if ($line != '' && ($line == $data['url'] || strstr($data['url'],$line)) !== false){
				$action = 'ALLOW';
				break;
			}
		}
		fclose($file);

		//if no match in whitelist test against the blacklist
		if ($action == 'SENTRY'){
			$file = fopen($dataDir.'/filter_blacklist.txt',"r");
			while (($line = fgets($file)) !== false){
				$line = rtrim($line);
				$line = explode("\t",$line);

				$actionType = $_config['filterviaserverShowBlockPage'] ? 'BLOCKPAGE' : 'BLOCKNOTIFY';
				$types = $_config['filterviaserverDefaultFilterTypes'];
				$url = '';
				switch(count($line)){
					case 1:
						if ($line[0] != '') $url = $line[0];
						break;
					case 2:
						if ($line[0] != '') $actionType = $line[0];
						if ($line[1] != '') $url = $line[1];
						break;
					case 3:
						if ($line[0] != '') $actionType = $line[0];
						if ($line[1] != '') $types = explode(',',$line[1]);
						if ($line[2] != '') $url = $line[2];
						break;
				}

				if (substr($actionType,0,9) == 'REDIRECT:'){
					$redirectUrl = substr($actionType,9);
					$actionType = 'REDIRECT';
				}

				$actionEmail = false;
				if (strpos($actionType,"+EMAIL") !== false){
					$actionEmail = true;
					$actionType = str_replace("+EMAIL","",$actionType);
				}


				if ($url != '' && (in_array('*',$types) || in_array($data['type'],$types)) && ($url == '*' || $url == $data['url'] || strstr($data['url'],$url)) !== false){
					$action = $actionType; //set SENTRY to action type since we hit something
					if ($actionEmail) {$sendEmail = true;}
					if ($actionType == 'BLOCKPAGE'){
						$toReturn['commands'][] = array(
							'action'=>'BLOCKPAGE',
							'data'=>'url_host='.urlencode($data['url']).'&data_type='.urlencode($data['type']).'&data_username='.urlencode($data['username']).'&filter_keyword='.urlencode($url),
						);
						$toReturn['return']['cancel'] = true;
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
						$toReturn['return']['cancel'] = true;
					} elseif ($actionType == 'BLOCK') {
						$toReturn['commands'][] = array('action'=>'BLOCK');
						$toReturn['return']['cancel'] = true;
					} elseif ($actionType == 'CANCEL') {
						$toReturn['return']['cancel'] = true;
					} elseif ($actionType == 'REDIRECT') {
						$toReturn['return']['redirectUrl'] = $redirectUrl;
					}
					break;
				}
			}
			fclose($file);
		}
	}

	//if no whitelist or blacklist match default to allow
	if ($action == 'SENTRY'){
		$action = 'ALLOW';
	}

	//log it
	$logentry = $action."\t".date('YmdHis',time())."\t".$data['type']."\t".$data['url']."\n";
	$logDir .= date('Ymd')."/";
	if (!file_exists($logDir)) mkdir($logDir,0755,true);
	$logDir .= $data['username']."_".$data['domain']."/";
	if (!file_exists($logDir)) mkdir($logDir,0755,true);
	$logDir .= $data['deviceID']."/";
	if (!file_exists($logDir)) mkdir($logDir,0755,true);
	$logFile = $logDir.str_replace(".",'-',$_SERVER['REMOTE_ADDR']).".tsv";
	if (!file_exists($logFile)) touch($logFile);
	file_put_contents($logFile, $logentry, FILE_APPEND | LOCK_EX);

	//email it
	if ($sendEmail && $_config['filterAlertEmail'] != ''){
		$header = "From: Open Screen Monitor <".$_config['filterAlertEmail'].">\r\n";
		$raw = $data['username']."_".$data['domain']
			."\n".$data['deviceID']
			."\n".str_replace(".",'-',$_SERVER['REMOTE_ADDR'])
			."\n".str_replace("\t","\n",$logentry);
		mail($_config['filterAlertEmail'], "OSM Filter Alert", $raw, $header);
	}

	//send it back
	die(json_encode($toReturn));
}
//if we get here, there has been a problem
die("Error in request");
