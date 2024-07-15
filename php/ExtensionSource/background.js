"use strict";

//this needs to be set for this extension to function
//it can only be set via managed policy (no default value) thus ensuring it poses no harm to users outside a managed environment
//i.e creating a file containing {"uploadURL":{"Value":"https://osm/osm/"}} and uploading it the Google Admin Console or setting the appropriate registry entries in Microsoft Windows
//make sure that the uploadURL points to the php folder and includes a trailing forward slash




///////////////
// Functions //
///////////////

async function osmFetch(resource, options = {}) {
	const httpTimeout = (await chrome.storage.session.get('httpTimeout')).httpTimeout;

	const controller = new AbortController();
	options.signal = controller.signal;
	const timeoutId = setTimeout(() => controller.abort(), httpTimeout);

	const response = await fetch(resource, options);

	clearTimeout(timeoutId);
	return response;
}

function getUploadURL(data){
	//return uploadURL from schema
	if (data['uploadURL']){return data['uploadURL'];}

	//if you want to hardcode a backup uploadURL replace following line
	//return "https://OSMUPLOADURL/";

	//all else fails, guess it from the email domain
	if (data['domain']){return "https://osm." + data['domain'] + "/";}

	return false;
}

async function getManagedProperties(){
	//get deviceID
	try {
		const tempDevID = await chrome.enterprise.deviceAttributes.getDirectoryDeviceId();
		chrome.storage.session.set({deviceID: tempDevID});
		console.log('Managed device with DeviceIdOfTheDirectoryAPI: ', tempDevId);
	} catch (e) {
		console.log("Info: not a managed device.");
	}

	const manageddata = await chrome.storage.managed.get();
	if ("uploadURL" in manageddata && manageddata.uploadURL != '') {chrome.storage.session.set({uploadURL: manageddata.uploadURL});}
	if ("data" in manageddata){
		for (var i=0;i<manageddata.data.length;i++){
			chrome.storage.session.set({[manageddata.data[i].name]: manageddata.data[i].value});
		}
	}
}

async function getUserProperties(){
	const userInfo = await chrome.identity.getProfileUserInfo({accountStatus: 'ANY'});
	chrome.storage.session.set({email: userInfo.email});

	var temp = userInfo.email.split("@");
	if (temp.length == 2) {
		//we probably don't need username anymore but domain is still used for guessing uploadURL
		chrome.storage.session.set({username: temp[0]});
		chrome.storage.session.set({domain: temp[1]});
	}
}

//move local data to session data
async function getLocalProperties(){
	const localdata = await chrome.storage.local.get();
	chrome.storage.session.set({local:localdata});
}

async function getRuntimeProperties(){
	chrome.storage.session.set({manifestVersion: chrome.runtime.getManifest().version});
	chrome.storage.session.set({userAgent: navigator.userAgent});
}

//setup data variables
async function setupVariables(){
	console.log('Setting up variables');

	const data = await chrome.storage.session.get();

	chrome.storage.session.set({localSession: true});

	//set some final things so not undefined
	if (typeof(data['email']) == "undefined") {chrome.storage.session.set({email: ''});}
	if (typeof(data['username']) == "undefined") {chrome.storage.session.set({username: ''});}
	if (typeof(data['domain']) == "undefined") {chrome.storage.session.set({domain: ''});}
	if (typeof(data['deviceID']) == "undefined") {chrome.storage.session.set({deviceID: 'non-enterprise-device'});}
	if (typeof(data['sessionID']) == "undefined") {chrome.storage.session.set({sessionID: crypto.randomUUID()});}
	if (typeof(data['filterID']) == "undefined") {chrome.storage.session.set({filterID: ''});}
	if (typeof(data['filterResourceTypes']) == "undefined") {chrome.storage.session.set({filterResourceTypes: ["main_frame","sub_frame","xmlhttprequest"]});}
	if (typeof(data['refreshTime']) == "undefined") {chrome.storage.session.set({refreshTime: 9000});}
	if (typeof(data['screenscrape']) == "undefined") {chrome.storage.session.set({screenscrape: false});}
	if (typeof(data['screenscrapeTime']) == "undefined") {chrome.storage.session.set({screenscrapeTime: 20000});}
	if (typeof(data['ignoreInUpload']) == "undefined") {chrome.storage.session.set({ignoreInUpload: []});}
	if (typeof(data['httpTimeout']) == "undefined") {chrome.storage.session.set({httpTimeout: 30000});}

	await getRuntimeProperties();
	await getLocalProperties();
	await getManagedProperties();
	await getUserProperties();
}

