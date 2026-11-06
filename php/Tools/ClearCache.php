<?php
namespace OSM\Tools;

//clearing a user's browser cache and site data through the extension's
//removeBrowsingData command; used by Admin\Clearcache, the monitor's
//Clear Cache link, cacheCleanupOnStartup and pending requests in Extension\Upload
class ClearCache {
	//the two removeBrowsingData commands for a full clean: cache and cookies
	//(keeping cookies for cacheCleanupExclude), then local site storage
	public static function commands(){
		return [
			[
				'action'=>'removeBrowsingData',
				'options'=>[
					'since'=>0,
					'originTypes'=>[
						'unprotectedWeb'=>true,
						'protectedWeb'=>true,
					],
					'excludeOrigins'=>Config::get('cacheCleanupExclude')
				],
				'dataToRemove'=>[
					'appcache'=>true,
					'cache'=>true,
					'cacheStorage'=>true,
					'cookies'=>true,
				],
			],
			[
				'action'=>'removeBrowsingData',
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
			],
		];
	}

	//sessions of a user that checked in recently (the same test the monitor uses for online)
	public static function onlineSessions($email){
		$sessions = [];
		foreach (array_keys(TempDB::scan('ping-user/'.bin2hex($email).'/*')) as $key){
			$sessions[] = substr($key, strrpos($key, '/') + 1);
		}
		return $sessions;
	}

	//queue the clean for one session; picked up at its next check-in (expires after 5 minutes)
	public static function sendToSession($sessionID){
		static $counter = 0;
		$sessionID = preg_replace('/[^0-9a-z\-]/','',$sessionID);
		foreach (self::commands() as $cmd){
			$counter++;
			TempDB::set('command/'.$sessionID.'/'.time().'clearcache'.$counter, json_encode($cmd), 300);
		}
	}

	private static function pendingKey($email){
		return 'clearcache-pending/'.bin2hex($email);
	}

	//clear now on every online session; otherwise at the user's next check-in
	//returns ['sessions' => count] or ['pending' => expiry timestamp]
	public static function request($email){
		$sessions = self::onlineSessions($email);
		if (count($sessions) > 0){
			foreach ($sessions as $sessionID){
				self::sendToSession($sessionID);
			}
			self::cancel($email);
			return ['sessions' => count($sessions)];
		}

		$expires = time() + intval(Config::get('cacheClearPendingTimeout'));
		TempDB::set(self::pendingKey($email), json_encode([
			'requestedBy' => $_SESSION['email'] ?? '',
			'requested' => time(),
			'expires' => $expires,
		]), intval(Config::get('cacheClearPendingTimeout')));
		return ['pending' => $expires];
	}

	//requests waiting for the user to come online: email => request details
	public static function pending(){
		$pending = [];
		foreach (TempDB::scan('clearcache-pending/*') as $key => $value){
			$hex = substr($key, strrpos($key, '/') + 1);
			if ($hex === '' || strlen($hex) % 2 || !ctype_xdigit($hex)){continue;}
			$email = hex2bin($hex);
			$pending[$email] = json_decode($value, true) ?: [];
		}
		ksort($pending);
		return $pending;
	}

	public static function cancel($email){
		TempDB::del(self::pendingKey($email));
	}

	//called on every check-in: hand over a pending request once, to the first session that checks in
	public static function takePending($email){
		$request = TempDB::get(self::pendingKey($email));
		if ($request === null){return false;}
		self::cancel($email);
		return json_decode($request, true) ?: [];
	}
}
