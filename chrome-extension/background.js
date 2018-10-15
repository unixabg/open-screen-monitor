"use strict";

//define variables

//this needs to be set for this extention to function
//it can only be set via managed policy (no default value) thus ensuring it poses no harm to users outside a managed environment
//i.e creating a file containing {"uploadURL":{"Value":"https://osm/osm/"}} and uploading it the Google Admin Console or setting the appropriate registry entries in Microsoft Windows
//make sure that the uploadURL points to the php folder and includes a trailing forward slash
var uploadURL = "";

var monitorTimer = null;
var data = {
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
	filtermessage:[],
	filterviaserver:false,
	filterresourcetypes:["main_frame","sub_frame","xmlhttprequest"],
	uploadInProgress:false
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

		if (uploadURL == ''){
			//try and guess uploadURL based on domain
			uploadURL = "https://osm." + data.domain + "/";
		}
	}
});
//get managed variables
function getManagedProperties(){
	chrome.storage.managed.get(["uploadURL"],function(manageddata) {
		if ("uploadURL" in manageddata && manageddata.uploadURL != '') uploadURL = manageddata.uploadURL;
	});
}
//listen for future changes
chrome.storage.onChanged.addListener(getManagedProperties);
//check at startup
getManagedProperties();

/////////////////
//setup filter
/////////////////
function filterPage(nextPageDetails) {
	//any page on the osm server can be skipped
	if (nextPageDetails.url.indexOf(uploadURL) == 0){return;}


	//a filter mode must be defined as well as items on the list for the filter to activate
	//we also only filter on the tab url not any internal frames which will also be sent to this function (nextPageDetails.type == "main_frame")
	if ( (data.filtermode == "defaultdeny" || data.filtermode == "defaultallow") && data.filterlist.length > 0 && nextPageDetails.type == "main_frame") {
		var foundMatch = false;
		for (var i=0;i<data.filterlist.length;i++) {
			if ((new RegExp(data.filterlist[i])).test(nextPageDetails.url)) {
				foundMatch = true;
				break;
			}
		}

		//remove the tab if
		// a) it is default deny and we didn't find an exception
		// b) it is default allow and we did find an exception
		if ( (data.filtermode == "defaultdeny" && !foundMatch) || (data.filtermode == "defaultallow" && foundMatch) ) {
			try {
				console.log("Blocking tab: " + nextPageDetails.url);
				chrome.tabs.remove(nextPageDetails.tabId);
				var tempstring = data.filtermessage["message"];
				data.filtermessage["message"] = data.filtermessage["message"] + nextPageDetails.url;
				chrome.notifications.create("",data.filtermessage);
				data.filtermessage["message"] = tempstring;
			} catch (e) {console.log(e);}
		}
	}

	//this has to be turned on via the regular syncing mechanism
	//it defaults to off
	if (data.filterviaserver && data.filterresourcetypes.includes(nextPageDetails.type)){
		var tempdata = {
			url:nextPageDetails.url,
			type:nextPageDetails.type,
			username:data.username,
			domain:data.domain,
			deviceID:data.deviceID
		};

		var xhttp = new XMLHttpRequest();
		xhttp.open("POST", uploadURL+'filter.php', false);
		xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
		xhttp.send("data=" + encodeURIComponent(JSON.stringify(tempdata)));

		var response = {};
		try {
			response = JSON.parse(xhttp.responseText);
		} catch (e) {console.log(e);}

		if ("commands" in response) {
			for (var i=0;i<response["commands"].length;i++) {
				var command = response["commands"][i];
				try {
					switch (command["action"]) {
						case "BLOCK":
							console.log("Blocking tab: " + nextPageDetails.url);
							chrome.tabs.remove(nextPageDetails.tabId);
							break;
						case "BLOCKPAGE":
							console.log("Blockpaging tab: " + nextPageDetails.url);
							chrome.tabs.update(nextPageDetails.tabId,{url:uploadURL+'block.php?'+command['data']});
							break;
						case "NOTIFY":
							console.log("Notification: " + nextPageDetails.url);
							chrome.notifications.create("",command['data']);
							break;
					}
				} catch (e) {console.log(e);}
			}
		}

		if ("return" in response){
			return response["return"];
		}
	}
};
chrome.webRequest.onBeforeRequest.addListener(filterPage,{urls:["<all_urls>"]},["blocking"]);

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
	if (uploadURL != "") {
		chrome.tabs.query({}, function (tabarray) {
			data.tabs = tabarray;
			step2CaptureImage();
		});

		//just to make sure that they stay closed if locked
		lockOpenWindows();
	}
}
function step2CaptureImage() {
	chrome.tabs.captureVisibleTab(null,null,function(dataUrl) {
		data.screenshot = dataUrl;
		step3PhoneHome();
	});
}
function step3PhoneHome() {
	var xhttp = new XMLHttpRequest();
	xhttp.open("POST", uploadURL+'upload.php', true);
	xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	xhttp.onload = function() {
		//see if we need to do anything
		var response = {};
		try {
			response = JSON.parse(this.responseText);
		} catch (e) {console.log(e);}

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
		data.uploadInProgress = false;
	};
	xhttp.onerror = function() {
		data.uploadInProgress = false;
	}
	if (!data.uploadInProgress){
		data.uploadInProgress = true;
		xhttp.send("data=" + encodeURIComponent(JSON.stringify(data)));
	} else {
		console.log("Skipping upload since one is already in progress");
	}
}
monitorTimer = setInterval(step1RefreshTabs,data.refreshTime);