//filter page used by listener and filterHistoryPage
async function filterPage(nextPageDetails) {
	const data = await chrome.storage.session.get();
	const uploadURL = getUploadURL(data);

	//any page on the osm server can be skipped
	if (nextPageDetails.url.indexOf(uploadURL) == 0){return;}

	//this has to be turned on via the regular syncing mechanism
	//it defaults to off
	if (uploadURL && data.filterID && data.filterResourceTypes.includes(nextPageDetails.type)){
		var request = {
			url:nextPageDetails.url,
			type:nextPageDetails.type,
			sessionID: data.sessionID,
			email:data.email,
			deviceID:data.deviceID,
			initiator: nextPageDetails.initiator,
			filterID: data.filterID
		};


		try {
			const fetchdata = await osmFetch(uploadURL+'?filter',{
				method: 'POST',
				headers: {
					"Content-type": "application/x-www-form-urlencoded"
				},
				body: "data=" + encodeURIComponent(JSON.stringify(request))
			});
			const response = await fetchdata.json();
			console.debug('Filter request/repsonse',request,response);

			if ("commands" in response) {
				for (var i=0;i<response["commands"].length;i++) {
					var command = response["commands"][i];
					try {
						switch (command["action"]) {
							case "BLOCK":
								console.log("Blocking tab", nextPageDetails);
								if (nextPageDetails.type == 'main_frame' || command.hasOwnProperty('forceTab')){
									chrome.tabs.remove(nextPageDetails.tabId);
								} else {
									chrome.scripting.executeScript({
										injectImmediately: true,
										target:{tabId:nextPageDetails.tabId,frameIds:[nextPageDetails.frameId]},
										func:function(url){console.log("OSM FRAME REDIRECT",url);window.location.href = url;},
										args:['about:blank']
									});
								}
								break;
							case "BLOCKPAGE":
								console.log("Blockpaging tab", nextPageDetails);
								if (nextPageDetails.type == 'main_frame' || command.hasOwnProperty('forceTab')){
									chrome.tabs.update(nextPageDetails.tabId,{url:command['data']});
								} else {
									chrome.scripting.executeScript({
										injectImmediately: true,
										target:{tabId:nextPageDetails.tabId,frameIds:[nextPageDetails.frameId]},
										func:function(url){console.log("OSM FRAME REDIRECT",url);window.location.href = url;},
										args:[command['data']]
									});
								}
								break;
							case "NOTIFY":
								console.log("Notification: " + nextPageDetails.url);
								chrome.notifications.create("",command['data']);
								break;
						}
					} catch (e) {console.error(e);}
				}
			}
		} catch (e) {
			console.error(e);
		}
	}
};

//used by listener
function filterHistoryPage(details) {
	details.type = "main_frame";
	filterPage(details);
}

//ran when the client filter list is updated
async function reloadFilter(){
	const tabarray = await chrome.tabs.query({});
	for (var i=0;i<tabarray.length;i=i+1){
		filterPage({
			url: tabarray[i].url,
			type: "main_frame",
			tabId: tabarray[i].id
		});
	}
}

//used by listener to ensure windows are locked
async function lockOpenWindows() {
	const data = await chrome.storage.session.get(['lock']);
	if (data.lock) {
		const windowdata = await chrome.windows.getAll();
		for (var i=0;i<windowdata.length;i=i+1) {
			if (windowdata[i]["state"] != "minimized") {
				chrome.windows.update(windowdata[i]["id"],{state:"minimized"});
			}
		}
	}
}

