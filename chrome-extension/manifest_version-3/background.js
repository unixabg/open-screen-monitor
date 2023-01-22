"use strict";

//this needs to be set for this extension to function
//it can only be set via managed policy (no default value) thus ensuring it poses no harm to users outside a managed environment
//i.e creating a file containing {"uploadURL":{"Value":"https://osm/osm/"}} and uploading it the Google Admin Console or setting the appropriate registry entries in Microsoft Windows
//make sure that the uploadURL points to the php folder and includes a trailing forward slash


/////////////////
//the below may not be needed later see https://developer.chrome.com/docs/extensions/mv3/known-issues/#sw-fixed-lifetime
//see https://stackoverflow.com/a/66618269
/////////////////
const onUpdate = (tabId, info, tab) => /^https?:/.test(info.url) && findTab([tab]);
findTab();
chrome.runtime.onConnect.addListener(port => {
	console.log('onConnect.addListener');
	if (port.name === 'keepAlive') {
		setTimeout(() => port.disconnect(), 250e3);
		port.onDisconnect.addListener(() => findTab());
	}
});
async function findTab(tabs) {
	console.log('findTab called');
	if (chrome.runtime.lastError) { /* tab was closed before setTimeout ran */ }
	for (const {id: tabId} of tabs || await chrome.tabs.query({url: '*://*/*'})) {
		try {
			await chrome.scripting.executeScript({target: {tabId}, func: connect});
			chrome.tabs.onUpdated.removeListener(onUpdate);
			return;
		} catch (e) {}
	}
	chrome.tabs.onUpdated.addListener(onUpdate);
}
function connect() {
	console.log('connect called');
	chrome.runtime.connect({name: 'keepAlive'})
		.onDisconnect.addListener(connect);
}


//start each service worker with a listener
chrome.alarms.onAlarm.addListener(function(alarm) {
	//run the events
	alarmTick();
	screenscrapeTick();
});

//check alarm
function ensureAlarms(){
	chrome.alarms.get('mainalarm', a => {
		if (!a) {
			chrome.storage.session.get(null).then(data => {
				chrome.alarms.create('mainalarm', {delayInMinutes: 1, periodInMinutes: 1});
			});
			console.log("Creating the mainalarm");
		} else {
			console.log("The mainalarm already exists, moving on");
		}
	});
}

//get managed variables
function getManagedProperties(){
	chrome.storage.managed.get(null,function(manageddata) {
		if ("uploadURL" in manageddata && manageddata.uploadURL != '') {chrome.storage.session.set({uploadURL: manageddata.uploadURL});}
		if ("data" in manageddata){
			for (var i=0;i<manageddata.data.length;i++){
				chrome.storage.session.set({[manageddata.data[i].name]: manageddata.data[i].value});
			}
		}
	});
}

//get properties for user
function getUserProperties(){
	chrome.identity.getProfileUserInfo(function(userInfo) {
		var temp = userInfo.email.split("@");
		if (temp.length == 2) {
			chrome.storage.session.set({username: temp[0]});
			chrome.storage.session.set({domain: temp[1]});

			chrome.storage.managed.get(['uploadURL'],function(data) {
				if (!data['uploadURL']){
					//try and guess uploadURL based on domain
					chrome.storage.session.set({uploadURL: "https://osm." + temp[1] + "/"});
				}
			});
		}
	});
}




//setup data variables
function setupVariables(){
	//sanity check for variabl stuff
	console.log('Setting up variables');
	chrome.storage.session.get(null).then(data => {
		if (typeof(data['localSession']) == "undefined") {
			//since moving to session based storage
			//clear any local storage of previous extension installs
			chrome.storage.local.clear();
			//clear any alarms that might exist
			//chrome.alarms.clearAll();
			chrome.storage.session.set({localSession: true});
			getManagedProperties();
			console.log('Looks like initial call of setupVariables');

			//get deviceID
			if (typeof(chrome["enterprise"]) !== "undefined") {
				chrome.enterprise.deviceAttributes.getDirectoryDeviceId(function(tempDevID) {
					chrome.storage.session.set({deviceID: tempDevID});
					console.log('Managed device with DeviceIdOfTheDirectoryAPI: ', tempDevId);
				});
			} else {
				console.log("Info: not a managed device.");
			}

			//get user properties
			getUserProperties();

			//set some final things if still undefined
			if (typeof(data['uploadURL']) == "undefined") {chrome.storage.session.set({uploadURL: ''});}
			if (typeof(data['username']) == "undefined") {chrome.storage.session.set({username: ''});}
			if (typeof(data['domain']) == "undefined") {chrome.storage.session.set({domain: ''});}
			if (typeof(data['deviceID']) == "undefined") {chrome.storage.session.set({deviceID: 'non-enterprise-device'});}
			if (typeof(data['sessionID']) == "undefined") {chrome.storage.session.set({sessionID: Math.floor(Math.random()*100000000)});}
			if (typeof(data['filtermode']) == "undefined") {chrome.storage.session.set({filtermode: ''});}
			if (typeof(data['filterlist']) == "undefined") {chrome.storage.session.set({filterlist: []});}
			if (typeof(data['filterviaserver']) == "undefined") {chrome.storage.session.set({filterviaserver: false});}
			if (typeof(data['filterresourcetypes']) == "undefined") {chrome.storage.session.set({filterresourcetypes: ["main_frame","sub_frame","xmlhttprequest"]});}
			if (typeof(data['refreshTime']) == "undefined") {chrome.storage.session.set({refreshTime: 9000});}
			if (typeof(data['screenscrape']) == "undefined") {chrome.storage.session.set({screenscrape: false});}
			if (typeof(data['screenscrapeTime']) == "undefined") {chrome.storage.session.set({screenscrapeTime: 20000});}
			if (typeof(data['manifestVersion']) == "undefined") {chrome.storage.session.set({manifestVersion: chrome.runtime.getManifest().version});}
			if (typeof(data['userAgent']) == "undefined") {chrome.storage.session.set({userAgent: navigator.userAgent});}
		} else {
			console.log('The localSession is set so must be a service worker call for setupVariables');
		}
		ensureAlarms();
		alarmTick();
		screenscrapeTick();
	});
}

