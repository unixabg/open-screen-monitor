<?php
namespace OSM;

//enable autoloading in the OSM namespace
require_once(__DIR__.'/components/autoload.php');

$dataDir = '../osm-data';

if (isset($_SERVER['OSM_DATA_DIR'])){
	$dataDir = $_SERVER['OSM_DATA_DIR'];
}

if (!file_exists($dataDir)) die('Missing osm-data directory: '.$dataDir);

//folder to store some policy settings for client ou or classrooms depending on mode
if (!file_exists($dataDir.'/config')) mkdir($dataDir.'/config',0755,true);

//everything in this dir is only needed for a few seconds
//this allows it to be mapped to a ramdisk to speed things up and cut down on disk writes
if (!file_exists($dataDir.'/clients')) mkdir($dataDir.'/clients',0755,true);

$logDir = $dataDir.'/logs/';
if (!file_exists($logDir)) mkdir($logDir,0755,true);

if (!file_exists($dataDir.'/config.json')) {
	$file = fopen($dataDir.'/config.json', 'w') or die("can't open file $dataDir/config.json");
	fclose($file);
}

//start config
//Don't modify these values in this script. Use config.json in $dataDir instead.
//set system wide version for php scripts
$_config['version']='0.3.0.1-next';

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
$_config['showUnknownDevices'] = false;
$_config['filterresourcetypes'] = array("main_frame","sub_frame","xmlhttprequest","trigger_exempt");
$_config['filterviaserver'] = false;
$_config['filterviaserverShowBlockPage'] = false;
$_config['filterviaserverDefaultFilterTypes'] = array('main_frame','sub_frame');
$_config['filterviaserverDefaultTriggerTypes'] = array('main_frame','sub_frame');
$_config['mode'] = 'device';
$_config['screenscrape'] = false;
$_config['cacheCleanupOnStartup'] = false;
$_config['cacheCleanupTime'] = 0;
$_config['forceSingleWindow'] = false;
$_config['forceMaximizedWindow'] = false;
$_config['debug'] = true;

//overlay settings from config file
if (file_exists($dataDir.'/config.json')){
	$overlayConfig = json_decode(file_get_contents($dataDir.'/config.json'),true);
	if (is_array($overlayConfig)) $_config = array_merge($_config,$overlayConfig);
}
