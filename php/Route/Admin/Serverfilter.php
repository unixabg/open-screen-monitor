<?php
namespace OSM\Route\Admin;

class Serverfilter extends \OSM\Tools\Route {
	public function action(){
		global $dataDir;

		$this->requireAdmin();
		if (count($_POST) > 0){
			$action = $_POST['action'] ?? '';
			foreach(($_POST['rules']??[]) as $id){
				if ($action == 'Delete'){
					\OSM\Tools\DB::delete('tbl_filter_entry',['fields'=>['id'=>$id]]);
				}
				if ($action == 'Enable'){
					\OSM\Tools\DB::update('tbl_filter_entry',['id'=>$id],['enabled'=>'1']);
				}
				if ($action == 'Disable'){
					\OSM\Tools\DB::update('tbl_filter_entry',['id'=>$id],['enabled'=>'0']);
				}
			}

			header('Location: /?route=Admin\\Serverfilter');
			die();
		}


		echo '<h2>Server Filter List</h2>';
		echo '<a href="/?route=Admin\\Serverfilteredit&id=">Add Rule</a>';
		echo '<hr />';

		$this->css = '
			.form {margin:auto;width:500px;}
			.form h1 {text-align:center;}
			.form table {width:100%;}
			table.info {margin:auto;}
			table.data {margin:auto;padding:10px;width:100%;}
			tr.section td {padding:40px;text-align:center;font-weight:bold;}
		';

		echo '<h2 style="text-align:center;">Rules</h2>';
		echo '<form method="post">';
		echo '<table class="data">';
		echo '<tr><th></th><th>Priority</th><th>Enabled</th><th>Action</th><th>App Name</th><th>URL</th><th>Resource Type</th><th>Username</th><th>Subnet</th><th>Initiator</th><th>Comment</th><th></th></tr>';
		$rows = \OSM\Tools\DB::select('tbl_filter_entry',['order'=>'priority desc, appName asc, id asc']);
		foreach($rows as $row){
			echo '<tr>';
			echo '<td><input type="checkbox" name="rules[]" value="'.$row['id'].'" /></td>';
			echo '<td>'.htmlentities($row['priority']).'</td>';
			echo '<td>'.htmlentities($row['enabled']).'</td>';
			echo '<td>'.htmlentities($row['action']).'</td>';
			echo '<td>'.htmlentities($row['appName']).'</td>';
			echo '<td>'.htmlentities($row['url']).'</td>';
			echo '<td>'.htmlentities($row['resourceType']).'</td>';
			echo '<td>'.htmlentities($row['username']).'</td>';
			echo '<td>'.htmlentities($row['subnet']).'</td>';
			echo '<td>'.htmlentities($row['initiator']).'</td>';
			echo '<td>'.htmlentities($row['comment']).'</td>';
			echo '<td><a href="/?route=Admin\\Serverfilteredit&id='.htmlentities($row['id']).'">Edit</a></td>';
			echo '</tr>';
		}
		echo '<tr><td colspan="14" style="text-align:center;"><input type="submit" name="action" value="Enable" /> <input type="submit" name="action" value="Disable" /> <input type="submit" name="action" value="Delete" /></td></tr>';
		echo '</table>';
		echo '</form>';
	}
}
