<?php
namespace OSM\Route\Admin;

class Syncdevices extends \OSM\Tools\Route {
	public function action(){
		global $dataDir;

		$this->requireAdmin();

		//sync devices
		$context = stream_context_create(['http'=>[
			'method'=>'GET',
			'header'=>'Authorization: Bearer '.$_SESSION['token']->access_token,
		]]);

		//links below are a reference for request and response, which is used to populate the devices.tsv
		//https://developers.google.com/admin-sdk/directory/v1/reference/chromeosdevices/get
		//https://developers.google.com/admin-sdk/directory/v1/reference/chromeosdevices#resource
		$url = 'https://www.googleapis.com/admin/directory/v1/customer/my_customer/devices/chromeos?projection=full&maxResults=100';
		$data = file_get_contents($url, false, $context);
		if ($data !== false) {
			$data = json_decode($data,true);
			$devices = $data['chromeosdevices'];
			while (isset($data['nextPageToken']) && $data['nextPageToken'] != '') {
				$data = file_get_contents($url.'&pageToken='.urlencode($data['nextPageToken']), false, $context);
				if ($data === false) return false;
					$data = json_decode($data,true);
				$devices = array_merge($devices,$data['chromeosdevices']);
			}
			$syncedTimestamp = date('Y-m-d H:i:s');
			foreach ($devices as $device) {
				if ($device['status'] == 'ACTIVE') {
					\OSM\Tools\DB::replace('tbl_lab_device',[
						'deviceid'=>$device['deviceId'],
						'path'=>$device['orgUnitPath'],
						'user'=>trim($device['annotatedUser']),
						'location'=>trim($device['annotatedLocation']),
						'assetid'=>trim($device['annotatedAssetId']),
						'lastSynced'=>$syncedTimestamp,
					]);
				}
			}
			\OSM\Tools\DB::delete('tbl_lab_device',[
				'where'=>'lastSynced <> :lastSynced',
				'bindings'=>[':lastSynced'=>$syncedTimestamp],
			]);
			echo '<h1>Successfully synced devices</h1>';

			//allow custom hooking here
			//make sure to set restrictive permissions on this file
			if (file_exists($dataDir.'/custom/sync-append.php')){
				require_once($dataDir.'/custom/sync-append.php');
			}
		} else {
			echo "<h1>No access to chrome devices</h1>";
		}
	}
}
