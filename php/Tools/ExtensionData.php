<?php
namespace OSM\Tools;

//decoding the JSON the extension posts as data=
class ExtensionData {
	//Text taken from web pages (tab titles, page text) can contain half of an emoji: a page
	//that shortens its title in JavaScript can cut a UTF-16 surrogate pair in two, and
	//JSON.stringify then writes the lone half as an escape such as \ud83d. PHP's json_decode
	//rejects the whole document for that, and for invalid UTF-8 bytes. Those characters are
	//replaced with U+FFFD so the rest of the data is kept.
	//Returns the decoded array, or null; $error is then json_decode's reason for the original.
	public static function decode($raw, &$error = null){
		$error = null;
		if (!is_string($raw)){
			$error = 'no data';
			return null;
		}

		$data = json_decode($raw, true);
		if (is_array($data)){return $data;}
		$error = json_last_error() === JSON_ERROR_NONE ? 'not a JSON object' : json_last_error_msg();

		$data = json_decode(self::repairSurrogates($raw), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
		if (is_array($data)){
			$error = null;
			return $data;
		}
		return null;
	}

	//Everything the extension uploads (screenshot, tabs, ...) is stored in TempDB under this key:
	//the extension's sessionID plus a hash of the device and user, so two clients with the same
	//sessionID do not collide. Upload stores with it; the filter and screenscrape requests of the
	//same client use it to find that client's last screenshot for trigger emails.
	//Returns '' when the request has no usable sessionID.
	public static function sessionKey($sessionID, $deviceID, $email){
		$sessionID = substr(preg_replace('/[^0-9a-z\-]/', '', is_string($sessionID) ? $sessionID : ''), 0, 36);
		if ($sessionID == ''){return '';}
		$deviceID = is_string($deviceID) ? $deviceID : '';
		$email = is_string($email) ? $email : '';
		if ($deviceID == ''){$deviceID = 'non-enterprise-device';}
		if ($email == ''){$email = 'unknown';}
		return $sessionID.'--'.md5($deviceID.$email);
	}

	//replace \uD800-\uDFFF escapes that are not part of a high+low pair with �;
	//every backslash in JSON starts an escape, so matching escapes left to right keeps
	//an escaped backslash (\\) followed by the text "ud83d" untouched
	private static function repairSurrogates($json){
		return preg_replace_callback(
			'/(\\\\u[dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2})|(\\\\u[dD][89a-fA-F][0-9a-fA-F]{2})|(\\\\.)/s',
			function($m){
				if (($m[2] ?? '') !== '' && ($m[1] ?? '') === ''){return '\\ufffd';}
				return $m[0];
			},
			$json
		);
	}
}
