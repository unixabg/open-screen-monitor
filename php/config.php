<?php

$dataDir = '../osm-data';

if (!file_exists($dataDir)) die('Missing osm-data directory');

if (!file_exists($dataDir.'/clients')) mkdir($dataDir.'/clients',0755,true);

$logDir = $dataDir.'/logs/';
if (!file_exists($logDir)) mkdir($logDir,0755,true);

//start config
//Don't modify these values in this script. Use config.json in $dataDir instead.
//set system wide version for php scripts
$_config['version']='0.2.0.16-next';

//set the default time chrome will wait between phone home attempst to the upload script
$_config['uploadRefreshTime']=9000;

//set the default time chrome will scan the active tab for flagged words
$_config['screenscrapeTime']=20000;

//set lock file timeout to avoid locking on stale lock request
$_config['lockTimeout']=300;

//set max number of device log entries to retain
$_config['logmax']=100;

//set the OSM lab filter message
$_config['filterMessage'] = array(
	'title' => 'OSM Server says ... ',
	'message' => array(
		'newtab' => 'A lab filter violation was detected on the url request of: ',
		'opentab' => 'A lab filter violation was detected on an existing tab url of: '
	)
);

$_config['showStartupNotification'] = false;
$_config['filterresourcetypes'] = array("main_frame","sub_frame","xmlhttprequest");
$_config['filterviaserver'] = false;
$_config['filterviaserverShowBlockPage'] = false;
$_config['filterviaserverDefaultFilterTypes'] = array('main_frame','sub_frame');
$_config['filterviaserverDefaultTriggerTypes'] = array('main_frame','sub_frame');
$_config['mode'] = 'device';
$_config['screenscrape'] = false;

//overlay settings from config file
if (file_exists($dataDir.'/config.json')){
	$overlayConfig = json_decode(file_get_contents($dataDir.'/config.json'),true);
	if (is_array($overlayConfig)) $_config = array_merge($_config,$overlayConfig);
}
