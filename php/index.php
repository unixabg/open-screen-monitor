<?php
session_start();

//this will need to be setup per site
//it should be downloaded from the api developers console after it is setup
//see https://developers.google.com/identity/sign-in/web/sign-in
$client_secret_file = '../client_secret.json';
if (file_exists($client_secret_file)) {
	$client_id = json_decode(file_get_contents($client_secret_file));
	$client_id = $client_id->web->client_id;
} else {
	die('Missing client_secret.json file');
}

function checkToken($token){
	$data = @file_get_contents("https://www.googleapis.com/oauth2/v3/tokeninfo?id_token=".urlencode($token));
	if ( $http_response_header[0] == "HTTP/1.0 200 OK") {
		//fixme
		//probably should filter here based on domain name or if they have a lab assigned to them

		//they are good
		$data = json_decode($data);
		$_SESSION['token'] = $token;
		$_SESSION['email'] = $data->email;
		$_SESSION['name'] = $data->name;

		//this is needed on the monitor page to check if they are authenticated
		//we have too many requests there to constantly hit googles servers (they would blacklist us)
		$_SESSION['validuntil'] = strtotime("+2 hours");
		return true;
	} else {
		return false;
	}
}


if (isset($_GET['logout'])) {
	session_destroy();
	header('Location: ?googleLogout');
	die();
} elseif (isset($_POST['token'])) {
	//user handed us a token, lets check it and if valid, sign them in
	if (!checkToken($_POST['token']))
		die("Error has occured validating token");

	//redirect them back to self, except this time they will be logged in
	header('Location: ?');
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
		Hello <?php echo htmlentities($_SESSION['name'].' ('.$_SESSION['email'].')');?>, here are your labs to choose from:
		<ul style="text-align:left;">
			<li><a href="monitor.php">Lab Test</a></li>
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
	<meta name="google-signin-client_id" content="<?php echo $client_id;?>">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
	<script src="https://apis.google.com/js/platform.js?onload=onGoogleLoad" async defer></script>
</head>
<body>
	<h1>Login</h1>
	<center>
	<div class="g-signin2" data-longtitle="true" data-ux_mode="redirect" data-onsuccess="onSignIn"></div>
	</center>
	<script type="text/javascript">
	jQuery(function($) {
		var signOut = <?php echo isset($_GET['googleLogout']) ? 'true':'false'; ?>;
		window.onSignIn = function(googleUser) {
			if (signOut) {
				var auth2 = gapi.auth2.getAuthInstance();
				auth2.signOut();
				signOut = false;
			} else {
				var id_token = googleUser.getAuthResponse().id_token;

				var form = $('<form />').attr('method','POST');
				var input = $('<input>').attr('type','hidden').attr('name','token').val(id_token);
				$(form).append($(input));
				form.appendTo( document.body )
				$(form).submit();
			}
		}

	});
	</script>
</body>
</html>
	<?php
	die();
}
