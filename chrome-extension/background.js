"use strict";

var uploadURL = "https://osm/osm/upload.php";

function refreshTabs(){
	chrome.tabs.query({}, function (tabarray) {
		data.tabs = tabarray;
		captureImage();
	});

	//just to make sure that they stay closed if locked
	lockOpenWindows();
}

function captureImage(){
	if (data.deviceID != "") {
		chrome.tabs.captureVisibleTab(null,null,function(dataUrl){
			data.screenshot = dataUrl;

			var xhttp = new XMLHttpRequest();
			xhttp.open("POST", uploadURL, true);
			xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
			xhttp.send("data=" + encodeURIComponent(JSON.stringify(data)));
			xhttp.onload = function() {
				//see if we need to do anything
				var response = JSON.parse(this.responseText);
				if ('commands' in response) {
					for (var i=0;i<response['commands'].length;i++){
						var command = response['commands'][i];
						try {
							switch (command['action']) {
								case 'tabsCreate':
									chrome.tabs.create(command['data']);
									break;
								case 'tabsUpdate':
									chrome.tabs.update(command['tabId'],command['data']);
									break;
								case 'tabsRemove':
									chrome.tabs.remove(command['tabId']);
									break;
								case 'windowsCreate':
									chrome.windows.create(command['data']);
									break;
								case 'windowsUpdate':
									chrome.windows.update(command['windowId'],command['data']);
									break;
								case 'executeScript':
									chrome.tabs.executeScript(command['tabId'],command['data']);
									break;
								case 'lock':
									data.lock = true;
									lockOpenWindows();
									break;
								case 'unlock':
									data.lock = false;
									openWindows();
									break;
								case 'changeRefreshTime':
									if (data.refreshTime != command['time']){
										data.refreshTime = command['time'];
										clearInterval(monitorTimer);
										monitorTimer = setInterval(refreshTabs,data.refreshTime);
										console.log('Timer updated to: ' + data.refreshTime);
									}
									break;
								case 'setSessionID':
									data.sessionID = command['sessionID'];
									break;
							}
						} catch (e) {console.log(e);}
					}
				}
			};
		});
	}
}

function lockOpenWindows(){
	if (data.lock){
		chrome.windows.getAll({},function(data){
			for (var i=0;i<data.length;i=i+1){
				if (data[i]['state'] != 'minimized')
					chrome.windows.update(data[i]['id'],{state:'minimized'});
			}
		});
	}
}

function openWindows(){
	chrome.windows.getAll({},function(data){
		for (var i=0;i<data.length;i=i+1){
			chrome.windows.update(data[i]['id'],{state:'maximized'});
		}
	});
}

//define variables
var data = {
	deviceID:"",
	username:"",
	domain:"",
	screenshot:"",
	tabs:[],
	lock:false,
	version:chrome.runtime.getManifest().version,
	refreshTime:5000,
	sessionID:'',
}

//get deviceID
if ("undefined" !== typeof(chrome['enterprise'])){
	chrome.enterprise.deviceAttributes.getDirectoryDeviceId(function(tempDevID) {data.deviceID = tempDevID;});
} else {
	console.log("Info: not managed device.");
}


//get username
chrome.identity.getProfileUserInfo(function(userInfo) {
	var temp = userInfo.email.split("@");
	if (temp.length == 2) {
		data.username = temp[0];
		data.domain = temp[1];
	}
});


//setup the window lock
chrome.windows.onFocusChanged.addListener(lockOpenWindows);
chrome.tabs.onActivated.addListener(lockOpenWindows);
chrome.tabs.onUpdated.addListener(lockOpenWindows);

//start timers
var monitorTimer = null;
monitorTimer = setInterval(refreshTabs,data.refreshTime);
