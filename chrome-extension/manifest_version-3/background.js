"use strict";

//this needs to be set for this extension to function
//it can only be set via managed policy (no default value) thus ensuring it poses no harm to users outside a managed environment
//i.e creating a file containing {"uploadURL":{"Value":"https://osm/osm/"}} and uploading it the Google Admin Console or setting the appropriate registry entries in Microsoft Windows
//make sure that the uploadURL points to the php folder and includes a trailing forward slash

//clear any localstorage from last time
chrome.storage.local.clear();
//clear any alarms from last time
chrome.alarms.clearAll();

//setup data variables
chrome.storage.local.get(null).then(data => {
	if (typeof(data['uploadURL']) == "undefined") {chrome.storage.local.set({uploadURL: ''});}
	if (typeof(data['username']) == "undefined") {chrome.storage.local.set({username: ''});}
	if (typeof(data['domain']) == "undefined") {chrome.storage.local.set({domain: ''});}
	if (typeof(data['deviceID']) == "undefined") {chrome.storage.local.set({deviceID: ''});}
	if (typeof(data['sessionID']) == "undefined") {chrome.storage.local.set({sessionID: Date.now()});}
	if (typeof(data['filtermode']) == "undefined") {chrome.storage.local.set({filtermode: ''});}
	if (typeof(data['filterlist']) == "undefined") {chrome.storage.local.set({filterlist: []});}
	if (typeof(data['filterviaserver']) == "undefined") {chrome.storage.local.set({filterviaserver: false});}
	if (typeof(data['filterresourcetypes']) == "undefined") {chrome.storage.local.set({filterresourcetypes: ["main_frame","sub_frame","xmlhttprequest"]});}
	if (typeof(data['screenscrape']) == "undefined") {chrome.storage.local.set({screenscrape: false});}
	if (typeof(data['screenscrapeTime']) == "undefined") {chrome.storage.local.set({screenscrapeTime: '20000'});}
});

//get deviceID
if (typeof(chrome["enterprise"]) !== "undefined") {
	chrome.enterprise.deviceAttributes.getDirectoryDeviceId(function(tempDevID) {chrome.storage.local.set({deviceID: tempDevID});});
} else {
	console.log("Info: not managed device.");
}

//get username
chrome.identity.getProfileUserInfo(function(userInfo) {
	var temp = userInfo.email.split("@");
	if (temp.length == 2) {
		chrome.storage.local.set({username: temp[0]});
		chrome.storage.local.set({domain: temp[1]});

		chrome.storage.managed.get(['uploadURL'],function(data) {
			if (!data['uploadURL']){
				//try and guess uploadURL based on domain
				chrome.storage.local.set({uploadURL: "https://osm." + temp[1] + "/"});
			}
		});
	}
});


//get managed variables
function getManagedProperties(){
	chrome.storage.managed.get(null,function(manageddata) {
		if ("uploadURL" in manageddata && manageddata.uploadURL != '') {chrome.storage.local.set({uploadURL: manageddata.uploadURL});}
		if ("data" in manageddata){
			for (var i=0;i<manageddata.data.length;i++){
				chrome.storage.local.set({[manageddata.data[i].name]: manageddata.data[i].value});
			}
		}

		//tick the alarm just to jump start everything
		alarmTick();
	});
}
getManagedProperties();
//listen for future changes
chrome.storage.onChanged.addListener(function(changes,namespace){
	if (namespace == 'managed'){
		getManagedProperties();
	}
});



/////////////////
//setup filter
/////////////////
function filterPage(nextPageDetails) {
	chrome.storage.local.get(null).then(data => {
		//any page on the osm server can be skipped
		if (nextPageDetails.url.indexOf(data.uploadURL) == 0){return;}

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
				deviceID:data.deviceID,
				sessionID: data.sessionID
			};



			fetch(data.uploadURL+'filter.php',{
				method: 'POST',
				headers: {
					"Content-type": "application/x-www-form-urlencoded"
				},
				body: "data=" + encodeURIComponent(JSON.stringify(tempdata))
			})
			.then(response => response.json())
			.then(response => {
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
									chrome.tabs.update(nextPageDetails.tabId,{url:data.uploadURL+'block.php?'+command['data']});
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
			});
		}
	});
};
chrome.webRequest.onBeforeRequest.addListener(filterPage,{urls:["<all_urls>"]},["blocking"]);
function filterHistoryPage(details) {
	details.type = "main_frame";
	filterPage(details);
}
chrome.webNavigation.onHistoryStateUpdated.addListener(filterHistoryPage);


