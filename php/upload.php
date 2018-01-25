<?php

if (isset($_POST['data'])) {
	$data = json_decode($_POST['data'],true);
	if (isset($data['deviceID'])){
		$deviceID = preg_replace("/[^a-z0-9-]/","",$data['deviceID']);

		if ($deviceID != "") {
			//make sure folder exists
			$folder = '../data/'.$deviceID;
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
			}
			elseif(file_exists($folder.'/screenshot.jpg')) {unlink($folder.'/screenshot.jpg');}

			foreach (array('username','version','domain') as $field){
				if (isset($data[$field]) && $data[$field] != "") {file_put_contents($folder.'/'.$field,$data[$field]);}
				elseif(file_exists($folder.'/'.$field)) {unlink($folder.'/'.$field);}
			}

			if (isset($data['tabs'])) {file_put_contents($folder.'/tabs',json_encode($data['tabs']));}
			elseif(file_exists($folder.'/tabs')) {unlink($folder.'/tabs');}











			//send commands back
			$toReturn = array();

			//set the refresh time
			$toReturn['commands'][] = array('action'=>'changeRefreshTime','time'=>11*1000);

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

			if (file_exists($folder.'/filterlist') && file_exists($folder.'/filtermode')){
				$filtermode = file_get_contents($folder.'/filtermode');
				$filterlisttime = filemtime($folder.'/filterlist');
				$filterlist = file_get_contents($folder.'/filterlist');
				$filterlist = explode("\n",$filterlist);

				foreach ($filterlist as $i=>$value){if ($value == "") unset($filterlist[$i]);}

				if ($filtermode == 'defaultdeny' && count($filterlist) > 0) {
					//allow the new tab page
					$filterlist[] = "^https://www.google.com/_/chrome/newtab";
					$filterlist[] = "^https://accounts.google.com/";
				}

				if ($data['filterlisttime'] < $filterlisttime) {
					$toReturn['commands'][] = array('action'=>'setData','key'=>'filtermode','value'=>$filtermode);
					$toReturn['commands'][] = array('action'=>'setData','key'=>'filterlist','value'=>$filterlist);
					$toReturn['commands'][] = array('action'=>'setData','key'=>'filterlisttime','value'=>$filterlisttime);
				}
			}

			if (file_exists($folder.'/messages')) {
				$messages = file_get_contents($folder.'/messages');
				$messages = explode("\n",$messages);
				foreach ($messages as $message){
					$message = explode("\t",$message);
					if (count($message == 2) && $message[0] != '' && $message[1] != '') {
						$toReturn['commands'][] = array('action'=>'sendNotification','data'=>array(
							'requireInteraction'=>true,
							'type'=>'basic',
							'iconUrl'=>'icon.png',
							'title'=>$message[0],
							'message'=>$message[1],
						));
					}
				}
				unlink($folder.'/messages');
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
