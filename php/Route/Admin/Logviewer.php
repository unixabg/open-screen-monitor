<?php
namespace OSM\Route\Admin;

class Logviewer extends \OSM\Tools\Route {
	public function action(){
		global $dataDir;

		$this->requireAdmin();

		$perPage = 50;

		$pageNumber = intval($_POST['pageNumber'] ?? 0);
		if (isset($_POST['nextPage'])){$pageNumber++;}
		if (isset($_POST['prevPage'])){$pageNumber--;}
		if ($pageNumber < 0){$pageNumber = 0;}

		$where = [];
		$bindings = [];
		$fields = ['timestamp','ip','username','type','targetid','data'];
		foreach($fields as $field){
			if ($value = $_POST['search'][$field] ?? false){
				$where[] = $field.' like :'.$field;
				$bindings[':'.$field] = $value;
			}
		}
		$where = implode(' AND ',$where);

		$limit = ($pageNumber*$perPage).', '.(($pageNumber+1)*$perPage);
		$rows = \OSM\Tools\DB::select('tbl_log',['where'=>$where,'bindings'=>$bindings,'order'=>'id desc','limit'=>$limit]);


		echo '<h2>Log Viewer</h2>';
		echo '<hr />';

		$this->css = '
			.form {margin:auto;width:500px;}
			.form h1 {text-align:center;}
			.form table {width:100%;}
			table.info {margin:auto;}
			table.data {margin:auto;padding:10px;width:100%;}
			tr.section td {padding:40px;text-align:center;font-weight:bold;}

			.pager {display:flex;justify-content:space-between;}
		';

		$this->js = '
			$(document).ready(function(){
				$(".logSearch input").on("change",function(){
					console.log("hey");
					$("#searchForm").submit();
				});
			});
		';

		echo '<form id="searchForm" method="post">';
		echo '<div class="pager">';
		echo '<input type="submit" name="prevPage" value="Previous Page" '.($pageNumber == 0 ? 'disabled' : '').'/>';
		echo '<span>Page '.($pageNumber+1).'</span>';
		echo '<input type="submit" name="nextPage" value="Next Page" '.(count($rows) < $perPage ? 'disabled' : '').'/>';
		echo '</div>';
		echo '<input type="hidden" name="pageNumber" value="'.$pageNumber.'" />';
		echo '<table class="data">';
		echo '<tr><th>Timestamp</th><th>IP</th><th>Username</th><th>Type</th><th>Target ID</th><th>Data</th></tr>';
		echo '<tr class="logSearch">';
			echo '<td><input name="search[timestamp]" value="'.htmlentities($_POST['search']['timestamp'] ?? '').'"/></td>';
			echo '<td><input name="search[ip]" value="'.htmlentities($_POST['search']['ip'] ?? '').'" /></td>';
			echo '<td><input name="search[username]" value="'.htmlentities($_POST['search']['username'] ?? '').'" /></td>';
			echo '<td><input name="search[type]" value="'.htmlentities($_POST['search']['type'] ?? '').'" /></td>';
			echo '<td><input name="search[targetid]" value="'.htmlentities($_POST['search']['targetid'] ?? '').'" /></td>';
			echo '<td><input name="search[data]" value="'.htmlentities($_POST['search']['data'] ?? '').'" /></td>';
		echo '</tr>';

		foreach($rows as $row){
			echo '<tr>';
			echo '<td>'.htmlentities($row['timestamp']).'</td>';
			echo '<td>'.htmlentities($row['ip']).'</td>';
			echo '<td>'.htmlentities($row['username']).'</td>';
			echo '<td>'.htmlentities($row['type']).'</td>';
			echo '<td>'.htmlentities($row['targetid']).'</td>';
			echo '<td>'.htmlentities($row['data']).'</td>';
			echo '</tr>';
		}
		echo '</table>';
		echo '</form>';
	}
}
