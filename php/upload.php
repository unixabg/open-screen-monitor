<?php

if (isset($_POST['data'])) {
	$data = json_decode($_POST['data'],true);
	if (isset($data['deviceID'])){
		$deviceID = preg_replace("/[^a-z0-9-]/","",$data['deviceID']);

		if ($deviceID != "") {
			//make sure folder exists
			$folder = '../../osm-data/'.$deviceID;
			if (!file_exists($folder)) mkdir($folder);

			//ping file for status
			touch($folder.'/ping');

			file_put_contents($folder.'/ip',$_SERVER['REMOTE_ADDR']);

			//debug
			file_put_contents($folder.'/debug',$_POST['data']);

			$screenshot = '';
			if (isset($data['screenshot'])) {
				$screenshot = $data['screenshot'];
				$screenshot = str_replace('data:image/jpeg;base64,','',$screenshot);
				$screenshot = base64_decode($screenshot);
			}
			if ($screenshot != "") {
				file_put_contents($folder.'/screenshot.jpg',$screenshot);
			}elseif(file_exists($folder.'/screenshot.jpg')) {
				unlink($folder.'/screenshot.jpg');
			}

			foreach (array('username','version','domain') as $field){
				if (isset($data[$field]) && $data[$field] != "") {file_put_contents($folder.'/'.$field,$data[$field]);}
				elseif(file_exists($folder.'/'.$field)) {unlink($folder.'/'.$field);}
			}

			if (isset($data['tabs'])) {file_put_contents($folder.'/tabs',json_encode($data['tabs']));}
			elseif(file_exists($folder.'/tabs')) {unlink($folder.'/tabs');}


			//send commands back
			$toReturn = array();

			//set the refresh time
			$toReturn['commands'][] = array('action'=>'changeRefreshTime','time'=>9000);

			if (file_exists($folder.'/openurl')) {
				$urls = file_get_contents($folder.'/openurl');
				$urls = explode("\n",$urls);
				foreach ($urls as $i=>$url){
					if ((isset($data['tabs']) && count($data['tabs']) > 0) || $i > 0)
						$toReturn['commands'][] = array('action'=>'tabsCreate','data'=>array('url'=>$url));
					else
						$toReturn['commands'][] = array('action'=>'windowsCreate','data'=>array('url'=>$url));
				}
				unlink($folder.'/openurl');
			}


			if (file_exists($folder.'/closetab')) {
				$tabs = file_get_contents($folder.'/closetab');
				$tabs = explode("\n",$tabs);
				foreach ($tabs as $tab){
					if ($tab != "") {
						$tab = intval($tab);
						$toReturn['commands'][] = array('action'=>'tabsRemove','tabId'=>$tab);
					}
				}
				unlink($folder.'/closetab');
			}


			if (file_exists($folder.'/lock')) {
				$toReturn['commands'][] = array('action'=>'lock');
				unlink($folder.'/lock');
			}


			if (file_exists($folder.'/unlock')) {
				$toReturn['commands'][] = array('action'=>'unlock');
				unlink($folder.'/unlock');
			}


			//send it back
			header('Content-Type: application/json');
			die(json_encode($toReturn));
		}
	}
}
