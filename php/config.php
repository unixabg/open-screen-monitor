<?php
namespace OSM;

$GLOBALS['dataDir'] = '../osm-data';
if (isset($_SERVER['OSM_DATA_DIR'])){
	$GLOBALS['dataDir'] = $_SERVER['OSM_DATA_DIR'];
}

//everthing is relative to this file
chdir(__DIR__);

if (!file_exists($dataDir)) die('Missing osm-data directory: '.$dataDir);

//enable autoloading in the OSM namespace
require_once('Tools/Autoload.php');

//everything in this dir is only needed for a few seconds
//this allows it to be mapped to a ramdisk to speed things up and cut down on disk writes
if (!file_exists($dataDir.'/clients')) mkdir($dataDir.'/clients',0755,true);

