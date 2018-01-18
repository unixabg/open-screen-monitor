<?php
session_start();

//Authenticate here FIXME

//show all devices
$folders = glob('../data/*', GLOB_ONLYDIR);
foreach ($folders as $folder) {
	$folder = basename($folder);
	$_SESSION['alloweddevices'][$folder] = $folder;
}

//return all images after ctime
if (isset($_GET['images'])) {
	ini_set('memory_limit','256M');
	$toReturn = array();

	foreach ($_SESSION['alloweddevices'] as $device=>$name) {
		$folder = '../data/'.$device.'/';
		$data[$device] = array('name'=>$name,'username'=>'','tabs'=>array());

		// Assure who needs access here FIXME
		if (is_readable($folder.'screenshot.jpg') && filemtime($folder.'screenshot.jpg') >= time() - 10 ) {
			$toReturn[$device] = base64_encode(file_get_contents($folder.'screenshot.jpg'));
		}
	}

	//send it back
	ini_set("zlib.output_compression", "On");

	header('Content-Type: application/json');
	die(json_encode($toReturn));
}

// Make sure lock key is in alloweddevices array FIXME reformat
if (isset($_POST['lock']) && isset($_SESSION['alloweddevices'][$_POST['lock']])){touch('../data/'.$_POST['lock'].'/lock');die();}
if (isset($_POST['unlock']) && isset($_SESSION['alloweddevices'][$_POST['unlock']])){touch('../data/'.$_POST['unlock'].'/unlock');die();}
if (isset($_POST['openurl']) && isset($_POST['url']) && isset($_SESSION['alloweddevices'][$_POST['openurl']]) && filter_var($_POST['url'],FILTER_VALIDATE_URL,FILTER_FLAG_HOST_REQUIRED)){
	file_put_contents('../data/'.$_POST['openurl'].'/openurl',$_POST['url']);
	die();
}
if (isset($_POST['closetab']) && isset($_POST['tabid']) && isset($_SESSION['alloweddevices'][$_POST['closetab']])){file_put_contents('../data/'.$_POST['closetab'].'/closetab',$_POST['tabid']."\n",FILE_APPEND);die();}

if (isset($_GET['update'])){
	$data = array();
	foreach ($_SESSION['alloweddevices'] as $device=>$name) {
		$folder = '../data/'.$device.'/';
		$data[$device] = array('name'=>$name,'username'=>'','tabs'=>array());

		if (file_exists($folder.'ping') && filemtime($folder.'ping') > time()-30){
			$data[$device]['ip'] = (file_exists($folder.'ip') ? file_get_contents($folder.'ip') : "Unknown IP");
			$data[$device]['username'] = (file_exists($folder.'username') ? file_get_contents($folder.'username') : "Unknown User");
			$data[$device]['tabs'] = "";
			if (file_exists($folder.'tabs')){
				$temp = json_decode(file_get_contents($folder.'tabs'),true);
				foreach($temp as $tab) {
					$data[$device]['tabs'] .= "<a href=\"#\" onmousedown=\"javscript:closeTab('".$device."','".$tab['id']."');return false;\">X</a> ".htmlspecialchars($tab['title']).'<br /><br />'.substr(htmlspecialchars($tab['url']),0,500).'<br /><br /><br />';
				}
			}
		}
	}

	header('Content-Type: application/json');
	die(json_encode($data));
}

