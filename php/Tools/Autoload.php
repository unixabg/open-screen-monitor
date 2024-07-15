<?php
namespace OSM;

//function for autoloading classes in the OSM namespace
spl_autoload_register(function ($className) {
	//make sure class name doesn't have anything special
	//we will be looking for a file based on this
	//and we don't want to allow for arbitary file includes
	if ($className !== preg_replace("/[^a-zA-Z0-9\\\\]/", "", $className)){
		return;
	}

	//get the parts of the class
	$className = explode('\\',$className);

	//this function only autoloads the OSM namespace
	if ($className[0] != 'OSM'){
		return;
	}

	//turn the full class path minus /OSM to a file path and try to include it
	unset($className[0]);

	$filePath = dirname(__DIR__).'/'.implode('/',$className).'.php';
	if (file_exists($filePath)){
		require_once($filePath);
	}
});
