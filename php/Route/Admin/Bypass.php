<?php
namespace OSM\Route\Admin;

class Bypass extends \OSM\Tools\Route {
	public function action(){
		if (!($_SESSION['bypass'] ?? false)){
			die('Permission Denied');
		}

		$namesByEmail = [];
		$rows = \OSM\Tools\DB::selectRaw('select distinct email, name from tbl_oneroster where role = "student" order by email');
		foreach($rows as $row){
			$namesByEmail[ $row['email'] ] = $row['name'];
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
			$email = $add['email'] ?? '';
			$group = $add['group'] ?? '';
			if (isset($namesByEmail[$email]) && $group != ''){
				\OSM\Tools\TempDB::set('bypass/'.bin2hex($email),$group,\OSM\Tools\Config::get('bypassTimeout'));
				\OSM\Tools\Log::add('bypass.addStudent',$email,$group);
				$this->redirect('Admin\Bypass');
			}
		}


		if ($delete = $_POST['delete'] ?? false){
			\OSM\Tools\TempDB::del('bypass/'.bin2hex($delete));
			\OSM\Tools\Log::add('bypass.deleteStudent',$delete);
			$this->redirect('Admin\Bypass');
		}




		//else let them build the groups

		echo '<h2>Bypass Users</h2>';

		echo '<br />';

		echo '<form method="post">';
		echo '<b>Add Student:</b>';
		echo ' <select required name="add[email]">';
			echo '<option></option>';
			asort($namesByEmail);
			foreach($namesByEmail as $email => $name){
				echo '<option value="'.htmlentities($email).'">'.htmlentities($name).'</option>';
			}
			echo '</select>';
		echo ' <b>Group Name:</b> <input required name="add[group]" />';
		echo ' <input type="submit" />';
		echo '</form>';

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
