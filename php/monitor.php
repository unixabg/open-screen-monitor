<?php
session_start();
require('config.php');

//Authenticate here
if (!isset($_SESSION['validuntil']) || $_SESSION['validuntil'] < time()){
	session_destroy();
	//if this is a request for images or meta, just silently die or it could crash the browser
	if (isset($_GET['images']) || isset($_GET['update'])){
		header('Content-Type: application/json');
		header('X-OSM-Refresh: page');
		echo json_encode(array());
	} else {
		header('Location: index.php?');
	}
	die();
}

function logger($filename, $information, $logmax) {
	//$information should be of the format: time<tab>user who requested the action<tab>action<tab>other action related information
	file_put_contents($filename,$information,FILE_APPEND);
	$lines = file($filename, FILE_IGNORE_NEW_LINES);
	$mycount = count($lines);
	if ($mycount>$logmax) {
		$newcount=count($lines)-$logmax;
		for ($i=$newcount; $i<$mycount;$i++) {
			//echo "Dumping array entry to file ".$i."\n";
			if ($i==$newcount) {
				file_put_contents($filename,$lines[$i]."\n");
			} else {
				file_put_contents($filename,$lines[$i]."\n",FILE_APPEND);
			}
		}
	}
}

//return all images after ctime
if (isset($_GET['images'])) {
	ini_set('memory_limit','256M');
	$toReturn = array();

	foreach ($_SESSION['allowedclients'] as $clientID=>$clientName) {
		$files = glob($dataDir.'/clients/'.$clientID.'/*',GLOB_ONLYDIR);
		foreach ($files as $file){
			$sessionID = basename($file);
			$file .= '/screenshot.jpg';
			// Assure who needs access here FIXME
			if (is_readable($file) && filemtime($file) >= time() - 30 ) {
				$toReturn[$clientID.'_'.$sessionID] = base64_encode(file_get_contents($file));
			} else {
				$toReturn[$clientID.'_'.$sessionID] = '';
			}
		}
	}

	//send it back
	ini_set("zlib.output_compression", "On");

	header('Content-Type: application/json');
	die(json_encode($toReturn));
}

// Actions are passed with the device/user id and session in the $_GET[]
if (isset($_GET['action'])){
	$action = $_GET['action'];

	//actions that require an (device\user) id
	if (isset($_GET['id']) && isset($_SESSION['allowedclients'][$_GET['id']]) && isset($_GET['sessionID']) && is_numeric($_GET['sessionID'])){
		//$configFolder = $dataDir.'/config/'.$_GET['id'];
		$clientFolder = $dataDir.'/clients/'.$_GET['id'].'/'.$_GET['sessionID'];

		if ($action == 'getImage'){
			$img = @file_get_contents($clientFolder.'/screenshot.jpg');
			if ($img != ''){
				echo $img;
			} else {
				header('Location: unavailable.jpg');
			}
			die();
		}
	}

	die();
}

