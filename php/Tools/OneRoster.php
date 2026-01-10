<?php
namespace OSM\Tools;

class OneRoster {
	private static function newCurl($method, $url, $headersin = [], &$headersout = []){
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headersin);
		curl_setopt($curl, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headersout) {
			$len = strlen($header);
			$parts = explode(':', $header, 2);
			if (count($parts) < 2){
				if (count($headersout) == 0){
					$headersout['code'] = $header;
				}
				return $len;
			}
			$headersout[strtolower(trim($parts[0]))][] = trim($parts[1]);
			return $len;
		});
		return $curl;
	}


	private static function rest($method,$url,$headersin = []){
		$toReturn = [];
		while($url !== false){
			$headersout = [];
			$curl = self::newCurl($method, $url, $headersin, $headersout);
			$data = curl_exec($curl);
			$data = json_decode($data,true);
			$data = array_shift($data);

			foreach($data as $_data){
				$toReturn[] = $_data;
			}

			$url = false;

			//look for links
			$links = explode(',',($headersout['link'][0] ?? ''));
			foreach($links as $link){
				$link = explode('; ',$link);
				if (($link[1] ?? '') == 'rel="next"'){
					$url = substr($link[0],1,-1);
				}
			}
			if (isset($headersout['x-total-count']) && count($toReturn) == $headersout['x-total-count'][0]){
				break;
			}
		}
		return $toReturn;
	}

	private static function getConfig(){
		$file = $GLOBALS['dataDir'].'/oneroster.json';
		if (!file_exists($file)){
			throw new \Exception('OneRoster File missing');
		}
		$file = file_get_contents($file);
		$file = json_decode($file,true);
		return $file;
	}

	private static function login($config){
		$headersin = [
			'Content-Type: application/x-www-form-urlencoded',
			'Authorization: Basic '.base64_encode($config['clientID'].':'.$config['clientSecret']),
		];
		$headersout = [];

		$curl = static::newCurl('POST',$config['tokenURL'],$headersin,$headersout);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(['grant_type'=>'client_credentials']));
		$data = curl_exec($curl);

		$result = json_decode($data,true);
		return ['Authorization: Bearer '.$result['access_token']];

	}

	public static function downloadData(){
		$config = static::getConfig();
		$headersin = static::login($config);
		$now = strtotime('today');

		$users = [];
		$data = static::rest('GET',$config['baseURL'].'/users',$headersin);
		foreach($data as $row){
			if (!in_array($row['role'],['student','teacher'])){continue;}
			if (!isset($row['email'])){continue;}

			$users[ $row['sourcedId'] ] = [
				'role' => $row['role'],
				'email' => $row['email'],
				'name' => $row['givenName'].' '.$row['familyName'],
			];
		}

		$classes = [];
		$data = static::rest('GET',$config['baseURL'].'/classes',$headersin);
		foreach($data as $row){
			$classes[ $row['sourcedId'] ] = $row['title'];
		}

		$enrollments = [];
		$data = static::rest('GET',$config['baseURL'].'/enrollments',$headersin);
		foreach($data as $row){
			if ($row['status'] != 'active') {continue;}
			if (($row['beginDate'] ?? '') != '' && $now < strtotime($row['beginDate'])){continue;}
			if (($row['endDate'] ?? '') != '' && strtotime($row['endDate']) < $now){continue;}

			if (!($user = $users[ $row['user']['sourcedId'] ] ?? false)){continue;}
			if (!($className = $classes[ $row['class']['sourcedId'] ] ?? false)){continue;}

			$enrollments[] = [
				'role' => $row['role'],
				'name' => $user['name'],
				'email' => $user['email'],
				'class' => $className,
			];
		}

		return $enrollments;
	}
}
