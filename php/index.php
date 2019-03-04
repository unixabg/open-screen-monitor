<?php
session_start();
require('config.php');

//this will need to be setup per site
//it should be downloaded from the api developers console after it is setup
//see https://developers.google.com/identity/sign-in/web/sign-in
$client_secret_file = $dataDir.'/client_secret.json';
$devices_file = $dataDir.'/devices.tsv';
$permissions_file = $dataDir.'/permissions.tsv';

//load variables
if (file_exists($client_secret_file)) {
	$client_secret = json_decode(file_get_contents($client_secret_file));
} else {
	die('Missing client_secret.json file');
}
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
$myPermissions = array();

function checkToken($token) {
	global $client_secret;
	global $permissions;
	global $myPermissions;

	$data = @file_get_contents("https://www.googleapis.com/oauth2/v3/tokeninfo?id_token=".urlencode($token->id_token));
	if ( $http_response_header[0] == "HTTP/1.0 200 OK") {
		$data = json_decode($data);

		if ($data->aud == $client_secret->web->client_id && $data->exp > time() && $data->iss == "https://accounts.google.com") {
			//they are good
			$_SESSION['token'] = $token;
			$_SESSION['email'] = $data->email;
			$_SESSION['name'] = $data->name;

			//this is needed on the monitor page to check if they are authenticated
			//we have too many requests there to constantly hit googles servers (they would blacklist us)
			$_SESSION['validuntil'] = $data->exp;

			$myPermissions = isset($permissions[$_SESSION['email']]) ? $permissions[$_SESSION['email']] : array();

			$_SESSION['admin'] = in_array('admin',$myPermissions);
			return true;
		}
	}
	return false;
}


//there are multiple pages contained in this file
//first are pages that simply redirect or output for ajax calls
//these need to be here so they can modify the http headers and completely control their output