// Actions are passed with the device/user id and session in the $_POST[]
if (isset($_POST['action'])){
	$action = $_POST['action'];

	//actions that require an (device\user) id
	if (isset($_POST['id']) && isset($_SESSION['allowedclients'][$_POST['id']])){
		$logDir = $dataDir.'/logs';
		file_exists($logDir) || mkdir($logDir);
		$logFile = $logDir.'/'.$_POST['id'].'.log';

		if ($action == 'log'){
			die(preg_replace("/\r\n|\r|\n/",'<br />',file_get_contents($logFile)));
		}

		//actions that require a session
		if (isset($_POST['sessionID']) && is_numeric($_POST['sessionID'])){
			$clientFolder = $dataDir.'/clients/'.$_POST['id'].'/'.$_POST['sessionID'];
			//create client folder if it doesn't exist
			if (!file_exists($clientFolder)) mkdir($clientFolder, 0755 , true);

			if ($action == 'openurl'){
				if (isset($_POST['url']) && filter_var($_POST['url'],FILTER_VALIDATE_URL,FILTER_FLAG_HOST_REQUIRED)) {
					file_put_contents($clientFolder.'/openurl',$_POST['url']);
					logger($logFile, date('YmdHis',time())."\t".$_SESSION['email']."\topenurl\t".$_POST['url']."\n", $_config['logmax']);
				}
				die();
			}
			if ($action == 'closetab') {
				if (isset($_POST['tabid'])){
					file_put_contents($clientFolder.'/closetab',$_POST['tabid']."\n",FILE_APPEND);
					//FIXME - add title of tab later
					logger($logFile, date('YmdHis',time())."\t".$_SESSION['email']."\tclosetab\t\n", $_config['logmax']);
				}
				die();
			}
			if ($action == 'closeAllTabs'){
				if (file_exists($clientFolder.'/tabs')) {
					$temp = json_decode(file_get_contents($clientFolder.'/tabs'),true);
					foreach ($temp as $tab) {
						//$tab['id']
						file_put_contents($clientFolder.'/closetab',$tab['id']."\n",FILE_APPEND);
						//FIXME - add title of tab later
						logger($logFile, date('YmdHis',time())."\t".$_SESSION['email']."\tclosealltab\t\n", $_config['logmax']);
					}
				}
				die();
			}
			if ($action == 'lock'){
				touch($clientFolder.'/lock');
				logger($logFile, date('YmdHis',time())."\t".$_SESSION['email']."\tlocked\t\n", $_config['logmax']);
				die();
			}
			if ($action == 'unlock'){
				if (file_exists($clientFolder.'/lock')) unlink($clientFolder.'/lock');
				logger($logFile, date('YmdHis',time())."\t".$_SESSION['email']."\tunlocked\t\n", $_config['logmax']);
				die();
			}
			if ($action == 'sendmessage'){
				if (isset($_POST['message'])) {
					file_put_contents($clientFolder.'/messages',$_SESSION['name']." says ... \t".$_POST['message']."\n",FILE_APPEND);
					logger($logFile, date('YmdHis',time())."\t".$_SESSION['email']."\tmessages\t".$_POST['message']."\n", $_config['logmax']);
				}
				die();
			}
			if ($action == 'screenshot'){
				if (file_exists($clientFolder."/screenshot.jpg")){
					logger($logFile, date('YmdHis',time())."\t".$_SESSION['email']."\tscreenshot\t\n", $_config['logmax']);

					$text = "Screenshot: ".date("Y-m-d h:i a")."\r\n\r\n";
					if (file_exists($clientFolder.'/username')) $text .= "Username: ".file_get_contents($clientFolder.'/username')."\r\n";
					if (file_exists($clientFolder.'/tabs')){
						$tabs = json_decode(file_get_contents($clientFolder.'/tabs'),true);
						foreach ($tabs as $tab){
							$text .= "Open Tab: <".$tab['title']."> ".$tab['url']."\r\n";
						}
					}

					$uid = md5(uniqid(time()));

					// header
					$header = "From: Open Screen Monitor <".$_SESSION['email'].">\r\n";
					$header .= "MIME-Version: 1.0\r\n";
					$header .= "Content-Type: multipart/mixed; boundary=\"".$uid."\"\r\n\r\n";

					// message & attachment
					$raw = "--".$uid."\r\n";
					$raw .= "Content-type:text/plain; charset=iso-8859-1\r\n";
					$raw .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
					$raw .= "$text\r\n\r\n";
					$raw .= "--".$uid."\r\n";
					$raw .= "Content-Type: image/jpeg; name=\"screenshot.jpg\"\r\n";
					$raw .= "Content-Transfer-Encoding: base64\r\n";
					$raw .= "Content-Disposition: attachment; filename=\"screenshot.jpg\"\r\n\r\n";
					$raw .= chunk_split(base64_encode(file_get_contents($clientFolder.'/screenshot.jpg')))."\r\n\r\n";
					$raw .= "--".$uid."--";

					echo mail($_SESSION['email'], "OSM Screenshot", $raw, $header) ? "Successfully Sent Screenshot To ".$_SESSION['email'] : "Error Sending Screenshot";
				} else {
					echo "No Screenshot to send";
				}
				die();
			}
		}
	}
	die();
}



