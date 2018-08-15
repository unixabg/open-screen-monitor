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
	$data['type'] = preg_replace("/[^a-z0-9-]/","",$data['type']);
	if ($data['type'] == '') $data['type'] = 'unknown';


	$data['url'] = str_replace("\t","",$data['url']);
	$data['url'] = str_replace("\n","",$data['url']);


	//determine action
	$action = 'SENTRY'; //set to SENTRY for whitelist and blacklist testing
	$blockPageParameters = '';
	$url = parse_url($data['url']);

	if (($data['type'] == 'mainframe' || $data['type'] == 'subframe') && isset($url['host']) && file_exists($dataDir.'/filter_domainwhitelist.txt') && file_exists($dataDir.'/filter_domainblacklist.txt')){
		//first check whitelist
		$file = fopen($dataDir.'/filter_domainwhitelist.txt',"r");
		while (($line = fgets($file)) !== false){
			$line = rtrim($line);
			//pass if domain equal or if a subdomain of domain
			if ($line != '' && ($line == $url['host'] || stripos($url['host'],'.'.$line)) !== false){
				$action = 'ALLOW';
				break;
			}
		}
		fclose($file);

		//if no match in whitelist test against the blacklist
		if ($action == 'SENTRY'){
			$file = fopen($dataDir.'/filter_domainblacklist.txt',"r");
			while (($line = fgets($file)) !== false){
				$line = rtrim($line);
				//block if domain equal or if a subdomain of domain
				if ($line != '' && ($line == $url['host'] || stripos($url['host'],'.'.$line)) !== false){
					$action='BLOCK';
					if ($_config['filterviaserverShowBlockPage']){
						//todo: pass parameters to block page here
						$blockPageParameters = 'url_host='.$url['host'].'&data_type='.$data['type'].'&data_username='.$data['username'];
						break;
					}
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


	//send it back
	die($action.($blockPageParameters != '' ? "\n".$blockPageParameters : ""));
}
//if we get here, there has been a problem
die("Error in request");
