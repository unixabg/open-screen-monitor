<?php
namespace OSM\Tools;

class Log {
	public static function add($event, $targetid = '', $data = ''){
		//unescaped slashes keep URLs and user agents readable and searchable in the log viewer;
		//invalid UTF-8 (text cut mid-character) is replaced instead of failing the whole entry
		$data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		DB::insert('tbl_log',[
			'timestamp' => date('Y-m-d H:i:s'),
			'ip' => $_SERVER['REMOTE_ADDR'],
			'username' => $_SESSION['email'] ?? '',
			'type' => $event,
			'targetid' => $targetid,
			'data' => $data,
		]);
		return $data;
	}
}
