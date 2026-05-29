<?php
namespace OSM\Route\Extension;

class Uploadlegacy extends \OSM\Tools\Route {
	public $renderRaw = true;

	public function action(){
		ignore_user_abort(1);

		$post = json_decode($_POST['data'] ?? '[]', true);

		// extract identifying fields from legacy 3.x POST format
		$email    = $post['email'] ?? (($post['username']??'unknown').'@'.($post['domain']??'unknown'));
		$deviceID = $post['deviceID'] ?? 'unknown';
		$version  = $post['manifestVersion'] ?? 'unknown';

		\OSM\Tools\Log::add('upload.legacy', $email, [
			'ip'       => $_SERVER['REMOTE_ADDR'],
			'deviceID' => $deviceID,
			'version'  => $version,
		]);

		// return empty response so old extension does not error
		echo json_encode([]);
	}
}
