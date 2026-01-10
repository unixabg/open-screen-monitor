<?php
namespace OSM\Tools;

class Google {
	private static $client;
	private static function getClient(){
		global $dataDir;

		if (is_null(self::$client)){
			//this will need to be setup per site
			//it should be downloaded from the api developers console after it is setup
			//see https://developers.google.com/identity/sign-in/web/sign-in
			$client_secret_file = $dataDir.'/client_secret.json';
			if (!file_exists($client_secret_file)) {
				die('Missing client_secret.json file');
			}
			self::$client = json_decode(file_get_contents($client_secret_file));
		}
		return self::$client;
	}

	public static function getLoginLink(){
		return 'https://accounts.google.com/o/oauth2/v2/auth?scope='
			.urlencode('profile email https://www.googleapis.com/auth/admin.directory.device.chromeos.readonly https://www.googleapis.com/auth/classroom.courses.readonly https://www.googleapis.com/auth/classroom.rosters.readonly https://www.googleapis.com/auth/classroom.profile.emails')
	                .'&response_type=code'
	                .'&client_id='.self::getClient()->web->client_id
	                .'&redirect_uri='.urlencode(self::getClient()->web->redirect_uris[0])
			.'&state='.random_int(100000,999999);
	}

	public static function getToken($code){
		$data = file_get_contents('https://www.googleapis.com/oauth2/v4/token', false, stream_context_create(['http' =>[
			'ignore_errors' => true,
			'method'=>'POST',
			'header'=>'Content-Type: application/x-www-form-urlencoded',
			'content' => http_build_query([
				'code' => $code,
				'client_id' => self::getClient()->web->client_id,
				'client_secret' => self::getClient()->web->client_secret,
				'redirect_uri'=> self::getClient()->web->redirect_uris[0],
				'grant_type'=>'authorization_code'
			]),
		]]));
		return json_decode($data);
	}

	public static function checkToken($token) {
		if ($token === false){return false;}
		if (!property_exists($token,'access_token')){return false;}

		$userinfo = file_get_contents('https://www.googleapis.com/oauth2/v1/userinfo',false,stream_context_create([
			'http' => [
				'ignore_errors' => true,
				'header' => ['Authorization: Bearer '.$token->access_token],
			],
		]));

		if ( substr($http_response_header[0], -7) != ' 200 OK') {
			return false;
		}

		$userinfo = json_decode($userinfo);

		if (!$userinfo->verified_email) {
			return false;
		}

		//they are good
		$sessionLength = \OSM\Tools\Config::get('sessionTimeout');

		TempDB::set('googlePicture/'.bin2hex($userinfo->email),file_get_contents($userinfo->picture),$sessionLength);

		//reset session unless same email and was already valid
		if ( ($_SESSION['email'] ?? '') != $userinfo->email || ($_SESSION['validuntil'] ?? 0) < time() ){
			$_SESSION = [];

			//helps show which devices are on a page
			$_SESSION['groups'] = [];

			//validates ability to monitor
			$_SESSION['clients'] = [];
		}

		$_SESSION['token'] = $token;
		$_SESSION['email'] = $userinfo->email;
		$_SESSION['name'] = $userinfo->name;
		$_SESSION['validuntil'] = time() + $sessionLength;

		//if no permissions have been setup, add this user as the first admin
		$permissions = \OSM\Tools\DB::select('tbl_lab_permission');
		if (count($permissions) == 0){
			\OSM\Tools\DB::insert('tbl_lab_permission',['username'=>$_SESSION['email'],'groupid'=>'admin']);
		}

		//check for admin permission
		$adminPermission = \OSM\Tools\DB::select('tbl_lab_permission',[
			'fields'=>[
				'username'=>$_SESSION['email'],
				'groupid'=>'admin',
			]
		]);
		$_SESSION['admin'] = (count($adminPermission) > 0);

		//check for oneroster permission
		$onerosterPermission = \OSM\Tools\DB::select('tbl_lab_permission',[
			'fields'=>[
				'username'=>$_SESSION['email'],
				'groupid'=>'oneroster',
			]
		]);
		$_SESSION['oneroster'] = $_SESSION['admin'] || (count($onerosterPermission) > 0);

		//check for bypass permission
		$bypassPermission = \OSM\Tools\DB::select('tbl_lab_permission',[
			'fields'=>[
				'username'=>$_SESSION['email'],
				'groupid'=>'bypass',
			]
		]);
		$_SESSION['bypass'] = $_SESSION['admin'] || (count($bypassPermission) > 0);

		return true;
	}
}
