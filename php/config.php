<?php

$dataDir = '../../osm-data';
$logDir = $dataDir.'/logs/';

if (!file_exists($dataDir)) die('Missing osm-data directory');
if (!file_exists($dataDir.'/devices')) mkdir($dataDir.'/devices',0755,true);
