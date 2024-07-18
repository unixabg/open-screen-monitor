<?php
namespace OSM\Route\Monitor;

class Viewer extends \OSM\Tools\Route {
	public function action(){
		$this->requireLogin();

		$groupID = $_GET['groupID'] ?? '';
		$group = $_SESSION['groups'][$groupID] ?? false;
		if ($group === false){
			http_response_code(403);
			die('Access Denied: Invalid Group');
		}

		$this->title = 'Open Screen Monitor - '.htmlentities($group['name']);

		$this->css = '
.leftHeader input {margin:5px;}
.content {display:flex;background-color: #C0C0C0;min-height:100%;}
#activedevs {text-align:center;}
#activedevs > div {display:inline-block;margin: 5px;border: 5px solid white;}
#activedevs .buttons {border-bottom: 1px solid black;background-color:white;cursor:pointer;display:flex;justify-content:space-evenly;align-content:center;flex-wrap:wrap;align-items:center;}
#activedevs .buttons span {line-height:100%;font-size:1em;}
#activedevs .info {display:none;}
#otherdevs > div {clear:both;padding-top:10px;display:flex;flex-direction:row;flex-wrap:wrap;justify-content: space-evenly;}
#otherdevs > div div {display:inline-block;width: 300px;min-height:75px;padding:5px;margin: 5px;background-color:black;color:white;border: 4px solid black;text-align:center;}
#otherdevs > div div.hidden {border: 4px solid green !important;}
#otherdevs > div div.hidden img, #hiddendevs div.hidden div {display:none;}
#menu {border-right: 1px solid black;display:none;word-wrap:break-word;background-color:#ffffff;}
#menu {padding: 5px;}
#menu > h3 {text-align:center;width:350px;}
div.locked {border: 5px solid red !important;}
div.notInGroup {border: 5px solid yellow !important;}
.fullscreen {position:fixed;top:0px !important;left:0px !important;height:100vh;width:100vw;background-color:black;z-index: 100;margin:0px !important;padding:0px !important;border-color:black !important;}
.fullscreen .buttons {margin-top:15px !important;height: 50px;max-width:unset !important;}
.fullscreen .buttons .title {font-size: 1.5rem !important;}
.fullscreen .buttons span {font-size: 2rem !important;}
.fullscreen .info {display:block !important;position:absolute;width:300px;left:20px;background-color:white;left:25px;100px;height:calc(100vh - 100px);top:75px;text-align:left;overflow-y:scroll;word-break:break-all;}
.fullscreen img {position:absolute;height:calc(100vh - 100px) !important;width:calc(100vw - 400px) !important;left: 350px;top:75px;}

		';


		$this->js = '
			window.osm = {
				groupID: "",
				imagePool: {},
				imgcss: {"width":400,"height":300,"fontsize":16,"multiplier":1,"auto":1},
				showInactive: true,
				disableUpdate: false,
				actions: {
					closeAllTabs: function (data){
						$.post("/?route=Monitor\\\\API",{action:"closeAllTabs",sessionID:data.sessionID});
					},
					lockDev: function(data){
						$.post("/?route=Monitor\\\\API",{action:"lock",sessionID:data.sessionID});
					},
					unlockDev: function(data){
						$.post("/?route=Monitor\\\\API",{action:"unlock",sessionID:data.sessionID});
					},
					openUrl: function(data){
						var url1 = "url" in data ? data.url : prompt("Please enter an URL", "http://");
						if (url1 == ""){return;}
						$.post("/?route=Monitor\\\\API",{action:"openurl",sessionID:data.sessionID,url:url1})
					},
					tts: function(data){
						var tts1 = "text" in data ? data.text : prompt("Please enter the message", "");
						if (tts1 == ""){return;}
						$.post("/?route=Monitor\\\\API",{action:"tts",sessionID:data.sessionID,tts:tts1})
					},
					sendMessage: function(data){
						var message1 = "message" in data ? data.message : prompt("Please enter a message", "");
						if (message1 == ""){return;}
						$.post("/?route=Monitor\\\\API",{action:"sendmessage",sessionID:data.sessionID,message:message1});
					},
					screenshot: function(data){
						$.post("/?route=Monitor\\\\API",{action:"screenshot",sessionID:data.sessionID},function(data){alert(data);})
					},
					closeTab: function(data) {
						$.post("/?route=Monitor\\\\API",{action:"closetab",sessionID:data.sessionID,tabid:data.tabid});
					}
				},
				takeOverClass: function(){
					$.post("/?route=Monitor\\\\API",{action:"takeOverClass",groupID:window.osm.groupID});
				},
				debug: []
			}

			setInterval(async function(){
				if (document.visibilityState != "visible"){return;}
				if (window.osm.disableUpdate){return;}

				var url = "/?route=Monitor\\\\API&action=getImage&sessionID=__SESSIONID__&time=" + (new Date()).getTime();

				var imgs = document.querySelectorAll("#activedevs .fullscreen img");
				if (imgs.length == 0){
					imgs = document.querySelectorAll("#activedevs img");
				}
				for (var i=0;i<imgs.length;i++){
					try {
						index = imgs[i].id;
						if (!(index in window.osm.imagePool)){
							window.osm.imagePool[index] = new Image();
							window.osm.imagePool[index].dataset.index = index;
							window.osm.imagePool[index].dataset.dev = imgs[i].dataset.dev;
							window.osm.imagePool[index].dataset.sessionid = imgs[i].dataset.sessionid;
							window.osm.imagePool[index].dataset.index = index;
							window.osm.imagePool[index].src = url.replace("__SESSIONID__",imgs[i].dataset.sessionid)
							window.osm.imagePool[index].onload = function(){
								var oldimg = document.getElementById(this.dataset.index);
								if (oldimg){
									this.style.width = (window.osm.imgcss.width * window.osm.imgcss.multiplier);
									this.style.height = (window.osm.imgcss.height * window.osm.imgcss.multiplier);
									oldimg.replaceWith(this);
									this.id = this.dataset.index;
								}
								delete window.osm.imagePool[this.dataset.index];
							};
							window.osm.imagePool[index].onerror = function(){
								delete window.osm.imagePool[this.dataset.index];
							};
						}
					} catch(e){
						console.log(e);
					}
				};
			},1000);

			//keep things cleaned up
			setTimeout(function(){
				window.stop();
				window.osm.imagePool = {};
			}, 60*1000);


			function setupDevice(session){
				var sessionid = session.sessionID;

				var imgid = "img_" + sessionid;
				var divid = "div_" + sessionid;

				//if the div exists then bail
				var test = document.getElementById(divid);
				if (test){
					if (test.classList.contains("hidden")){
						test.innerHTML = session.title;
					} else {
						test.querySelector(".title").innerHTML = session.title;

						if (session.locked){
							if (!test.classList.contains("locked")){test.classList.add("locked");}
						} else {
							if (test.classList.contains("locked")){test.classList.remove("locked");}
						}

						if (session.groupID != window.osm.groupID){
							if (!test.classList.contains("notInGroup")){test.classList.add("notInGroup");test.title = "User in other class: " + session.groupID;}
						} else {
							if (test.classList.contains("notInGroup")){test.classList.remove("notInGroup");test.title = "";}
						}
					}

					test.parentElement.append(test);
					test.classList.remove("metaNotUpdated");
					return test;
				}


				//delete other elements if exist
				test = document.getElementById(imgid);
				if (test){test.remove();}


				var img = new Image();
				img.dataset.sessionid = sessionid;
				img.id = imgid;
				img.src = "unavailable.jpg";
				img.style.width = (window.osm.imgcss.width * window.osm.imgcss.multiplier);
				img.style.height = (window.osm.imgcss.height * window.osm.imgcss.multiplier);
				img.oncontextmenu = function(e){return false;};

				var title = document.createElement("span");
				title.classList.add("title");
				title.innerHTML = session.title;

				var divButtons = document.createElement("div");
				divButtons.classList.add("buttons");
				divButtons.appendChild(title);
				divButtons.style.fontSize = window.osm.imgcss.fontsize * window.osm.imgcss.multiplier;
				divButtons.style.maxWidth = window.osm.imgcss.width * window.osm.imgcss.multiplier;

				var divInfo = document.createElement("div");
				divInfo.dataset.updateurl = "/?route=Monitor\\\\API&action=info&sessionID="+encodeURIComponent(sessionid);
				divInfo.classList.add("info");

				var buttons = [
					["lock","Lock this device","lockDev"],
					["lock_open","Unlock this device","unlockDev"],
					["cloud", "Open an URL on this device","openUrl"],
					["cancel","Close all tabs on this device","closeAllTabs"],
					["chat","Send a message to this device","sendMessage"],
					["screenshot_monitor","Take a screenshot of this device","screenshot"],
					["text_to_speech","Send a TTS message to this device","tts"],
				];
				for (var j=0;j<buttons.length;j++){
					var i = document.createElement("span");
					i.classList.add("material-symbols-outlined");
					i.innerHTML = buttons[j][0];
					//i.classList.add(buttons[j][0]);
					i.title = buttons[j][1];
					i.dataset.osmaction = buttons[j][2];
					divButtons.appendChild(i);
				}

				var tabs = document.createElement("div");
				tabs.classList.add("tabs");

				var div = document.createElement("div");
				div.dataset.sessionid = sessionid;
				div.classList.add("dev");
				div.classList.add("active");
				div.id = divid;
				div.appendChild(divButtons);
				div.appendChild(divInfo);
				div.appendChild(img);
				div.appendChild(tabs);
				if (session.groupID != window.osm.groupID){div.classList.add("notInGroup");div.title = "User in other class: " + session.groupID;}
				document.getElementById("activedevs").appendChild(div);

				return div;
			}

			function refreshZoom(){
				$("#activedevs img").css({
					"width":window.osm.imgcss.width * window.osm.imgcss.multiplier,
					"height":window.osm.imgcss.height * window.osm.imgcss.multiplier,
				});
				$("#activedevs .buttons").css({
					"max-width":window.osm.imgcss.width * window.osm.imgcss.multiplier,
					"font-size":window.osm.imgcss.fontsize * window.osm.imgcss.multiplier,
				});
			}

			function updateMeta() {
				if (document.visibilityState != "visible"){console.log("updateMeta not visible, exiting");return;}
				if (window.osm.disableUpdate){return;}

				$.get("/?route=Monitor\\\\API&action=online&groupID=" + window.osm.groupID,function(data,textStatus,jqXHR){
					if (jqXHR.getResponseHeader("X-OSM-Refresh") == "page"){
						location.reload(true);
						return;
					}

					$("#inactivedevs").html(data["inactive"]);
					$("#activedevs > div, #hiddendevs > div").addClass("metaNotUpdated");
					for (sessionID in data["sessions"]){
						session = data["sessions"][sessionID];
						session.sessionID = sessionID;
						setupDevice(session);
					}
					$("#devicesdiv .metaNotUpdated").remove();

					var count = $("div.active").length;
					$("#activeCount").html(count);

					if (window.osm.imgcss.auto == 1){
						var newvalue = 1;
						if (count > 0) {
							newvalue =  Math.sqrt((window.innerWidth * window.innerHeight)/((window.osm.imgcss.width+40) * (window.osm.imgcss.height+40) * count))-0.05;
							if (newvalue > 1) newvalue = 1;
						}

						//if it is off by 10 clicks (.05) auto adjust
						if (Math.abs(newvalue - window.osm.imgcss.multiplier) > .51){
							window.osm.imgcss.multiplier = newvalue;
							refreshZoom();
						}
					}

				});
			}

			function updateInfo(){
				var info = document.querySelector(".fullscreen .info");
				if (info){
					$.get(info.dataset.updateurl,function(data){
						info.innerHTML = data;
						setTimeout(updateInfo,2000);
					});
				}
			}

			window.onbeforeunload = function () {
				window.stop();
			}

			$(document).ready(function(){
				window.osm.groupID = document.getElementById("inputGroupID").value;

				//increase
				$("#increase_size").click(function(){window.osm.imgcss.auto = 0;window.osm.imgcss.multiplier = window.osm.imgcss.multiplier + .05;refreshZoom();});
				$("#decrease_size").click(function(){window.osm.imgcss.auto = 0;window.osm.imgcss.multiplier = window.osm.imgcss.multiplier - .05;refreshZoom();});
				$("#select_all").click(function(){
					$("#hidemenu").click();
				});
				$("#select_loggedin").click(function(){
					$("#hidemenu").click();
					if (window.osm.imgcss.multiplier > 1) window.osm.imgcss.multiplier = 1;
					refreshZoom();
				});

				$("#takeOverClass").click(window.osm.takeOverClass);

				$("#massLock").click(function(){$("#activedevs > div").each(function(){window.osm.actions.lockDev({"sessionID":this.dataset.sessionid});});});
				$("#massUnlock").click(function(){$("#activedevs > div").each(function(){window.osm.actions.unlockDev({"sessionID":this.dataset.sessionid});});});
				$("#massCloseAllTabs").click(function(){$("#activedevs > div").each(function(){window.osm.actions.closeAllTabs({"sessionID":this.dataset.sessionid});});});
				$("#massOpenurl").click(function(){
					var url1 = prompt("Please enter an URL", "http://");
					if (url1 == ""){return;}
					$("#activedevs > div").each(function(){window.osm.actions.openUrl({"sessionID":this.dataset.sessionid,"url":url1});});
				});

				$("#massTts").click(function(){
					var text1 = prompt("Please enter the message", "");
					if (text1 == ""){return;}
					$("#activedevs > div").each(function(){window.osm.actions.tts({"sessionID":this.dataset.sessionid,"text":text1});});
				});

				$("#massSendmessage").click(function(){
					var message1 = prompt("Please enter a message", "");
					if (message1 == ""){return;}
					$("#activedevs > div").each(function(){window.osm.actions.sendMessage({"sessionID":this.dataset.sessionid,"message":message1});});
				});

				$("#massHide").click(function(){
					window.stop();
					$("div.dev").addClass("hidden");
					$("div.active").removeClass("active")
						.each(function(){this.innerHTML = this.querySelector(".title").innerHTML;})
						.prependTo("#hiddendevs");
					updateMeta();
				});
				$("#massShow").click(function(){$("div.hidden").remove();updateMeta();});

				$("#activedevs" ).on( "mousedown", ".buttons span", function(e) {
					if ("osmaction" in e.target.dataset){
						var action = e.target.dataset.osmaction;
						var sessionID = e.target.parentElement.parentElement.dataset.sessionid;
						window.osm.actions[action]({sessionID:sessionID});
						return false;
					}
					console.log(e);
				});
				$("#devicesdiv" ).on( "mousedown", "div.info", function(e) {return false;});

				document.addEventListener("contextmenu", event => event.preventDefault());
				$("#devicesdiv" ).on( "mousedown", "div.dev", function(e) {
					var thisdiv = $(this);

					if (e.which == 1 || !e.which) {
						if (thisdiv.hasClass("active")){
							if (thisdiv.hasClass("fullscreen")) {
								thisdiv.removeClass("fullscreen");
								//$("#activedevs > div,#hiddendevs > div").css("display","block");
								//thisdiv.css("top","auto");
								//thisdiv.css("left","auto");
								//thisdiv.css("height","auto");
								//thisdiv.css("width","auto");
								thisdiv.find(".info").html("");
							} else {
								thisdiv.addClass("fullscreen");
								$("#hidemenu").click();
								updateInfo();


								//$("#activedevs > div:not(.fullscreen),#hiddendevs > div").css("display","none");
							}
						}
					} else if (e.which == 3 && !thisdiv.hasClass("fullscreen")) {
						//right click
						if (thisdiv.hasClass("active")){
							//hide it
							this.classList.remove("active");
							this.classList.add("hidden");
							this.innerHTML = this.querySelector(".title").innerHTML;
							document.getElementById("hiddendevs").appendChild(this);
						} else {
							if (thisdiv.hasClass("hidden")) {
								//show it
								thisdiv.remove();
								updateMeta();
							}
						}
					}

					e.preventDefault();
				});

				setTimeout(updateMeta,1000);
				setInterval(updateMeta,10000);

				$("#showmenu").click(function(){
					$("#menu").show();
					$(this).hide();
					$("#hidemenu").show();
				});

				$("#hidemenu").click(function(){
					$("#menu").hide();
					$(this).hide();
					$("#showmenu").show();
				});

				/* logic trigger apply button for filter */
				$("#filterlistdefaultdeny, #filterlistdefaultallow").on("input propertychange", function() {
					if(this.value.length){
						$("#applyfilter").show();
					}
				});
				$("input.apps").on("change", function(){
					$("#applyfilter").show();
				});
				$("input[name=filtermode]").change(function(){
					$("#filterlistdefaultdeny").hide();
					$("#filterlistdefaultallow").hide();
					$("#filterlistheader").show();

					if (this.value == "defaultdeny"){$("#filterlistdefaultdeny").show();}
					if (this.value == "defaultallow"){$("#filterlistdefaultallow").show();}
					if (this.value == "disabled"){$("#filterlistheader").hide();}
					$("#applyfilter").show();
				});
				$("input[name=filtermode]:checked").change();
				$("#applyfilter").click(function (){$(this).hide();});
				$("#applyfilter").hide();

				window.scrollTo(0, 0);
			});
		';

		$groupConfig = \OSM\Tools\Config::getGroup($groupID);

		$this->leftHeader .= '<input type="button" class="btn btn-primary" id="hidemenu"  style="display: none;" value="Hide Side Menu" />';
		$this->leftHeader .= '<input type="button" class="btn btn-primary" id="showmenu" value="Show Side Menu" />';
		$this->leftHeader .= '<input type="button" class="btn btn-primary" id="decrease_size" value="    -    " />';
		$this->leftHeader .= '<input type="button" class="btn btn-primary" id="increase_size" value="    +    " />';
		$this->leftHeader .= '<input type="button" class="btn btn-primary" id="massHide" value="Hide All" />';
		$this->leftHeader .= '<input type="button" class="btn btn-primary" id="massShow" value="Show All" />';


		echo '<div id="menu">';
		echo '<h2 style="text-align:center;">Total Active: <span id="activeCount"></span></h2>';
		echo '<div style="text-align:center;">';
		echo '<br /><input type="button" class="btn btn-primary" id="takeOverClass" value="Take Over Class" />';
		echo '<br /><br /><a href="/?route=Monitor\Filterlog" target="_blank" class="btn btn-primary">View Browsing History</a>';
		echo '<br /><br /><a href="/?route=Monitor\Tablist&groupID='.htmlentities($groupID).'" target="_blank" class="btn btn-primary">Tab List for All</a>';
		echo '<br /><br /><input type="button" class="btn btn-primary" id="massLock" value="Lock All" />';
		echo '<br /><br /><input type="button" class="btn btn-primary" id="massUnlock" value="Unlock All" />';
		echo '<br /><br /><input type="button" class="btn btn-primary" id="massOpenurl" value="Open Url on All" />';
		echo '<br /><br /><input type="button" class="btn btn-primary" id="massCloseAllTabs" value="Close All Tabs" />';
		echo '<br /><br /><input type="button" class="btn btn-primary" id="massSendmessage" value="Send Message to All" />';
		echo '<br /><br /><input type="button" class="btn btn-primary" id="massTts" value="Send TTS Message to All" />';
		echo '</div>';
		echo '<hr />';
		echo '<h3>Web Filter</h3>';
		echo '<form id="filter" method="post" target="_blank" action="/?route=Monitor\\API">';
		echo '<input type="hidden" name="action" value="filter" />';
		echo '<input type="hidden" id="inputGroupID" name="groupID" value="'.htmlentities($groupID).'" />';
		echo '<b>Apps</b>';
		$apps = \OSM\Tools\DB::select("tbl_filter_entry_group",['fields'=>['filterID'=>$groupID]]);
		$appNames = [];
		foreach($apps as $app){
			$appNames[] = $app['appName'];
		}

		$rows = \OSM\Tools\DB::selectRaw("select distinct appName from tbl_filter_entry where appName <> '' order by appName");
		foreach($rows as $row){
			echo '<br /><input type="checkbox" '.(in_array($row['appName'],$appNames) ? 'checked' : '').' class="apps" name="apps[]" value="'.htmlentities($row['appName']).'" /> '.htmlentities($row['appName']);
		}
		echo '<br /><br /><b>Other Sites</b>';
		$options = [
			'defaultallow' => 'Allow all but those matching listed patterns.',
			'defaultdeny' => 'Allow only sites matching listed patterns.',
		];
		foreach($options as $option => $description){
			echo '<div class="form-check">';
			echo '<input class="form-check-input" name="filtermode" type="radio" id="filterOption'.$option.'" value="'.$option.'" '.($option == $groupConfig['filtermode'] ? 'checked':'').'>';
			echo '<label class="form-check-label" for="filterOption'.$option.'">'.$description.'</label>';
			echo '</div>';
		}
		echo '<div style="text-align:center;">';
		echo '<div id="filterlistheader">Site URLs or keywords (one per line):</div>';
		echo '<textarea name="filterlist-defaultdeny" id="filterlistdefaultdeny" style="text-align:left;width: 90%;height:200px;">'.htmlentities($groupConfig['filterlist-defaultdeny']).'</textarea>';
		echo '<textarea name="filterlist-defaultallow" id="filterlistdefaultallow" style="text-align:left;width: 90%;height:200px;">'.htmlentities($groupConfig['filterlist-defaultallow']).'</textarea>';
		echo '<input type="submit" id="applyfilter" onclick="$(\\"#applyfilter\\").hide();" value="Apply Changes" class="btn btn-primary" />';
		echo '</div>';
		echo '</form>';
		echo '</div>';
		echo '<div id="devicesdiv">';
		echo '<div id="activedevs"></div>';
		echo '<div id="otherdevs">';
			echo '<div id="hiddendevs"></div>';
			echo '<div id="inactivedevs"></div>';
		echo '</div>';
		echo '</div>';
	}
}