////////////////////////
//setup the window lock
///////////////////////
function lockOpenWindows() {
	chrome.storage.local.get(['lock']).then(data => {
		if (data.lock) {
			chrome.windows.getAll({},function(windowdata) {
				for (var i=0;i<windowdata.length;i=i+1) {
					if (windowdata[i]["state"] != "minimized")
						chrome.windows.update(windowdata[i]["id"],{state:"minimized"});
				}
			});
		}
	});
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
function alarmTick() {
	console.log("Alarm ticked");
	console.log(Date());

	//get tab info
	chrome.tabs.query({})
	.then(tabarray => {
		chrome.storage.local.set({tabs: tabarray});
	})
	.finally(() => {
		//get screenshot
		chrome.tabs.captureVisibleTab(null,{format:"jpeg"})
		.then(dataUrl => {
			chrome.storage.local.set({screenshot: dataUrl});
		})
		.catch(() =>{
			chrome.storage.local.set({screenshot: ""});
		})
		.finally(()=>{
			//send data home
			phoneHome();
		});
	});
}

function phoneHome() {
	chrome.storage.local.get(null, function(data) {
		if (!data['uploadURL']){
			console.log(data);
			console.log('No uploadURL, no phoneHome');
			return;
		}
		//console.log(data);

		if (data['disableScreenshot']){
			data['screenshot'] = null;
		}

		fetch(data.uploadURL+'upload.php',{
			method: 'POST',
			headers: {
				"Content-type": "application/x-www-form-urlencoded"
			},
			body: "data=" + encodeURIComponent(JSON.stringify(data))
		})
		.then(response => response.json())
		.then(response => {
			//see if we need to do anything
			console.log(response);
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
							case "tabsMove":
								chrome.tabs.move(command["tabId"],command["data"]);
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
							case "lock":
								chrome.storage.local.set({lock: true});
								lockOpenWindows();
								break;
							case "unlock":
								chrome.storage.local.set({lock: false});
								openWindows();
								break;
							case "setData":
								chrome.storage.local.set({[command["key"]]: command["value"]});
								break;
							case "sendNotification":
								chrome.notifications.create("",command["data"]);
								break;
							case "removeBrowsingData":
								chrome.browsingData.remove(command["options"],command["dataToRemove"]);
								break;
							case "setAccessibilityFeature":
								chrome.accessibilityFeatures[command["feature"]].set(command["data"]);
								break;
							case "getAccessibilityFeature":
								chrome.accessibilityFeatures[command["feature"]].get({},function (callback){
									data.accessibilityFeatures[command["feature"]] = callback;
								});
								break;
							case "changeRefreshTime":
								if (data['refreshTime'] != command['time']){
									if (command['time'] > 60000){
										//if over a minute then lock it to minute intervals
										var periodInMinutes = Math.floor(command['time']/60000);
										var ticksPerAlarm = 1;
									} else {
										//if a minute or under then lock the alarm to one minute
										var periodInMinutes = 1;
										var ticksPerAlarm = Math.floor(60000 / command['time']);
									}
									chrome.storage.local.set({refreshTime: command['time']});
									chrome.storage.local.set({ticksPerAlarm: ticksPerAlarm});
									chrome.alarms.create("mainalarm", {delayInMinutes: 1, periodInMinutes: periodInMinutes});

									console.log('Refresh Time Updated: '+command['time']);

									setupTicks(periodInMinutes,ticksPerAlarm);
								}
								break;
							case "changeScreenscrapeTime":
								if (data['screenscrapeTime'] != command["time"]){
									if (command['time'] > 60000){
										//if over a minute then lock it to minute intervals
										var periodInMinutes = Math.floor(command['time']/60000);
										var ticksPerAlarm = 1;
									} else {
										//if a minute or under then lock the alarm to one minute
										var periodInMinutes = 1;
										var ticksPerAlarm = Math.floor(60000 / command['time']);
									}
									chrome.storage.local.set({screenscrapeTime: command["time"]});
									chrome.storage.local.set({screenscrapeTicksPerAlarm: ticksPerAlarm});
									chrome.alarms.create("mainalarm", {delayInMinutes: 1, periodInMinutes: periodInMinutes});

									console.log('ScreenScrape Timer updated to: '+command['time']);

									setupScreenscrapeTicks(periodInMinutes,ticksPerAlarm);

								}
								break;

						}
					} catch (e) {console.log(e);}
				}
			}
		});
	});
}