//call the varaibles
setupVariables();


//listen for future changes
chrome.storage.onChanged.addListener(function(changes,namespace){
	if (namespace == 'managed'){
		getManagedProperties();
	}
});
//listen for future changes
chrome.identity.onSignInChanged.addListener(function(accountinfo, signedin){
	getUserProperties();
});



/////////////////
//setup filter
/////////////////
function filterPage(nextPageDetails) {
	chrome.storage.session.get(null).then(data => {
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
	chrome.storage.session.get(['lock']).then(data => {
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
	//just make sure we are not ticking faster than requested
	chrome.storage.session.get(null).then(data => {
		if (typeof(data['alarmTickLast']) == "undefined") {
			chrome.storage.session.set({alarmTickLast: Date.now()});
			console.log('Setting the alarmTickLast time sentry for the first time');
			setTimeout(alarmTick,data['refreshTime']);
			console.log('Created first call of setTimeout for next phoneHome run');
		} else if ((Math.abs(data['alarmTickLast'] - Date.now())) < data['refreshTime']) {
			//console.log(data);
			console.log('It appears it is not yet time for a phoneHome, stopping here');
			return;
		} else {
			chrome.storage.session.set({alarmTickLast: Date.now()});
			console.log('Updating the sentry for the next phoneHome requests');
			setTimeout(alarmTick,data['refreshTime']);
			console.log('Created new setTimeout for next phoneHome run');
		}

		//get tab info
		chrome.tabs.query({})
		.then(tabarray => {
			chrome.storage.session.set({tabs: tabarray});
		})
		.finally(() => {
			//get screenshot
			chrome.tabs.captureVisibleTab(null,{format:"jpeg"})
			.then(dataUrl => {
				chrome.storage.session.set({screenshot: dataUrl});
			})
			.catch(() =>{
				chrome.storage.session.set({screenshot: ""});
			})
			.finally(()=>{
				//send data home
				phoneHome();
			});
		});
	});
}

function phoneHome() {
	chrome.storage.session.get(null, function(data) {
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
								chrome.storage.session.set({lock: true});
								lockOpenWindows();
								break;
							case "unlock":
								chrome.storage.session.set({lock: false});
								openWindows();
								break;
							case "setData":
								chrome.storage.session.set({[command["key"]]: command["value"]});
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
									chrome.storage.session.set({refreshTime: command['time']});
									console.log('Refresh Time Updated: '+command['time']);
									//set the alarmTick sentry to 0 to ensure a tick
									chrome.storage.session.set({alarmTickLast: 0});
									alarmTick();
								}
								break;
							case "changeScreenscrapeTime":
								if (data['screenscrapeTime'] != command["time"]){
									chrome.storage.session.set({screenscrapeTime: command["time"]});
									console.log('ScreenScrape Timer updated to: '+command['time']);
									//set the screenscrapeTick sentry to 0 to ensure a tick
									chrome.storage.session.set({screenscrapeTickLast: 0});
									screenscrapeTick();
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
	chrome.storage.session.get(null).then(data => {
		//just make sure we are not ticking faster than requested
		if (typeof(data['screenscrapeTickLast']) == "undefined") {
			chrome.storage.session.set({screenscrapeTickLast: Date.now()});
			console.log('Setting the screenscrapeTickLast time sentry');
			setTimeout(screenscrapeTick,data['screenscrapeTime']);
			console.log('Created first call of setTimeout for next screenscrape run');
		} else if ((Math.abs(data['screenscrapeTickLast'] - Date.now())) < data['screenscrapeTime']) {
			//console.log(data);
			console.log('It appears it is not yet time for a screenscrape, stopping here');
			return;
		} else {
			chrome.storage.session.set({screenscrapeTickLast: Date.now()});
			console.log('Updating the sentry for the screenscrape requests');
			setTimeout(screenscrapeTick,data['screenscrapeTime']);
			console.log('Created new setTimeout for next screenscrape run');
		}
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

