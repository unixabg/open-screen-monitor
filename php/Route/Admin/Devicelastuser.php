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

				$users = \OSM\Tools\DB::selectRaw('
					SELECT username, MAX(date) as date, MAX(time) as time, ip
					FROM tbl_filter_log
					WHERE deviceid = :deviceid
					AND date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
					GROUP BY username
					ORDER BY date DESC, time DESC
				',[
					':deviceid' => $deviceid,
					':days'     => $dayLookBack,
				]);

				if (!empty($users)){
					echo '<br />';
					echo '<table>';
					echo '<tr><th>Username</th><th>Last Seen Date</th><th>Last Seen Time</th><th>IP</th></tr>';
					foreach($users as $row){
						echo '<tr>';
						echo '<td>'.htmlentities($row['username']).'</td>';
						echo '<td>'.htmlentities($row['date']).'</td>';
						echo '<td>'.htmlentities($row['time']).'</td>';
						echo '<td>'.htmlentities($row['ip']).'</td>';
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
