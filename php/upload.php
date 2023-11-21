<?php
namespace OSM;

require('config.php');

//allow custom hooking here
//make sure to set restrictive permissions on this file
if (file_exists($dataDir.'/custom-upload-prepend.php'))
	include($dataDir.'/custom-upload-prepend.php');

$toReturn = array();
if (isset($_POST['data'])) {
	$data = json_decode($_POST['data'],true);

	$clientID = '';
	if ($_config['mode'] == 'device' && isset($data['deviceID'])){
		$clientID = preg_replace("/[^a-z0-9-]/","",$data['deviceID']);
	} elseif ($_config['mode'] == 'user' && isset($data['username']) && isset($data['domain'])) {
		$clientID = preg_replace("/[^a-zA-Z0-9-_]/","",$data['username'].'_'.$data['domain']);
	}

	if ($clientID == '' && $_config['showUnknownDevices']){
		$clientID = 'unknown';
	}

	if ($clientID != "") {
		//default config if nothing is found, should just be empty folder
		$configFolder = $dataDir.'/config/unknown';

		if ($_config['mode'] == 'device'){
			$devices = fopen($dataDir.'/devices.tsv','r');
			while($line = trim(fgets($devices))){
				$line = explode("\t",$line);
				if ($line[0] == $clientID){
					$configFolder = $dataDir.'/config/'.base64_encode($line[1]);
					break;
				}
			}
			fclose($devices);
		} elseif ($_config['mode'] == 'user'){
			//we only set config folder if it exists because we are looking for a symlink
			//to a class config folder for user mode
			//if the user hasn't been claimed by a class they will use the unknown folder from above
			//using file_exists() because it should error out if the target doesn't exist while
			//is_link() will return true if the link has been created even to an invalid target
			if (file_exists($dataDir.'/config/'.$clientID)){
				$configFolder = $dataDir.'/config/'.$clientID;
			}
		}

		//create config folder if it doesn't exist
		if (!file_exists($configFolder)) mkdir($configFolder, 0755 , true);

		//set path for client folder
		$clientFolder = $dataDir.'/clients/'.$clientID;
		//create client folder if it doesn't exist
		if (!file_exists($clientFolder)) mkdir($clientFolder, 0755 , true);


		//do some housekeeping on the client folder on the first upload call
		//set the clientSessionsHousekeeping key so the client will not attempt another pass
		if (!isset($data['clientSessionsHousekeeping'])){
			$toReturn['commands'][] = array('action'=>'setData','key'=>'clientSessionsHousekeeping','value'=>true);
			$folders = glob($clientFolder.'/*',GLOB_ONLYDIR);
			foreach ($folders as $folder){
				if (!file_exists($folder.'/ping') || filemtime($folder.'/ping') < strtotime("-1 day")){
					$files = glob($folder.'/*');
					foreach ($files as $file){
						unlink($file);
					}
					rmdir($folder);
				}
			}
		}

		//get the session id
		$data['sessionID'] = preg_replace("/[^0-9]/","",$data['sessionID']);
		if ($data['sessionID'] == ''){
			//fill the session id with something so it doesn't put trash files in random places
			$data['sessionID'] = hash('sha256',$_SERVER['HTTP_USER_AGENT']);
		}
		$clientFolder .= '/'.$data['sessionID'];
		if (!file_exists($clientFolder)) mkdir($clientFolder, 0755 , true);

		//ping file for status
		touch($clientFolder.'/ping');
		file_put_contents($clientFolder.'/ip',$_SERVER['REMOTE_ADDR']);
		//debug in
		if ($_config['debug']){
			file_put_contents($clientFolder.'/debug-in',json_encode($data,JSON_PRETTY_PRINT));
		}
		//screenshot
		$screenshot = '';
		if (isset($data['screenshot'])) {
			$screenshot = $data['screenshot'];
			$screenshot = str_replace('data:image/jpeg;base64,','',$screenshot);
			$screenshot = base64_decode($screenshot);
		}
		if ($screenshot != "") {
			file_put_contents($clientFolder.'/screenshot.jpg',$screenshot);
		} elseif (file_exists($clientFolder.'/screenshot.jpg')) {
			unlink($clientFolder.'/screenshot.jpg');
		}

		//document some stuff
		foreach (array('username','version','domain') as $field) {
			if (isset($data[$field]) && $data[$field] != "") {
				file_put_contents($clientFolder.'/'.$field,$data[$field]);
			} elseif (file_exists($clientFolder.'/'.$field)) {
				unlink($clientFolder.'/'.$field);
			}
		}

		//not fully fleshed out but here is the basic way to force the client to reset extension
		//without going into a reset loop
		//if ($resetClient && !isset($data['local']['reset'])){
		//	$toReturn['commands'][] = array('action'=>'setLocalData','key'=>'reset','value'=>time());
		//	$toReturn['commands'][] = array('action'=>'reset');
		//}

		//tabs
		if (isset($data['tabs'])) {
			file_put_contents($clientFolder.'/tabs',json_encode($data['tabs']));
		} elseif (file_exists($clientFolder.'/tabs')) {
			unlink($clientFolder.'/tabs');
		}

		//send commands back
		$toReturn['commands'][] = array('action'=>'changeRefreshTime','time'=>$_config['uploadRefreshTime']);
		$toReturn['commands'][] = array('action'=>'changeScreenscrapeTime','time'=>$_config['screenscrapeTime']);
		if (file_exists($clientFolder.'/openurl')) {
			$urls = file_get_contents($clientFolder.'/openurl');
			$urls = explode("\n",$urls);
			foreach ($urls as $i=>$url) {
				if ((isset($data['tabs']) && count($data['tabs']) > 0) || $i > 0) {
					$toReturn['commands'][] = array('action'=>'tabsCreate','data'=>array('url'=>$url));
				} else {
					$toReturn['commands'][] = array('action'=>'windowsCreate','data'=>array('url'=>$url));
				}
			}
			unlink($clientFolder.'/openurl');
		}
		if (file_exists($configFolder.'/filtermode')) {
			$filtermode = file_get_contents($configFolder.'/filtermode');
			$filterlist = '';
			$filterlisttime = 0;

			if ($filtermode == 'defaultdeny' && file_exists($configFolder.'/filterlist-defaultdeny')){
				$filterlisttime = filemtime($configFolder.'/filterlist-defaultdeny');
				$filterlist = file_get_contents($configFolder.'/filterlist-defaultdeny');
			} elseif ($filtermode == 'defaultallow' && file_exists($configFolder.'/filterlist-defaultallow')) {
				$filterlisttime = filemtime($configFolder.'/filterlist-defaultallow');
				$filterlist = file_get_contents($configFolder.'/filterlist-defaultallow');
			}
			$filterlist = explode("\n",$filterlist);

			foreach ($filterlist as $i=>$value) {
				if ($value == "") {
					unset($filterlist[$i]);
				} elseif (substr($value,0,6) == 'regex:') {
					$filterlist[$i] = substr($value,6);
				} else {
					$filterlist[$i] = preg_replace('/[^A-Za-z0-9_]/','\\\\$0',$value);
				}
			}

			if ($filtermode == 'defaultdeny' && count($filterlist) > 0) {
				//always allow the new tab page so they can atleast open the browser
				$filterlist[] = "^https\\:\\/\\/www\\.google\\.com\\/\\_\\/chrome\\/newtab";
				$filterlist[] = "^https\\:\\/\\/ogs\\.google\\.com\\/";
				$filterlist[] = "^chrome\\:\\/\\/newtab\\/";
				//always allow the google signin page for google
				$filterlist[] = "^https\\:\\/\\/accounts\\.google\\.com\\/";
				//always allow blank loading pages
				$filterlist[] = "^$";
			}

			if (($data['filtermode'] ?? '') != $filtermode || ($data['filterlisttime'] ?? 0) < $filterlisttime) {
				$toReturn['commands'][] = array('action'=>'setData','key'=>'filtermode','value'=>$filtermode);
				$toReturn['commands'][] = array('action'=>'setData','key'=>'filterlist','value'=>$filterlist);
				$toReturn['commands'][] = array('action'=>'setData','key'=>'filterlisttime','value'=>$filterlisttime);
			}
			//test here for tabs that need dropped for filtering policy
			if (isset($data['tabs'])) {
				foreach ($data['tabs'] as $tabs=>$tab) {
					$foundMatch = false;
					//test each tab against the filterlist
					foreach ($filterlist as $i=>$value) {
						$foundMatch = preg_match("/$value/i", $tab['url']);
						if ($foundMatch) {
							break;
						}
					}
					if (($filtermode == 'defaultdeny' && !$foundMatch) || ($filtermode == 'defaultallow' && $foundMatch)) {
						//filter violation found so append to closetab
						file_put_contents($clientFolder.'/closetab',$tab['id']."\n",FILE_APPEND);
						//notify the user we dropped the tab
						file_put_contents($clientFolder.'/messages',$_config['filterMessage']["title"]."\t".$_config['filterMessage']["message"]["opentab"].$tab['url']."\n",FILE_APPEND);
					}
				}
			}
		}
		//give the option to force all the tabs to the same window
		if ($_config['forceSingleWindow']){
			$windowId = false;
			foreach ($data['tabs'] as $tab){
				if ($windowId === false){
					$windowId = $tab['windowId'];
				} elseif ($tab['windowId'] != $windowId && substr($tab['url'],0,9) != 'chrome://') {
					$toReturn['commands'][] = array('action'=>'tabsMove','tabId'=>$tab['id'],'data'=>array('windowId'=>$windowId,'index'=>-1));
				}
			}
		}
		//give the option to force all windows to a maximized state
		//otherwise a student can open two windows side by side and the teacher would never know
		if ($_config['forceMaximizedWindow']){
			$windowIDs = array();
			foreach ($data['tabs'] as $tab){
				if (!in_array($tab['windowId'],$windowIDs)){
					$windowIDs[] = $tab['windowId'];
				}
				foreach($windowIDs as $windowId){
					$toReturn['commands'][] = array('action'=>'windowsUpdate','windowId'=>$windowId,'data'=>array('state'=>'maximized'));
				}
			}
		}
		if (file_exists($clientFolder.'/closetab')) {
			$tabs = file_get_contents($clientFolder.'/closetab');
			$tabs = explode("\n",$tabs);
			foreach ($tabs as $tab){
				if ($tab != "") {
					$tab = intval($tab);
					$toReturn['commands'][] = array('action'=>'tabsRemove','tabId'=>$tab);
				}
			}
			unlink($clientFolder.'/closetab');
		}
		if ((!isset($data['lock']) || !$data['lock']) && file_exists($clientFolder.'/lock')) {
			//avoid locking with stale lock file
			if (filemtime($clientFolder.'/lock') <= time() - $_config['lockTimeout'] ) {
				unlink($clientFolder.'/lock');
			} else {
				$toReturn['commands'][] = array('action'=>'lock');
			}
		}
		if (isset($data['lock']) && $data['lock'] && !file_exists($clientFolder.'/lock')) {
			$toReturn['commands'][] = array('action'=>'unlock');
		}

		if (file_exists($clientFolder.'/messages')) {
			$messages = file_get_contents($clientFolder.'/messages');
			$messages = explode("\n",$messages);
			foreach ($messages as $message) {
				$message = explode("\t",$message);
				if (count($message) == 2 && $message[0] != '' && $message[1] != '') {
					$toReturn['commands'][] = array('action'=>'sendNotification','data'=>array(
						'requireInteraction'=>true,
						'type'=>'basic',
						'iconUrl'=>'icon.png',
						'title'=>$message[0],
						'message'=>$message[1],
					));
				}
			}
			unlink($clientFolder.'/messages');
		}

		//populate filtermessage
		if (!array_key_exists('filtermessage',$data)){
			$toReturn['commands'][] = array('action'=>'setData','key'=>'filtermessage','value'=>array(
				'requireInteraction'=>true,
				'type'=>'basic',
				'iconUrl'=>'icon.png',
				'title'=>$_config['filterMessage']['title'],
				'message'=>$_config['filterMessage']['message']['newtab'],
			));
		}

	} else {
		//clientID not set

		//up the refresh since this is probably a personal or atleast non-chromebook device and
		//we don't really care except for maybe eventually enabling the filter so it follows them even on those devices
		$toReturn['commands'][] = array('action'=>'changeRefreshTime','time'=>10*60*1000);
	}

	//(de)activate server side filter
	if ($_config['filterviaserver'] != $data['filterviaserver'])
		$toReturn['commands'][] = array('action'=>'setData','key'=>'filterviaserver','value'=>$_config['filterviaserver']);

	//(de)activate screen scrapeer
	if ($_config['screenscrape'] != $data['screenscrape'])
		$toReturn['commands'][] = array('action'=>'setData','key'=>'screenscrape','value'=>$_config['screenscrape']);

	//update resource types that the filter processes
	if (implode($_config['filterresourcetypes']) != implode($data['filterresourcetypes']))
		$toReturn['commands'][] = array('action'=>'setData','key'=>'filterresourcetypes','value'=>$_config['filterresourcetypes']);


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

	//refresh tabs on load so that we can be sure the filter sees them
	if (!isset($data['onLoadRefreshed'])){
		if (isset($data['tabs'])) {
			foreach ($data['tabs'] as $tab) {
				$toReturn['commands'][] = array('action'=>'tabsUpdate','tabId'=>$tab['id'],'data'=>array('url'=>$tab['url']));
			}
		}
		$toReturn['commands'][] = array('action'=>'setData','key'=>'onLoadRefreshed','value'=>true);
	}

	//big clear the cache on startup (including cookies)
	if ($_config['cacheCleanupOnStartup'] && !isset($data['cacheClearedOnStartup'])){
		$toReturn['commands'][] = array('action'=>'removeBrowsingData','options'=>array('since'=>0),'dataToRemove'=>array(
			'appcache'=>true,
			'cache'=>true,
			'cacheStorage'=>true,
			'cookies'=>true,
			'fileSystems'=>true,
			'indexedDB'=>true,
			'localStorage'=>true,
			'serviceWorkers'=>true,
			'webSQL'=>true
		));
		$toReturn['commands'][] = array('action'=>'setData','key'=>'cacheClearedOnStartup','value'=>true);
	}

	//tiny cache regularly (no cookies)
	if ($_config['cacheCleanupTime'] > 0 && (!isset($data['cacheLastCleared']) || $data['cacheLastCleared'] < time() - $_config['cacheCleanupTime']) ){
		$toReturn['commands'][] = array('action'=>'removeBrowsingData','options'=>array('since'=>0),'dataToRemove'=>array(
			'appcache'=>true,
			'cache'=>true,
			'cacheStorage'=>true,
			'fileSystems'=>true,
		));
		$toReturn['commands'][] = array('action'=>'setData','key'=>'cacheLastCleared','value'=>time());
	}
}

//allow custom hooking here
//make sure to set restrictive permissions on this file
if (file_exists($dataDir.'/custom-upload-append.php'))
	include($dataDir.'/custom-upload-append.php');

//debug out
if ($_config['debug'] && isset($clientFolder) && $clientFolder != ''){
	file_put_contents($clientFolder.'/debug-out',json_encode($toReturn,JSON_PRETTY_PRINT));
}


//send it back
header('Content-Type: application/json');
die(json_encode($toReturn));
