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
		}
	}
}
