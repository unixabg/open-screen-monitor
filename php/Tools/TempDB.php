<?php
namespace OSM\Tools;

class TempDB {
	public static function sanitizeFileKey($key){
		$key = preg_replace('[^0-9a-z/\-\*]','',$key);
		return $key;
	}

	public static function set($key,$data,$expires = 100){
		$key = static::sanitizeFileKey($key);

		$file = $GLOBALS['dataDir'].'/clients/'.$key;
		$dir = dirname($file);
		if (!file_exists($dir)){
			mkdir($dir,0777,true);
		}
		file_put_contents($file,$data);
		touch($file, time() + $expires);
	}

	public static function get($key){
		$key = static::sanitizeFileKey($key);

		$file = $GLOBALS['dataDir'].'/clients/'.$key;
		if (file_exists($file) && time() < filemtime($file)){
			return file_get_contents($file);
		}
	}

	public static function del($key){
		$key = static::sanitizeFileKey($key);

		$file = $GLOBALS['dataDir'].'/clients/'.$key;
		if (file_exists($file)){
			unlink($file);
		}
	}

	public static function scan($query,$debug = false){
		$data = [];

		$query = static::sanitizeFileKey($query);

		$root = $GLOBALS['dataDir'].'/clients/';
		$files = glob($root.$query);
		if ($debug){echo "hello\n";print_r([$root,$query,$files]);}
		foreach($files as $file){
			if (!is_file($file)){continue;}
			if (time() < filemtime($file)){
				$key = substr($file,strlen($root));
				$data[$key] = file_get_contents($file);
			}
		}
		return $data;
	}
}
