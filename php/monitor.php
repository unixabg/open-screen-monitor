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

	foreach ($_SESSION['alloweddevices'] as $deviceID=>$deviceName) {
		$files = glob($dataDir.'/devices/'.$deviceID.'/*',GLOB_ONLYDIR);
		foreach ($files as $file){
			$sessionID = basename($file);
			$file .= '/screenshot.jpg';
			// Assure who needs access here FIXME
			if (is_readable($file) && filemtime($file) >= time() - 30 ) {
				$toReturn[$deviceID.'_'.$sessionID] = base64_encode(file_get_contents($file));
			} else {
				$toReturn[$deviceID.'_'.$sessionID] = '';
			}
		}
	}

	//send it back
	ini_set("zlib.output_compression", "On");

	header('Content-Type: application/json');
	die(json_encode($toReturn));
}

// Actions are passed with the device id in the $_POST[] to get the full path we append that device id to the $dataDir.'/devices'
if (isset($_POST['log'])){
	if (isset($_SESSION['alloweddevices'][$_POST['log']])) {
		$_actionPath = $dataDir.'/devices/'.$_POST['log'];
		die(preg_replace("/\r\n|\r|\n/",'<br />',file_get_contents($_actionPath.'/log')));
	}
	die();
}

if (isset($_POST['lock'])){
	if(isset($_POST['sessionID']) && is_numeric($_POST['sessionID']) && isset($_SESSION['alloweddevices'][$_POST['lock']])) {
		$_actionPath = $dataDir.'/devices/'.$_POST['lock'].'/'.$_POST['sessionID'];
		touch($_actionPath.'/lock');
		logger(dirname($_actionPath).'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tlocked\t\n", $_config['logmax']);
	}
	die();
}

if (isset($_POST['unlock'])){
	if (isset($_POST['sessionID']) && is_numeric($_POST['sessionID']) && isset($_SESSION['alloweddevices'][$_POST['unlock']])) {
		$_actionPath = $dataDir.'/devices/'.$_POST['unlock'].'/'.$_POST['sessionID'];
		if (file_exists($_actionPath.'/lock')) unlink($_actionPath.'/lock');
		logger(dirname($_actionPath).'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tunlocked\t\n", $_config['logmax']);
		touch($_actionPath.'/unlock');
	}
	die();
}

if (isset($_POST['openurl']) && isset($_POST['url'])){
	if (isset($_POST['sessionID']) && is_numeric($_POST['sessionID']) && isset($_SESSION['alloweddevices'][$_POST['openurl']]) && filter_var($_POST['url'],FILTER_VALIDATE_URL,FILTER_FLAG_HOST_REQUIRED)) {
		$_actionPath = $dataDir.'/devices/'.$_POST['openurl'].'/'.$_POST['sessionID'];
		file_put_contents($_actionPath.'/openurl',$_POST['url']);
		logger(dirname($_actionPath).'/log', date('YmdHis',time())."\t".$_SESSION['email']."\topenurl\t".$_POST['url']."\n", $_config['logmax']);
	}
	die();
}

if (isset($_POST['closetab']) && isset($_POST['tabid'])){
	if (isset($_POST['sessionID']) && is_numeric($_POST['sessionID']) && isset($_SESSION['alloweddevices'][$_POST['closetab']])) {
		$_actionPath = $dataDir.'/devices/'.$_POST['closetab'].'/'.$_POST['sessionID'];
		file_put_contents($_actionPath.'/closetab',$_POST['tabid']."\n",FILE_APPEND);
		//FIXME - add title of tab later
		logger(dirname($_actionPath).'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tclosetab\t\n", $_config['logmax']);
	}
	die();
}

if (isset($_POST['closeAllTabs'])){
	if (isset($_POST['sessionID']) && is_numeric($_POST['sessionID']) && isset($_SESSION['alloweddevices'][$_POST['closeAllTabs']])) {
		$_actionPath = $dataDir.'/devices/'.$_POST['closeAllTabs'].'/'.$_POST['sessionID'];
		if (file_exists($_actionPath.'/tabs')) {
			$temp = json_decode(file_get_contents($_actionPath.'/tabs'),true);
			foreach ($temp as $tab) {
				//$tab['id']
				file_put_contents($_actionPath.'/closetab',$tab['id']."\n",FILE_APPEND);
				//FIXME - add title of tab later
				logger(dirname($_actionPath).'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tclosetab\t\n", $_config['logmax']);
			}
		}
	}
	die();
}

if (isset($_POST['sendmessage'])){
	if (isset($_POST['sessionID']) && is_numeric($_POST['sessionID']) && isset($_POST['message']) && isset($_SESSION['alloweddevices'][$_POST['sendmessage']])) {
		$_actionPath = $dataDir.'/devices/'.$_POST['sendmessage'].'/'.$_POST['sessionID'];
		file_put_contents($_actionPath.'/messages',$_SESSION['name']." says ... \t".$_POST['message']."\n",FILE_APPEND);
		logger(dirname($_actionPath).'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tmessages\t".$_POST['message']."\n", $_config['logmax']);
	}
	die();
}

if (isset($_POST['screenshot'])){
	if (isset($_POST['sessionID']) && is_numeric($_POST['sessionID']) && isset($_SESSION['alloweddevices'][$_POST['screenshot']])){
		$_actionPath = $dataDir.'/devices/'.$_POST['screenshot']."/".$_POST['sessionID'];
		if (file_exists($_actionPath."/screenshot.jpg")){
			logger(dirname($_actionPath).'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tscreenshot\t\n", $_config['logmax']);

			$text = "Screenshot: ".date("Y-m-d h:i a")."\r\n\r\n";
			if (file_exists($_actionPath.'/username')) $text .= "Username: ".file_get_contents($_actionPath.'/username')."\r\n";
			if (file_exists($_actionPath.'/tabs')){
				$tabs = json_decode(file_get_contents($_actionPath.'/tabs'),true);
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
			$raw .= chunk_split(base64_encode(file_get_contents($_actionPath.'/screenshot.jpg')))."\r\n\r\n";
			$raw .= "--".$uid."--";

			echo mail($_SESSION['email'], "OSM Screenshot", $raw, $header) ? "Successfully Sent Screenshot To ".$_SESSION['email'] : "Error Sending Screenshot";
		} else {
			echo "No Screenshot to send";
		}
	}
	die();
}

if (isset($_GET['update'])) {
	$data = array();
	foreach ($_SESSION['alloweddevices'] as $deviceID=>$deviceName) {
		$data[$deviceID] = array();
		$folders = glob($dataDir.'/devices/'.$deviceID.'/*',GLOB_ONLYDIR);
		foreach ($folders as $folder){
			$sessionID = basename($folder);
			$folder .= '/';
			$data[$deviceID][$sessionID] = array('name'=>$deviceName,'username'=>'','tabs'=>array());

			if (file_exists($folder.'ping') && filemtime($folder.'ping') > time()-30) {
				$data[$deviceID][$sessionID]['ip'] = (file_exists($folder.'ip') ? file_get_contents($folder.'ip') : "Unknown IP");
				$data[$deviceID][$sessionID]['username'] = (file_exists($folder.'username') ? file_get_contents($folder.'username') : "Unknown User");
				$data[$deviceID][$sessionID]['tabs'] = "";
				$data[$deviceID][$sessionID]['locked'] = file_exists($folder.'lock');
				if (file_exists($folder.'tabs')) {
					$temp = json_decode(file_get_contents($folder.'tabs'),true);
					foreach ($temp as $tab) {
						$data[$deviceID][$sessionID]['tabs'] .= "<a href=\"#\" onmousedown=\"javscript:closeTab('".$deviceID."_".$sessionID."','".$tab['id']."');return false;\"><i class=\"fas fa-trash\" title=\"Close this tab.\"></i></a> ".htmlspecialchars($tab['title']).'<br />'.substr(htmlspecialchars($tab['url']),0,500).'<br />';
					}
				}
			}
		}
	}

	header('Content-Type: application/json');
	die(json_encode($data));
}

if (isset($_POST['filterlist']) && isset($_POST['filtermode']) && in_array($_POST['filtermode'],array('defaultallow','defaultdeny','disabled'))) {
	//only allow printable characters and new lines
	$_POST['filterlist'] = preg_replace('/[\x00-\x09\x20\x0B-\x1F\x7F-\xFF]/', '', $_POST['filterlist']);
	//let us do a second pass to drop empty lines and correctly format
	$_POST['filterlist'] = strtolower(trim(preg_replace('/\n+/', "\n", $_POST['filterlist'])));

	foreach ($_SESSION['alloweddevices'] as $deviceID=>$deviceName) {
		$_actionPath = $dataDir.'/devices/'.$deviceID.'/';
		file_put_contents($_actionPath.'filtermode',$_POST['filtermode']);
		file_put_contents($_actionPath.'filterlist',$_POST['filterlist']);
		logger($_actionPath.'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tfiltermode\t".$_POST['filtermode']."\n", $_config['logmax']);
		logger($_actionPath.'/log', date('YmdHis',time())."\t".$_SESSION['email']."\tfilterlist\t".preg_replace('/\n/', " ", $_POST['filterlist'])."\n", $_config['logmax']);
	}
	die("<h1>Filter updated</h1><script type=\"text/javascript\">setTimeout(function(){window.close();},1500);</script>");
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
		var deviceNames = <?php echo json_encode($_SESSION['alloweddevices']); ?>

		function enableDevice(dev){
			var img = $('<img />');
			img.attr("id","img_" + dev);
			img.attr("alt",name);
			img.attr("src","unavailable.jpg");
			img.css({'width':imgcss.width * imgcss.multiplier,'height':imgcss.height * imgcss.multiplier});
			img.on('contextmenu',function(){return false;});


			var h1 = $('<h1></h1>');
			h1.css({'font-size':imgcss.fontsize * imgcss.multiplier});
			h1.on('mousedown',function(){
				var div = $(this).parent();
				div.find('img, div.info').toggle();
				return false;
			});

			var info = $("<div class=\"info\">"+
				"<a href=\"#\" onmousedown=\"javascript:lockDev('"+dev+"');return false;\"><i class=\"fas fa-lock\" title=\"Lock this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:unlockDev('"+dev+"');return false;\"><i class=\"fas fa-unlock\" title=\"Unlock this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:openUrl('"+dev+"');return false;\"><i class=\"fas fa-cloud\" title=\"Open an URL on this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:closeAllTabs('"+dev+"');return false;\"><i class=\"fas fa-window-close\" title=\"Close all tabs on this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:sendMessage('"+dev+"');return false;\"><i class=\"fas fa-envelope\" title=\"Send a message to this device.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:showLog('"+dev+"');return false;\"><i class=\"fas fa-book\" title=\"Device log.\"></i></a> | " +
				"<a href=\"#\" onmousedown=\"javascript:screenshot('"+dev+"');return false;\"><i class=\"fas fa-camera\" title=\"Take Screenshot.\"></i></a>" +
				"<br /><font size=\"4\">Tabs</font><div class=\"hline\"></div><div class=\"tabs\"></div></div>").css({'width':imgcss.width * imgcss.multiplier,'height':imgcss.height * imgcss.multiplier});

			var div = $('<div class=\"dev active\"></div>');
			div.attr("id","div_" + dev);
			div.append(h1);
			div.append(img);
			div.append(info);

			$('#activedevs').append(div);
		}

		function closeAllTabs(dev){
			var div = $('#div_'+dev);
			$.post('?',{closeAllTabs:div.data('dev'),sessionID:div.data('sessionID')});
		}

		function lockDev(dev){
			var div = $('#div_'+dev);
			$.post('?',{lock:div.data('dev'),sessionID:div.data('sessionID')});
		}

		function unlockDev(dev){
			var div = $('#div_'+dev);
			$.post('?',{unlock:div.data('dev'),sessionID:div.data('sessionID')});
		}

		function openUrl(dev){
			var div = $('#div_'+dev);
			var url1 = prompt('Please enter an URL', 'http://');
			if (url1 != '')
				$.post('?',{openurl:div.data('dev'),sessionID:div.data('sessionID'),url:url1})
		}

		function sendMessage(dev){
			var div = $('#div_'+dev);
			var message1 = prompt('Please enter a message', '');
			if (message1 != '')
				$.post('?',{sendmessage:div.data('dev'),sessionID:div.data('sessionID'),message:message1});
		}

		function showLog(dev){
			var div = $('#div_'+dev);
			$.post('?',{log:div.data('dev')},function(data){
				$('#logdialog').html(data);
				$('#logdialog').dialog('open');
				$('#logdialog').dialog('option','title',dev);
			});
		}

		function screenshot(dev){
			var div = $('#div_'+dev);
			$.post('?',{screenshot:div.data('dev'),sessionID:div.data('sessionID')},function(data){alert(data);})
		}

		function closeTab(dev,id) {
			var div = $('#div_'+dev);
			$.post('?',{closetab:div.data('dev'),sessionID:div.data('sessionID'),tabid:id});
		}

		function updateAllImages() {
			$.get('?images',function(data){
				var image;
				for(device in data) {
					image = document.getElementById('img_'+device);
					if (image) {
						if (data[device] == '')
							image.src = 'unavailable.jpg';
						else
							image.src = 'data:image/jpg;base64,'+data[device];
					}
				}
				setTimeout(updateAllImages,4000);
			}).fail(function(){
				setTimeout(updateAllImages,4000);
			});
			updateMeta();
		}

		function refreshZoom(){
			$('img, div.info').css({
				'width':imgcss.width * imgcss.multiplier,
				'height':imgcss.height * imgcss.multiplier,
			});
			$('h1').css({
				'font-size':imgcss.fontsize * imgcss.multiplier,
			});
		}

		function sortDevs(){
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

				var URLdata = "";
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
								if ($('#img_'+dev+'_'+sessionID).length == 0) {
									//we may have to delete it from the inactive devices
									$('#div_'+dev+'_'+sessionID).remove();
									enableDevice(dev+'_'+sessionID);
								}

								//update username
								$('#div_'+dev+'_'+sessionID+' h1').html(data[dev][sessionID].username+' ('+data[dev][sessionID].name+')');
								$('#div_'+dev+'_'+sessionID+' div.tabs').html(data[dev][sessionID].tabs);
								thisdiv.data('name',data[dev][sessionID].name);
								if (data[dev][sessionID].locked){
									if (!thisdiv.hasClass('locked')) {thisdiv.addClass('locked');}
								} else {
									if (thisdiv.hasClass('locked')) {thisdiv.removeClass('locked');}
								}

								URLdata = URLdata + "<div class=\"hline\" style=\"height:2px\"></div><b>"+data[dev][sessionID].username+' ('+data[dev][sessionID].name +' - '+data[dev][sessionID].ip +')</b><br />'+$('#div_'+dev+'_'+sessionID+' div.info').html();
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
						$('#inactivedevs').append("<div id=\"div_" + dev + "\" class=\"dev\">"+deviceNames[dev]+"</div>");
					}
				}

				$('#urls').html(URLdata);

				if ($('.fullscreen').length == 1)
					$('#activedevs > div:not(.fullscreen),#inactivedevs > div').css('display','none');

				var count = $('div.active').length;
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
			});
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
					$('#activedevs > div').each(function(){var div = $(this);$.post('?',{openurl:div.data('dev'),sessionID:div.data('sessionID'),url:url1});});
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

			$('#fullscreenBG').click(function(){
				$('.fullscreen').mousedown();
			});

			$("#devicesdiv" ).on( "mousedown", "div.dev", function(e) {
				var thisdiv = $(this);

				if (e.which == 1 || !e.which) {
					//left click
					if (thisdiv.hasClass('active') && thisdiv.find('div.info').css('display') == 'none'){
						if (thisdiv.hasClass('fullscreen')) {
							thisdiv.removeClass('fullscreen');
							$('#fullscreenBG').css('display','none');
							$('#activedevs > div,#inactivedevs > div').css('display','block');
						} else {
							thisdiv.addClass('fullscreen');
							$('#fullscreenBG').css('display','block');
							$('#activedevs > div:not(.fullscreen),#inactivedevs > div').css('display','none');
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

			setInterval(updateMeta, 30000);
			updateMeta();
			updateAllImages();
			$('#applyfilter').hide();

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
				$('#devicesdiv').css('margin-left','350px');
			});

			$('#hidemenu').click(function(){
				$('#menu').hide();
				$(this).hide();
				$('#showmenu').show();
				$('#devicesdiv').css('margin-left','0px');
			});

			/* logic trigger apply button for filter */
			$('#filterlist').on('input propertychange', function() {
				if(this.value.length){
					$('#applyfilter').show();
				}
			});

			$('#applyfilter').click(function (){
				$(this).hide();
			});
		});
	</script>
</head>
<body>
<div id="fullscreenBG"><div style="padding: 5px;">X</div></div>
<div id="wrapper">
	<div id="topmenu">
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="decrease_size" value="    -    " />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="increase_size" value="    +    " />
		|
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="hidemenu"  style="display: none;" value="Hide Side Menu" />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="showmenu" value="Show Side Menu" />
		|
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massLock" value="Lock All" />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massUnlock" value="Unlock All" />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massOpenurl" value="Open Url on All" />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massCloseAllTabs" value="Close All Tabs" />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massSendmessage" value="Send Message to All" />
		|
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massHide" value="Hide All" />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massShow" value="Show All" />
		|
		<a href="index.php">Change Lab</a> | Current Lab: <?php echo htmlentities($_SESSION['lab'])."<div style=\"display:inline; float:right; padding-top:5px; padding-right:10px;\">Version ".$_config['version']."</div>"; ?>
	</div>
	<div id="menu">
	<?php
	//FIXME get the filter list from first device ... not the best method but this is beta
	$deviceID = array_keys($_SESSION['alloweddevices'])[0];
	$filtermode = "";
	$filterlist = "";
	if (file_exists($dataDir.'/devices/'.$deviceID.'/filtermode') && file_exists($dataDir.'/devices/'.$deviceID.'/filterlist')){
		$filtermode = file_get_contents($dataDir.'/devices/'.$deviceID.'/filtermode');
		$filterlist = file_get_contents($dataDir.'/devices/'.$deviceID.'/filterlist');
	} else {
		$filtermode = "disabled";
	}
	?>
	<a href="filterlog.php" target="_blank">View Browsing History</a><hr />
	<h3>Lab Filter (Beta)</h3> <?php echo "Version ".$_config['version']; ?>
	<div class="hline" style="height:2px"></div>
	<form id="filter" method="post" target="_blank" action="?filter">
		<section id="first" class="section">
			<div class="container">
				<input type="radio" id="left" name="filtermode" value="defaultallow" onclick="$('#applyfilter').show();" <?php if ($filtermode == 'defaultallow') echo 'checked="checked"'; ?> />
				<label for="left"><span class="radio"><div class="tooltip">Picket Fence<span class="tooltiptext">Block sites matching listed patterns.</span></div></span></label>
			</div>
			<div class="container">
				<input type="radio" id="center" name="filtermode" value="defaultdeny" onclick="$('#applyfilter').show();" <?php if ($filtermode == 'defaultdeny') echo 'checked="checked"'; ?> />
				<label for="center"><span class="radio"><div class="tooltip">Walled Garden<span class="tooltiptext">Allow only sites matching listed patterns.</span></div></span></label>
			</div>
			<div class="container">
				<input type="radio" id="right" name="filtermode" value="disabled" onclick="$('#applyfilter').show();" <?php if ($filtermode == 'disabled') echo 'checked="checked"'; ?> />
				<label for="right"><span class="radio"><div class="tooltip">Disabled<span class="tooltiptext">Disable all filter operations.</span></div></span></label>
			</div>
		</section>
		Site URLs or keywords (one per line):
		<textarea name="filterlist" id="filterlist" style="width: 90%;height:50px;"><?php echo htmlentities($filterlist); ?></textarea>
		<input type="submit" id="applyfilter" onclick="$('#applyfilter').hide();" value="Apply Changes" class="w3-button w3-white w3-border w3-border-blue w3-round-large" />
	</form>
	<h5>Device URLs - Data</h5>
	<div id="urls"></div>
	<br />
	</div>
	<div id="devicesdiv">
		<div id="activedevs"></div>
		<div id="inactivedevs"></div>
	</div>
</div>
<div id="logdialog"></div>
<!-- <?php print_r($_SESSION); ?>-->
</body>
</html>
