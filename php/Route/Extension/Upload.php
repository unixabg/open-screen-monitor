<?php
namespace OSM\Route\Extension;

class Upload extends \OSM\Tools\Route {
	public $renderRaw = true;

	public function action(){
		//in case the http timeout kills the connection, still dump the data
		ignore_user_abort(1);


		if (!isset($_POST['data'])){
			http_response_code(404);
			die();
		}

		$dataDir = $GLOBALS['dataDir'];
		$toReturn = [];
		$data = json_decode($_POST['data'],true);

		//allow custom hooking here
		//make sure to set restrictive permissions on this file
		if (file_exists($dataDir.'/custom/upload-prepend.php')){
			include($dataDir.'/custom/upload-prepend.php');
		}

		//get the session id (everything is saved by sessionid)
		$sessionID = preg_replace('/[^0-9a-z\-]/','',($data['sessionID'] ?? ''));
		if ($sessionID == ''){
			$sessionID = 'server_'.bin2hex(random_bytes(15));
		}
		$sessionID = substr($sessionID,0,36);
		if ($sessionID != $data['sessionID']){
			$data['sessionID'] = $sessionID;
			$toReturn['commands'][] = ['action'=>'setData','key'=>'sessionID','value'=>$sessionID];
		}

		$deviceID = $data['deviceID'] ?? '';
		if ($deviceID == '') {
			$deviceID = 'non-enterprise-device';
			$toReturn['commands'][] = ['action'=>'setData','key'=>'deviceID','value'=>$deviceID];
			$data['deviceID'] = $deviceID;
		}
		$email = $data['email'] ?? '';
		if ($email == ''){$email = 'unknown';}

		// check allowed user domains
		$allowedUserDomains = \OSM\Tools\Config::get('allowedUserDomains');
		if ($allowedUserDomains != '') {
			$domains = array_map('trim', explode(',', $allowedUserDomains));
			$emailDomain = substr($email, strrpos($email, '@') + 1);
			if ($email == 'unknown' || !in_array($emailDomain, $domains)) {
				\OSM\Tools\Log::add('upload.denied', $email);
				http_response_code(403);
				die();
			}
		}

		// handle email mismatch reported by extension
		if ($data['emailMismatch'] ?? false) {
			$count = intval($data['emailMismatchCount'] ?? 1);
			$realEmail = $data['verifiedEmail'] ?? 'unknown';
			\OSM\Tools\Log::add('security.email_mismatch',
				'attempt #'.$count.' real: '.$realEmail.' attempting as: '.$email
			);
			$alertEmail = \OSM\Tools\Config::get('securityAlertEmail');
			if ($alertEmail != '' && \OSM\Tools\Config::get('enableSecurityAlerts') &&
				($count === 1 || $count % intval(\OSM\Tools\Config::get('securityAlertFrequency') ?: 10) === 0)) {
				mail($alertEmail,
					'OSM Security Alert - Email Mismatch (attempt #'.$count.')',
					'Real user: '.$realEmail."\nAttempting as: ".$email."\nDevice: ".$this->niceName($data['deviceID'] ?? '')."\nIP: ".$_SERVER['REMOTE_ADDR']."\nMismatch count: ".$count
				);
			}
		}

		//this is just to help keep collisions on the session id from happening
		//if these values change it may cause issues
		$sessionID .= '--'.md5($deviceID.$email);

		//this tells if client is active
		\OSM\Tools\TempDB::set('ping-device/'.bin2hex($deviceID).'/'.$sessionID, '');
		\OSM\Tools\TempDB::set('ping-user/'.bin2hex($email).'/'.$sessionID, '');

		//this keeps track of sessions for takeOverClass function
		\OSM\Tools\TempDB::set('sessions-device/'.bin2hex($deviceID).'/'.$sessionID, '', \OSM\Tools\Config::get('userGroupTimeout'));
		\OSM\Tools\TempDB::set('sessions-user/'.bin2hex($email).'/'.$sessionID, '', \OSM\Tools\Config::get('userGroupTimeout'));

		//debug in
		if (\OSM\Tools\Config::get('debug')){
			\OSM\Tools\TempDB::set('debug-in/'.$sessionID, json_encode($data,JSON_PRETTY_PRINT));
		}

		//screenshot
		$screenshot = '';
		if (isset($data['screenshot'])) {
			$screenshot = $data['screenshot'];
			$screenshot = str_replace('data:image/jpeg;base64,','',$screenshot);
			$screenshot = base64_decode($screenshot);
		}
		\OSM\Tools\TempDB::set('screenshot/'.$sessionID,$screenshot);

		//remove later
		if (!isset($data['email'])){$data['email'] = ($data['username']??'unknown').'@'.($data['domain']??'unknown');}

		//document some stuff
		$data['ip'] = $_SERVER['REMOTE_ADDR'];
		foreach (['deviceID','email','manifestVersion','ip'] as $field) {
			\OSM\Tools\TempDB::set($field.'/'.$sessionID, $data[$field]??'');
		}

		//tabs
		$tabs = [];
		foreach(($data['tabs'] ?? []) as $tab){
			$tabs[] = [
				'id'=>$tab['id'],
				'title'=>$tab['title'],
				'url'=>$tab['url'],
			];
		}
		\OSM\Tools\TempDB::set('tabs/'.$sessionID, json_encode($tabs));

		$locked = \OSM\Tools\TempDB::get('lock/'.$sessionID) != '';
		if ((!isset($data['lock']) || !$data['lock']) && $locked) {
			$toReturn['commands'][] = ['action'=>'lock'];
		}
		if (isset($data['lock']) && $data['lock'] && !$locked) {
			$toReturn['commands'][] = ['action'=>'unlock'];
		}

		$rows = \OSM\Tools\TempDB::scan('command/'.$sessionID.'/*');
		foreach($rows as $key => $value){
			$toReturn['commands'][] = json_decode($value,true);
			\OSM\Tools\TempDB::del($key);
		}

		//populate filtermessage
		if (!array_key_exists('filtermessage',$data)){
			$toReturn['commands'][] = ['action'=>'setData','key'=>'filtermessage','value'=>[
				'requireInteraction'=>true,
				'type'=>'basic',
				'iconUrl'=>'icon.png',
				'title'=>\OSM\Tools\Config::get('filterMessage')['title'],
				'message'=>\OSM\Tools\Config::get('filterMessage')['message']['newtab'],
			]];
		}


		//(de)activate disableGroups
		if (\OSM\Tools\Config::get('disableGroups') != ($data['disableGroups'] ?? false)){
			$toReturn['commands'][] = ['action'=>'setData','key'=>'disableGroups','value'=>\OSM\Tools\Config::get('disableGroups')];
			$toReturn['commands'][] = ['action'=>'ungroupTabs'];
		}

		//(de)activate server side filter
		if (\OSM\Tools\Config::get('filterViaServer') != ($data['filterViaServer'] ?? false)){
			$toReturn['commands'][] = ['action'=>'setData','key'=>'filterViaServer','value'=>\OSM\Tools\Config::get('filterViaServer')];
		}

		//(de)activate screen scrapeer
		if (\OSM\Tools\Config::get('screenscrape') != $data['screenscrape']){
			$toReturn['commands'][] = ['action'=>'setData','key'=>'screenscrape','value'=>\OSM\Tools\Config::get('screenscrape')];
		}

		//update resource types that the filter processes
		if (implode(\OSM\Tools\Config::get('filterResourceTypes')) != implode($data['filterResourceTypes'] ?? [])){
			$toReturn['commands'][] = ['action'=>'setData','key'=>'filterResourceTypes','value'=>\OSM\Tools\Config::get('filterResourceTypes')];
		}


		//show startup notification
		if (\OSM\Tools\Config::get('showStartupNotification') && !isset($data['startupNotification'])){
			$toReturn['commands'][] = ['action'=>'setData','key'=>'startupNotification','value'=>true];
			$toReturn['commands'][] = ['action'=>'sendNotification','data'=>[
				'type'=>'basic',
				'iconUrl'=>'icon.png',
				'title'=>'Open Screen Monitor starting...',
				'message'=>'The account '.$data['username'].'@'.$data['domain'].' is under a managed policy.',
			]];
		}

		//big clear the cache on startup (including cookies)
		if (\OSM\Tools\Config::get('cacheCleanupOnStartup') && !isset($data['cacheClearedOnStartup'])){
			$toReturn['commands'][] = ['action'=>'removeBrowsingData',
				'options'=>[
					'since'=>0,
					'originTypes'=>[
						'unprotectedWeb'=>true,
						'protectedWeb'=>true,
					],
					'excludeOrigins'=>\OSM\Tools\Config::get('cacheCleanupExclude')
				],
				'dataToRemove'=>[
					'appcache'=>true,
					'cache'=>true,
					'cacheStorage'=>true,
					'cookies'=>true,
				],
			];
			$toReturn['commands'][] = ['action'=>'removeBrowsingData',
				'options'=>[
					'since'=>0,
					'originTypes'=>[
						'unprotectedWeb'=>true,
						'protectedWeb'=>true,
					],
				],
				'dataToRemove'=>[
					'fileSystems'=>true,
					'indexedDB'=>true,
					'localStorage'=>true,
					'serviceWorkers'=>true,
					'webSQL'=>true,
				],
			];
			$toReturn['commands'][] = ['action'=>'setData','key'=>'cacheClearedOnStartup','value'=>true];
		}

		//tiny cache regularly (no cookies)
		if (\OSM\Tools\Config::get('cacheCleanupTime') > 0 && (!isset($data['cacheLastCleared']) || $data['cacheLastCleared'] < time() - \OSM\Tools\Config::get('cacheCleanupTime')) ){
			$toReturn['commands'][] = ['action'=>'removeBrowsingData',
				'options'=>[
					'since'=>0,
					'originTypes'=>[
						'unprotectedWeb'=>true,
						'protectedWeb'=>true,
					],
					'excludeOrigins'=>\OSM\Tools\Config::get('cacheCleanupExclude')
				],
				'dataToRemove'=>[
					'appcache'=>true,
					'cache'=>true,
					'cacheStorage'=>true,
				],
			];
			$toReturn['commands'][] = ['action'=>'setData','key'=>'cacheLastCleared','value'=>time()];
		}

		//set syncing times
		$time = \OSM\Tools\Config::get('uploadRefreshTime');
		if (($data['refreshTime'] ?? '') != $time){
			$toReturn['commands'][] = ['action'=>'changeRefreshTime','time'=>$time];
		}

		$time = \OSM\Tools\Config::get('screenscrapeTime');
		if (($data['screenscrapeTime'] ?? '') != $time){
			$toReturn['commands'][] = ['action'=>'changeScreenscrapeTime','time'=>$time];
		}

		$filterID = '__OSMDEFAULT__';
		if (($groupConfig = \OSM\Tools\Config::getGroupFromSession($sessionID)) !== false) {
			//give the option to force all the tabs to the same window
			if ($groupConfig['forceSingleWindow']){
				$windowId = false;
				foreach ($data['tabs'] as $tab){
					if ($windowId === false){
						$windowId = $tab['windowId'];
					} elseif ($tab['windowId'] != $windowId && substr($tab['url'],0,9) != 'chrome://') {
						$toReturn['commands'][] =['action'=>'tabsMove','tabId'=>$tab['id'],'data'=>['windowId'=>$windowId,'index'=>-1]];
					}
				}
			}

			//give the option to force all windows to a maximized state
			//otherwise a student can open two windows side by side and the teacher would never know
			if ($groupConfig['forceMaximizedWindow']){
				$windowIDs = [];
				foreach ($data['tabs'] as $tab){
					if (!in_array($tab['windowId'],$windowIDs)){
						$windowIDs[] = $tab['windowId'];
					}
				}
				foreach($windowIDs as $windowId){
					$toReturn['commands'][] = ['action'=>'windowsUpdate','windowId'=>$windowId,'data'=>['state'=>'maximized']];
				}
			}

			//get filter id if set
			if ($groupConfig['filterID'] != ''){
				$filterID = $groupConfig['filterID'];
			}
		}
		if (($data['filterID'] ?? '') != $filterID){
			$toReturn['commands'][] = ['action'=>'setData','key'=>'filterID','value'=>$filterID];

			//refresh tabs on filterID change so that we can be sure the filter sees them
			//	the extension defaults filterID to '' so this will also take effect on load
			//	after cacheCleanupOnStartup so the pages don't have their cache cleaned out from under them
			//	after setData for filterID so the correct rules are used when they are refreshed
			if (isset($data['tabs'])) {
				foreach ($data['tabs'] as $tab) {
					$toReturn['commands'][] = ['action'=>'tabsReload','tabId'=>$tab['id'],'data'=>['bypassCache'=>true]];
				}
			}
		}


		//allow custom hooking here
		//make sure to set restrictive permissions on this file
		if (file_exists($dataDir.'/custom/upload-append.php')){
			include($dataDir.'/custom/upload-append.php');
		}

		//debug out
		if (\OSM\Tools\Config::get('debug')){
			\OSM\Tools\TempDB::set('debug-out/'.$sessionID, json_encode($toReturn,JSON_PRETTY_PRINT));
		}

		//send it back
		header('Content-Type: application/json');
		die(json_encode($toReturn));
	}
}
