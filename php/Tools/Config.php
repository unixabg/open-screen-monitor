<?php
namespace OSM\Tools;

class Config {
	private static $config;
	public static function get($name = null){
		if (is_null(self::$config)){
			//Don't modify these values in this script. Use config.json in $dataDir instead.
			self::$config = [];

			//set system wide version for php scripts
			self::$config['version']='0.4.0.3';

			//set the default time chrome will wait between phone home attempst to the upload script
			self::$config['uploadRefreshTime']=9000;

			//set the default time chrome will scan the active tab for flagged words
			self::$config['screenscrapeTime']=20000;

			//set lock file timeout to avoid locking on stale lock request
			self::$config['lockTimeout']=300;

			//how long a user that is pulled into a class is in there before it times out
			self::$config['userGroupTimeout'] = 28800; //60*60*8

			//how long a user that is pulled into bypass is in there before it times out
			self::$config['bypassTimeout'] = 28800; //60*60*8

			//how long a web session lasts before it times out
			self::$config['sessionTimeout'] = 28800; //60*60*8

			//set the OSM lab filter message
			self::$config['filterMessage'] = [
			        'title' => 'OSM Server says ... ',
			        'message' => [
			                'newtab' => 'A lab filter violation was detected on the url request of: ',
			                'opentab' => 'A lab filter violation was detected on an existing tab url of: '
			        ]
			];

			self::$config['deviceLastUserLookback'] = 7;
			self::$config['showStartupNotification'] = false;
			self::$config['showNonEnterpriseDevices'] = false;
			self::$config['filterResourceTypes'] = ["main_frame","sub_frame","xmlhttprequest","trigger_exempt"];
			self::$config['filterViaServer'] = false;
			self::$config['filterViaServerGroupIgnoreNonEnterprise'] = true;
			self::$config['filterviaserverShowBlockPage'] = false;
			self::$config['filterviaserverDefaultFilterTypes'] = ['main_frame','sub_frame'];
			self::$config['filterviaserverDefaultTriggerTypes'] = ['main_frame','sub_frame'];
			self::$config['enableGoogleClassroom'] = false;
			self::$config['enableOneRoster'] = false;
			self::$config['enableLab'] = true;
			self::$config['screenscrape'] = false;
			self::$config['cacheCleanupOnStartup'] = false;
			self::$config['cacheCleanupTime'] = 0;
			self::$config['cacheCleanupExclude'] = [];
			self::$config['disableGroups'] = false;
			self::$config['debug'] = true;
			self::$config['oneRosterUserIgnore'] = [];
			self::$config['apiSecrets'] = [];
			self::$config['allTeachersGetBypass'] = true;

			//overlay settings from database
			$query = DB::select('tbl_config');
			foreach($query as $row){
				$configname = $row['name'];
				$configvalue = json_decode($row['value'],true);

				//only pull values for keys we have already defined
				if (!isset(self::$config[ $configname ])){continue;}

				self::$config[ $configname ] = $configvalue;
			}

		}

		return is_null($name) ? self::$config : self::$config[$name];
	}

	public static function getGroup($groupID){
		$config = [
			'forceSingleWindow' => false,
			'forceMaximizedWindow' => false,
			'filtermode' => 'defaultallow',
			'filterlist-defaultdeny' => '',
			'filterlist-defaultallow' => '',
			'lastUpdated' => 0,
		];

		$rows = DB::select('tbl_group_config',['fields'=>['groupid'=>$groupID]]);
		foreach($rows as $row){
			if (isset($config[ $row['name'] ])){
				$config[ $row['name'] ] = $row['value'];
			}
		}

		$config['filterID'] = $groupID;

		return $config;
	}

	public static function getGroupFromSession($sessionID){
		//check for if we config non-enterprise devices
		$deviceID = \OSM\Tools\TempDB::get('deviceID/'.$sessionID);
		if ($deviceID == 'non-enterprise-device' && \OSM\Tools\Config::get('filterViaServerGroupIgnoreNonEnterprise')){
			\OSM\Tools\TempDB::del('groupID/'.$sessionID);
			return false;
		}


		$email = \OSM\Tools\TempDB::get('email/'.$sessionID);

		//look for a temp bypass
		//this doesn't get set so that when the bypass is over,
		//it instantly goes back to where it should have been
		if ($bypass = \OSM\Tools\TempDB::get('bypass/'.bin2hex($email))){
			return self::getGroup('bypass{'.$bypass.'}');
		}

		//return group if set
		if ($groupID = \OSM\Tools\TempDB::get('groupID/'.$sessionID)){
			return self::getGroup($groupID);
		}

		//look for a default group id by client and set
		if ($groupID = \OSM\Tools\TempDB::get('groupID-userDefault/'.bin2hex($email))){
			\OSM\Tools\TempDB::set('groupID/'.$sessionID, $groupID, \OSM\Tools\Config::get('userGroupTimeout'));
			return self::getGroup($groupID);
		}

		//otherwise look for a device group
		$deviceID = \OSM\Tools\TempDB::get('deviceID/'.$sessionID);
		$lab = \OSM\Tools\TempDB::get('lab/'.bin2hex($deviceID));
		if ($lab == ''){
			$rows = \OSM\Tools\DB::select('tbl_lab_device',['fields'=>['deviceid'=>$deviceID]]);
			$lab = ($rows[0]['path'] ?? '');
			if ($lab != ''){
				\OSM\Tools\TempDB::set('lab/'.bin2hex($deviceID), $lab, \OSM\Tools\Config::get('userGroupTimeout'));
			}
		}
		if ($lab != ''){
			return self::getGroup('lab{'.$lab.'}');
		}

		//todo figure out default
		return false;
	}

	public static function filterPath(){
		//put in clients folder so might be ramdisked
		return $GLOBALS['dataDir'].'clients/filter.json';
	}

	public static function refreshFilter(){
		$data = [
			'apps' => [],
			'entries' => [],
		];

		$rows = \OSM\Tools\DB::selectRaw('select * from tbl_filter_entry_group');
		foreach ($rows as $row){
			$data['apps'][ $row['appName'] ][] = $row['filterID'];
		}

		$data['entries'] = \OSM\Tools\DB::selectRaw('select * from tbl_filter_entry where enabled = 1 order by priority desc, appName asc, id asc');

		file_put_contents(static::filterPath(),json_encode($data,\JSON_PRETTY_PRINT), \LOCK_EX);
	}

	public static function getFilter(){
		if (!file_exists(static::filterPath())){
			static::refreshFilter();
		}
		return json_decode(file_get_contents(static::filterPath()),true);
	}
}
