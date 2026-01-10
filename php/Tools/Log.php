<?php
namespace OSM\Tools;

class Log {
	public static function add($event, $targetid = '', $data = ''){
		$data = json_encode($data);
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
