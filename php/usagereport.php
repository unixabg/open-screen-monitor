<?php
session_start();
require('config.php');

//Authenticate here
if (!isset($_SESSION['validuntil']) || $_SESSION['validuntil'] < time()){
	session_destroy();
	header('Location: index.php?');
	die();
}


?>
<html>
<head>
	<title>Open Screen Monitor - Usage Report</title>
	<link rel="stylesheet" href="https://www.w3schools.com/w3css/4/w3.css">
	<link rel="stylesheet" href="./style.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
	<script defer src="https://use.fontawesome.com/releases/v5.0.6/js/all.js"></script>
	<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
	<style>
		table {table-layout: fixed;}
		td {word-break: break-all;}
	</style>
</head>
<body>
<h1 style="display:inline;">Open Screen Monitor |</h1> <a href="index.php?">Home</a> <?php if (isset($_SESSION['token'])){?>| <a href="index.php?logout">Logout</a><?php } echo "<div style=\"display:inline; float:right; padding-top:20px; padding-right:10px;\">Version ".$_config['version']."</div>"; ?>
<hr />

<form method="get">Date: <input type="text" name="date"/> <input type="submit" /></form>
<?php
if (isset($_GET['date'])){
	$dateIn = date("Ymd", ($_GET['date'] != '' ? strtotime($_GET['date']) : time()));
	$logfiles = glob("$dataDir/logs/$dateIn/*/*/*.tsv");
	$_myTmpCnt=0;

	$activeBlocks = array();//times rounded down to to previous 5 min increment
	foreach($logfiles as $_logfile){
		$logfile = explode("/",$_logfile);
		//get the data positions
		$datapos=sizeof($logfile);
		$date = $logfile[$datapos-4];
		$username = $logfile[$datapos-3];
		$deviceID = $logfile[$datapos-2];
		$ip = substr($logfile[$datapos-1],0,-4);
		$url = $ip;
		if (true || isset($_SESSION['alloweddevices'][$deviceID])){
			if ($file = fopen($_logfile,"r")){
				while (($line = fgets($file)) !== false) {
					$line = explode("\t",$line);
					if (count($line) == 4){
						$lineaction = $line[0];
						$date = $line[1];
						$type = $line[2];
						$url = $line[3];

						$seconds = strtotime($date);
						$seconds = round($seconds / (5 * 60)) * (5 * 60);

						$activeBlocks[$seconds][$deviceID] = true;
					}
				}
				fclose($file);
			} else {
				echo "Failed to open $_logfile !";
			}
		}
	}

	$_data = array(array('Dates',''));
	ksort($activeBlocks);
	foreach ($activeBlocks as $time=>$devices){
		$_data[] = array(date("g:i a",$time), count($devices));
	}
	$jsdata = array('divid'=>'total_count_div','vtitle'=>'Devices','data'=>$_data,'title'=>'Usage');


	echo '<script type="text/javascript">
	google.charts.load("current", {packages: ["corechart", "line"]});
	google.charts.setOnLoadCallback(drawCurveTypes);
	function drawCurveTypes() {
	var jsdata = '.json_encode($jsdata).';
	//document.write("<div style=\"height: 600px;width: 1200px;\" id=\"" + jsdata.divid + "\"></div>");
	var data = google.visualization.arrayToDataTable(jsdata.data);
	var chart = new google.visualization.LineChart(document.getElementById(jsdata.divid));
	chart.draw(data,{hAxis:{title:"Date ('.$dateIn.')"},vAxis:{textStyle:{fontSize: 20},title:jsdata.vtitle},legend:{position:"none"}});
	}
	</script>';
}
?>
<div style="height: 600px;width: 1200px;" id="total_count_div"></div>
</body>
</html>