?><html>
<head>
	<title>Open Screen Monitor</title>
	<meta http-equiv="refresh" content="3600">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
	<script type="text/javascript">
		var imgcss = {'width':400,'height':300,'fontsize':14,'multiplier':1};

		function enableDevice(dev){
			var img = $('<img />');
			img.attr("id","img_" + dev);
			img.attr("alt",name);
			img.attr("src","unavailable.jpg");
			//img.load(function(){setTimeout(function(){refreshImg(dev);},4000+Math.random()*2000);});
			//img.error(function(){setTimeout(function(){refreshImg(dev);},4000+Math.random()*2000);});
			img.css({'width':imgcss.width * imgcss.multiplier,'height':imgcss.height * imgcss.multiplier});
			img.on('contextmenu',function(){return false;});


			var h1 = $('<h1></h1>');
			h1.css({'font-size':imgcss.fontsize * imgcss.multiplier});
			h1.on('mousedown',function(){
				var div = $(this).parent();
				div.find('img, div.info').toggle();
				return false;
			});

			var info = $("<div class=\"info\"><a href=\"#\" onmousedown=\"javascript:$.post('?',{lock:'"+dev+"'});return false;\">Lock</a> <a href=\"#\" onmousedown=\"javascript:$.post('?',{unlock:'"+dev+"'});return false;\">Unlock</a> <a href=\"#\" onmousedown=\"javascript:var url1 = prompt('Please enter an URL', 'http://'); if (url1 != '') $.post('?',{openurl:'"+dev+"',url:url1});return false;\">Open Url</a> <h2>Tabs</h2><div class=\"tabs\"></div></div>").css({'width':imgcss.width * imgcss.multiplier,'height':imgcss.height * imgcss.multiplier});

			var div = $('<div class=\"dev active\"></div>');
			div.attr("id","div_" + dev);
			div.append(h1);
			div.append(img);
			div.append(info);

			$('#activedevs').append(div);
			//start the auto update cycle
			//refreshImg(dev);
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

		function refreshImg(deviceindex) {
			var url = "?device=" + deviceindex  + "&time=" + (new Date()).getTime();
			$('#img_'+deviceindex).attr("src",url);
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
							$('#div_'+dev+' h1').html(data[dev].name+' ('+data[dev].username+')');
							$('#div_'+dev+' div.tabs').html(data[dev].tabs);
							$('#div_'+dev).data('name',data[dev].name);

							URLdata = URLdata + "<hr /><b>"+data[dev].name+' ('+data[dev].username +' - '+data[dev].ip +')</b><br /><br />'+$('#div_'+dev+' div.info').html();
						}
					} else if (thisdiv.first().hasClass('hidden')){
						thisdiv.html('*'+data[dev].name+'*<br />('+data[dev].username+')');
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
				$('.devicecheckbox:not(:checked)').click();
				$('#hidemenu').click();
			});
			$('#select_loggedin').click(function(){
				$('.devicecheckbox:not(:checked):not([data-name*=\'Unknown User\'])').click();
				$('#hidemenu').click();
				if (imgcss.multiplier > 1) imgcss.multiplier = 1;
				refreshZoom();
			});
			$('#select_none').click(function(){$('.devicecheckbox:checked').click();$('#showmenu').click();});

			$('#massLock').click(function(){$('#activedevs > div').each(function(){var id = this.id.substring(4);$.post('?',{lock:id});});});
			$('#massUnlock').click(function(){$('#activedevs > div').each(function(){var id = this.id.substring(4);$.post('?',{unlock:id});});});
			$('#massOpenurl').click(function(){
				var url1 = prompt("Please enter an URL", "http://");
				if (url1 != '')
					$('#activedevs > div').each(function(){var id = this.id.substring(4);$.post('?',{openurl:id,url:url1});});
			});

			$('#massHide').click(function(){
				$('div.dev').addClass('hidden');
				$('div.active').removeClass('active')
					.each(function(){var dev=$(this);dev.html(dev.data('name'));})
					.prependTo('#inactivedevs');
				updateMeta();
			});
			$('#massShow').click(function(){$('div.hidden').remove();updateMeta();});

			//checkboxes
			$('.devicecheckbox').click(function(){
				var input = $(this);
				if (input.prop('checked')) {
					enableDevice(this.value, input.attr('data-name'));
				} else {
					disableDevice(this.value);
				}
			});

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
	<style type="text/css">
		.devices {display: block;float: left; width: 200px; height: 200px;background-color: black;}
		.devices img {width: 100%;height: 100%;}

		.info a {float: left; display: block;width: 100px;margin:3px;background-color:grey;text-align:center;}
		.info h2 {clear:both;}

		#fullscreenBG {display:none;position:absolute;top:0px;left:0px;width:100%;height:100%;background-color:black;color:white;font-size:2em;cursor:pointer;text-align:left;}
		.fullscreen {width: 90% !important;height: 90% !important;z-index: 100;position:absolute;left: 5%;top: 5%;margin: 0px !important;border: none !important;}
		.fullscreen h1 {font-size: 2em !important;5%}
		.fullscreen img, .fullscreen div.info {width: 100% !important;height: 95% !important;}

		html, body, div, h1 { margin: 0; padding: 0; border: 0 none; }
		#topmenu {clear:both;}
		#menu {width: 350px; float: left; border-right: 1px solid black;display:none;word-wrap:break-word;overflow-y:scroll;height: calc(100% - 40px);}

		#inactivedevs {clear:both;padding-top:10px;border-top: 1px solid black;}
		#inactivedevs div {float:left;width: 100px;height:40px;padding:5px;margin: 5px;background-color:black;color:white;border: 4px solid black;}
		#inactivedevs div.hidden {border: 4px solid green !important;}
		#activedevs > div {float:left;margin: 5px;border: 1px solid black;}
		#activedevs div h1 {border-bottom: 1px solid black;background-color:white;cursor:pointer;}
		#activedevs div.info {word-wrap:break-word;overflow-y:scroll;display:none;background-color:white;}
	</style>
</head>
<body>
<div id="fullscreenBG"><div style="padding: 5px;">X</div></div>
<div id="wrapper">
	<div id="topmenu">
		<input type="button" id="decrease_size" value="    -    " />
		<input type="button" id="increase_size" value="    +    " />
		|
		<input type="button" id="hidemenu"  style="display: none;" value="Hide Side Menu" />
		<input type="button" id="showmenu" value="Show Side Menu" />
		|
		<input type="button" id="massLock" value="Lock All" />
		<input type="button" id="massUnlock" value="Unlock All" />
		<input type="button" id="massOpenurl" value="Open Url on All" />
		|
		<input type="button" id="massHide" value="Hide All" />
		<input type="button" id="massShow" value="Show All" />
	</div>
	<div id="menu">
	<h2>URLs</h2>
	<div id="urls"></div>
	</div>
	<div id="devicesdiv">
		<div id="activedevs"></div>
		<div id="inactivedevs"></div>
	</div>
</div>
<!-- <?php print_r($_SESSION); ?>-->
</body>
</html>
