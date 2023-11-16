<?php
namespace OSM;

//function for autoloading classes in the OSM namespace
spl_autoload_register(function ($className) {
	//all files are in lowercase regardless of class case
	$className = strtolower($className);

	//get the parts of the class
	$className = explode('\\',$className);

	//this function only autoloads the OSM namespace
	//the OSM namespace will also only be 1 layer deep (as for now)
	if ($className[0] != 'osm' || count($className) != 2 || $className[1] !== preg_replace("/[^a-z0-9]/", "", $className[1])){
		return;
	}

	$filePath = __DIR__.'/'.$className[1].'.php';
	if (file_exists($filePath)){
		require_once($filePath);
		return;
	}
});
