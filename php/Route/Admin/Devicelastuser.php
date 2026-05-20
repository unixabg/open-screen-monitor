<?php
namespace OSM\Route\Admin;

class Devicelastuser extends \OSM\Tools\Route {
	public function action(){
		$this->requireLogin();

		//admin
		$valid = $_SESSION['admin'];

		//lab permission
		if (!$valid){
			$valid = count($this->myLabs()) > 0;
		}

		//oneroster permission
		if (!$valid){
			$rows = \OSM\Tools\DB::select('tbl_oneroster',[
				'fields' => [
					'role' => 'teacher',
					'email' => $_SESSION['email'],
				],
			]);
			$valid = count($rows) > 0;
		}

		//google classroom permission
		if (!$valid){
			$valid = count($_SESSION['userLabNames'] ?? []) > 0;
		}

		if (!$valid){
			die('Permission Denied');
		}

		$this->css = '
			.content {max-width:800px;margin:auto;padding-top:40px;}
		';

		echo '<h2>Find Last User for Device</h2>';

		$devices = $this->deviceNames();
		asort($devices);

		echo '<form method="post">';
		echo 'Device Search:<br /><input type="text" name="devicesearch" value="'.htmlentities($_POST['devicesearch'] ?? '').'" placeholder="serial, asset, annotated user, location, device id" />';
		echo ' <input type="submit" value="Search" />';
		echo '</form>';

		if (isset($_POST['devicesearch']) && $_POST['devicesearch'] != ''){
			$search = $_POST['devicesearch'];
			$matchingDevices = \OSM\Tools\DB::select('tbl_lab_device',[
				'where' => 'annotateduser LIKE :ds1 OR annotatedlocation LIKE :ds2 OR annotatedassetid LIKE :ds3 OR serialnumber LIKE :ds4 OR deviceid LIKE :ds5',
				'bindings' => [
					':ds1' => '%'.$search.'%',
					':ds2' => '%'.$search.'%',
					':ds3' => '%'.$search.'%',
					':ds4' => '%'.$search.'%',
					':ds5' => '%'.$search.'%',
				],
			]);

			if (empty($matchingDevices)){
				echo '<p>No devices found matching: '.htmlentities($search).'</p>';
			}

			$dayLookBack = intval(\OSM\Tools\Config::get('deviceLastUserLookback'));

			foreach($matchingDevices as $device){
				$deviceid = $device['deviceid'];
				$nicename = $devices[$deviceid] ?? $deviceid;
				echo '<hr />';
				echo '<b>Annotated Info:</b> '.htmlentities($nicename);
				echo '<br /><b>Device ID:</b> '.htmlentities($deviceid);

				$found = false;
				for($i=0;$i<$dayLookBack;$i++){
					$day = strtotime('-'.$i.' days');
					$rows = \OSM\Tools\DB::select('tbl_filter_log',[
						'fields' => [
							'date' => date('Y-m-d',$day),
							'deviceid' => $deviceid,
						],
						'limit' => 1,
						'order' => 'time desc',
					]);

					if (isset($rows[0])){
						echo '<br /><b>Username:</b> '.htmlentities($rows[0]['username']);
						echo '<br /><b>Date:</b> '.htmlentities($rows[0]['date']);
						echo '<br /><b>Time:</b> '.htmlentities($rows[0]['time']);
						echo '<br /><b>IP:</b> '.htmlentities($rows[0]['ip']);
						$found = true;
						break;
					}
				}

				if (!$found){
					echo '<br /><b>Not found in the last '.$dayLookBack.' days</b>';
				}
			}

			\OSM\Tools\Log::add('admin.devicelastuser');
		}
	}
}