//used by phonehome to reopen a locked window state
async function openWindows() {
	const data = await chrome.windows.getAll();
	for (var i=0;i<data.length;i=i+1) {
		chrome.windows.update(data[i]["id"],{state:"maximized"});
	}
}

//used by alarmtick
async function phoneHome() {
	let data = await chrome.storage.session.get();

	var uploadURL = getUploadURL(data);
	if (!uploadURL){
		console.log(data);
		console.log('No uploadURL or domain, no phoneHome');
		return;
	}

	if ('ignoreInUpload' in data){
		for (let i=0;i<data['ignoreInUpload'].length;i++){
			let key = data['ignoreInUpload'][i];
			if (key in data){
				data[key] = null;
			}
		}
	}

	const fetchdata = await osmFetch(uploadURL+'?upload',{
		method: 'POST',
		headers: {
			"Content-type": "application/x-www-form-urlencoded"
		},
		body: "data=" + encodeURIComponent(JSON.stringify(data))
	});
	const response = await fetchdata.json();
	console.debug('Upload request/response',data,response);

	//see if we need to do anything
	if ("commands" in response) {
		for (var i=0;i<response["commands"].length;i++) {
			var command = response["commands"][i];
			try {
				switch (command["action"]) {
					case "actionOpenPopup":
						chrome.action.openPopup(command["data"]);
						break;
					case "ttsSpeak":
						chrome.tts.speak(command["data"],command["options"]);
						break;
					case "powerRequest":
						chrome.power.requestKeepAwake(command["data"]);
						break;
					case "powerRelease":
						chrome.power.releaseKeepAwake();
						break;
					case "tabsCreate":
						chrome.tabs.create(command["data"]);
						break;
					case "tabsUpdate":
						chrome.tabs.update(command["tabId"],command["data"]);
						break;
					case "tabsMove":
						chrome.tabs.move(command["tabId"],command["data"]);
						break;
					case "tabsReload":
						chrome.tabs.reload(command["tabId"],command["data"]);
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
					case "setLocalData":
						chrome.storage.local.set({[command["key"]]: command["value"]});
						getLocalProperties();
						break;
					case "clearLocalData":
						chrome.storage.local.clear();
						getLocalProperties();
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
					case "keepAwakeFindTab":
						keepAwakeFindTab();
						break;
					case "reset":
						console.log('Resetting Extension');
						chrome.runtime.reload();
						break;
					case "requestUpdateCheck":
						chrome.runtime.requestUpdateCheck(function(a,b){
							console.log('requestUpdateCheck',a,b);
							if(a == 'update_available'){chrome.runtime.reload();}
						});
						break;
					case "setContentSettings":
						chrome.contentSettings[ command['type'] ].set(command['data']);
						break;
					case "clearContentSettings":
						chrome.contentSettings[ command['type'] ].clear(command['data']);
						break;
					case "reloadFilter":
						reloadFilter();
						break;
					case "setDNRRules":
						let previousRules = await chrome.declarativeNetRequest.getDynamicRules();
						let previousRuleIds = previousRules.map(rule => rule.id);
						chrome.declarativeNetRequest.updateDynamicRules({
							removeRuleIds: previousRuleIds,
							addRules: command['data']
						});
						break;
					default:
						console.log('Unknown command in upload',command);
						break;
				}
			} catch (e) {console.error(e);}
		}
	}
}

// used as an inject script in screenscrapeTick
function OSMDumpBodyInnerText() {
  return document.body.innerText;
}

