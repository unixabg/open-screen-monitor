<?php

$dataDir = '../../osm-data';
$logDir = $dataDir.'/logs/';

if (!file_exists($dataDir)) die('Missing osm-data directory');
if (!file_exists($dataDir.'/devices')) mkdir($dataDir.'/devices',0755,true);


//start config
//set system wide version for php scripts
$_config['version']='0.2.0.2';

//set the default time chrome will wait between phone home attempst to the upload script
$_config['uploadRefreshTime']=9000;

//set lock file timeout to avoid locking on stale lock request
$_config['lockTimeout']=300;

//set the OSM lab filter message
$_config['filterMessage'] = array(
	'title' => 'OSM Server says ... ',
	'message' => array(
		'newtab' => 'A lab filter violation was detected on the url request of: ',
		'opentab' => 'A lab filter violation was detected on an existing tab url of: '
	)
);
