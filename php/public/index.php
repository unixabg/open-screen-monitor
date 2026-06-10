<?php
namespace OSM;

require_once('../config.php');

//we will need a session everywhere that this page goes
session_start();

if (!isset($_GET['route'])){
	if (isset($_GET['logout'])) {
		session_destroy();
		header('Location: /');
		die();
	} elseif (isset($_GET['code'])) {
		$token = Tools\Google::getToken($_GET['code']);
		if (!Tools\Google::checkToken($token))
			die("Error has occured validating token");

		\OSM\Tools\Log::add('login');

		//redirect them back to self, except this time they will be logged in
		if (isset($_SESSION['loginredirect'])){
			header('Location: '.$_SESSION['loginredirect']);
			unset($_SESSION['loginredirect']);
		} else {
			header('Location: /');
		}
		die();
	} elseif (isset($_GET['non-enterprise-device']) && Tools\Google::checkToken($_SESSION['token']) && $_SESSION['admin']){
		$_SESSION['allowedclients'] = ['non-enterprise-device'=>'Non Enterprise Devices'];
		$_SESSION['lab'] = 'Non Enterprise Devices';
		$_SESSION['groups']['non-enterprise-device'] = [
			'name' => 'Non Enterprise Devices',
			'type' => 'device',
			'clients' => ['non-enterprise-device' => 'Non Enterprise Devices'],
		];
		header('Location: /?route=Monitor\Viewer&groupID=non-enterprise-device');
		die();
	} elseif (isset($_GET['upload_legacy'])){
		$_GET['route'] = 'Extension\\Uploadlegacy';
	} elseif (isset($_GET['upload'])){
		$_GET['route'] = 'Extension\\Upload';
	} elseif (isset($_GET['filter'])){
		$_GET['route'] = 'Extension\\Filter';
	} elseif (isset($_GET['block'])){
		$_GET['route'] = 'Extension\\Block';
	} elseif (isset($_GET['screenscrape'])){
		$_GET['route'] = 'Extension\\Screenscrape';
	} elseif (isset($_GET['extfile'])){
		$files = ['xml'=>'update.xml','crx'=>'ext.crx','zip'=>'ext.zip'];
		$file = $files[ $_GET['extfile'] ] ?? '';
		if ($file != ''){
			$file = $GLOBALS['dataDir'].'/extension/'.$file;
			if (file_exists($file)){
				die(file_get_contents($file));
			}
		}
		http_response_code(404);
		die();
	}
}


//fall back to routes
$route = 'OSM\\Route\\'.($_GET['route'] ?? 'Index');

try {
	if (class_exists($route)){
		$route = new $route();
		$route->render();
	} else {
		die('Invalid Route: '.htmlentities($route));
	}
} catch (\Throwable $e){
	file_put_contents($GLOBALS['dataDir'].'/error.log',"\n\n".date('Y-n-d h:i:s')."\n".print_r($e,true),\FILE_APPEND | \LOCK_EX);
	if ($_SERVER['OSM_SHOW_ERRORS'] ?? false){
		die('<h1>An Error Occured</h1><pre>'.htmlentities(print_r($e,true)).'</pre>');
	} else {
		die('<h1>An Error Occured</h1><pre>'.htmlentities($e->getMessage()).'</pre>');
	}
}
