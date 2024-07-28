<?php
namespace OSM\Route\Monitor;

class Filterlog extends \OSM\Tools\Route {
	public function action(){
		global $dataDir;

		$this->requireLogin();


		$rows = \OSM\Tools\DB::select('tbl_lab_device');
		$deviceNames = [];
		foreach($rows as $row){
			$nicename = [];
			if ($row['user'] != ''){$nicename[] = $row['user'];}
			if ($row['location'] != ''){$nicename[] = $row['location'];}
			if ($row['assetid'] != ''){$nicename[] = $row['assetid'];}
			$nicename = implode(' - ',$nicename);
			if ($nicename == ''){$nicename = 'Unknown: '.$row['deviceid'];}

			$deviceNames[ $row['deviceid'] ] = $nicename;
		}
		asort($deviceNames);



		$date = isset($_POST['date']) ? ($_POST['date'] == ''?'':date('Y-m-d',strtotime($_POST['date']))) : date('Y-m-d');

		$urlfilter = $_POST['urlfilter'] ?? '';

		$action = $_POST['action'] ?? '';
		$actions = [''=>'','ALLOW'=>'Allowed Requests','BLOCK'=>'Filtered Requests','TRIGGER'=>'Trigger','TRIGGER_EXEMPTION'=>'Trigger Exemptions'];
		$actionTypes = [
			'ALLOW' => ['ALLOW'],
			'BLOCK' => ['BLOCK','BLOCKPAGE','BLOCKNOTIFY','KEYWORDBLOCK','REDIRECT','CANCEL'],
			'TRIGGER' => ['TRIGGER'],
			'TRIGGER_EXEMPTION' => ['TRIGGER_EXEMPTION'],
		];


		$username = $_POST['username'] ?? '';
		$deviceid = $_POST['deviceid'] ?? '';
		$device = $_POST['device'] ?? '';
		$type = $_POST['type'] ?? '';
		$initiator = $_POST['initiator'] ?? '';


		$this->title = 'Open Screen Monitor - Log Viewer';
		$this->css = '
			.searchForm {max-width:400px;}
			.searchForm input, .searchForm select {width:100%;}

			table.results tr td:nth-child(1) {word-break: keep-all;white-space:nowrap;}
			table.results tr td:nth-child(2) {word-break: break-all;}
			table.results td {padding:10px;}

			@media print {
				.noprint, .noprint * {display: none !important;}
			}
		';





		//only show results if something was searched
		$results = '';
		$stats = ['TOTAL' => 0];
		$domains = [];
		$reportSummary = [];
		if (isset($_POST['search'])){
			$where = [];
			$bindings = [];

			if ($date != ''){
				$where[] = 'date = :date';
				$bindings[':date'] = $date;
			}
			if ($urlfilter != ''){
				$where[] = 'url like :url';
				$bindings[':url'] = '%'.$urlfilter.'%';
			}
			if (isset($actionTypes[$action])){
				$subwhere = [];
				foreach($actionTypes[$action] as $i=>$actiontype){
					$subwhere[] = 'action = :action'.$i;
					$bindings[':action'.$i] = $actiontype;
				}
				$subwhere = '('.implode(' OR ',$subwhere).')';
				$where[] = $subwhere;
			}
			if ($username != ''){
				$where[] = 'username like :username';
				$bindings[':username'] = '%'.$username.'%';
			}
			if ($type != ''){
				$where[] = 'type = :type';
				$bindings[':type'] = $type;
			}
			if ($initiator != ''){
				$where[] = 'initiator = :initiator';
				$bindings[':initiator'] = $initiator;
			}

			if (!isset($_POST['showadvanced'])){
				$where[] = 'type = "main_frame"';
			}

			if (!$_SESSION['admin']){
				$subwhere = [];
				foreach(array_keys($_SESSION['allowedclients']) as $i => $clientid){
					$subwhere[] = ':client'.$i;
					$bindings[':client'.$i] = $clientid;
				}
				$subwhere = '('.implode(',',$subwhere).')';

				if (\OSM\Tools\Config::get('mode') == 'user'){
					$where[] = 'username IN '.$subwhere;
				} elseif (\OSM\Tools\Config::get('mode') == 'device'){
					$where[] = 'deviceid IN '.$subwhere;
				} else {
					echo "Error finding Mode";
					return;
				}
			}

			$where = implode(' AND ',$where);
			$rows = \OSM\Tools\DB::select('tbl_filter_log',['where'=>$where,'bindings'=>$bindings,'order'=>'date desc, time desc']);
			$results .= '<table class="w3-table-all results"><tbody>';
			foreach ($rows as $row){
				$results .= '<tr><td>';
				$results .= '<b>Action:</b> '.$row['action'].'<br />';
				$results .= '<b>Date:</b> '.$row['date'].'<br />';
				$results .= '<b>Time:</b> '.$row['time'].'<br />';
				$results .= '<b>User:</b> '.htmlentities($row['username']).'<br />';
				$results .= '<b>Device:</b> '.htmlentities($deviceNames[$row['deviceid']] ?? $row['deviceid']);
				if (isset($_POST['showadvanced'])) {
					$results .= '<br /><b>IP:</b> '.$row['ip'];
					if ($row['action'] == 'KEYWORDBLOCK') {
						$results .= '<br /><b>Key Word:</b> '.$row['type'];
					} else {
						$results .= '<br /><b>Type:</b> '.$row['type'];
					}
					$results .= '<br /><b>Initiator:</b> '.$row['initiator'];
				}
				$results .= '</td><td>'.htmlentities($row['url']).'</td></tr>';

				$stats['TOTAL']++;
				if (!isset($stats[ $row['action'] ])){
					$stats[ $row['action'] ] = 0;
				}
				$stats[ $row['action'] ]++;



				$url = parse_url($row['url']);
				$domain = $url['host'] ?? false;
				if ($domain){
					if (!in_array($domain,$domains)){
						$domains[] = $domain;
					}
				}
			}
			$results .= '</tbody></table>';
		}




		echo '<table style="width:100%" class="noprint">';
		echo '<tbody>';
		echo '<tr><td>';
		echo '<form method="post" class="searchForm">';
		echo 'Date:<br /><input type="date" name="date" value="'.htmlentities($date).'" />';
		echo '<br />URL Filter:<br /><input type="text" name="urlfilter" value="'.htmlentities($urlfilter).'" />';
		echo '<br />Action:<br /><select name="action">';
			foreach($actions as $key=>$value){
				echo '<option '.($action == $key?'selected="selected"':'').' value="'.$key.'">'.$value.'</option>';
			}
			echo '</select>';
		echo '<br />Username:<br /><input type="text" name="username" value="'.htmlentities($username).'" />';
		echo '<br />Device ID:<br /><input type="text" name="deviceid" value="'.htmlentities($deviceid).'" />';
		echo '<br />Device:<br /><select name="device">';
			echo '<option></option>';
			foreach($deviceNames as $deviceid => $nicename){
				if (!$_SESSION['admin'] && !in_array($row['deviceid'], $_SESSION['allowedclients'])){continue;}
				echo '<option value="'.htmlentities($deviceid).'" '.($device == $deviceid ? 'selected' : '').'>'.htmlentities($nicename).'</option>';
			}
			echo '</select>';

		echo '<br /><input type="checkbox" name="showadvanced" value="1" '.(isset($_POST['showadvanced'])?'checked="checked"':'').' />Show Advanced';
		if (isset($_POST['showadvanced'])) {
			echo '<br />Type:<br /><input type="text" name="type" value="'.htmlentities($type).'" />';
			echo '<br />Initiator:<br /><input type="text" name="initiator" value="'.htmlentities($initiator).'" />';
		}
		echo '<br /><input type="submit" name="search" value="Search" />';
		echo '</form>';
		echo '</td>';
		echo '<td><div id="reports">';
			echo '<h3>Report Summary</h3>';
			foreach($stats as $stat => $count){
				echo '<br /><b>'.htmlentities($stat).':</b> '.$count;
			}
		echo '</div>';
		echo '<td><div id="reportsurls">';
			echo '<h3>Sites</h3>';
			echo '<ul>';
			sort($domains);
			foreach($domains as $domain){
				echo '<li>'.htmlentities($domain).'</li>';
			}
			echo '</ul>';
		echo '</div></td>';
		echo '</tr></td>';
		echo '</tbody>';
		echo '</table>';
		echo $results;
	}
}
