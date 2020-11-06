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

$device = isset($_GET['device']) ? preg_replace("/[^a-z0-9-]/","",$_GET['device']) : '';
if ($device == "") $device = "*";


$date = date("Ymd", (isset($_GET['date']) ? strtotime($_GET['date']) : time()));
$urlfilter = isset($_GET['urlfilter']) ? $_GET['urlfilter'] : '';
$action = isset($_GET['action']) ? preg_replace("/[^A-Z]/","",$_GET['action']) : '';
if ($action == 'ALLOW'){
	$actiontype = array("ALLOW");
} elseif ($action == 'BLOCK'){
	$actiontype = array("BLOCK","BLOCKPAGE","BLOCKNOTIFY","REDIRECT","CANCEL");
} else {
	$actiontype = array();
}

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
<?php
	if ($_config['mode'] == 'user') {
		echo 'Username: <select name="username">';
		foreach ($_SESSION['allowedclients'] as $_clientID => $_clientName) echo '<option value="'.$_clientID.'" '.($_clientID == $username ? 'selected="selected"':'').'>'.htmlentities($_clientName).'</option>';
		echo '</select><br />Device: <input type="text" name="device" value="'.htmlentities($device == '*' ? '' : $device).'" />';
	} elseif ($_config['mode'] == 'device') {
		echo 'Username: <input type="text" name="username" value="'.htmlentities($username == '*' ? '' : $username).' />';
		echo '<br />Device: <select name="device"><option value=""></option>';
		foreach ($_SESSION['alloweddevices'] as $_device => $_deviceName) echo "<option value=\"$_device\" ".($_device == $device ? 'selected="selected"':'').">".htmlentities($_deviceName)."</option>";
		echo '</select>';
	} else {
		echo 'Query error.';
	}
?>
<br />URL Filter: <input type="text" name="urlfilter" value="<?php echo htmlentities($urlfilter);?>" />
<br />Date: <input type="text" name="date" value="<?php echo htmlentities($date);?>" />
<br />Action: <select name="action">
	<option <?php if ($action == '') echo 'selected="selected"'; ?> value=""></option>
	<option <?php if ($action == 'ALLOW') echo 'selected="selected"'; ?> value="ALLOW">Allowed Requests</option>
	<option <?php if ($action == 'BLOCK') echo 'selected="selected"'; ?> value="BLOCK">Filtered Requests</option>
	</select>
<br /><input type="checkbox" name="showadvanced" value="1" <?php if (isset($_GET['showadvanced'])) echo 'checked="checked"'; ?> />Show Advanced
<br /><input type="submit" name="search" value="Search" />
</form>
<?php
//only show results if something was searched
if (isset($_GET['search'])){
	echo "<table class=\"w3-table-all\"><col width=\"400\" /><tbody>";
	$logfiles = glob("$dataDir/logs/$date/$username/$device/*.tsv");
	$_myTmpCnt=0;
	foreach($logfiles as $_logfile){
		$logfile = explode("/",$_logfile);
		//get the data positions
		$datapos=sizeof($logfile);
		$date = $logfile[$datapos-4];
		$username = $logfile[$datapos-3];
		$device = $logfile[$datapos-2];
		$ip = substr($logfile[$datapos-1],0,-4);
		$url = $ip;
		if ($_config['mode'] == 'user') {
			$clientID = $username;
		} elseif ($_config['mode'] == 'device') {
			$clientID = $device;
		} else {
			echo 'Error setting $clientID!';
		}
		if (isset($_SESSION['allowedclients'][$clientID])){
			if ($file = fopen($_logfile,"r")){
				//$device = isset($_SESSION['allowedclients'][$clientID]) ? htmlentities($_SESSION['allowedclients'][$clientID]) : $ip;
				while (($line = fgets($file)) !== false) {
					$line = explode("\t",$line);
					if (count($line) == 4){
						$lineaction = $line[0];
						$date = $line[1];
						$type = $line[2];
						$url = $line[3];
						if ( (isset($_GET['showadvanced']) || $type == 'main_frame') && ($action == '' || in_array($lineaction, $actiontype)) && ($urlfilter == '' || preg_match("/$urlfilter/i", $url)) ){
							echo "<tr><td>";
							echo "Action: $lineaction<br />";
							echo "Date: $date<br />";
							echo "User: ".htmlentities($username)."<br />";
							echo "Device: $device";
							if (isset($_GET['showadvanced'])) {
								echo "<br />IP: $ip";
								echo "<br />Type: $type";
							}
							echo "</td><td>".htmlentities($url)."</td></tr>";
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