if (isset($_GET['logout'])) {
	session_destroy();
	header('Location: ?googleLogout');
	die();
} elseif (isset($_GET['code'])) {
	$data = file_get_contents('https://www.googleapis.com/oauth2/v4/token', false, stream_context_create(array('http' =>array(
		'method'=>'POST',
		'content' => http_build_query(array(
			'code' => $_GET['code'],
			'client_id' => $client_secret->web->client_id,
			'client_secret' => $client_secret->web->client_secret,
			'redirect_uri'=> $client_secret->web->redirect_uris[0],
			'grant_type'=>'authorization_code'
		)),
	))));
	$token = json_decode($data);
	if (!checkToken($token))
		die("Error has occured validating token");

	file_put_contents($logDir.'login.log', date('YmdHis',time())."\t".$_SESSION['email']."\t".$_SERVER['REMOTE_ADDR']."\n" ,FILE_APPEND);

	//redirect them back to self, except this time they will be logged in
	header('Location: ?');
	die();
} elseif (isset($_GET['lab']) && isset($_SESSION['token']) && checkToken($_SESSION['token'])) {
	if (isset($labs[$_GET['lab']]) && (in_array($_GET['lab'],$permissions[$_SESSION['email']]) || $_SESSION['admin'] )) {
		//they have permission to this lab
		$_SESSION['alloweddevices'] = array();
		//prefix data dir to each device
		foreach ($labs[$_GET['lab']] as $deviceID=>$deviceInfo) {
			unset($deviceInfo[0]);
			unset($deviceInfo[1]);
			foreach($deviceInfo as $i => $value) {
				if ($value == "") unset($deviceInfo[$i]);
			}
			$description = implode(" - ",$deviceInfo);
			$_SESSION['lab'] = $_GET['lab'];
			$_SESSION['alloweddevices'][$deviceID] = ($description != "" ? $description : $deviceID);
		}

		//sort the allowed devices array
		asort($_SESSION['alloweddevices']);

		header('Location: monitor.php');
	} else {
		//they don't have permission to this lab but are valid, redirect back
		header('Location: ?');
	}
	die();
} elseif (isset($_GET['adminfilterlog']) && isset($_SESSION['token']) && checkToken($_SESSION['token']) && $_SESSION['admin']) {
	//they have permission to this lab
	$_SESSION['alloweddevices'] = array();
	//prefix data dir to each device
	foreach ($labs as $lab){
		foreach ($lab as $deviceID=>$deviceInfo) {
			unset($deviceInfo[0]);
			unset($deviceInfo[1]);
			foreach($deviceInfo as $i => $value) {
				if ($value == "") unset($deviceInfo[$i]);
			}
			$description = implode(" - ",$deviceInfo);
			$_SESSION['lab'] = $_GET['lab'];
			$_SESSION['alloweddevices'][$deviceID] = ($description != "" ? $description : $deviceID);
		}

		//sort the allowed devices array
		asort($_SESSION['alloweddevices']);

		header('Location: filterlog.php');
	}
	die();
} elseif (isset($_GET['course']) && isset($_SESSION['token']) && checkToken($_SESSION['token'])) {
	if ($_config['mode'] == 'user') {
		//sync devices
		$context = stream_context_create(array('http' =>array(
			'method'=>'GET',
			'header'=>'Authorization: Bearer '.$_SESSION['token']->access_token,
		)));

		//links below are a reference for request and response
		//https://developers.google.com/classroom/reference/rest/v1/courses.students/list
		$students = array();
		$url = 'https://classroom.googleapis.com/v1/courses/'.urlencode($_GET['course']).'/students?pageSize=100';
		$data = file_get_contents($url, false, $context);
		if ($data !== false) {
			$data = json_decode($data,true);
			if (isset($data['students'])){
				$students = $data['students'];
				while (isset($data['nextPageToken']) && $data['nextPageToken'] != '') {
					$data = file_get_contents($url.'&pageToken='.urlencode($data['nextPageToken']), false, $context);
					if ($data === false) return false;
						$data = json_decode($data,true);
					$students = array_merge($students,$data['students']);
				}
			}
		}
		$_SESSION['alloweddevices'] = array();
		foreach ($students as $student){
			$email = $student['profile']['emailAddress'];
			$email = str_replace("@","_",$email);
			$email = preg_replace("/[^a-zA-Z0-9-_]/","",$email);
			$_SESSION['alloweddevices'][$email] = $student['profile']['name']['fullName'];
		}
		if (count($_SESSION['alloweddevices']) > 0){
			asort($_SESSION['alloweddevices']);
			$_SESSION['lab'] = 'CLASS NAME GOES HERE';
			header('Location: monitor.php');
			die();
		}
	}
	//they don't have permission to this lab but are valid, redirect back
	header('Location: ?');
	die();
} elseif (isset($_GET['permissions']) && isset($_SESSION['token']) && checkToken($_SESSION['token']) && $_SESSION['admin']){
	$changesMade = false;
	if (isset($_POST['emails']) && isset($_POST['labs']) && is_array($_POST['labs'])){
		$emails = explode(";",$_POST['emails']);
		foreach($emails as $email){
			if (isset($_POST['addNotErase'])){
				if (isset($permissions[$email])){
					$permissions[$email] = array_unique(array_merge($permissions[$email], $_POST['labs']));
				} else {
					$permissions[$email] = $_POST['labs'];
				}
			} else {
				$permissions[$email] = $_POST['labs'];
			}
			$changesMade = true;
		}
	}

	if (isset($_GET['delEmail'])){
		if (isset($permissions[$_GET['delEmail']])){
			unset($permissions[$_GET['delEmail']]);
			$changesMade = true;
		}
	}

	//save changes to file
	if ($changesMade){
		//sort permissions by email
		ksort($permissions);

		$data = "";
		foreach ($permissions as $email=>$_labs){
			$_labs = array_unique($_labs);
			ksort($_labs);
			foreach ($_labs as $lab){
				if ($data != '') $data .= "\n";
				$data .= $email."\t".$lab;
			}
		}

		file_put_contents($permissions_file,$data);
		header('Location: ?permissions');
		die();
	}
}

//begin html pages
?>
<html>
<head>
	<link rel="stylesheet" href="https://www.w3schools.com/w3css/4/w3.css">
	<link rel="stylesheet" href="./style.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
	<script defer src="https://use.fontawesome.com/releases/v5.0.6/js/all.js"></script>
