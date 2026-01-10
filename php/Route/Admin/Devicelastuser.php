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

		if (isset($_POST['deviceid'])){
			echo '<b>Device:</b> '.htmlentities( ($devices[$_POST['deviceid']] ?? 'Unknown'));
			echo '<br /><b>Device ID:</b> '.htmlentities($_POST['deviceid']);
			$dayLookBack = intval(\OSM\Tools\Config::get('deviceLastUserLookback'));
			for($i=0;$i<$dayLookBack;$i++){
				$day = strtotime('-'.$i.' days');
				$rows = \OSM\Tools\DB::select('tbl_filter_log',[
					'fields' => [
						'date' => date('Y-m-d',$day),
						'deviceid' => $_POST['deviceid'],
					],
					'limit' => 1,
					'order' => 'time desc',
				]);

				if (isset($rows[0])){
					echo '<br /><b>Username:</b> '.htmlentities($rows[0]['username']);
					echo '<br /><b>Date:</b> '.htmlentities($rows[0]['date']);
					echo '<br /><b>Time:</b> '.htmlentities($rows[0]['time']);
					echo '<br /><b>IP:</b> '.htmlentities($rows[0]['ip']);
					break;
				}
			}

			if (!isset($rows[0])){
				echo '<br /><b>Not found in the last '.$dayLookBack.' days</b>';
			}


			\OSM\Tools\Log::add('admin.devicelastuser');
			echo '<br /><br />';
		}


		echo '<form method="post">';
		echo 'Device: <select name="deviceid">';
			echo '<option></option>';
			foreach($devices as $deviceid => $devicename){
				echo '<option value="'.htmlentities($deviceid).'">'.htmlentities($devicename).'</option>';
			}
			echo '</select>';
		echo ' <input type="submit" value="Search" />';
		echo '</form>';
	}
}
