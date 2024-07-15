"use strict";
//attempt to get dynamic html for popup
//overwrites default body contents
window.addEventListener('DOMContentLoaded', (event) => {
	chrome.storage.session.get('popup').then( (data) => {
		if ('popup' in data && data['popup'] != ''){
			document.body.innerHTML = data['popup'];
		}
	});
});