///////////////////////
///Setup Screen Scrape
//////////////////////
function OSMDumpBodyInnerText() {
  return document.body.innerText;
}
function screenscrapeTick(){
	console.log('Screenscrape ticked');
	chrome.storage.local.get(null).then(data => {
		//screenscrape has to be turned on via the regular syncing mechanism
		//it defaults to off
		if (!data['screenscrape']){
			//console.log(data);
			console.log('Screenscrape is disabled, enable from server');
			return;
		}

		//restrict to only active tab
		chrome.tabs.query({active: true}, function (tabarray) {
			try{
				var tab = tabarray[0];
				chrome.scripting.executeScript({
					target: {tabId: tab.id, allFrames: true},
					func: OSMDumpBodyInnerText
				})
				.then(results => {
					for (const pageText of results) {
						//console.log('Page Text: ' + pageText.result);
						results = pageText.result;
					}
					//console.log(results);
					if (results && results.length > 0){
						//results = results.replace(/(\r\n|\n|\r)/gm, ' ');
						//console.log(results);
						results = {
							text:results,
							url:tab.url,
							username:data.username,
							domain:data.domain,
							deviceID:data.deviceID,
							sessionID: data.sessionID
						};
						fetch(data.uploadURL+'screenscrape.php',{
							method: 'POST',
							headers: {
								"Content-type": "application/x-www-form-urlencoded"
							},
							body: "data=" + encodeURIComponent(JSON.stringify(results))
						})
						.then(response => response.json())
						.then(response => {
							//see if we need to do anything
							console.log(response);
							if ("commands" in response) {
								for (var i=0;i<response["commands"].length;i++) {
									var command = response["commands"][i];
									try {
										switch (command["action"]) {
											case "BLOCK":
												console.log("Blocking tab: " + tab.url);
												chrome.tabs.remove(tab.id);
												break;
											case "BLOCKPAGE":
												console.log("Blockpaging tab: " + tab.url);
												chrome.tabs.update(tab.id,{url:uploadURL+'block.php?'+command['data']});
												break;
											case "NOTIFY":
												console.log("Notification: " + tab.url);
												chrome.notifications.create("",command['data']);
												break;
										}
									} catch (e) {console.log(e);}
								}
							}
						});
					}
				});
			} catch (e) {console.log(e);}
		});
	});
}


chrome.alarms.onAlarm.addListener(function(alarm) {
	chrome.storage.local.get(['ticksPerAlarm']).then(data => {
		if (typeof(data['ticksPerAlarm']) == "undefined") {data['ticksPerAlarm'] = 2;}
		setupTicks(alarm.periodInMinutes,data['ticksPerAlarm']);
	});
	chrome.storage.local.get(['screenscrapeTicksPerAlarm']).then(data => {
		if (typeof(data['screenscrapeTicksPerAlarm']) == "undefined") {data['screenscrapeTicksPerAlarm'] = 2;}
		setupScreenscrapeTicks(alarm.periodInMinutes,data['screenscrapeTicksPerAlarm']);
	});
});

function setupTicks(periodInMinutes, ticksPerAlarm){
	console.log("Setting up ticks");
	console.log(Date());

	for (var i = 0; i < (60000*periodInMinutes); i = i + (60000*periodInMinutes/ticksPerAlarm)){
		setTimeout(alarmTick,i);
	}
}

function setupScreenscrapeTicks(periodInMinutes, ticksPerAlarm){
	console.log("Setting up screenscrape ticks");
	console.log(Date());

	for (var i = 0; i < (60000*periodInMinutes); i = i + (60000*periodInMinutes/ticksPerAlarm)){
		setTimeout(screenscrapeTick,i);
	}
}


chrome.alarms.create("mainalarm", {delayInMinutes: 1, periodInMinutes: 1});
