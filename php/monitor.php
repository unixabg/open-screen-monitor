<?php
session_start();

//Authenticate here
if (!isset($_SESSION['validuntil']) || $_SESSION['validuntil'] < time()){
	session_destroy();
	header('Location: index.php?');
	die();
}


//set data path
$dataDir='../../osm-data';


//return all images after ctime
if (isset($_GET['images'])) {
	ini_set('memory_limit','256M');
	$toReturn = array();

	foreach ($_SESSION['alloweddevices'] as $deviceID=>$deviceName) {
		$file = $dataDir.'/'.$deviceID.'/screenshot.jpg';
		// Assure who needs access here FIXME
		if (is_readable($file) && filemtime($file) >= time() - 30 ) {
			$toReturn[$deviceID] = base64_encode(file_get_contents($file));
		} else {
			$toReturn[$deviceID] = '';
		}
	}

	//send it back
	ini_set("zlib.output_compression", "On");

	header('Content-Type: application/json');
	die(json_encode($toReturn));
}

// Actions are passed with the device id in the $_POST[] to get the full path we append that device id to the $dataDir
if (isset($_POST['lock']) && isset($_SESSION['alloweddevices'][$_POST['lock']])) {
	$_actionPath = $dataDir.'/'.$_POST['lock'];
	touch($_actionPath.'/lock');
	die();
}

if (isset($_POST['unlock']) && isset($_SESSION['alloweddevices'][$_POST['unlock']])) {
	$_actionPath = $dataDir.'/'.$_POST['unlock'];
	touch($_actionPath.'/unlock');
	die();
}

if (isset($_POST['openurl']) && isset($_POST['url']) && isset($_SESSION['alloweddevices'][$_POST['openurl']]) && filter_var($_POST['url'],FILTER_VALIDATE_URL,FILTER_FLAG_HOST_REQUIRED)) {
	$_actionPath = $dataDir.'/'.$_POST['openurl'];
	file_put_contents($_actionPath.'/openurl',$_POST['url']);
	die();
}

if (isset($_POST['closetab']) && isset($_POST['tabid']) && isset($_SESSION['alloweddevices'][$_POST['closetab']])) {
	$_actionPath = $dataDir.'/'.$_POST['closetab'];
	file_put_contents($_actionPath.'/closetab',$_POST['tabid']."\n",FILE_APPEND);
	die();
}

if (isset($_POST['sendmessage']) && isset($_POST['message']) && isset($_SESSION['alloweddevices'][$_POST['sendmessage']])) {
	$_actionPath = $dataDir.'/'.$_POST['sendmessage'];
	file_put_contents($_actionPath.'/messages',$_SESSION['name']." says ... \t".$_POST['message']."\n",FILE_APPEND);
	die();
}

