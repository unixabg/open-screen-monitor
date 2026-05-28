<?php
namespace OSM\Route\Admin;

class Bypass extends \OSM\Tools\Route {
	public function action(){
		if (!($_SESSION['bypass'] ?? false)){
			die('Permission Denied');
		}

		$namesByEmail = [];
		// Only query OneRoster if enabled — bypass works without it.
		// If another roster tool is added in future, populate $namesByEmail here.
		if (\OSM\Tools\Config::get('enableOneRoster')){
			$rows = \OSM\Tools\DB::selectRaw('select distinct email, name from tbl_oneroster where role = "student" order by email');
			foreach($rows as $row){
				$namesByEmail[ $row['email'] ] = $row['name'];
			}
		}


		//get bypass groups
		$groups = [];
		$rows = \OSM\Tools\TempDB::scan('bypass/*');
		foreach($rows as $path => $groupID){
			$path = explode('/',$path);
			if ($email = ($path[1] ?? false)){
				$email = hex2bin($email);
				$groups[$groupID][$email] = $namesByEmail[$email] ?? $email;
			}
		}


		//if group specified then build and send to monitor
		$group = $_GET['group'] ?? '';
		if (isset($groups[$group])){
			$groupID = 'bypass{'.$group.'}';
			$_SESSION['groups'][ $groupID ] = [
				'name' => 'Bypass: '.$group,
				'type' => 'user',
			];

			foreach ($groups[$group] as $email => $name) {
				$_SESSION['clients']['users'][$email] = $name;
				$_SESSION['groups'][ $groupID ]['clients'][$email] = $name;
			}
			asort($_SESSION['groups'][ $groupID ]['clients']);
			header('Location: /?route=Monitor\Viewer&groupID='.urlencode($groupID));

			\OSM\Tools\Log::add('viewer.bypass',$groupID);
			die();
		}


		//next let them add or delete bypass users
		if ($add = $_POST['add'] ?? false){
			$emails = $add['email'] ?? [];
			// Allow manual email entry so bypass works without OneRoster.
			// OneRoster checkboxes are a convenience only, not a gate.
			$manualEmail = trim($add['manual_email'] ?? '');
			if ($manualEmail != ''){ $emails[] = $manualEmail; }
			$group = $add['group'] ?? '';
			foreach($emails as $email){
				$email = trim($email);
				// Original gate: isset($namesByEmail[$email])
				// Removed — any valid email can be bypassed regardless of roster.
				if ($email != '' && $group != ''){
					\OSM\Tools\TempDB::set('bypass/'.bin2hex($email),$group,\OSM\Tools\Config::get('bypassTimeout'));
					\OSM\Tools\Log::add('bypass.addStudent',$email,$group);
				}
			}
			$this->redirect('Admin\Bypass');
		}


		if ($delete = $_POST['delete'] ?? false){
			\OSM\Tools\TempDB::del('bypass/'.bin2hex($delete));
			\OSM\Tools\Log::add('bypass.deleteStudent',$delete);
			$this->redirect('Admin\Bypass');
		}




		//else let them build the groups

		echo '<h2>Bypass Users</h2>';

		echo '<br />';

		echo '<div style="padding:10px;max-width:500px;margin:auto;">';
		echo '<form method="post">';
		echo '<h3>Add Student:</h3>';
		echo '<div style="display:flex;justify-content:space-around;"><b>Group Name:</b> <input required name="add[group]" /><input type="submit" /></div>';
		echo '<div style="padding:10px;">';
		echo '<b>Manual Email:</b> <input type="email" name="add[manual_email]" placeholder="user@example.com" style="width:300px;" />';
		echo '</div>';
		if (\OSM\Tools\Config::get('enableOneRoster') && !empty($namesByEmail)){
			echo '<div style="overflow-y:scroll;height:500px;display:inline-block;padding:10px;margin:10px;">';
			echo '<table style="padding:10px;width:100%;">';
				ksort($namesByEmail);
				foreach($namesByEmail as $email => $name){
					echo '<tr><td><input name="add[email][]" type="checkbox" value="'.htmlentities($email).'"></td><td>'.htmlentities($email).'</td><td>'.htmlentities($name).'</td></tr>';
				}
			echo '</table>';
			echo '</div>';
		}
		echo '</form>';
		echo '</div>';

		echo '<br />';
		echo '<br />';
		echo '<br />';

		echo '<table style="width:100%;">';
		echo '<tr><th>Group</th><th>Email</th><th>Name</th><th>Delete</th></tr>';
		asort($groups);
		foreach($groups as $group => $users){
			foreach($users as $email => $name){
				echo '<tr>';
				echo '<td>'.htmlentities($group).' (<a href="/?route=Admin\Bypass&group='.urlencode($group).'">View</a>)</td>';
				echo '<td>'.htmlentities($email).'</td>';
				echo '<td>'.htmlentities($name).'</td>';
				echo '<td><form method="post"><input type="hidden" name="delete" value="'.htmlentities($email).'" /><input type="submit" value="Delete" /></form></td>';
				echo '</tr>';
			}
		}
		echo '</table>';
	}
}