</head>
<body>
<h1 style="display:inline;">Open Screen Monitor |</h1> <a href="?">Home</a> <?php if (isset($_SESSION['token'])){?>| <a href="?logout">Logout</a><?php } echo "<div style=\"display:inline; float:right; padding-top:5px; padding-right:10px;\">Version ".$_config['version']."</div>"; ?>
<hr />
<?php

if (isset($_SESSION['token']) && checkToken($_SESSION['token'])) {
	//user is authenticated

	if (isset($_GET['syncdevices']) ) {
		//sync devices
		$context = stream_context_create(array('http' =>array(
			'method'=>'GET',
			'header'=>'Authorization: Bearer '.$_SESSION['token']->access_token,
		)));

		//links below are a reference for request and response, which is used to populate the devices.tsv
		//https://developers.google.com/admin-sdk/directory/v1/reference/chromeosdevices/get
		//https://developers.google.com/admin-sdk/directory/v1/reference/chromeosdevices#resource
		$url = 'https://www.googleapis.com/admin/directory/v1/customer/my_customer/devices/chromeos?projection=full&maxResults=100';
		$data = file_get_contents($url, false, $context);
		if ($data !== false) {
			$data = json_decode($data,true);
			$devices = $data['chromeosdevices'];
			while (isset($data['nextPageToken']) && $data['nextPageToken'] != '') {
				$data = file_get_contents($url.'&pageToken='.urlencode($data['nextPageToken']), false, $context);
				if ($data === false) return false;
					$data = json_decode($data,true);
				$devices = array_merge($devices,$data['chromeosdevices']);
			}
			$toSave = "";
			foreach ($devices as $device) {
				if ($device['status'] == 'ACTIVE') {
					if ($toSave != '') $toSave .= "\n";
					$toSave .= $device['deviceId']."\t"
						.$device['orgUnitPath']."\t"
						.trim($device['annotatedUser'])."\t"
						.trim($device['annotatedLocation'])."\t"
						.trim($device['annotatedAssetId']);
				}
			}
			file_put_contents($devices_file,$toSave);
			echo "<h1>Successfully synced devices</h1>";

			//if permissions file doesn't exist, go ahead and create it giving the current user admin privs
			if (!file_exists($permissions_file))
				file_put_contents($permissions_file,$_SESSION['email']."\tadmin");
		} else {
			echo "<h1>No access to chrome devices</h1>";
		}
	} elseif (isset($_GET['permissions']) && $_SESSION['admin']) {
		echo "<h2>Permissions</h2>";
		if (isset($_GET['email'])){
			echo "<form method=\"post\">Username: <input name=\"emails\" value=\"".htmlentities($_GET['email'])."\"/>";
			echo "<br />* - Seperate multiple emails by semicolon";
			echo "<br /><input type=\"checkbox\" name=\"addNotErase\" value=\"1\" /> Add instead of replace following permissions";
			echo "<br /><br /><div style=\"border: 1px solid black;padding: 5px;margin:5px;column-count: 3; column-fill: auto;\">";

			if (!isset($permissions[$_GET['email']]))$permissions[$_GET['email']] = array();
			echo "<input type=\"checkbox\" name=\"labs[]\" value=\"admin\" ".(in_array('admin',$permissions[$_GET['email']]) ? 'checked="checked"':'')." /> Admin";
			foreach($labs as $lab=>$devices) {
				echo "<br /><input type=\"checkbox\" name=\"labs[]\" value=\"".htmlentities($lab)."\" ".(in_array($lab,$permissions[$_GET['email']]) ? 'checked="checked"':'')." /> ".htmlentities($lab);
			}
			echo "</div>";
			echo "<br /><input type=\"submit\" class=\"w3-button w3-white w3-border w3-border-blue w3-round-large\" /></form>";
		} else {
			echo "<a href=\"?permissions&email\">Add Permissions</a><br /><br />";
			echo "<table class=\"w3-table-all\"><thead><tr><th>Email</th><th>Lab</th><th>&nbsp;</th></tr></thead><tbody>";
			foreach($permissions as $email=>$_labs) {
				foreach($_labs as $i=>$value){$_labs[$i] = htmlentities($value);}
				echo "<tr>";
				echo "<td>".htmlentities($email)."</td>";
				echo "<td>".implode("<br />",$_labs)."</td>";
				echo "<td><a href=\"?permissions&email=".htmlentities($email)."\"><i class=\"fas fa-pencil-alt\" title=\"Edit this user.\"></i></a>  <a href=\"?permissions&delEmail=".htmlentities($email)."\"><i class=\"fas fa-times\" title=\"Delete this user.\"></i></a></td>";
				echo "</tr>";
			}
			echo "</tbody></table>";
		}
	} elseif (isset($_GET['config']) && $_SESSION['admin']) {
		echo "<h2>Config Editor (must be a valid JSON object)</h2>";
		if (isset($_POST['configjson'])){
			$newConfig = json_decode($_POST['configjson'],true);

			if (is_array($newConfig)){
				if (file_put_contents($dataDir.'/config.json',$_POST['configjson'])){
					$_config = array_merge($_config,$newConfig);
					echo "<h3>Successfully Saved JSON</h3>";
				} else {
					echo "<h3>Error Saving Config to config.json</h3>";
				}
			} else {
				echo "<h3>Error in parsing JSON</h3>";
			}
		}
		//unset variables that shouldn't be set by user
		$tempConfig = $_config;
		unset($tempConfig['version']);
		echo "<form method=\"post\"><textarea name=\"configjson\" style=\"width:100%;height:400px;\">".htmlentities(json_encode($tempConfig,JSON_PRETTY_PRINT))."</textarea><br /><input type=\"submit\" value=\"Save Config\"/></form>";
	} elseif (isset($_GET['serverfilter']) && $_SESSION['admin']) {
		echo "<h2>Server Filter List</h2>";
		if (isset($_POST['blacklist'])){
			if (file_put_contents($dataDir.'/filter_blacklist.txt',$_POST['blacklist']) !== false)
				echo "<h3>Successfully Saved Blacklist</h3>";
			else
				echo "<h3>Error Saving Blacklist</h3>";
		}
		if (isset($_POST['whitelist'])){
			if (file_put_contents($dataDir.'/filter_whitelist.txt',$_POST['whitelist']) !== false)
				echo "<h3>Successfully Saved Whitelist</h3>";
			else
				echo "<h3>Error Saving Whitelist</h3>";
		}
		if (isset($_POST['triggerlist'])){
			if (file_put_contents($dataDir.'/triggerlist.txt',$_POST['triggerlist']) !== false)
				echo "<h3>Successfully Saved Trigger List</h3>";
			else
				echo "<h3>Error Saving Trigger List</h3>";
		}

		echo "<hr />";
		echo "<form method=\"post\">";

		$data = file_exists($dataDir.'/filter_blacklist.txt') ? file_get_contents($dataDir.'/filter_blacklist.txt') : '';
		echo "<h2>Blacklist</h2>";
		echo "<br />Three entry formats:<ul><li>url</li><li>action -tab- url</li><li>action -tab- resourceType -tab- url</li></ul>";
		echo "<br />URL can be: <ul><li>*</li><li>an actual url</li><li>a substring of a URL</li></ul>";
		echo "<br />Action can be: <ul><li>BLOCKPAGE</li><li>BLOCKNOTIFY</li><li>BLOCK</li><li>CANCEL</li><li>REDIRECT:http://google.com</li></ul>";
		echo "<br />If the type is also enabled in the config variable 'filterresourcetypes', ResourceType can be: <ul><li>*</li><li>main_frame</li><li>sub_frame</li><li>image</li><li>media</li><li>... and any other valid resource type in Chrome<br />https://developer.chrome.com/extensions/webRequest#type-ResourceType</li></ul>";
		echo "<textarea onkeydown=\"if(event.keyCode===9){var v=this.value,s=this.selectionStart,e=this.selectionEnd;this.value=v.substring(0, s)+'\t'+v.substring(e);this.selectionStart=this.selectionEnd=s+1;return false;}\" name=\"blacklist\" style=\"width:50%;height:400px;\">".htmlentities($data)."</textarea><br />";

		$data = file_exists($dataDir.'/filter_whitelist.txt') ? file_get_contents($dataDir.'/filter_whitelist.txt') : '';
		echo "<h2>Whitelist</h2>";
		echo "<textarea name=\"whitelist\" style=\"width:50%;height:400px;\">".htmlentities($data)."</textarea><br />";

		$data = file_exists($dataDir.'/triggerlist.txt') ? file_get_contents($dataDir.'/triggerlist.txt') : '';
		echo "<h2>Trigger list</h2>";
		echo "<br />Three entry formats:<ul><li>email -tab- url</li><li>email -tab- resourceType -tab- url</li></ul>";
		echo "<br />URL can be: <ul><li>*</li><li>an actual url</li><li>a substring of a URL</li></ul>";
		echo "<br />If the type is also enabled in the config variable 'filterresourcetypes', ResourceType can be: <ul><li>*</li><li>main_frame</li><li>sub_frame</li><li>image</li><li>media</li><li>... and any other valid resource type in Chrome<br />https://developer.chrome.com/extensions/webRequest#type-ResourceType</li></ul>";
		echo "<textarea onkeydown=\"if(event.keyCode===9){var v=this.value,s=this.selectionStart,e=this.selectionEnd;this.value=v.substring(0, s)+'\t'+v.substring(e);this.selectionStart=this.selectionEnd=s+1;return false;}\" name=\"triggerlist\" style=\"width:50%;height:400px;\">".htmlentities($data)."</textarea><br />";

		echo "<input type=\"submit\" value=\"Save Config\"/></form>";
	} else {
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
						echo "<li><a href=\"?lab=".urlencode($lab)."\">".htmlentities($lab)."</a></li>";
					}
				} else {
					//show just what they can access
					foreach ($myPermissions as $permission) {
						echo "<li><a href=\"?lab=".urlencode($permission)."\">".htmlentities($permission)."</a></li>";
					}
				}
				?>
				</ul>
			<?php
			} elseif ($_config['mode'] == 'user'){
				//sync devices
				$context = stream_context_create(array('http' =>array(
					'method'=>'GET',
					'header'=>'Authorization: Bearer '.$_SESSION['token']->access_token,
				)));

				//links below are a reference for request and response
				//https://developers.google.com/classroom/reference/rest/v1/courses/list
				$courses = array();
				$url = 'https://classroom.googleapis.com/v1/courses?pageSize=100&courseStates=ACTIVE'.($_SESSION['admin'] ? '':'&teacherId=me');
				$data = file_get_contents($url, false, $context);
				if ($data !== false) {
					$data = json_decode($data,true);
					$courses = $data['courses'];
					while (isset($data['nextPageToken']) && $data['nextPageToken'] != '') {
						$data = file_get_contents($url.'&pageToken='.urlencode($data['nextPageToken']), false, $context);
						if ($data === false) return false;
							$data = json_decode($data,true);
						$courses = array_merge($courses,$data['courses']);
					}
				}
				echo "Here are your Google Classroom classes: <ul style=\"text-align:left;\">";
				$_courses = array();
				foreach ($courses as $course) {
					$_courses[$course['id']]=$course['name'];
				}
				asort($_courses);
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
				<li><a href="?config">Config Editor</a></li>
				<li><a href="?permissions">Permissions</a></li>
				<li><a href="?serverfilter">Server Filter Lists</a></li>
				<li><a href="?syncdevices" >Sync Devices</a></li>
				<li><a href="usagereport.php" >Usage Report</a></li>
				<li><a href="?adminfilterlog">View Browsing History</a></li>
			</ul>
		</div>
		<?php
		}
	 }
} else {
	//user needs to login, show them the login screen
	?>
	<h1>Login</h1>
	<center>
	<div id="myGoogleSignin"></div>
	</center>
	<?php
	echo "<a href=\"https://accounts.google.com/o/oauth2/v2/auth?scope="
		.urlencode("profile email https://www.googleapis.com/auth/admin.directory.device.chromeos.readonly https://www.googleapis.com/auth/classroom.courses.readonly https://www.googleapis.com/auth/classroom.rosters.readonly https://www.googleapis.com/auth/classroom.profile.emails")
		."&response_type=code"
		."&client_id=".$client_secret->web->client_id
		."&redirect_uri=".urlencode($client_secret->web->redirect_uris[0])
		."\"><img src=\"google_signin.png\" alt=\"Google Signin\" /></a><br />";
}
?>
</body>
</html>
