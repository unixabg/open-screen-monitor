<?php
namespace OSM;

require('config.php');

//we will need a session everywhere that this page goes
session_start();

$devices_file = $dataDir.'/devices.tsv';
$permissions_file = $dataDir.'/permissions.tsv';

$permissions = array();
if (file_exists($permissions_file)) {
	$_permissions = file_get_contents($permissions_file);
	$_permissions = explode("\n",$_permissions);
	foreach ($_permissions as $permission) {
		$permission = explode("\t",$permission);
		if (count($permission) == 2) {
			$permissions[$permission[0]][] = $permission[1];
		}
	}
}
$labs = array();
if (file_exists($devices_file)) {
	$devices = file_get_contents($devices_file);
	$devices = explode("\n",$devices);
	foreach ($devices as $device) {
		$device = explode("\t",$device);
		if (count($device) == 5) {
			$labs[$device[1]][$device[0]] = $device;
		}
	}
	ksort($labs);
}
$myPermissions = [];


if (isset($_GET['route'])){
	$route = 'OSM\\Route\\'.$_GET['route'];

	if (class_exists($route)){
		$route = new $route();
		$route->render();
	}
	die('Invalid Route: '.htmlentities($route));
} elseif (isset($_GET['logout'])) {
	session_destroy();
	header('Location: ?googleLogout');
	die();
} elseif (isset($_GET['code'])) {
	$token = Tools\Google::getToken($_GET['code']);
	if (!Tools\Google::checkToken($token))
		die("Error has occured validating token");

	file_put_contents($logDir.'login.log', date('YmdHis',time())."\t".$_SESSION['email']."\t".$_SERVER['REMOTE_ADDR']."\n" ,FILE_APPEND);

	//redirect them back to self, except this time they will be logged in
	header('Location: ?');
	die();
} elseif (isset($_GET['unknowngroup']) && Tools\Google::checkToken($_SESSION['token']) && $_SESSION['admin']){
	$_SESSION['alloweddevices'] = array('unknown'=>'Unknown');
	$_SESSION['lab'] = 'Unknown';
	header('Location: monitor.php');
	die();
}

?>
<html>
<head>
	<link rel="stylesheet" href="https://www.w3schools.com/w3css/4/w3.css">
	<link rel="stylesheet" href="./style.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
	<script defer src="https://use.fontawesome.com/releases/v5.0.6/js/all.js"></script>
</head>
<body>
<div style="display:inline; float:right; padding-top:5px; padding-right:10px;">
	Version <?php echo $_config['version']; ?>
	<br /><a href="?">Home</a> <?php if (isset($_SESSION['token'])){echo '| <a href="?logout">Logout</a>';}?>
</div>
<h1 style="text-align:center;">Open Screen Monitor</h1>
<hr />
<?php

if (isset($_SESSION['token']) && Tools\Google::checkToken($_SESSION['token'])) {
	//user is authenticated
	//the user is at the home (show labs) screen
	?>
	<h2>Hello <?php echo htmlentities($_SESSION['name'].' ('.$_SESSION['email'].')');?></h2>
	<div>
		<?php if ($_config['mode'] == 'device'){ ?>
			Here are the labs you have access to:
			<ul style="text-align:left;">
			<?php
			if ($_SESSION['admin']) {
				//show all devices
				foreach (array_keys($labs) as $lab) {
					echo "<li><a href=\"?route=Monitor\Lab&lab=".urlencode($lab)."\">".htmlentities($lab)."</a> - (".count($labs[$lab])." devices)</li>";
				}
			} else {
				//show just what they can access
				foreach ($myPermissions as $permission) {
					echo "<li><a href=\"?route=Monitor\Lab&lab=".urlencode($permission)."\">".htmlentities($permission)."</a> - (".count($labs[$permission])." devices)</li>";
				}
			}
			?>
			</ul>
		<?php
		} elseif ($_config['mode'] == 'user'){
			//sync courses
			$context = stream_context_create(array('http' =>array(
				'method'=>'GET',
				'header'=>'Authorization: Bearer '.$_SESSION['token']->access_token,
			)));

			//links below are a reference for request and response
			//https://developers.google.com/classroom/reference/rest/v1/courses/list
			$courses = array();
			$url = 'https://classroom.googleapis.com/v1/courses?pageSize=100&courseStates=ACTIVE'.($_SESSION['admin'] ? '':'&teacherId=me');
			$data = file_get_contents($url, false, $context);
			if (!empty($data)) {
				$data = json_decode($data,true);
				$courses = $data['courses'];
				while (isset($data['nextPageToken']) && $data['nextPageToken'] != '') {
					$data = file_get_contents($url.'&pageToken='.urlencode($data['nextPageToken']), false, $context);
					if (!empty($data)) {
						$data = json_decode($data,true);
						if (!empty($data['courses'])) {
							$courses = array_merge($courses,$data['courses']);
						} else {
							break;
						}
					}
				}
			}
			echo "Here are your Google Classroom classes: <ul style=\"text-align:left;\">";
			$_courses = array();
			foreach ($courses as $course) {
				$_courses[$course['id']]=$course['name'];
			}
			asort($_courses);
			//save names so we can use them when a user activates a lab
			$_SESSION['userLabNames'] = $_courses;
			foreach($_courses as $id=>$name){
				echo "<li><a href=\"?course=".urlencode($id)."\">".htmlentities($name)."</a></li>";
			}

			echo "</ul>";
		}
		?>
	</div>
	<?php if ($_SESSION['admin'] || count($permissions) == 0) { ?>
	<div>
		<h2>Admin Tools</h2>
		<ul style="text-align:left;">
			<li><a href="?route=Admin\Config">Config Editor</a></li>
			<li><a href="?route=Admin\Permissions">Permissions</a></li>
			<li><a href="?route=Admin\Serverfilter">Server Filter Lists</a></li>
			<?php if ($_config['mode'] == 'device') {echo '<li><a href="?route=Admin\Syncdevices" >Sync Devices</a></li>';} ?>
			<li><a href="usagereport.php" >Usage Report</a></li>
			<li><a href="?route=Monitor\Filterlog">View Browsing History</a></li>
			<?php if ($_config['showUnknownDevices']){echo '<li><a href="?unknowngroup">Unknown Group</a></li>';} ?>
		</ul>
	</div>
	<?php
	}
} else {
	//user needs to login, show them the login screen
	?>
	<h1>Login</h1>
	<center>
	<div id="myGoogleSignin"></div>
	</center>
	<?php
	echo "<a href=\"".Tools\Google::getLoginLink()."\"><img src=\"google_signin.png\" alt=\"Google Signin\" /></a><br />";
}
?>
</body>
</html>
