<?php
session_start();

//this will need to be setup per site
//it should be downloaded from the api developers console after it is setup
//see https://developers.google.com/identity/sign-in/web/sign-in
$dataDir='../../osm-data';
$client_secret_file = '../../client_secret.json';
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
	foreach($_permissions as $permission){
		$permission = explode("\t",$permission);
		if (count($permission) == 2) {
			$permissions[$permission[0]][] = $permission[1];
		}
	}
}
$labs = array();
if (file_exists($devices_file)){
	$devices = file_get_contents($devices_file);
	$devices = explode("\n",$devices);
	foreach ($devices as $device) {
		$device = explode("\t",$device);
		if (count($device) == 5) {
			$labs[$device[1]][] = $device[0];
		}
	}
	ksort($labs);
}


function checkToken($token){
	global $client_secret;

	$data = @file_get_contents("https://www.googleapis.com/oauth2/v3/tokeninfo?id_token=".urlencode($token->id_token));
	if ( $http_response_header[0] == "HTTP/1.0 200 OK") {
		$data = json_decode($data);

		if ($data->aud == $client_secret->web->client_id && $data->exp > time() && $data->iss == "accounts.google.com"){
			if (count($permissions) > 0 && !isset($permissions[$data->email])){
				die("<h1>Credentials valid. No Permissions</h1>");
			}

			//they are good
			$_SESSION['token'] = $token;
			$_SESSION['email'] = $data->email;
			$_SESSION['name'] = $data->name;

			//this is needed on the monitor page to check if they are authenticated
			//we have too many requests there to constantly hit googles servers (they would blacklist us)
			$_SESSION['validuntil'] = $data->exp;
			return true;
		}
	}
	return false;
}


if (isset($_GET['logout'])) {
	session_destroy();
	header('Location: ?googleLogout');
	die();
} elseif (isset($_POST['token'])) {
	$token = json_decode($_POST['token']);
	//user handed us a token, lets check it and if valid, sign them in
	if (!checkToken($token))
		die("Error has occured validating token");

	//redirect them back to self, except this time they will be logged in
	header('Location: ?');
	die();
} elseif (isset($_GET['lab']) && isset($_SESSION['token']) && checkToken($_SESSION['token'])) {
	if (isset($labs[$_GET['lab']]) && (in_array($_GET['lab'],$permissions[$_SESSION['email']]) || in_array('admin',$permissions[$_SESSION['email']]))){
		//they have permission to this lab
		$_SESSION['alloweddevices'] = array();
		//prefix data dir to each device
		foreach ($labs[$_GET['lab']] as $deviceID) {
			$_SESSION['alloweddevices'][$deviceID] = $deviceID;
		}
		header('Location: monitor.php');
	} else {
		//they don't have permission to this lab but are valid, redirect back
		header('Location: ?');
	}
	die();
} elseif (isset($_GET['syncdevices']) && isset($_SESSION['token']) && checkToken($_SESSION['token'])) {
	$context = stream_context_create(array('http' =>array(
		'method'=>'GET',
		'header'=>'Authorization: Bearer '.$_SESSION['token']->access_token,
	)));
	$url = 'https://www.googleapis.com/admin/directory/v1/customer/my_customer/devices/chromeos?projection=full&maxResults=100';
	$data = file_get_contents($url, false, $context);
	if ($data !== false) {
		$data = json_decode($data,true);
		$devices = $data['chromeosdevices'];
			while (isset($data['nextPageToken']) && $data['nextPageToken'] != ''){
			$data = file_get_contents($url.'&pageToken='.urlencode($data['nextPageToken']), false, $context);
			if ($data === false) return false;
				$data = json_decode($data,true);
			$devices = array_merge($devices,$data['chromeosdevices']);
		}
		$toSave = "";
		foreach ($devices as $device) {
			if ($device['status'] == 'ACTIVE'){
				if ($toSave != '') $toSave .= "\n";
				$toSave .= $device['deviceId']."\t".$device['orgUnitPath']."\t".$device['annotatedUser']."\t".$device['annotatedLocation']."\t".$device['annotatedAssetId'];
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
	die();
} elseif (isset($_SESSION['token']) && checkToken($_SESSION['token'])) {
	//user is authenticated, show them their labs
	?>
<html>
<head>
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
</head>
<body>
	<h1>Logged In</h1>
	<a href="?logout">Logout</a>
	<center><div style="width: 500px;">
		Hello <?php echo htmlentities($_SESSION['name'].' ('.$_SESSION['email'].')');?>
		<br />Here are your labs to choose from:
		<ul style="text-align:left;">
		<?php
		$myPermissions = $permissions[$_SESSION['email']];
		if (in_array('admin',$myPermissions)) {
			//show all devices
			foreach (array_keys($labs) as $lab){
				echo "<li><a href=\"?lab=".urlencode($lab)."\">".htmlentities($lab)."</a></li>";
			}
		} else {
			//show just what they can access
			foreach ($myPermissions as $permission){
				$directPermissions .= "<li><a href=\"?lab=".urlencode($permission)."\">".htmlentities($permission)."</a></li>";
			}
		}

		?>
		</ul>
		<h2>Admin Tools</h2>
		<ul style="text-align:left;">
			<li><a href="?syncdevices" target="_blank">Sync Devices</a></li>
		</ul>
	</div></center>
</body>
</html>
	<?php
	die();
} else {
	//user needs to login, show them the login screen
	?> 
<html>
<head>
	<meta name="google-signin-client_id" content="<?php echo $client_secret->web->client_id;?>">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
</head>
<body>
	<h1>Login</h1>
	<center>
	<div id="myGoogleSignin"></div>
	</center>
	<script type="text/javascript">
		var signOut = <?php echo isset($_GET['googleLogout']) ? 'true':'false'; ?>;
		var onSuccess = function(googleUser) {
			if (signOut) {
				var auth2 = gapi.auth2.getAuthInstance();
				auth2.signOut();
				signOut = false;
			} else {
				console.log(googleUser.getAuthResponse());
				var response = googleUser.getAuthResponse();

				var form = $('<form />').attr('method','POST');
				var input = $('<input>').attr('type','hidden').attr('name','token').val(JSON.stringify(response));
				$(form).append($(input));
				form.appendTo( document.body )
				$(form).submit();
			}
		}
		function onGoogleLoad() {
			gapi.signin2.render('myGoogleSignin', {
				'scope': 'profile email https://www.googleapis.com/auth/admin.directory.device.chromeos.readonly',
				'width': 240,
				'height': 50,
				'longtitle': true,
				'theme': 'dark',
				'onsuccess': onSuccess,
			});
		};
	</script>
	<script src="https://apis.google.com/js/platform.js?onload=onGoogleLoad" async defer></script>
</body>
</html>
	<?php
	die();
}