// used by alarm listener and settimeout
async function screenscrapeTick(){
	console.debug('Screenscrape ticked');

	const data = await chrome.storage.session.get();

	//just make sure we are not ticking faster than requested
	if (typeof(data['screenscrapeTickLast']) == "undefined") {
		chrome.storage.session.set({screenscrapeTickLast: Date.now()});
		console.debug('Setting the screenscrapeTickLast time sentry');
		setTimeout(screenscrapeTick,data['screenscrapeTime']);
		console.debug('Created first call of setTimeout for next screenscrape run');
	} else if ((Math.abs(data['screenscrapeTickLast'] - Date.now())) < data['screenscrapeTime']) {
		//console.debug(data);
		console.debug('It appears it is not yet time for a screenscrape, stopping here');
		return;
	} else {
		chrome.storage.session.set({screenscrapeTickLast: Date.now()});
		console.debug('Updating the sentry for the screenscrape requests');
		setTimeout(screenscrapeTick,data['screenscrapeTime']);
		console.debug('Created new setTimeout for next screenscrape run');
	}

	//screenscrape has to be turned on via the regular syncing mechanism
	//it defaults to off
	if (!data['screenscrape']){
		//console.debug(data);
		console.debug('Screenscrape is disabled, enable from server');
		return;
	}

	//restrict to only active tab
	const tabarray = await chrome.tabs.query({active: true});
	try{
		var tab = tabarray[0];
		const results = await chrome.scripting.executeScript({
			target: {tabId: tab.id, allFrames: true},
			func: OSMDumpBodyInnerText
		});

		for (const pageText of results) {
			//console.debug('Page Text: ' + pageText.result);
			results = pageText.result;
		}
		//console.debug(results);
		if (results && results.length > 0){
			//results = results.replace(/(\r\n|\n|\r)/gm, ' ');
			//console.debug(results);
			results = {
				text:results,
				url:tab.url,
				sessionID: data.sessionID,
				email:data.email,
				deviceID:data.deviceID
			};


			var uploadURL = getUploadURL(data);
			if (!uploadURL){
				console.log(data);
				console.log('No uploadURL no screenscrape');
				return;
			}

			const fetchdata = await osmFetch(uploadURL+'?screenscrape',{
				method: 'POST',
				headers: {
					"Content-type": "application/x-www-form-urlencoded"
				},
				body: "data=" + encodeURIComponent(JSON.stringify(results))
			});
			const response = await fetchdata.json();
			console.debug('Screenscrape request/response',results,response);

			//see if we need to do anything
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
								chrome.tabs.update(tab.id,{url:uploadURL+'?block&'+command['data']});
								break;
							case "NOTIFY":
								console.log("Notification: " + tab.url);
								chrome.notifications.create("",command['data']);
								break;
						}
					} catch (e) {console.error(e);}
				}
			}
		}
	} catch (e) {console.error(e);}
}

//used by alarmlistener and setTimeout
var currentUploadRequestTime = 0;
async function alarmTick() {
	console.debug("Alarm ticked", Date());

	//just make sure we are not ticking faster than requested
	const data = await chrome.storage.session.get();
	if (typeof(data['alarmTickLast']) == "undefined") {
		chrome.storage.session.set({alarmTickLast: Date.now()});
		console.debug('Setting the alarmTickLast time sentry for the first time');
		setTimeout(alarmTick,data['refreshTime']);
		console.debug('Created first call of setTimeout for next phoneHome run');
	} else if ((Math.abs(data['alarmTickLast'] - Date.now())) < data['refreshTime']) {
		//console.debug(data);
		console.debug('It appears it is not yet time for a phoneHome, stopping here');
		return;
	} else {
		chrome.storage.session.set({alarmTickLast: Date.now()});
		console.debug('Updating the sentry for the next phoneHome requests');
		setTimeout(alarmTick,data['refreshTime']);
		console.debug('Created new setTimeout for next phoneHome run');
	}

	//get tab info
	const tabarray = await chrome.tabs.query({});
	chrome.storage.session.set({tabs: tabarray});

	//get screenshot
	try {
		const dataUrl = await chrome.tabs.captureVisibleTab(null,{format:"jpeg"});
		chrome.storage.session.set({screenshot: dataUrl});
	} catch (e){
		console.log(e);
		chrome.storage.session.set({screenshot: ""});
	}

	//send data home if one isn't in progress
	//we will try again anyway if it was over a minute ago
	if (Date.now() - currentUploadRequestTime < 60*1000){
		console.warn('currentUploadRequestTime is within 60 seconds, skipping phoneHome attempt');
		return;
	}
	try {
		currentUploadRequestTime = Date.now();
		await phoneHome();
		currentUploadRequestTime = 0;
	} catch (e) {
		console.error(e);
		currentUploadRequestTime = 0;
	}
}




