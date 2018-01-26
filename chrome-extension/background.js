"use strict";

//define variables
var uploadURL = "https://osm/osm/upload.php";
var monitorTimer = null;
var data = {
	group:"default",
	deviceID:"",
	username:"",
	domain:"",
	screenshot:"",
	tabs:[],
	lock:false,
	version:chrome.runtime.getManifest().version,
	refreshTime:5000,
	filtermode:"",
	filterlist:[],
	filterlisttime:0,
	filterblockpage:""
}
//get deviceID
if ("undefined" !== typeof(chrome["enterprise"])) {
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
//magic code goes here to get the managed storage variables and override those defined above
//chrome.storage.managed.blablabla(function(blalballba){
//	if (something.uploadURL != "") uploadURL = something.uploadURL;
//	if (something.group != "") data.group = something.group;
//});


/////////////////
//setup filter
/////////////////
function filterPage(nextPageDetails) {
	//a filter mode must be defined as well as items on the list for the filter to activate
	//we also only filter on the tab url not any internal frames which will also be sent to this function (nextPageDetails.frameId != 0)
	if ( (data.filtermode == "defaultdeny" || data.filtermode == "defaultallow") && data.filterlist.length > 0 && nextPageDetails.frameId == 0) {
		var foundMatch = false;
		for (var i=0;i<data.filterlist.length;i++) {
			if ((new RegExp(data.filterlist[i])).test(nextPageDetails.url)) {
				foundMatch = true;
				break;
			}
		}

		//remove the tab if
		// a) it is default deny and we didn"t find an exception
		// b) it is default allow and we did find an exception
		if ( (data.filtermode == "defaultdeny" && !foundMatch) || (data.filtermode == "defaultallow" && foundMatch) ) {
			try {
				console.log("Blocking tab: " + nextPageDetails.url);
				if (data.filterblockpage != "") {
					chrome.tabs.update(nextPageDetails.tabId,{url:data.filterblockpage});
				} else {
					chrome.tabs.remove(nextPageDetails.tabId);
				}
			} catch (e) {console.log(e);}
		}
	}
};
chrome.webNavigation.onBeforeNavigate.addListener(filterPage);

////////////////////////
//setup the window lock
///////////////////////
function lockOpenWindows() {
	if (data.lock) {
		chrome.windows.getAll({},function(data) {
			for (var i=0;i<data.length;i=i+1) {
				if (data[i]["state"] != "minimized")
					chrome.windows.update(data[i]["id"],{state:"minimized"});
			}
		});
	}
}

function openWindows() {
	chrome.windows.getAll({},function(data) {
		for (var i=0;i<data.length;i=i+1) {
			chrome.windows.update(data[i]["id"],{state:"maximized"});
		}
	});
}
chrome.windows.onFocusChanged.addListener(lockOpenWindows);
chrome.tabs.onActivated.addListener(lockOpenWindows);
chrome.tabs.onUpdated.addListener(lockOpenWindows);


////////////////
//setup monitor
////////////////
function step1RefreshTabs() {
	chrome.tabs.query({}, function (tabarray) {
		data.tabs = tabarray;
		step2CaptureImage();
	});

	//just to make sure that they stay closed if locked
	lockOpenWindows();
}
function step2CaptureImage() {
	if (data.deviceID != "") {
		chrome.tabs.captureVisibleTab(null,null,function(dataUrl) {
			data.screenshot = dataUrl;
			step3PhoneHome();
		});
	}
}
function step3PhoneHome() {
	var xhttp = new XMLHttpRequest();
	xhttp.open("POST", uploadURL, true);
	xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	xhttp.send("data=" + encodeURIComponent(JSON.stringify(data)));
	xhttp.onload = function() {
		//see if we need to do anything
		var response = JSON.parse(this.responseText);
		if ("commands" in response) {
			for (var i=0;i<response["commands"].length;i++) {
				var command = response["commands"][i];
				try {
					switch (command["action"]) {
						case "tabsCreate":
							chrome.tabs.create(command["data"]);
							break;
						case "tabsUpdate":
							chrome.tabs.update(command["tabId"],command["data"]);
							break;
						case "tabsRemove":
							chrome.tabs.remove(command["tabId"]);
							break;
						case "windowsCreate":
							chrome.windows.create(command["data"]);
							break;
						case "windowsUpdate":
							chrome.windows.update(command["windowId"],command["data"]);
							break;
						case "executeScript":
							chrome.tabs.executeScript(command["tabId"],command["data"]);
							break;
						case "lock":
							data.lock = true;
							lockOpenWindows();
							break;
						case "unlock":
							data.lock = false;
							openWindows();
							break;
						case "changeRefreshTime":
							if (data.refreshTime != command["time"]){
								data.refreshTime = command["time"];
								clearInterval(monitorTimer);
								monitorTimer = setInterval(step1RefreshTabs,data.refreshTime);
								console.log("Timer updated to: " + data.refreshTime);
							}
							break;
						case "setData":
							data[command["key"]] = command["value"];
							break;
						case "sendNotification":
							chrome.notifications.create("",command["data"]);
							break;
					}
				} catch (e) {console.log(e);}
			}
		}
	};
}
monitorTimer = setInterval(step1RefreshTabs,data.refreshTime);
