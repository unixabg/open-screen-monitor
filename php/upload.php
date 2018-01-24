<?php

$dataDir='../../osm-data';
$osmURL='https://osm/osm';
if (isset($_POST['data'])) {
	$data = json_decode($_POST['data'],true);
	if (isset($data['deviceID'])) {
		$deviceID = preg_replace("/[^a-z0-9-]/","",$data['deviceID']);
		if ($deviceID != "") {
			//first glob in the groups which have a full path
			$group = glob("$dataDir/*", GLOB_ONLYDIR);
			$groupCount = count($group);
			for ($g=0; $g < $groupCount; $g++) {
				$deviceFolder="$group[$g]/$deviceID";
				if (file_exists($deviceFolder)) {
					//ping file for status
					touch($deviceFolder.'/ping');
					file_put_contents($deviceFolder.'/ip',$_SERVER['REMOTE_ADDR']);
					//debug
					file_put_contents($deviceFolder.'/debug',$_POST['data']);
					$screenshot = '';
					if (isset($data['screenshot'])) {
						$screenshot = $data['screenshot'];
						$screenshot = str_replace('data:image/jpeg;base64,','',$screenshot);
						$screenshot = base64_decode($screenshot);
					}
					if ($screenshot != "") {
						file_put_contents($deviceFolder.'/screenshot.jpg',$screenshot);
					} elseif (file_exists($deviceFolder.'/screenshot.jpg')) {
						unlink($deviceFolder.'/screenshot.jpg');
					}
					foreach (array('username','version','domain') as $field) {
						if (isset($data[$field]) && $data[$field] != "") {
							file_put_contents($deviceFolder.'/'.$field,$data[$field]);
						} elseif (file_exists($deviceFolder.'/'.$field)) {
							unlink($deviceFolder.'/'.$field);
						}
					}
					if (isset($data['tabs'])) {
						file_put_contents($deviceFolder.'/tabs',json_encode($data['tabs']));
					} elseif (file_exists($deviceFolder.'/tabs')) {
						unlink($deviceFolder.'/tabs');
					}
					//send commands back
					$toReturn = array();
					//set the refresh time
					$toReturn['commands'][] = array('action'=>'changeRefreshTime','time'=>9000);
					//override a few things for devices which are waiting in enroll group
					if (basename($group[$g])=='enroll') {
						file_put_contents($deviceFolder.'/openurl',"$osmURL/enroll.html");
						//prompt the user to enroll every 10 min
						$toReturn['commands'][] = array('action'=>'changeRefreshTime','time'=>600000);
					}
					if (file_exists($deviceFolder.'/openurl')) {
						$urls = file_get_contents($deviceFolder.'/openurl');
						$urls = explode("\n",$urls);
						foreach ($urls as $i=>$url) {
							if ((isset($data['tabs']) && count($data['tabs']) > 0) || $i > 0) {
								$toReturn['commands'][] = array('action'=>'tabsCreate','data'=>array('url'=>$url));
							} else {
								$toReturn['commands'][] = array('action'=>'windowsCreate','data'=>array('url'=>$url));
							}
						}
						unlink($deviceFolder.'/openurl');
					}
					if (file_exists($deviceFolder.'/closetab')) {
						$tabs = file_get_contents($deviceFolder.'/closetab');
						$tabs = explode("\n",$tabs);
						foreach ($tabs as $tab){
							if ($tab != "") {
								$tab = intval($tab);
								$toReturn['commands'][] = array('action'=>'tabsRemove','tabId'=>$tab);
							}
						}
						unlink($deviceFolder.'/closetab');
					}
					if (file_exists($deviceFolder.'/lock')) {
						$toReturn['commands'][] = array('action'=>'lock');
						unlink($deviceFolder.'/lock');
					}
					if (file_exists($deviceFolder.'/unlock')) {
						$toReturn['commands'][] = array('action'=>'unlock');
						unlink($deviceFolder.'/unlock');
					}
					//send it back
					header('Content-Type: application/json');
					die(json_encode($toReturn));
				}
			}
			//if we get here then we need to enroll the device
			//make sure the enrollment folder and the device folder exists
			//first submitt we create the deviceFolder and on next run it will be populated
			$deviceFolder = "$dataDir/enroll/$deviceID";
			if (!file_exists($deviceFolder)) mkdir($deviceFolder);
		}
	}
}
