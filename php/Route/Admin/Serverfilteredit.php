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
		echo '<tr><td>Priority</td><td><input name="rule[priority]" type="number" value="'.htmlentities($data['priority'] ?? '').'" /></td></tr>';
		echo '<tr><td>URL</td><td><input name="rule[url]" value="'.htmlentities($data['url'] ?? '').'" /></td></tr>';
		echo '<tr><td>Resource Type</td><td><select name="rule[resourceType]"><option></option>';
			foreach(\OSM\Tools\Config::get('filterResourceTypes') as $value){
				echo '<option value="'.$value.'" '.($value == ($data['resourceType'] ?? '') ? 'selected':'').' >'.$value.'</option>';
			}
			echo '</select></td></tr>';
		echo '<tr><td>Action</td><td><select name="rule[action]"><option></option>';
			foreach(['ALLOW','BLOCK','BLOCKPAGE','BLOCKNOTIFY','TRIGGER','SCREENSCRAPE'] as $value){
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
		echo '<table class="info">
		<tr class="section"><td colspan="2">Black List</td></tr>
		<tr>
			<th>Blacklist Entry Formats</th>
			<td><ul><li>url</li><li>action -tab- url</li><li>action -tab- resourceType -tab- url</li></ul></td>
		</tr>
		<tr>
			<th>URL</th>
			<td><ul><li>An actual url</li><li>a substring of a URL</li></ul></td>
		</tr>
		<tr>
			<th>Actions</th>
			<td><ul><li>Allow</li><li>BLOCKPAGE</li><li>BLOCKNOTIFY</li><li>BLOCK</li></ul></td>
		</tr>
		<tr>
			<th>ResourceType<br />(must have config variable "filterresourcetypes" enabled) </th>
			<td><ul><li>*</li><li>main_frame</li><li>sub_frame</li><li>image</li><li>media</li><li>... and any other valid resource type in Chrome<br />https://developer.chrome.com/extensions/webRequest#type-ResourceType</li></ul></td>
		</tr>


		<tr class="section"><td colspan="2">White List</td></tr>
		<tr>
			<th>Whitelist Entry Formats</th>
			<td><ul><li>url</li><li>action -tab- url</li><li>action -tab- resourceType -tab- url</li><li>action -tab- resourceType -tab- url -tab- customNotificationMessage</li></ul></td>
		</tr>
		<tr>
			<th>URL</th>
			<td><ul><li>An actual url</li><li>a substring of a URL</li></ul></td>
		</tr>
		<tr>
			<th>Actions</th>
			<td><ul><li>NOTIFY</li></ul></td>
		</tr>
		<tr>
			<th>ResourceType<br />(must have config variable "filterresourcetypes" enabled) </th>
			<td><ul><li>*</li><li>main_frame</li><li>sub_frame</li><li>image</li><li>media</li><li>... and any other valid resource type in Chrome<br />https://developer.chrome.com/extensions/webRequest#type-ResourceType</li></ul></td>
		</tr>
		<tr>
			<th>CustomNotificationMessage</th>
			<td><ul><li>Custom message you want in notification.</li></ul></td>
		</tr>


		<tr class="section"><td colspan="2">Trigger List</td></tr>
		<tr>
			<th>Trigger List Entry Formats</th>
			<td><ul><li>email -tab- url</li><li>email -tab- resourceType -tab- url</li></ul></td>
		</tr>
		<tr>
			<th>URL</th>
			<td><ul><li>*</li><li>an actual url</li><li>a substring of a URL</li></ul></td>
		</tr>
		<tr>
			<th>ResourceType<br />(must have config variable "filterresourcetypes" enabled) </th>
			<td><ul><li>*</li><li>main_frame</li><li>sub_frame</li><li>image</li><li>media</li><li>trigger_exempt</li><li>... and any other valid resource type in Chrome<br />https://developer.chrome.com/extensions/webRequest#type-ResourceType</li></ul></td>
		</tr>

		<tr class="section"><td colspan="2">Trigger List</td></tr>
		<tr>
			<th>Page Content Bad Word List Formats</th>
			<td><ul><li>word</li><li>word -tab- count</li><li>action -tab- word -tab- count</li></ul></td>
		</tr>
		<tr>
			<th>Actions</th>
			<td><ul><li>BLOCK</li><li>BLOCKNOTIFY</li><li>BLOCKPAGE</li></ul></td>
		</tr>
		</table>
		';

	}
}
