<?php
session_start();
require('config.php');

//Authenticate here
if (!isset($_SESSION['validuntil']) || $_SESSION['validuntil'] < time()){
	session_destroy();
	header('Location: index.php?');
	die();
}


$username = isset($_GET['username']) ? preg_replace("/[^a-z0-9-_\.@]/","",$_GET['username']) : '';
$username = str_replace("@","_",$username);
if ($username == "") $username = "*";

$deviceID = isset($_GET['deviceID']) ? preg_replace("/[^a-z0-9-]/","",$_GET['deviceID']) : '';
if ($deviceID == "") $deviceID = "*";


$date = date("Ymd", (isset($_GET['date']) && $_SESSION['admin'] ? strtotime($_GET['date']) : time()));
$urlfilter = isset($_GET['urlfilter']) ? $_GET['urlfilter'] : '';
$action = isset($_GET['action']) ? preg_replace("/[^A-Z]/","",$_GET['action']) : '';

?><html>
<head>
	<title>Open Screen Monitor - Log Viewer</title>
	<link rel="stylesheet" href="https://www.w3schools.com/w3css/4/w3.css">
	<style>
		table {table-layout: fixed;}
		td {word-break: break-all;}
	</style>
</head>
<body>
<h1 style="display:inline;">Open Screen Monitor |</h1> <a href="index.php?">Home</a> <?php if (isset($_SESSION['token'])){?>| <a href="index.php?logout">Logout</a><?php } echo "<div style=\"display:inline; float:right; padding-top:5px; padding-right:10px;\">Version ".$_config['version']."</div>"; ?>
<hr />
<form method="get">
Username: <input type="text" name="username" value="<?php echo htmlentities($username == '*' ? '' : $username);?>" />
<br />Device: <select name="deviceID">
	<option value=""></option>
	<?php
	foreach ($_SESSION['alloweddevices'] as $_deviceID => $_deviceName) echo "<option value=\"$_deviceID\" ".($_deviceID == $deviceID ? 'selected="selected"':'').">".htmlentities($_deviceName)."</option>";
	?>
</select>
<br />URL Filter: <input type="text" name="urlfilter" value="<?php echo htmlentities($urlfilter);?>" />
<br />Date: <input type="text" name="date" value="<?php echo htmlentities($date);?>" />
<br />Action: <select name="action">
	<option <?php if ($action == '') echo 'selected="selected"'; ?> value=""></option>
	<option <?php if ($action == 'ALLOW') echo 'selected="selected"'; ?> value="ALLOW">ALLOW</option>
	<option <?php if ($action == 'BLOCK') echo 'selected="selected"'; ?> value="BLOCK">BLOCK</option>
	</select>
<br /><input type="submit" name="search" value="Search" />
</form>
<?php
//only show results if something was searched
if (isset($_GET['search'])){
	echo "<table class=\"w3-table-all\"><col width=\"130\"><col width=\"300\"><col width=\"130\"><thead>";
	echo "<tr><td>Action</td><td>Date</td><td>Username</td><td>Device</td>".(isset($_GET['showadvanced'])?"<td>IP</td><td>Request Type</td>":"")."<td>URL</td></tr>";
	echo "</thead><tbody>";

	$logfiles = glob("$dataDir/logs/$date/$username/$deviceID/*.tsv");
	$_myTmpCnt=0;
	foreach($logfiles as $_logfile){
		$logfile = explode("/",$_logfile);
		//get the data positions
		$datapos=sizeof($logfile);
		$date = $logfile[$datapos-4];
		$username = $logfile[$datapos-3];
		$deviceID = $logfile[$datapos-2];
		$ip = substr($logfile[$datapos-1],0,-4);
		$url = $ip;
		if (isset($_SESSION['alloweddevices'][$deviceID])){
			if ($file = fopen($_logfile,"r")){
				$device = htmlentities($_SESSION['alloweddevices'][$deviceID]);
				while (($line = fgets($file)) !== false) {
					$line = explode("\t",$line);
					if (count($line) == 4){
						$lineaction = $line[0];
						$date = $line[1];
						$type = $line[2];
						$url = $line[3];
						if ( (isset($_GET['showadvanced']) || $type == 'mainframe') && ($action == '' || $action == $lineaction) && ($urlfilter == '' || preg_match("/$urlfilter/i", $url)) ){
							echo "<tr><td>$lineaction</td><td>$date</td><td>".htmlentities($username)."</td><td>$device</td>";
							if (isset($_GET['showadvanced'])) {
								echo "<td>$ip</td><td>$type</td>";
							}
							echo "<td>".htmlentities($url)."</td></tr>";
						}
					}
				}
				fclose($file);
			} else {
				echo "Failed to open $_logfile !";
			}
		}
	}


	echo "</tbody></table>";
}
?>
</body>
</html>
