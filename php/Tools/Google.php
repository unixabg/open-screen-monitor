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
		$data = @file_get_contents('https://www.googleapis.com/oauth2/v3/tokeninfo?id_token='.urlencode($token->id_token));
		if ( substr($http_response_header[0], -7) == ' 200 OK') {
			$data = json_decode($data);

			if ($data->aud == self::getClient()->web->client_id && $data->exp > time() && $data->iss == 'https://accounts.google.com') {
				//they are good
				$_SESSION = [];
				$_SESSION['token'] = $token;
				$_SESSION['email'] = $data->email;
				$_SESSION['name'] = $data->name;

				//this is needed on the monitor page to check if they are authenticated
				//we have too many requests there to constantly hit googles servers (they would blacklist us)
				//$_SESSION['validuntil'] = $data->exp;
				$_SESSION['validuntil'] = strtotime('+12 hours');

				//check for admin permission
				$adminPermission = \OSM\Tools\DB::select('tbl_lab_permission',[
					'fields'=>[
						'username'=>$_SESSION['email'],
						'groupid'=>'admin',
					]
				]);
				$_SESSION['admin'] = (count($adminPermission) > 0);
				//helps show which devices are on a page
				$_SESSION['groups'] = [];
				//validates ability to monitor
				$_SESSION['clients'] = [];

				return true;
			}
		}
		return false;
	}
}
