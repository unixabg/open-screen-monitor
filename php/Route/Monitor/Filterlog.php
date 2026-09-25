<?php
namespace OSM\Route\Monitor;

class Filterlog extends \OSM\Tools\Route {
	public function action(){
		global $dataDir;

		$this->requireLogin();

		//non-admins must open one of their labs or classes first; that is what fills
		//$_SESSION['clients'] and decides whose history they may see
		if (!($_SESSION['admin'] ?? false) && empty($_SESSION['clients']['devices']) && empty($_SESSION['clients']['users'])){
			$this->denyAccess('Permission Denied: open one of your labs or classes first, then view browsing history from there.');
		}

		$rows = \OSM\Tools\DB::select('tbl_lab_device');
		$deviceNames = [];
		foreach($rows as $row){
			$nicename = [];
			if ($row['annotateduser'] != ''){$nicename[] = $row['annotateduser'];}
			if ($row['annotatedlocation'] != ''){$nicename[] = $row['annotatedlocation'];}
			if ($row['annotatedassetid'] != ''){$nicename[] = $row['annotatedassetid'];}
			if ($row['serialnumber'] != ''){$nicename[] = $row['serialnumber'];}
			$nicename = implode(' - ',$nicename);
			if ($nicename == ''){
				$nicename = 'Unknown: '.$row['deviceid'];
			} else {
				$nicename .= ' - '.substr($row['deviceid'],0,8);
			}

			$deviceNames[ $row['deviceid'] ] = $nicename;
		}
		asort($deviceNames);



		$date = isset($_POST['date']) ? ($_POST['date'] == ''?'':date('Y-m-d',strtotime($_POST['date']))) : date('Y-m-d');
		$enddate = isset($_POST['enddate']) ? ($_POST['enddate'] == ''?'':date('Y-m-d',strtotime($_POST['enddate']))) : '';

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
		$devicesearch = $_POST['devicesearch'] ?? '';
		$type = $_POST['type'] ?? '';
		$initiator = $_POST['initiator'] ?? '';


		$this->title = 'Open Screen Monitor - Log Viewer';
		$this->css = '
			.searchForm {max-width:400px;}
			.searchForm input[type=text], .searchForm select {width:100%;}

			table.results tr td:nth-child(1) {word-break: keep-all;white-space:nowrap;}
			table.results tr td:nth-child(2) {word-break: break-all;}
			table.results td {padding:10px;}

			table.noprint tr td {vertical-align:top;}

			@media print {
				.noprint, .noprint * {display: none !important;}
			}
		';





		//only show results if something was searched
		$results = '';
		$stats = ['TOTAL' => 0];
		$domains = [];
		$users = [];
		$reportSummary = [];
		if (isset($_POST['search'])){
			$where = [];
			$bindings = [];
			$rangeError = '';

			//date range validation
			$maxRangeDays = 14;
			if ($enddate != '' && $date != '' && $enddate != $date){
				$startTs = strtotime($date);
				$endTs   = strtotime($enddate);
				$rangeDays = ($endTs - $startTs) / 86400 + 1;
				if ($endTs < $startTs){
					$rangeError = 'End date must be on or after start date.';
				} elseif ($rangeDays > $maxRangeDays){
					$rangeError = 'Date range exceeds maximum of '.$maxRangeDays.' days. Please narrow your selection.';
				} elseif ($urlfilter == '' && $username == '' && $action == '' && $device == '' && $deviceid == '' && $devicesearch == ''){
					$rangeError = 'Multi-day searches require at least one filter (URL, Username, Action, or Device) to limit result size.';
				} else {
					$where[] = 'date BETWEEN :date AND :enddate';
					$bindings[':date'] = $date;
					$bindings[':enddate'] = $enddate;
				}
			} elseif ($date != ''){
				$where[] = 'date = :date';
				$bindings[':date'] = $date;
			}

			if ($rangeError != ''){
				$results = '<p style="color:red;font-weight:bold;">'.htmlentities($rangeError).'</p>';
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
				$where[] = '(type = "main_frame" OR action IN ("TRIGGER","TRIGGER_EXEMPT"))';
			}

			if ($device != ''){
				$where[] = 'deviceid = :device1';
				$bindings[':device1'] = $device;
			}

			if ($deviceid != ''){
				$where[] = 'deviceid = :device2';
				$bindings[':device2'] = $deviceid;
			}

			if ($devicesearch != ''){
				$matchingDevices = \OSM\Tools\DB::select('tbl_lab_device',['where'=>'annotateduser LIKE :ds1 OR annotatedlocation LIKE :ds2 OR annotatedassetid LIKE :ds3 OR serialnumber LIKE :ds4 OR deviceid LIKE :ds5','bindings'=>[':ds1'=>'%'.$devicesearch.'%',':ds2'=>'%'.$devicesearch.'%',':ds3'=>'%'.$devicesearch.'%',':ds4'=>'%'.$devicesearch.'%',':ds5'=>'%'.$devicesearch.'%']]);
				$matchingIDs = array_column($matchingDevices,'deviceid');
				if (!empty($matchingIDs)){
					$inList = implode(',',array_map(fn($id)=>"'".addslashes($id)."'",$matchingIDs));
					$where[] = 'deviceid IN ('.$inList.')';
				} else {
					$where[] = '1=0';
				}
			}

			//non-admins only see devices/users from groups they have opened this session
			//(a lab, class, or bypass group puts them in $_SESSION['clients'])
			if (!($_SESSION['admin'] ?? false)){
				$clientWhere = [];
				$i = 0;
				foreach(['devices'=>'deviceid','users'=>'username'] as $clientType => $column){
					$clientIDs = array_keys($_SESSION['clients'][$clientType] ?? []);
					if (count($clientIDs) == 0){continue;}

					$placeholders = [];
					foreach($clientIDs as $clientID){
						$placeholders[] = ':client'.$i;
						$bindings[':client'.$i] = $clientID;
						$i++;
					}
					$clientWhere[] = $column.' IN ('.implode(',',$placeholders).')';
				}

				if (count($clientWhere) == 0){
					//no groups opened means nothing to show, not everything
					$where[] = '1=0';
					$results .= '<p style="color:red;font-weight:bold;">No results: open one of your labs or classes first, then view browsing history from there.</p>';
				} else {
					//a row matches if it is one of their devices OR one of their users
					$where[] = '('.implode(' OR ',$clientWhere).')';
				}
			}

			$where = implode(' AND ',$where);

			//cap results - an unfiltered day can match millions of rows which
			//exhausts PHP memory and cannot be usefully displayed or printed
			$maxResults = 5000;
			$rows = [];
			$totalMatches = 0;
			if ($rangeError == ''){
				$countRows = \OSM\Tools\DB::selectRaw('SELECT COUNT(*) AS c FROM tbl_filter_log'.($where != '' ? ' WHERE '.$where : ''),$bindings);
				$totalMatches = intval($countRows[0]['c'] ?? 0);
				$rows = \OSM\Tools\DB::select('tbl_filter_log',['where'=>$where,'bindings'=>$bindings,'order'=>'id desc','limit'=>$maxResults]);
			}

			if ($totalMatches > $maxResults){
				$results .= '<p style="color:#b00;font-weight:bold;">Showing the most recent '.number_format($maxResults).' of '.number_format($totalMatches).' matching entries. Narrow your search (add a username, URL filter, or device) to see complete results.</p>';
			}

			$results .= '<table class="w3-table-all results"><tbody>';
			foreach ($rows as $row){
				$results .= '<tr><td>';
				$results .= '<b>Action:</b> '.htmlentities($row['action']).'<br />';
				$results .= '<b>Date:</b> '.htmlentities($row['date']).'<br />';
				$results .= '<b>Time:</b> '.htmlentities($row['time']).'<br />';
				$results .= '<b>User:</b> '.htmlentities($row['username']).'<br />';
				$results .= '<b>Annotated Info:</b> '.htmlentities($deviceNames[$row['deviceid']] ?? $row['deviceid']);
				if (isset($_POST['showadvanced'])) {
					//type and initiator come from unauthenticated extension requests
					$results .= '<br /><b>IP:</b> '.htmlentities($row['ip']);
					if ($row['action'] == 'KEYWORDBLOCK') {
						$results .= '<br /><b>Key Word:</b> '.htmlentities($row['type']);
					} else {
						$results .= '<br /><b>Type:</b> '.htmlentities($row['type']);
					}
					$results .= '<br /><b>Initiator:</b> '.htmlentities($row['initiator']);
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

				if (!in_array($row['username'],$users)){
					$users[] = $row['username'];
				}
			}
			$results .= '</tbody></table>';
		}




		echo '<table style="width:100%" class="noprint">';
		echo '<tbody>';
		echo '<tr><td>';
		echo '<form method="post" class="searchForm">';
		echo 'Date:<br /><input type="date" name="date" value="'.htmlentities($date).'" />';
		echo '<br />End Date (optional, max 14 days, requires a filter):<br /><input type="date" name="enddate" value="'.htmlentities($enddate).'" />';
		echo '<br />URL Filter:<br /><input type="text" name="urlfilter" value="'.htmlentities($urlfilter).'" />';
		echo '<br />Action:<br /><select name="action">';
			foreach($actions as $key=>$value){
				echo '<option '.($action == $key?'selected="selected"':'').' value="'.$key.'">'.$value.'</option>';
			}
			echo '</select>';
		echo '<br />Username:<br /><input type="text" name="username" value="'.htmlentities($username).'" />';
		echo '<br />Device ID:<br /><input type="text" name="deviceid" value="'.htmlentities($deviceid).'" />';
		echo '<br />Device Search:<br /><input type="text" name="devicesearch" value="'.htmlentities($devicesearch).'" placeholder="serial, asset, annotated user, location, device id" />';
		echo '<br />Annotated Info:<br /><select name="device">';
			echo '<option></option>';
			foreach($deviceNames as $deviceid => $nicename){
				if (!$_SESSION['admin'] && !isset($_SESSION['clients']['devices'][$deviceid])){continue;}
				echo '<option value="'.htmlentities($deviceid).'" '.($device == $deviceid ? 'selected' : '').'>'.htmlentities($nicename).'</option>';
			}
			echo '</select>';

		echo '<br /><br /><input type="checkbox" name="showadvanced" value="1" '.(isset($_POST['showadvanced'])?'checked="checked"':'').' />Show Advanced';
		if (isset($_POST['showadvanced'])) {
			echo '<br /><br />Type:<br /><input type="text" name="type" value="'.htmlentities($type).'" />';
			echo '<br />Initiator:<br /><input type="text" name="initiator" value="'.htmlentities($initiator).'" />';
		}
		echo '<br /><br /><input type="submit" name="search" value="Search" />';
		echo '</form>';
		echo '<br /><br />';
		echo '<div id="reports">';
			echo '<h3>Report Summary</h3>';
			foreach($stats as $stat => $count){
				echo '<br /><b>'.htmlentities($stat).':</b> '.$count;
			}
		echo '</div>';
		echo '</td>';
		echo '<td><div id="reportsusers">';
			echo '<h3>Users</h3>';
			echo '<ul>';
			sort($users);
			foreach($users as $user){
				echo '<li>'.htmlentities($user).'</li>';
			}
			echo '</ul>';
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