if (isset($_GET['update'])) {
	$data = array();
	foreach ($_SESSION['allowedclients'] as $clientID=>$clientName) {
		$data[$clientID] = array();
		$folders = glob($dataDir.'/clients/'.$clientID.'/*',GLOB_ONLYDIR);
		foreach ($folders as $folder){
			$sessionID = basename($folder);
			$folder .= '/';
			$data[$clientID][$sessionID] = array('name'=>$clientName,'username'=>'','tabs'=>array());

			if (file_exists($folder.'ping') && filemtime($folder.'ping') > time()-30) {
				$data[$clientID][$sessionID]['ip'] = (file_exists($folder.'ip') ? file_get_contents($folder.'ip') : "Unknown IP");
				$data[$clientID][$sessionID]['username'] = (file_exists($folder.'username') ? file_get_contents($folder.'username') : "Unknown User");
				$data[$clientID][$sessionID]['tabs'] = "";
				$data[$clientID][$sessionID]['locked'] = file_exists($folder.'lock');
				if (file_exists($folder.'tabs')) {
					$temp = json_decode(file_get_contents($folder.'tabs'),true);
					foreach ($temp as $tab) {
						$data[$clientID][$sessionID]['tabs'] .= "<a href=\"#\" onmousedown=\"javscript:closeTab('".$clientID."_".$sessionID."','".$tab['id']."');return false;\"><i class=\"fas fa-trash\" title=\"Close this tab.\"></i></a> ".htmlspecialchars($tab['title']).'<br />'.substr(htmlspecialchars($tab['url']),0,500).'<br />';
					}
				}
			}
		}
	}

	header('Content-Type: application/json');
	die(json_encode($data));
}

