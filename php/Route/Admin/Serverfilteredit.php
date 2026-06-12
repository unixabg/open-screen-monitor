<?php
namespace OSM\Route\Admin;

class Serverfilteredit extends \OSM\Tools\Route {
	public function action(){
		global $dataDir;

		$this->requireAdmin();

		$id = $_GET['id'] ?? '';

		$data = [];
		if ($id != ''){
			$rows = \OSM\Tools\DB::select('tbl_filter_entry',['where'=>'id = :id','bindings'=>[':id'=>$id]]);
			if (isset($rows[0])){
				$data = $rows[0];
			}
		}

		if (isset($_POST['rule'])){
			$fields = [
				'enabled' => isset($_POST['rule']['enabled']) ? 1 : 0,
				'priority' => $_POST['rule']['priority'] ?? '',
				'url' => $_POST['rule']['url'] ?? '',
				'action' => $_POST['rule']['action'] ?? '',
				'resourceType' => $_POST['rule']['resourceType'] ?? '',
				'username' => $_POST['rule']['username'] ?? '',
				'subnet' => $_POST['rule']['subnet'] ?? '',
				'initiator' => $_POST['rule']['initiator'] ?? '',
				'appName' => $_POST['rule']['appName'] ?? '',
				'comment' => $_POST['rule']['comment'] ?? '',
			];

			if ($id == ''){
				\OSM\Tools\DB::insert('tbl_filter_entry',$fields);
			} else {
				\OSM\Tools\DB::update('tbl_filter_entry',['id'=>$id],$fields);
			}

			\OSM\Tools\Config::refreshFilter();

			header('Location: /?route=Admin\\Serverfilter');
			die();
		}


		echo "<h2>Server Filter Rule Edit</h2>";
		echo "<hr />";

		$this->css = '
			.form {margin:auto;width:500px;}
			.form h1 {text-align:center;}
			.form table {width:100%;}
			table.info {margin:auto;}
			table.data {margin:auto;padding:10px;width:100%;}
			tr.section td {padding:40px;text-align:center;font-weight:bold;}
		';

		echo '<div class="form">';
		echo '<form method="post">';
		echo '<h1>Add Rule</h1>';
		echo '<table>';
		echo '<tr><td>Enabled</td><td><input name="rule[enabled]" type="checkbox" '.(($data['enabled'] ?? '') == 1 ? 'checked="checked"' : '').' /></td></tr>';
		echo '<tr><td>Priority</td><td><input name="rule[priority]" type="number" value="'.htmlentities($data['priority'] ?? '').'" /></td></tr>';
		echo '<tr><td>URL</td><td><input name="rule[url]" value="'.htmlentities($data['url'] ?? '').'" /></td></tr>';
		echo '<tr><td>Resource Type</td><td><select name="rule[resourceType]"><option></option>';
			foreach( [...\OSM\Tools\Config::get('filterResourceTypes'), 'SCREENSCRAPE'] as $value){
				echo '<option value="'.$value.'" '.($value == ($data['resourceType'] ?? '') ? 'selected':'').' >'.$value.'</option>';
			}
			echo '</select></td></tr>';
		echo '<tr><td>Action</td><td><select name="rule[action]"><option></option>';
			foreach(['ALLOW','BLOCK','BLOCKPAGE','BLOCKNOTIFY','TRIGGER','TRIGGER_EXEMPT'] as $value){
				echo '<option value="'.$value.'" '.($value == ($data['action'] ?? '') ? 'selected':'').' >'.$value.'</option>';
			}
			echo '</select></td></tr>';
		echo '<tr><td>Username</td><td><input name="rule[username]" value="'.htmlentities($data['username'] ?? '').'" /></td></tr>';
		echo '<tr><td>Subnet</td><td><input name="rule[subnet]" maxlength="18" value="'.htmlentities($data['subnet'] ?? '').'" /></td></tr>';
		echo '<tr><td>Initiator</td><td><input name="rule[initiator]" value="'.htmlentities($data['initiator'] ?? '').'" /></td></tr>';
		echo '<tr><td>App Name</td><td><input name="rule[appName]" value="'.htmlentities($data['appName'] ?? '').'" /></td></tr>';
		echo '<tr><td>Comment</td><td><textarea name="rule[comment]">'.htmlentities($data['comment'] ?? '').'</textarea></td></tr>';
		echo '<tr><td></td><td><input type="submit" /></td>';
		echo '</table>';
		echo '</form>';
		echo '</div>';


		echo '<hr />';
		echo '<h2>Field Reference</h2>';
		echo '<table class="info">';

		// ENABLED
		echo '<tr class="section"><td colspan="2">Enabled</td></tr>';
		echo '<tr><th>Purpose</th><td>Toggle rule on or off without deleting it.</td></tr>';

		// PRIORITY
		echo '<tr class="section"><td colspan="2">Priority</td></tr>';
		echo '<tr><th>Purpose</th><td>Rules are evaluated in ascending priority order. Lower number = evaluated first. First matching rule wins for ALLOW/BLOCK actions. All matching TRIGGER rules fire.</td></tr>';
		echo '<tr><th>Examples</th><td><ul><li><code>10</code> — evaluated before priority 20</li><li>Leave blank to append at end</li></ul></td></tr>';

		// URL
		echo '<tr class="section"><td colspan="2">URL</td></tr>';
		echo '<tr><th>Purpose</th><td>The URL pattern to match against the request. Three matching modes are supported.</td></tr>';
		echo '<tr><th>Modes</th><td><ul>';
		echo '<li><b>Substring (default)</b> — matches if the URL starts with the value.<br /><code>https://example.com</code> matches <code>https://example.com/any/path</code></li>';
		echo '<li><b>simple:</b> — matches the domain and any subdomain, ignoring path.<br /><code>simple:example.com</code> matches <code>https://www.example.com/page</code></li>';
		echo '<li><b>regex:</b> — full regular expression match against the URL.<br /><code>regex:.*\.example\.com.*</code> matches any URL containing <code>.example.com</code></li>';
		echo '<li>Leave blank to match all URLs (use with Username or Resource Type to scope).</li>';
		echo '</ul></td></tr>';
		echo '<tr><th>Examples</th><td><ul>';
		echo '<li><code>https://youtube.com</code> — block all YouTube</li>';
		echo '<li><code>simple:google.com</code> — allow Google and all subdomains</li>';
		echo '<li><code>regex:.*\.googleapis\.com.*</code> — match any googleapis subdomain</li>';
		echo '</ul></td></tr>';

		// RESOURCE TYPE
		echo '<tr class="section"><td colspan="2">Resource Type</td></tr>';
		echo '<tr><th>Purpose</th><td>Limits the rule to a specific type of browser request. Leave blank to use the default filter types from config.</td></tr>';
		echo '<tr><th>Values</th><td><ul>';
		echo '<li><b>(blank)</b> — uses <code>filterviaserverDefaultFilterTypes</code> from config (typically main_frame, sub_frame, xmlhttprequest)</li>';
		echo '<li><b>main_frame</b> — top-level page navigations only</li>';
		echo '<li><b>sub_frame</b> — iframes and embedded frames</li>';
		echo '<li><b>xmlhttprequest</b> — AJAX/API requests</li>';
		echo '<li><b>image, media, script, stylesheet</b> — specific asset types</li>';
		echo '<li><b>SCREENSCRAPE</b> — page text content scan instead of URL match. Used with BLOCKPAGE or TRIGGER actions.</li>';
		echo '</ul></td></tr>';

		// ACTION
		echo '<tr class="section"><td colspan="2">Action</td></tr>';
		echo '<tr><th>ALLOW</th><td>Explicitly allow the URL. Stops further rule evaluation. Use to whitelist within a defaultdeny group.</td></tr>';
		echo '<tr><th>BLOCK</th><td>Silently cancel the request. No redirect, no notification.</td></tr>';
		echo '<tr><th>BLOCKPAGE</th><td>Redirect the tab to the OSM block page showing the blocked URL. Also used with SCREENSCRAPE to block and redirect when a keyword is found on a page.</td></tr>';
		echo '<tr><th>BLOCKNOTIFY</th><td>Cancel the request and show a Chrome notification. No redirect.</td></tr>';
		echo '<tr><th>TRIGGER</th><td>Send an alert email when the URL is visited or a screenscrape keyword is found. Does not block. Set App Name to the destination email address.</td></tr>';
		echo '<tr><th>TRIGGER_EXEMPT</th><td>Stop TRIGGER processing for this URL. Useful to suppress alerts for specific URLs that would otherwise match a broader TRIGGER rule.</td></tr>';

		// USERNAME
		echo '<tr class="section"><td colspan="2">Username</td></tr>';
		echo '<tr><th>Purpose</th><td>Limit the rule to a specific user or pattern of users. Leave blank to apply to all users.</td></tr>';
		echo '<tr><th>Modes</th><td><ul>';
		echo '<li><b>Exact match</b> — <code>student@example.com</code></li>';
		echo '<li><b>regex:</b> — <code>regex:.*@example.com$</code> matches all users in the domain</li>';
		echo '</ul></td></tr>';

		// SUBNET
		echo '<tr class="section"><td colspan="2">Subnet</td></tr>';
		echo '<tr><th>Purpose</th><td>Limit the rule to devices on a specific network. Leave blank to apply to all networks.</td></tr>';
		echo '<tr><th>Examples</th><td><ul><li><code>192.168.1.0/24</code> — match a /24 subnet</li><li><code>10.0.0.0/8</code> — match a /8 subnet</li></ul></td></tr>';

		// INITIATOR
		echo '<tr class="section"><td colspan="2">Initiator</td></tr>';
		echo '<tr><th>Purpose</th><td>Meaning depends on the Action selected.</td></tr>';
		echo '<tr><th>For ALLOW / BLOCK / BLOCKPAGE / BLOCKNOTIFY</th><td>The URL of the page that initiated the request. Use to scope a rule to requests made by a specific page.<br />';
		echo 'Supports <code>regex:</code> prefix. Leave blank to match any initiator.<br />';
		echo '<b>Example:</b> <code>regex:.*youtube\.com.*</code> — only apply rule when request comes from a YouTube page.</td></tr>';
		echo '<tr><th>For TRIGGER / TRIGGER_EXEMPT with SCREENSCRAPE</th><td>Defines the keyword(s) and count threshold for page text scanning.<br />';
		echo 'Format: <code>count,word1|word2|wordN</code><br />';
		echo '<ul>';
		echo '<li><code>2,the</code> — trigger if "the" appears 2 or more times</li>';
		echo '<li><code>1,tiger|lion|cougar</code> — trigger if any single word appears 1 or more times</li>';
		echo '<li><code>7,tiger|lion|cougar</code> — trigger if any single word appears 7 or more times</li>';
		echo '</ul></td></tr>';

		// APP NAME
		echo '<tr class="section"><td colspan="2">App Name</td></tr>';
		echo '<tr><th>For TRIGGER</th><td>The email address to send the alert to when the rule fires.<br /><b>Example:</b> <code>support@example.com</code></td></tr>';
		echo '<tr><th>For ALLOW / BLOCK (app filtering)</th><td>Restrict the rule to a specific app group defined in the filter app list. Only applies in defaultdeny filter mode.</td></tr>';

		// COMMENT
		echo '<tr class="section"><td colspan="2">Comment</td></tr>';
		echo '<tr><th>Purpose</th><td>Free text note for admin reference. Not used in rule evaluation.</td></tr>';

		// EXAMPLES
		echo '<tr class="section"><td colspan="2">Common Rule Examples</td></tr>';
		echo '<tr><th>Block YouTube for all users</th><td>URL: <code>https://youtube.com</code> | Action: <code>BLOCKPAGE</code></td></tr>';
		echo '<tr><th>Allow Google for all users</th><td>URL: <code>simple:google.com</code> | Action: <code>ALLOW</code></td></tr>';
		echo '<tr><th>Block a site for one user</th><td>URL: <code>https://reddit.com</code> | Action: <code>BLOCKPAGE</code> | Username: <code>student@example.com</code></td></tr>';
		echo '<tr><th>Alert on keyword in page text</th><td>Resource Type: <code>SCREENSCRAPE</code> | Action: <code>TRIGGER</code> | Username: <code>regex:.*@example\.com$</code> | Initiator: <code>1,badword|otherword</code> | App Name: <code>admin@example.com</code></td></tr>';
		echo '<tr><th>Block page containing keyword</th><td>Resource Type: <code>SCREENSCRAPE</code> | Action: <code>BLOCKPAGE</code> | Initiator: <code>3,badword</code></td></tr>';
		echo '<tr><th>Suppress trigger for specific URL</th><td>URL: <code>https://safepage.com</code> | Action: <code>TRIGGER_EXEMPT</code> | Priority: lower number than the TRIGGER rule</td></tr>';

		echo '</table>';

	}
}
