<?php
namespace OSM\Route\Admin;

class Usagereport extends \OSM\Tools\Route {
	public function action(){
		$this->requireAdmin();

		set_time_limit(0);

		echo '<form method="post">Date: <input type="date" name="date" /> <input type="submit" /></form>';

		if (isset($_POST['date'])){
			echo '<div style="height: 600px;width: 1200px;" id="total_count_div"></div>';
			$dateIn = ($_POST['date'] != '' ? strtotime($_POST['date']) : time());

			$data = \OSM\Tools\DB::selectRaw('SELECT COUNT(DISTINCT username) as count FROM tbl_filter_log WHERE date = :date',[
				':date' => date('Y-m-d',$dateIn),
			]);
			$activeUsers = $data[0]['count'];

			$data = \OSM\Tools\DB::selectRaw('SELECT sec_to_time(Floor(time_to_sec(time)/(5*60))*(5*60)) as roundedTime, COUNT(DISTINCT username) as count FROM tbl_filter_log WHERE date = :date GROUP BY roundedTime ORDER BY roundedTime ASC',[
				':date' => date('Y-m-d',$dateIn),
			]);
			$jsdata = [['Dates','']];
			foreach($data as $row){
				$jsdata[] = [
					date("g:i a", strtotime($row['roundedTime'])),
					$row['count'],
				];
			}

			echo '<script type="text/javascript">
			google.charts.load("current", {packages: ["corechart", "line"]});
			google.charts.setOnLoadCallback(drawCurveTypes);
			function drawCurveTypes() {
				var jsdata = '.json_encode([
					'divid'=>'total_count_div',
					'vtitle'=>'Devices',
					'data'=>$jsdata,
					'title'=>'Usage'
				]).';
				//document.write("<div style=\"height: 600px;width: 1200px;\" id=\"" + jsdata.divid + "\"></div>");
				var data = google.visualization.arrayToDataTable(jsdata.data);
				var chart = new google.visualization.LineChart(document.getElementById(jsdata.divid));
				chart.draw(data,{hAxis:{title:"Date ('.date('Y-m-d',$dateIn).')\nActive users ('.$activeUsers.')"},vAxis:{textStyle:{fontSize: 20},title:jsdata.vtitle},legend:{position:"none"}});
			}
			</script>';

			//per-user estimated screen time
			$sort = $_POST['sort'] ?? 'minutes';
			$sort = ($sort == 'username') ? 'username asc' : 'minutes desc';

			$userData = \OSM\Tools\DB::selectRaw('
				SELECT username, COUNT(DISTINCT roundedTime) * 5 as minutes
				FROM (
					SELECT username, sec_to_time(Floor(time_to_sec(time)/(5*60))*(5*60)) as roundedTime
					FROM tbl_filter_log
					WHERE date = :date
					GROUP BY username, roundedTime
				) buckets
				GROUP BY username
				ORDER BY '.$sort,[
				':date' => date('Y-m-d',$dateIn),
			]);

			echo '<h2>Estimated Screen Time by User</h2>';
			echo '<p><i>Estimated based on activity within 5-minute windows. This is an approximation of active browsing time, not exact screen-on time.</i></p>';

			echo '<form method="post" id="screentimeform">';
			echo '<input type="hidden" name="date" value="'.htmlentities($_POST['date']).'" />';
			echo '<input type="hidden" name="sort" value="'.htmlentities($sort == 'username asc' ? 'username' : 'minutes').'" />';
			echo 'Filter username: <input type="text" name="userfilter" value="'.htmlentities($_POST['userfilter'] ?? '').'" /> <input type="submit" value="Filter" />';
			echo '</form>';

			$userfilter = trim($_POST['userfilter'] ?? '');

			echo '<table class="data" style="margin:auto;max-width:600px;">';
			echo '<tr>';
			echo '<th><a href="javascript:void(0)" onclick="document.querySelector(\'#screentimeform input[name=sort]\').value=\'username\';document.querySelector(\'#screentimeform\').submit();">Username</a></th>';
			echo '<th><a href="javascript:void(0)" onclick="document.querySelector(\'#screentimeform input[name=sort]\').value=\'minutes\';document.querySelector(\'#screentimeform\').submit();">Estimated Time</a></th>';
			echo '</tr>';

			foreach($userData as $row){
				if ($userfilter != '' && stripos($row['username'],$userfilter) === false){continue;}
				$minutes = intval($row['minutes']);
				$hours = intdiv($minutes,60);
				$mins = $minutes % 60;
				$display = ($hours > 0 ? $hours.'h ' : '').$mins.'m';
				echo '<tr>';
				echo '<td>'.htmlentities($row['username']).'</td>';
				echo '<td>'.htmlentities($display).'</td>';
				echo '</tr>';
			}
			echo '</table>';
		}
	}
}