if (isset($_POST['filterlistdefaultdeny']) && isset($_POST['filterlistdefaultallow']) && isset($_POST['filtermode']) && in_array($_POST['filtermode'],array('defaultallow','defaultdeny','disabled'))) {
	//only allow printable characters and new lines
	$_POST['filterlistdefaultdeny'] = preg_replace('/[\x00-\x09\x20\x0B-\x1F\x7F-\xFF]/', '', $_POST['filterlistdefaultdeny']);
	$_POST['filterlistdefaultallow'] = preg_replace('/[\x00-\x09\x20\x0B-\x1F\x7F-\xFF]/', '', $_POST['filterlistdefaultallow']);
	//let us do a second pass to drop empty lines and correctly format
	$_POST['filterlistdefaultdeny'] = strtolower(trim(preg_replace('/\n+/', "\n", $_POST['filterlistdefaultdeny'])));
	$_POST['filterlistdefaultallow'] = strtolower(trim(preg_replace('/\n+/', "\n", $_POST['filterlistdefaultallow'])));

	$_actionPath = $dataDir.'/config/'.base64_encode($_SESSION['lab']).'/';
	file_put_contents($_actionPath.'filtermode',$_POST['filtermode']);
	file_put_contents($_actionPath.'filterlist-defaultdeny',$_POST['filterlistdefaultdeny']);
	file_put_contents($_actionPath.'filterlist-defaultallow',$_POST['filterlistdefaultallow']);
	logger($_actionPath.'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tfiltermode\t".$_POST['filtermode']."\n", $_config['logmax']);
	logger($_actionPath.'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tfilterlist-defaultdeny\t".preg_replace('/\n/', " ", $_POST['filterlistdefaultdeny'])."\n", $_config['logmax']);
	logger($_actionPath.'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tfilterlist-defaultallow\t".preg_replace('/\n/', " ", $_POST['filterlistdefaultallow'])."\n", $_config['logmax']);

	die("<h1>Filter updated</h1><script type=\"text/javascript\">setTimeout(function(){window.close();},1500);</script>");
}

if (!isset($_SESSION['lastLab']) || ($_SESSION['lastLab'] !== $_SESSION['lab'])) {
	// Set the lastLab to the new lab
	$_SESSION['lastLab'] = $_SESSION['lab'];
	// Drop filterlist and filterlmode on clients for sanity on a new lab session
	foreach ($_SESSION['allowedclients'] as $clientID=>$clientName) {
		$_actionPath = $dataDir.'/clients/'.$clientID.'/';
		if (file_exists($_actionPath.'/filtermode')) {
			unlink($_actionPath.'/filtermode');
			logger($_actionPath.'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tsanity-check\tNew lab session reset dropping filtermode\n", $_config['logmax']);
		}
		if (file_exists($_actionPath.'/filterlist')) {
			unlink($_actionPath.'/filterlist');
			logger($_actionPath.'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tsanity-check\tNew lab session reset dropping filterlist\n", $_config['logmax']);
		}
	}
}

?><html>
<head>
	<title>Open Screen Monitor</title>
	<meta http-equiv="refresh" content="3600">
	<link rel="stylesheet" href="https://www.w3schools.com/w3css/4/w3.css">
	<link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css">
	<link rel="stylesheet" href="./style.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
	<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
	<script defer src="https://use.fontawesome.com/releases/v5.0.6/js/all.js"></script>
	<script type="text/javascript">
		var imgcss = {'width':400,'height':300,'fontsize':14,'multiplier':1};
		var clientNames = <?php echo json_encode($_SESSION['allowedclients']); ?>

		function enableDevice(dev,sessionID){
			var img = $('<img />');
			img.attr("id","img_" + dev+'_'+sessionID);
			img.attr("alt",name);
			img.attr("src","unavailable.jpg");
			img.css({'width':imgcss.width * imgcss.multiplier,'height':imgcss.height * imgcss.multiplier});
			img.on('contextmenu',function(){return false;});
			img[0].onload = function(){setTimeout(function(){refreshImg(dev,sessionID);},1000+Math.random()*2000);};
			img[0].onerror = function(){setTimeout(function(){refreshImg(dev,sessionID);},1000+Math.random()*2000);};

			var h1 = $('<h1></h1>');
			h1.css({'font-size':imgcss.fontsize * imgcss.multiplier});
			h1.on('mousedown',function(){
				$('#showmenu').click();
				$('#urls_'+dev+'_'+sessionID)[0].scrollIntoView();
				var thisdiv = $(this).parent();
				if (!thisdiv.hasClass('fullscreen')){
					thisdiv.addClass('fullscreen');
					//this isn't dynamic to screen size changes but it works
					resizeFullscreen();
				}
				return false;
			});

			var div = $('<div class=\"dev active\"></div>');
			div.attr("id","div_" + dev+'_'+sessionID);
			div.append(h1);
			div.append(img);
			$('#activedevs').append(div);


			var info = $("<div id=\"urls_"+dev+'_'+sessionID+"\"><br /><div class=\"hline\"></div><div style=\"text-align:center;\"><b class=\"title\"></b><br />"+
				"<a href=\"#\" onmousedown=\"javascript:lockDev('"+dev+'_'+sessionID+"');return false;\"><i class=\"fas fa-lock\" title=\"Lock this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:unlockDev('"+dev+'_'+sessionID+"');return false;\"><i class=\"fas fa-unlock\" title=\"Unlock this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:openUrl('"+dev+'_'+sessionID+"');return false;\"><i class=\"fas fa-cloud\" title=\"Open an URL on this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:closeAllTabs('"+dev+'_'+sessionID+"');return false;\"><i class=\"fas fa-window-close\" title=\"Close all tabs on this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:sendMessage('"+dev+'_'+sessionID+"');return false;\"><i class=\"fas fa-envelope\" title=\"Send a message to this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:showLog('"+dev+'_'+sessionID+"');return false;\"><i class=\"fas fa-book\" title=\"Device log.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:screenshot('"+dev+'_'+sessionID+"');return false;\"><i class=\"fas fa-camera\" title=\"Take Screenshot.\"></i></a>" +
				"</div><br /><div class=\"tabs\"></div><div>");

			$('#urls').append(info);

		}

		function refreshImg(dev,sessionID) {
			var url = "?action=getImage&id=" + dev + "&sessionID=" + sessionID + "&time=" + (new Date()).getTime();
			$('#img_'+dev+'_'+sessionID).attr("src",url);
		}

		function closeAllTabs(dev){
			var div = $('#div_'+dev);
			$.post('?',{action:'closeAllTabs',id:div.data('dev'),sessionID:div.data('sessionID')});
		}

		function lockDev(dev){
			var div = $('#div_'+dev);
			$.post('?',{action:'lock',id:div.data('dev'),sessionID:div.data('sessionID')});
		}

		function unlockDev(dev){
			var div = $('#div_'+dev);
			$.post('?',{action:'unlock',id:div.data('dev'),sessionID:div.data('sessionID')});
		}

		function openUrl(dev){
			var div = $('#div_'+dev);
			var url1 = prompt('Please enter an URL', 'http://');
			if (url1 != '')
				$.post('?',{action:'openurl',id:div.data('dev'),sessionID:div.data('sessionID'),url:url1})
		}

		function sendMessage(dev){
			var div = $('#div_'+dev);
			var message1 = prompt('Please enter a message', '');
			if (message1 != '')
				$.post('?',{action:'sendmessage',id:div.data('dev'),sessionID:div.data('sessionID'),message:message1});
		}

		function showLog(dev){
			var div = $('#div_'+dev);
			$.post('?',{action:'log',id:div.data('dev')},function(data){
				$('#logdialog').html(data);
				$('#logdialog').dialog('open');
				$('#logdialog').dialog('option','title',dev);
			});
		}

		function screenshot(dev){
			var div = $('#div_'+dev);
			$.post('?',{action:'screenshot',id:div.data('dev'),sessionID:div.data('sessionID')},function(data){alert(data);})
		}

		function closeTab(dev,id) {
			var div = $('#div_'+dev);
			$.post('?',{action:'closetab',id:div.data('dev'),sessionID:div.data('sessionID'),tabid:id});
		}

		function refreshZoom(){
			$('#activedevs img').css({
				'width':imgcss.width * imgcss.multiplier,
				'height':imgcss.height * imgcss.multiplier,
			});
			$('#activedevs h1').css({
				'font-size':imgcss.fontsize * imgcss.multiplier,
			});
		}

		function sortDevs(){
			return;
			var active = $('#activedevs h1').toArray().sort(function(a,b){return (a.innerHTML+a.parentNode.id).toLowerCase().localeCompare((b.innerHTML+b.parentNode.id).toLowerCase());});
			for (var i=0;i<active.length;i++){
				$(active[i]).parent().detach().appendTo('#activedevs');
			}

			var inactive = $('#inactivedevs > div.hidden').toArray().sort(function(a,b){return (a.innerHTML+a.id).toLowerCase().localeCompare((b.innerHTML+b.id).toLowerCase());})
			for (var i=0;i<inactive.length;i++){
				$(inactive[i]).appendTo('#inactivedevs');
			}

			var inactive = $('#inactivedevs > div:not(.hidden)').toArray().sort(function(a,b){return (a.innerHTML+a.id).toLowerCase().localeCompare((b.innerHTML+b.id).toLowerCase());})
			for (var i=0;i<inactive.length;i++){
				$(inactive[i]).appendTo('#inactivedevs');
			}
		}

		function updateMeta() {
			$.get('?update',function(data,textStatus,jqXHR){
				if (jqXHR.getResponseHeader('X-OSM-Refresh') == 'page'){
					location.reload(true);
					return;
				}

				var time = (new Date()).getTime();
				for (dev in data) {
					var _active = false;
					$('#div_'+dev).remove();
					for (sessionID in data[dev]){
						var thisdiv = $('#div_'+dev+'_'+sessionID);
						thisdiv.data('dev',dev);
						thisdiv.data('sessionID',sessionID);

						if (thisdiv.length == 0 || !thisdiv.first().hasClass('hidden')){
							if (data[dev][sessionID].username == "") {
								$('#div_'+dev+'_'+sessionID).remove();
							} else {
								_active = true;
								//if we don't have an image for the device
								//add the image for the first time
								var img = $('#img_'+dev+'_'+sessionID);
								if (img.length == 0) {
									//we may have to delete it from the inactive devices
									$('#div_'+dev+'_'+sessionID).remove();
									enableDevice(dev,sessionID);
								}
								//img.attr("src","?action=getImage&id=" + dev + "&sessionID=" + sessionID + "&time=" + time);
								//update username
								$('#div_'+dev+'_'+sessionID+' h1').html(data[dev][sessionID].username+' ('+data[dev][sessionID].name+')');
								$('#urls_'+dev+'_'+sessionID+' .title').html(data[dev][sessionID].username+' ('+data[dev][sessionID].name+'\\'+data[dev][sessionID].ip+')');
								$('#urls_'+dev+'_'+sessionID+' .tabs').html(data[dev][sessionID].tabs);
								thisdiv.data('name',data[dev][sessionID].name);
								if (data[dev][sessionID].locked){
									if (!thisdiv.hasClass('locked')) {thisdiv.addClass('locked');}
								} else {
									if (thisdiv.hasClass('locked')) {thisdiv.removeClass('locked');}
								}
							}
						} else if (thisdiv.first().hasClass('hidden')){
							if (data[dev][sessionID].username == "") {
								$('#div_'+dev+'_'+sessionID).remove();
							} else {
								thisdiv.html('*'+data[dev][sessionID].username+'*<br />('+data[dev][sessionID].name+')');
							}
						}
					}

					if (!_active){
						$('#inactivedevs').append("<div id=\"div_" + dev + "\" class=\"dev\">"+clientNames[dev]+"</div>");
					}
				}

				var count = $('div.active').length;
				$('#activeCount').html(count);

				var newvalue = 1;
				if (count > 0) {
					newvalue =  Math.sqrt((window.innerWidth * window.innerHeight)/((imgcss.width+40) * (imgcss.height+40) * count))-0.05;
					if (newvalue > 1) newvalue = 1;
				}

				//if it is off by 10 clicks (.05) auto adjust
				if (Math.abs(newvalue - imgcss.multiplier) > .51){
					imgcss.multiplier = newvalue;
					refreshZoom();
				}

				sortDevs();
				setTimeout(updateMeta,4000);
			});
		}

		function resizeFullscreen(){
			var devicesdiv = document.getElementById('devicesdiv');
			var thisdiv = $('.fullscreen');
			thisdiv.css('top',devicesdiv.offsetTop+'px');
			thisdiv.css('left',devicesdiv.offsetLeft+'px');
			thisdiv.css('height',devicesdiv.offsetHeight+'px');
			thisdiv.css('width',devicesdiv.offsetWidth+'px');
		}

		$(document).ready(function(){
			//increase
			$('#increase_size').click(function(){imgcss.multiplier = imgcss.multiplier + .05;refreshZoom();});
			$('#decrease_size').click(function(){imgcss.multiplier = imgcss.multiplier - .05;refreshZoom();});
			$('#select_all').click(function(){
				$('#hidemenu').click();
			});
			$('#select_loggedin').click(function(){
				$('#hidemenu').click();
				if (imgcss.multiplier > 1) imgcss.multiplier = 1;
				refreshZoom();
			});

			$('#massLock').click(function(){$('#activedevs > div').each(function(){var id = this.id.substring(4);lockDev(id);});});
			$('#massUnlock').click(function(){$('#activedevs > div').each(function(){var id = this.id.substring(4);unlockDev(id);});});
			$('#massCloseAllTabs').click(function(){$('#activedevs > div').each(function(){var id = this.id.substring(4);closeAllTabs(id);});});
			$('#massOpenurl').click(function(){
				var url1 = prompt("Please enter an URL", "http://");
				if (url1 != '')
					$('#activedevs > div').each(function(){var div = $(this);$.post('?',{action:'openurl',id:div.data('dev'),sessionID:div.data('sessionID'),url:url1});});
			});

			$('#massSendmessage').click(function(){
				var message1 = prompt("Please enter a message", "");
				if (message1 != '')
					$('#activedevs > div').each(function(){var div = $(this);$.post('?',{sendmessage:div.data('dev'),sessionID:div.data('sessionID'),message:message1});});
			});

			$('#massHide').click(function(){
				$('div.dev').addClass('hidden');
				$('div.active').removeClass('active')
					.each(function(){var dev=$(this);dev.html(dev.data('name'));})
					.prependTo('#inactivedevs');
				updateMeta();
			});
			$('#massShow').click(function(){$('div.hidden').remove();updateMeta();});

			$("#devicesdiv" ).on( "mousedown", "div.dev", function(e) {
				var thisdiv = $(this);

				if (e.which == 1 || !e.which) {
					//left click
					if (thisdiv.hasClass('active')){
						if (thisdiv.hasClass('fullscreen')) {
							thisdiv.removeClass('fullscreen');
							//$('#activedevs > div,#inactivedevs > div').css('display','block');
							thisdiv.css('top','auto');
							thisdiv.css('left','auto');
							thisdiv.css('height','auto');
							thisdiv.css('width','auto');
						} else {
							thisdiv.addClass('fullscreen');
							//this isn't dynamic to screen size changes but it works
							resizeFullscreen();

							//$('#activedevs > div:not(.fullscreen),#inactivedevs > div').css('display','none');
						}
					}
				} else if (e.which == 3 && !thisdiv.hasClass('fullscreen')) {
					//right click
					if (thisdiv.hasClass('active')){
						//hide it
						thisdiv.removeClass('active');
						thisdiv.addClass('hidden');
						thisdiv.html(thisdiv.data('name'));
						thisdiv.prependTo('#inactivedevs');
					} else {
						if (thisdiv.hasClass('hidden')) {
							//show it
							thisdiv.remove();
							updateMeta();
						}
					}
				}

				sortDevs();
				return false;
			});

			updateMeta();
			$("#logdialog").dialog({
				dialogClass: 'logdialog',
				show: {
					effect: "blind",
					duration: 1000
				},
				hide: {
					effect: "fade",
					duration: 1000
				},
				autoOpen: false
			});

			$('#showmenu').click(function(){
				$('#menu').show();
				$(this).hide();
				$('#hidemenu').show();
				resizeFullscreen();
			});

			$('#hidemenu').click(function(){
				$('#menu').hide();
				$(this).hide();
				$('#showmenu').show();
				resizeFullscreen();
			});

			/* logic trigger apply button for filter */
			$('#filterlistdefaultdeny, #filterlistdefaultallow').on('input propertychange', function() {
				if(this.value.length){
					$('#applyfilter').show();
				}
			});
			$('input[name=filtermode]').change(function(){
				$('#filterlistdefaultdeny').hide();
				$('#filterlistdefaultallow').hide();
				$('#filterlistheader').show();

				if (this.value == 'defaultdeny'){$('#filterlistdefaultdeny').show();}
				if (this.value == 'defaultallow'){$('#filterlistdefaultallow').show();}
				if (this.value == 'disabled'){$('#filterlistheader').hide();}
				$('#applyfilter').show();
			});
			$('input[name=filtermode]:checked').change();
			$('#applyfilter').click(function (){$(this).hide();});
			$('#applyfilter').hide();
		});
	</script>
</head>
<body>
<div id="logdialog"></div>
<div id="box">
	<div id="box-top">
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="hidemenu"  style="display: none;" value="Hide Side Menu" />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="showmenu" value="Show Side Menu" />
		|
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="decrease_size" value="    -    " />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="increase_size" value="    +    " />
		|
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massHide" value="Hide All" />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massShow" value="Show All" />
		|
		<a href="index.php">Change Lab</a> | Current Lab: <?php echo htmlentities($_SESSION['lab'])."<div style=\"display:inline; float:right; padding-top:5px; padding-right:10px;\">Total Active: <span id=\"activeCount\"></span> | Version ".$_config['version']."</div>"; ?>
	</div>
	<div id="box-bottom">
		<div id="menu">
			<?php
			/* FIXME FIXME MERGE get the filter list from first device ... not the best method but this is beta
			$clientID = array_keys($_SESSION['allowedclients'])[0];
			$filtermode = "disabled";
			$filterlist = "";
			if (file_exists($dataDir.'/config/'.$clientID.'/filtermode') && file_exists($dataDir.'/config/'.$clientID.'/filterlist')){
				$filtermode = file_get_contents($dataDir.'/config/'.$clientID.'/filtermode');
				$filterlist = file_get_contents($dataDir.'/config/'.$clientID.'/filterlist');
			*/
			$filtermode = "disabled";
			$filterlistdefaultdeny = "";
			$filterlistdefaultallow = "";
			if (file_exists($dataDir.'/config/'.base64_encode($_SESSION['lab']).'/filtermode') && file_exists($dataDir.'/config/'.base64_encode($_SESSION['lab']).'/filterlist-defaultdeny') && file_exists($dataDir.'/config/'.base64_encode($_SESSION['lab']).'/filterlist-defaultallow')){
				$filtermode = file_get_contents($dataDir.'/config/'.base64_encode($_SESSION['lab']).'/filtermode');
				$filterlistdefaultdeny = file_get_contents($dataDir.'/config/'.base64_encode($_SESSION['lab']).'/filterlist-defaultdeny');
				$filterlistdefaultallow = file_get_contents($dataDir.'/config/'.base64_encode($_SESSION['lab']).'/filterlist-defaultallow');
			}
			?>
			<div style="text-align:center;">
				<br /><a href="filterlog.php" target="_blank" class="w3-button w3-white w3-border w3-border-blue w3-round-large">View Browsing History</a>
				<br /><br /><input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massLock" value="Lock All" />
				<br /><br /><input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massUnlock" value="Unlock All" />
				<br /><br /><input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massOpenurl" value="Open Url on All" />
				<br /><br /><input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massCloseAllTabs" value="Close All Tabs" />
				<br /><br /><input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massSendmessage" value="Send Message to All" />
			</div>
			<hr />
			<h3>Lab Filter (Beta)</h3>
			<?php echo "Version ".$_config['version']; ?>
			<div class="hline" style="height:2px"></div>
			<form id="filter" method="post" target="_blank" action="?filter">
				<section id="first" class="section">
					<div class="container">
						<input type="radio" id="left" name="filtermode" value="defaultallow" <?php if ($filtermode == 'defaultallow') echo 'checked="checked"'; ?> />
						<label for="left"><span class="radio"><div class="tooltip">Picket Fence<span class="tooltiptext">Block sites matching listed patterns.</span></div></span></label>
					</div>
					<div class="container">
						<input type="radio" id="center" name="filtermode" value="defaultdeny" <?php if ($filtermode == 'defaultdeny') echo 'checked="checked"'; ?> />
						<label for="center"><span class="radio"><div class="tooltip">Walled Garden<span class="tooltiptext">Allow only sites matching listed patterns.</span></div></span></label>
					</div>
					<div class="container">
						<input type="radio" id="right" name="filtermode" value="disabled" <?php if ($filtermode == 'disabled') echo 'checked="checked"'; ?> />
						<label for="right"><span class="radio"><div class="tooltip">Disabled<span class="tooltiptext">Disable all filter operations.</span></div></span></label>
					</div>
				</section>
				<div style="text-align:center;">
					<div id="filterlistheader">Site URLs or keywords (one per line):</div>
					<textarea name="filterlistdefaultdeny" id="filterlistdefaultdeny" style="text-align:left;width: 90%;height:200px;"><?php echo htmlentities($filterlistdefaultdeny); ?></textarea>
					<textarea name="filterlistdefaultallow" id="filterlistdefaultallow" style="text-align:left;width: 90%;height:200px;"><?php echo htmlentities($filterlistdefaultallow); ?></textarea>
					<input type="submit" id="applyfilter" onclick="$('#applyfilter').hide();" value="Apply Changes" class="w3-button w3-white w3-border w3-border-blue w3-round-large" />
				</div>
			</form>
			<h5>Device URLs - Data</h5>
			<div id="urls"></div>
			<br />
			<div style="height:100%;"></div>
		</div>
		<div id="devicesdiv">
			<div id="activedevs"></div>
			<div id="inactivedevs"></div>
		</div>
	</div>
</body>
</html>