if (isset($_GET['update'])) {
	$data = array();
	foreach ($_SESSION['alloweddevices'] as $deviceID=>$deviceName) {
		$folder = $dataDir.'/'.$deviceID.'/';
		$data[$deviceID] = array('name'=>$deviceName,'username'=>'','tabs'=>array());

		if (file_exists($folder.'ping') && filemtime($folder.'ping') > time()-30) {
			$data[$deviceID]['ip'] = (file_exists($folder.'ip') ? file_get_contents($folder.'ip') : "Unknown IP");
			$data[$deviceID]['username'] = (file_exists($folder.'username') ? file_get_contents($folder.'username') : "Unknown User");
			$data[$deviceID]['tabs'] = "";
			if (file_exists($folder.'tabs')) {
				$temp = json_decode(file_get_contents($folder.'tabs'),true);
				foreach ($temp as $tab) {
					$data[$deviceID]['tabs'] .= "<a href=\"#\" onmousedown=\"javscript:closeTab('".$deviceID."','".$tab['id']."');return false;\"><i class=\"fas fa-trash\" title=\"Close this tab.\"></i></a> ".htmlspecialchars($tab['title']).'<br />'.substr(htmlspecialchars($tab['url']),0,500).'<br />';
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
	$_POST['filterlist'] = trim(preg_replace('/\n+/', "\n", $_POST['filterlist']));

	foreach ($_SESSION['alloweddevices'] as $deviceID=>$deviceName) {
		$_devicePath = $dataDir.'/'.$deviceID.'/';
		file_put_contents($_devicePath.'filtermode',$_POST['filtermode']);
		file_put_contents($_devicePath.'filterlist',$_POST['filterlist']);
	}
	die("<h1>Filter updated</h1><script type=\"text/javascript\">setTimeout(function(){window.close();},1500);</script>");
}

?><html>
<head>
	<title>Open Screen Monitor</title>
	<meta http-equiv="refresh" content="3600">
	<link rel="stylesheet" href="https://www.w3schools.com/w3css/4/w3.css">
	<link rel="stylesheet" href="./style.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
	<script defer src="https://use.fontawesome.com/releases/v5.0.6/js/all.js"></script>
	<script type="text/javascript">
		var imgcss = {'width':400,'height':300,'fontsize':14,'multiplier':1};

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

			var info = $("<div class=\"info\"><a href=\"#\" onmousedown=\"javascript:$.post('?',{lock:'"+dev+"'});return false;\"><i class=\"fas fa-lock\" title=\"Lock this device.\"></i></a> | <a href=\"#\" onmousedown=\"javascript:$.post('?',{unlock:'"+dev+"'});return false;\"><i class=\"fas fa-unlock\" title=\"Unlock this device.\"></i></a> | <a href=\"#\" onmousedown=\"javascript:var url1 = prompt('Please enter an URL', 'http://'); if (url1 != '') $.post('?',{openurl:'"+dev+"',url:url1});return false;\"><i class=\"fas fa-cloud\" title=\"Open an URL on this device.\"></i></a> | <a href=\"#\" onmousedown=\"javascript:var message1 = prompt('Please enter a message', ''); if (message1 != '') $.post('?',{sendmessage:'"+dev+"',message:message1});return false;\"><i class=\"fas fa-envelope\" title=\"Send a message to this device.\"></i></a><br /><font size=\"4\">Tabs</font><div class=\"hline\"></div><div class=\"tabs\"></div></div>").css({'width':imgcss.width * imgcss.multiplier,'height':imgcss.height * imgcss.multiplier});

			var div = $('<div class=\"dev active\"></div>');
			div.attr("id","div_" + dev);
			div.append(h1);
			div.append(img);
			div.append(info);

			$('#activedevs').append(div);
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
		}

		function closeTab(dev,id) {
			$.post('?',{closetab:dev,tabid:id});
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
			$.get('?update',function(data){
				var URLdata = "";
				for (var dev in data) {
					var thisdiv = $('#div_'+dev);

					if (thisdiv.length == 0 || !thisdiv.first().hasClass('hidden')){
						if (data[dev].username == "") {
							$('#div_'+dev).remove();
							$('#inactivedevs').append("<div id=\"div_" + dev + "\" class=\"dev\">"+data[dev].name+"</div>");
						} else {
							//if we don't have an image for the device
							//add the image for the first time
							if ($('#img_'+dev).length == 0) {
								//we may have to delete it from the inactive devices
								$('#div_'+dev).remove();
								enableDevice(dev);
							}

							//update username
							$('#div_'+dev+' h1').html(data[dev].username+' ('+data[dev].name+')');
							$('#div_'+dev+' div.tabs').html(data[dev].tabs);
							$('#div_'+dev).data('name',data[dev].name);

							URLdata = URLdata + "<div class=\"hline\" style=\"height:2px\"></div><b>"+data[dev].username+' ('+data[dev].name +' - '+data[dev].ip +')</b><br />'+$('#div_'+dev+' div.info').html();
						}
					} else if (thisdiv.first().hasClass('hidden')){
						thisdiv.html('*'+data[dev].username+'*<br />('+data[dev].name+')');
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

				//if it is off by 5 clicks (.05) auto adjust
				if (Math.abs(newvalue - imgcss.multiplier) > .26){
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

			$('#massLock').click(function(){$('#activedevs > div').each(function(){var id = this.id.substring(4);$.post('?',{lock:id});});});
			$('#massUnlock').click(function(){$('#activedevs > div').each(function(){var id = this.id.substring(4);$.post('?',{unlock:id});});});
			$('#massOpenurl').click(function(){
				var url1 = prompt("Please enter an URL", "http://");
				if (url1 != '')
					$('#activedevs > div').each(function(){var id = this.id.substring(4);$.post('?',{openurl:id,url:url1});});
			});

			$('#massSendmessage').click(function(){
				var message1 = prompt("Please enter a message", "");
				if (message1 != '')
					$('#activedevs > div').each(function(){var id = this.id.substring(4);$.post('?',{sendmessage:id,message:message1});});
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
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massSendmessage" value="Send Message to All" />
		|
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massHide" value="Hide All" />
		<input type="button" class="w3-button w3-white w3-border w3-border-blue w3-round-large" id="massShow" value="Show All" />
		|
		<a href="index.php">Change Lab</a> | Current Lab: <?php echo htmlentities($_SESSION['lab']); ?>
	</div>
	<div id="menu">
	<?php
	//FIXME get the filter list from first device ... not the best method but this is beta
	$deviceID = array_keys($_SESSION['alloweddevices'])[0];
	$filtermode = "";
	$filterlist = "";
	if (file_exists($dataDir.'/'.$deviceID.'/filtermode') && file_exists($dataDir.'/'.$deviceID.'/filterlist')){
		$filtermode = file_get_contents($dataDir.'/'.$deviceID.'/filtermode');
		$filterlist = file_get_contents($dataDir.'/'.$deviceID.'/filterlist');
	} else {
		$filtermode = "disabled";
	}
	?>
	<h3>Lab Filter (Beta)</h3>
	<hr />
	<form id="filter" method="post" target="_blank" action="?filter">
		Mode:
		<br /><input type="radio" name="filtermode" value="defaultallow" <?php if ($filtermode == 'defaultallow') echo 'checked="checked"'; ?> />Picket Fence (block selected sites)
		<br /><input type="radio" name="filtermode" value="defaultdeny" <?php if ($filtermode == 'defaultdeny') echo 'checked="checked"'; ?> />Walled Garden (allow selected sites)
		<br /><input type="radio" name="filtermode" value="disabled" <?php if ($filtermode == 'disabled') echo 'checked="checked"'; ?> />Disable Filter (no filter actions applied)

		<br />Sites (one per line):
		<textarea name="filterlist" style="width: 90%;height:50px;"><?php echo htmlentities($filterlist); ?></textarea>
		<br /><input type="submit" class="w3-button w3-white w3-border w3-border-blue w3-round-large">
	</form>
	<br /><h5>Device URLs - Data</h5>
	<div id="urls"></div>
	<br />
	</div>
	<div id="devicesdiv">
		<div id="activedevs"></div>
		<div id="inactivedevs"></div>
	</div>
</div>
<!-- <?php print_r($_SESSION); ?>-->
</body>
</html>
