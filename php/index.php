<?php
session_start();

//this will need to be setup per site
//it should be downloaded from the api developers console after it is setup
//see https://developers.google.com/identity/sign-in/web/sign-in
$dataDir='../../osm-data';
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
			if (count($permissions) > 0 && !isset($permissions[$data->email])) {
				die("<h1>Credentials valid. No Permissions</h1>");
			}

			//they are good
			$_SESSION['token'] = $token;
			$_SESSION['email'] = $data->email;
			$_SESSION['name'] = $data->name;

			//this is needed on the monitor page to check if they are authenticated
			//we have too many requests there to constantly hit googles servers (they would blacklist us)
			$_SESSION['validuntil'] = $data->exp;

			$myPermissions = isset($permissions[$_SESSION['email']]) ? $permissions[$_SESSION['email']] : array();
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

	//redirect them back to self, except this time they will be logged in
	header('Location: ?');
	die();
} elseif (isset($_GET['lab']) && isset($_SESSION['token']) && checkToken($_SESSION['token'])) {
	if (isset($labs[$_GET['lab']]) && (in_array($_GET['lab'],$permissions[$_SESSION['email']]) || in_array('admin',$permissions[$_SESSION['email']]))) {
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
		header('Location: monitor.php');
	} else {
		//they don't have permission to this lab but are valid, redirect back
		header('Location: ?');
	}
	die();
} elseif (isset($_GET['permissions']) && isset($_SESSION['token']) && checkToken($_SESSION['token']) && in_array('admin',$myPermissions)){
	$changesMade = false;
	if (isset($_GET['addEmail']) && isset($_GET['addLab'])){
		$permissions[$_GET['addEmail']][] = $_GET['addLab'];
		$changesMade = true;
	}

	if (isset($_GET['delEmail']) && isset($_GET['delLab'])){
		foreach ($permissions as $email=>$_labs){
			if ($email == $_GET['delEmail']){
				foreach ($_labs as $i=>$lab){
					if ($lab == $_GET['delLab']){
						unset($permissions[$email][$i]);
						$changesMade = true;
					}
				}
			}
		}
	}

	//save changes to file
	if ($changesMade){
		$data = "";
		foreach ($permissions as $email=>$_labs){
			$_labs = array_unique($_labs);
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
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
</head>
<body>
<h1 style="display:inline;">Open Screen Monitor |</h1> <a href="?">Home</a> <?php if (isset($_SESSION['token'])){?>| <a href="?logout">Logout</a><?php } ?>
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
	} elseif (isset($_GET['permissions']) && in_array('admin',$myPermissions)) {
		echo "<h2>Permissions</h2>";
		echo "<table><thead><tr><th>Email</th><th>Lab</th><th>Delete</th></tr></thead><tbody>";
		foreach($permissions as $email=>$_labs) {
			foreach($_labs as $lab){
				echo "<tr><td>".htmlentities($email)."</td><td>".htmlentities($lab)."<td><td><a href=\"?permissions&delEmail=".htmlentities($email)."&delLab=".htmlentities($lab)."\">X</a></td></tr>";
			}
		}
		echo "</tbody></table>";
		echo "<form method=\"get\"><input type=\"hidden\" name=\"permissions\" />Add User<br />Email: <input name=\"addEmail\" /><br />Lab: <select name=\"addLab\">";
		foreach($labs as $lab=>$devices){
			echo "<option value=\"".htmlentities($lab)."\">".htmlentities($lab)."</option>";
		}
		echo "</select><br /><input type=\"submit\" /></form>";

	} else {
		//the user is at the home (show labs) screen
		?>
		<h2>Hello <?php echo htmlentities($_SESSION['name'].' ('.$_SESSION['email'].')');?></h2>
		<div>
			Here are the labs you have access to:
			<ul style="text-align:left;">
			<?php
			if (in_array('admin',$myPermissions)) {
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
		</div>
		<?php if (in_array('admin',$myPermissions) || count($permissions) == 0) { ?>
		<div>
			<h2>Admin Tools</h2>
			<ul style="text-align:left;">
				<li><a href="?syncdevices" >Sync Devices</a></li>
				<li><a href="?permissions">Permissions</a></li>
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
		.urlencode("profile email https://www.googleapis.com/auth/admin.directory.device.chromeos.readonly")
		."&response_type=code"
		."&client_id=".$client_secret->web->client_id
		."&redirect_uri=".urlencode($client_secret->web->redirect_uris[0])
		."\"><img src=\"google_signin.png\" alt=\"Google Signin\" /></a>";
}
?>
</body>
</html>
