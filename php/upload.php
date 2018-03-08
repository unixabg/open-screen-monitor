<?php
require('config.php');

$toReturn = array();
if (isset($_POST['data'])) {
	$data = json_decode($_POST['data'],true);
	if (isset($data['deviceID'])) {
		$deviceID = preg_replace("/[^a-z0-9-]/","",$data['deviceID']);
		if ($deviceID != "") {
			$deviceFolder=$dataDir.'/devices/'.$deviceID;
			//create device folder if it doesn't exist
			if (!file_exists($deviceFolder)) mkdir($deviceFolder, 0755 , true);
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
			//set the refresh time
			$toReturn['commands'][] = array('action'=>'changeRefreshTime','time'=>$_config['uploadRefreshTime']);
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
			if (file_exists($deviceFolder.'/filterlist') && file_exists($deviceFolder.'/filtermode')) {
				$filtermode = file_get_contents($deviceFolder.'/filtermode');
				$filterlisttime = filemtime($deviceFolder.'/filterlist');
				$filterlist = file_get_contents($deviceFolder.'/filterlist');
				$filterlist = explode("\n",$filterlist);

				foreach ($filterlist as $i=>$value) {
					if ($value == "") unset($filterlist[$i]);
				}

				if ($filtermode == 'defaultdeny' && count($filterlist) > 0) {
					//always allow the new tab page so they can atleast open the browser
					$filterlist[] = "^https://www.google.com/_/chrome/newtab";
					//always allow the google signin page for google
					$filterlist[] = "^https://accounts.google.com/";
				}

				if ($data['filterlisttime'] < $filterlisttime) {
					$toReturn['commands'][] = array('action'=>'setData','key'=>'filtermode','value'=>$filtermode);
					$toReturn['commands'][] = array('action'=>'setData','key'=>'filterlist','value'=>$filterlist);
					$toReturn['commands'][] = array('action'=>'setData','key'=>'filterlisttime','value'=>$filterlisttime);
				}
				//test here for tabs that need dropped for filtering policy
				if (isset($data['tabs'])) {
					foreach ($data['tabs'] as $tabs=>$tab) {
						//test each tab against the filterlist
						foreach ($filterlist as $i=>$value) {
							$foundMatch = preg_match("/$value/i", $tab['url']);
							if ($foundMatch) {
								break;
							}
						}
						if (($filtermode == 'defaultdeny' && !$foundMatch) || ($filtermode == 'defaultallow' && $foundMatch)) {
							//filter violation found so append to closetab
							file_put_contents($deviceFolder.'/closetab',$tab['id']."\n",FILE_APPEND);
							//notify the user we dropped the tab
							file_put_contents($deviceFolder.'/messages',$_config['filterMessage']["title"]."\t".$_config['filterMessage']["message"]["opentab"].$tab['url']."\n",FILE_APPEND);
						}
					}
				}
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
			if ((!isset($data['lock']) || !$data['lock']) && file_exists($deviceFolder.'/lock')) {
				//avoid locking with stale lock file
				if (filemtime($deviceFolder.'/lock') <= time() - $_config['lockTimeout'] ) {
					unlink($deviceFolder.'/lock');
				} else {
					$toReturn['commands'][] = array('action'=>'lock');
				}
			}
			if (file_exists($deviceFolder.'/unlock')) {
				$toReturn['commands'][] = array('action'=>'unlock');
				unlink($deviceFolder.'/unlock');
			}

			if (file_exists($deviceFolder.'/messages')) {
				$messages = file_get_contents($deviceFolder.'/messages');
				$messages = explode("\n",$messages);
				foreach ($messages as $message) {
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
				unlink($deviceFolder.'/messages');
			}

			//populate filtermessage
			if (!$data['filtermessage'])
				$toReturn['commands'][] = array('action'=>'setData','key'=>'filtermessage','value'=>array(
					'requireInteraction'=>true,
					'type'=>'basic',
					'iconUrl'=>'icon.png',
					'title'=>$_config['filterMessage']['title'],
					'message'=>$_config['filterMessage']['message']['newtab'],
				));
			//activate server side filter
			if (!$data['filterviaserver'])
				$toReturn['commands'][] = array('action'=>'setData','key'=>'filterviaserver','value'=>true);
		} else {
			//deviceID not set

			//activate server side filter
			if (!$data['filterviaserver'])
				$toReturn['commands'][] = array('action'=>'setData','key'=>'filterviaserver','value'=>true);
			//up the refresh since this is probably a personal or atleast non-chromebook device and
			//we don't really care except for maybe eventually enabling the filter so it follows them even on those devices
			$toReturn['commands'][] = array('action'=>'changeRefreshTime','time'=>10*60*1000);
		}
		//show startup notification
		if ($_config['showStartupNotification'] && !isset($data['startupNotification'])){
			$toReturn['commands'][] = array('action'=>'setData','key'=>'startupNotification','value'=>true);
			$toReturn['commands'][] = array('action'=>'sendNotification','data'=>array(
				'type'=>'basic',
				'iconUrl'=>'icon.png',
				'title'=>'Open Screen Monitor starting...',
				'message'=>'The account '.$data['username'].'@'.$data['domain'].' is under a managed policy.',
			));
		}
	}
}

//send it back
header('Content-Type: application/json');
die(json_encode($toReturn));
