<?php
namespace OSM\Route\Extension;

class Block extends \OSM\Tools\Route {
	public function action(){
		$dataDir = $GLOBALS['dataDir'];

		$data = $_GET['data'] ?? '';
		$data = base64_decode($data);
		$data = gzuncompress($data);
		$data = json_decode($data,true);

		//allow custom hooking here
		//make sure to set restrictive permissions on this file
		if (file_exists($dataDir.'/custom/block-prepend.php')){
			$returnAfterIncude = false;
			include($dataDir.'/custom/block-prepend.php');
			if ($returnAfterInclude) {return;}
		}

		$this->css = '
			#content {max-width:800px;text-align:center;margin:1.5em auto;border:3px solid #a0a0a0;background-color:#f0f0f0;padding:1.5em;}
			#content table {width:100%;}
			#content h1 {padding-bottom:1.5em;}
		';

		echo '<div id="content">';
		echo '<h1>Page Blocked</h1>';
		echo '<table>';
		echo '<tr><th>URL</th><td>'.htmlentities($data['url'] ?? '').'</td></tr>';
		echo '<tr><th>Filter Search</th><td>'.htmlentities($data['search'] ?? '').'</td></tr>';
		echo '<tr><th>Username</th><td>'.htmlentities($data['username'] ?? '').'</td></tr>';
		echo '<tr><th>Device ID</th><td>'.htmlentities($data['deviceID'] ?? '').'</td></tr>';
		echo '<tr><th>Resource Type</th><td>'.htmlentities($data['type'] ?? '').'</td></tr>';
		echo '<tr><th>IP</th><td>'.$_SERVER['REMOTE_ADDR'].'</td></tr>';
		echo '</table>';
		echo '</div>';
	}
}