///////////////
// Listeners //
///////////////

//start each service worker with a listener
chrome.alarms.onAlarm.addListener(async (alarm) => {
	await getManagedProperties();
	await getUserProperties();

	//run the events
	alarmTick();
	screenscrapeTick();
});

//detect updates to the extension
chrome.runtime.onInstalled.addListener((details) => {
	getRuntimeProperties();
});

//listen for future changes
chrome.storage.onChanged.addListener((changes,namespace) => {
	if (namespace == 'managed'){
		getManagedProperties();
	}
});

//listen for future changes
chrome.identity.onSignInChanged.addListener((accountinfo, signedin) => {
	getUserProperties();
});

//filter regular pages
chrome.webRequest.onBeforeRequest.addListener(filterPage,{urls:["<all_urls>"]},["blocking"]);

//filter history pages
chrome.webNavigation.onHistoryStateUpdated.addListener(filterHistoryPage);

//setup the window lock
chrome.windows.onFocusChanged.addListener(lockOpenWindows);
chrome.tabs.onActivated.addListener(lockOpenWindows);
chrome.tabs.onUpdated.addListener(lockOpenWindows);




//////////////////////////
// Service Worker Setup //
//////////////////////////

//preset the keepWakeWorkaround based on chrome version (can be overwritten by server if need be)
const keepAliveChromeVersion = 113;
const cVersion = parseInt(/Chrome\/([0-9]+)/.exec(navigator.userAgent)[1]);
chrome.storage.session.set({keepAwakeWorkaround: (cVersion <= keepAliveChromeVersion)});

/////////////////
//the below may not be needed later see https://developer.chrome.com/docs/extensions/mv3/known-issues/#sw-fixed-lifetime
//see https://stackoverflow.com/a/66618269
//
//only variable names changed and check for keepAwakeWorkaround in session added from stackoverflow link
/////////////////
function keepAwakeConnect() {
	console.debug('keepAwakeConnect called');
	chrome.runtime.connect({name: 'keepAlive'}).onDisconnect.addListener(keepAwakeConnect);
}
const keepAwakeOnUpdate = (tabId, info, tab) => /^https?:/.test(info.url) && keepAwakeFindTab([tab]);
async function keepAwakeFindTab(tabs) {
	console.debug('keepAwakeFindTab called');

	var data = await chrome.storage.session.get(['keepAwakeWorkaround']);
	if (!data.keepAwakeWorkaround){
		return;
	}

	console.debug('keepAwakeFindTab running');
	for (const {id: tabId} of tabs || await chrome.tabs.query({url: '*://*/*'})) {
		try {
			await chrome.scripting.executeScript({target: {tabId}, func: keepAwakeConnect});
			chrome.tabs.onUpdated.removeListener(keepAwakeOnUpdate);
			return;
		} catch (e) {}
	}
	chrome.tabs.onUpdated.addListener(keepAwakeOnUpdate);
}
chrome.runtime.onConnect.addListener(port => {
	console.debug('onConnect.addListener');
	if (port.name === 'keepAlive') {
		setTimeout(() => port.disconnect(), 250e3);
		port.onDisconnect.addListener(() => keepAwakeFindTab());
	}
});
keepAwakeFindTab();
//end keep alive code



//sanity check for variable stuff on background reload
async function serviceWorkerSetup() {
	const data = await chrome.storage.session.get();

	if (typeof(data['localSession']) == "undefined") {
		console.log('Looks like initial call of checkVariables');
		setupVariables();
	} else {
		console.debug('The localSession is set so must be a service worker call for checkVariables');
	}

	const a = await chrome.alarms.get('mainalarm');
	if (!a) {
		chrome.alarms.create('mainalarm', {delayInMinutes: 1, periodInMinutes: 1});
		console.log("Creating the mainalarm");
	} else {
		console.debug("The mainalarm already exists, moving on");
	}

	alarmTick();
	screenscrapeTick();
}
serviceWorkerSetup();

