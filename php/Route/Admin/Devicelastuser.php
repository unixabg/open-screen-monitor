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

		$dayLookBack = intval(\OSM\Tools\Config::get('deviceLastUserLookback'));

		echo '<h2>Find Users for Device</h2>';
		echo '<p>Showing all users active on device within the last '.$dayLookBack.' days.</p>';

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

			foreach($matchingDevices as $device){
				$deviceid = $device['deviceid'];
				$nicename = $devices[$deviceid] ?? $deviceid;
				echo '<hr />';
				echo '<b>Annotated Info:</b> '.htmlentities($nicename);
				echo '<br /><b>Device ID:</b> '.htmlentities($deviceid);

				$users = [];
				for($i=0;$i<$dayLookBack;$i++){
					$day = strtotime('-'.$i.' days');
					$rows = \OSM\Tools\DB::select('tbl_filter_log',[
						'fields' => [
							'date' => date('Y-m-d',$day),
							'deviceid' => $deviceid,
						],
						'order' => 'time desc',
					]);
					foreach($rows as $row){
						if (!isset($users[$row['username']])){
							$users[$row['username']] = [
								'date' => $row['date'],
								'time' => $row['time'],
								'ip'   => $row['ip'],
							];
						}
					}
				}

				if (!empty($users)){
					echo '<br />';
					echo '<table>';
					echo '<tr><th>Username</th><th>Last Seen Date</th><th>Last Seen Time</th><th>IP</th></tr>';
					foreach($users as $username => $info){
						echo '<tr>';
						echo '<td>'.htmlentities($username).'</td>';
						echo '<td>'.htmlentities($info['date']).'</td>';
						echo '<td>'.htmlentities($info['time']).'</td>';
						echo '<td>'.htmlentities($info['ip']).'</td>';
						echo '</tr>';
					}
					echo '</table>';
				} else {
					echo '<br /><b>No activity found in the last '.$dayLookBack.' days</b>';
				}
			}

			\OSM\Tools\Log::add('admin.devicelastuser');
		}
	}
}
