<?php
namespace OSM\Tools;

class Config {
	private static $config;
	public static function get($name = null){
		if (is_null(self::$config)){
			//Don't modify these values in this script. Use config.json in $dataDir instead.
			self::$config = [];

			//set system wide version for php scripts
			self::$config['version']='0.3.0.1-next';

			//set the default time chrome will wait between phone home attempst to the upload script
			self::$config['uploadRefreshTime']=9000;

			//set the default time chrome will scan the active tab for flagged words
			self::$config['screenscrapeTime']=20000;

			//set lock file timeout to avoid locking on stale lock request
			self::$config['lockTimeout']=300;

			//how long a user that is pulled into a class is in there before it times out
			self::$config['userGroupTimeout'] = 28800; //60*60*8

			//set the OSM lab filter message
			self::$config['filterMessage'] = [
			        'title' => 'OSM Server says ... ',
			        'message' => [
			                'newtab' => 'A lab filter violation was detected on the url request of: ',
			                'opentab' => 'A lab filter violation was detected on an existing tab url of: '
			        ]
			];

			self::$config['showStartupNotification'] = false;
			self::$config['showNonEnterpriseDevices'] = false;
			self::$config['filterResourceTypes'] = ["main_frame","sub_frame","xmlhttprequest","trigger_exempt"];
			self::$config['filterViaServer'] = false;
			self::$config['filterviaserverShowBlockPage'] = false;
			self::$config['filterviaserverDefaultFilterTypes'] = ['main_frame','sub_frame'];
			self::$config['filterviaserverDefaultTriggerTypes'] = ['main_frame','sub_frame'];
			self::$config['enableGoogleClassroom'] = false;
			self::$config['enableOneRoster'] = false;
			self::$config['enableLab'] = true;
			self::$config['screenscrape'] = false;
			self::$config['cacheCleanupOnStartup'] = false;
			self::$config['cacheCleanupTime'] = 0;
			self::$config['debug'] = true;

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

		return $config;
	}

	public static function getGroupFromSession($sessionID){
		$groupID = \OSM\Tools\TempDB::get('groupID/'.$sessionID);
		if ($groupID != ''){
			//if we found a group then just return it
			$group = self::getGroup($groupID);
			$group['filterID'] = $groupID;
			return $group;
		}

		//otherwise look for a device group and set
		$deviceID = \OSM\Tools\TempDB::get('deviceID/'.$sessionID);
		$rows = \OSM\Tools\DB::select('tbl_lab_device',['fields'=>['deviceid'=>$deviceID]]);
		if ($groupID = ($rows[0]['path'] ?? false)){
			$groupID = 'lab{'.$groupID.'}';
			$group = self::getGroup($groupID);
			\OSM\Tools\TempDB::set('groupID/'.$sessionID, $groupID, \OSM\Tools\Config::get('userGroupTimeout'));
			$group['filterID'] = $groupID;
			return $group;
		}

		//todo figure out default
		return false;
	}
}
