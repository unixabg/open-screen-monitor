<?php
namespace OSM\Route\Admin;

class Permissions extends \OSM\Tools\Route {
	public function action(){
		$this->requireAdmin();

		//get current permissions
		$permissions = [];
		$rows = \OSM\Tools\DB::select('tbl_lab_permission',['order'=>'username asc']);
		foreach($rows as $row){
			$permissions[ $row['username'] ][] = $row['groupid'];
		}

		if (isset($_POST['email']) && isset($_POST['action'])){
			switch($_POST['action']){
				case 'Update':
					\OSM\Tools\DB::delete('tbl_lab_permission',['fields'=>['username'=>$_POST['email']]]);
					//fallthrough to add all the new permissions
				case 'Add':
					foreach($_POST['labs'] ?? [] as $lab){
						//only add new permissions
						$fields = ['username'=>$_POST['email'],'groupid'=>$lab];
						$permission = \OSM\Tools\DB::select('tbl_lab_permission',['fields'=>$fields]);
						if (count($permission) > 0){continue;}
						\OSM\Tools\DB::insert('tbl_lab_permission',$fields);
					}
					break;
				case 'Delete':
					\OSM\Tools\DB::delete('tbl_lab_permission',['fields'=>['username'=>$_POST['email']]]);
					break;
			}
			$this->redirect('Admin\Permissions');
		}



		echo '<h2>Permissions</h2>';
		if (isset($_GET['email'])){
			$thisPermissions = $permissions[$_GET['email']] ?? [];

			echo '<form method="post">Username: <input name="email" value="'.htmlentities($_GET['email']).'"/>';
			echo '<br /><br /><div style="border: 1px solid black;padding: 5px;margin:5px;column-count: 3; column-fill: auto;">';

			echo '<input type="checkbox" name="labs[]" value="admin" '.(in_array('admin', $thisPermissions) ? 'checked="checked"':'').' /> Admin';
			foreach($this->labs() as $lab=>$devices) {
				$checked = in_array($lab,$thisPermissions) ? 'checked="checked"':'';
				$lab = htmlentities($lab);
				echo '<br /><input type="checkbox" name="labs[]" value="'.$lab.'" '.$checked.' /> '.$lab;
			}
			echo '</div>';
			echo '<br />';
			echo ' <input type="submit" class="btn btn-primary" name="action" value="Add" />';
			echo ' <input type="submit" class="btn btn-primary" name="action" value="Update" />';
			echo ' <input type="submit" class="btn btn-primary" name="action" value="Delete" />';
			echo '</form>';
		} else {
			echo '<a href="?route=Admin\Permissions&email">Add Permissions</a><br /><br />';
			echo '<table class="table"><thead><tr><th>Email</th><th>Lab</th><th>&nbsp;</th></tr></thead><tbody>';
			foreach($permissions as $email=>$_labs) {
				foreach($_labs as $i=>$value){$_labs[$i] = htmlentities($value);}
				echo '<tr style="height:1em;">';
				echo '<td>'.htmlentities($email).'</td>';
				echo '<td>'.implode('<br />',$_labs).'</td>';
				echo '<td><a href="?route=Admin\Permissions&email='.htmlentities($email).'"><span class="material-symbols-outlined" title="Edit this user.">edit</span></a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}
}
