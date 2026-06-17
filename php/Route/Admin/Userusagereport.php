<?php
namespace OSM\Route\Admin;

class Userusagereport extends \OSM\Tools\Route {
	public function action(){
		$this->requireAdmin();

		set_time_limit(0);

		$maxDays = 30;

		echo '<h1>User Usage Report</h1>';
		echo '<p><i>Estimated active browsing time per user over a date range, based on page navigation (main_frame) activity within 5-minute windows.</i></p>';
		echo '<p><i>Ranges over 7 days require a username prefix filter. Maximum range is '.$maxDays.' days.</i></p>';

		echo '<form method="post" id="userusageform" onsubmit="this.querySelector(\'input[type=submit]\').disabled=true;this.querySelector(\'input[type=submit]\').value=\'Loading...\';">';
		echo '<table style="margin:auto;">';
		echo '<tr>';
		echo '<td><b>Start Date:</b></td><td><input type="date" name="start_date" value="'.htmlentities($_POST['start_date'] ?? '').'" required /></td>';
		echo '</tr><tr>';
		echo '<td><b>End Date:</b></td><td><input type="date" name="end_date" value="'.htmlentities($_POST['end_date'] ?? '').'" required /></td>';
		echo '</tr><tr>';
		echo '<td><b>Username Prefix:</b></td><td><input type="text" name="userprefix" value="'.htmlentities($_POST['userprefix'] ?? '').'" placeholder="e.g. 27 for class of 2027" style="width:250px;" /></td>';
		echo '</tr><tr>';
		echo '<td></td><td><input type="hidden" name="sort" value="'.htmlentities($_POST['sort'] ?? 'minutes').'" /><input type="submit" value="Run Report" /></td>';
		echo '</tr>';
		echo '</table>';
		echo '</form>';

		if (isset($_POST['start_date']) && isset($_POST['end_date'])){

			$startDate = $_POST['start_date'] ?? '';
			$endDate   = $_POST['end_date'] ?? '';
			$userprefix = trim($_POST['userprefix'] ?? '');
			$sort = $_POST['sort'] ?? 'minutes';
			$sort = ($sort == 'username') ? 'username asc' : 'total_minutes desc';

			// validate dates
			$startTs = strtotime($startDate);
			$endTs   = strtotime($endDate);

			if (!$startTs || !$endTs || $endTs < $startTs){
				echo '<p style="color:red;">Invalid date range.</p>';
				return;
			}

			$days = ($endTs - $startTs) / 86400 + 1;

			if ($days > $maxDays){
				echo '<p style="color:red;">Date range exceeds maximum of '.$maxDays.' days. Please narrow your selection.</p>';
				return;
			}

			if ($days > 7 && $userprefix == ''){
				echo '<p style="color:red;">A username prefix is required for ranges over 7 days. Please enter a prefix (e.g. 27 for class of 2027) or shorten the date range.</p>';
				return;
			}

			// build query — always use LIKE binding, empty prefix becomes '%' matching all
			$bindings = [
				':start'      => $startDate,
				':end'        => $endDate,
				':userprefix' => $userprefix.'%',
			];

			$userData = \OSM\Tools\DB::selectRaw('
				SELECT username,
				       SUM(daily_minutes) as total_minutes,
				       COUNT(DISTINCT date) as days_active,
				       ROUND(SUM(daily_minutes) / COUNT(DISTINCT date)) as avg_per_day
				FROM (
				    SELECT username, date,
				           COUNT(DISTINCT sec_to_time(FLOOR(time_to_sec(time)/(5*60))*(5*60))) * 5 as daily_minutes
				    FROM tbl_filter_log
				    WHERE date BETWEEN :start AND :end
				    AND type = "main_frame"
				    AND username LIKE :userprefix
				    GROUP BY username, date
				) daily
				GROUP BY username
				ORDER BY '.$sort,
				$bindings
			);

			$totalUsers = count($userData);
			$label = $userprefix != '' ? ' for prefix "'.htmlentities($userprefix).'"' : '';

			echo '<h2>Results: '.htmlentities($startDate).' to '.htmlentities($endDate).$label.'</h2>';
			echo '<p>'.$totalUsers.' user(s) found | '.intval($days).' day(s) in range</p>';

			if (empty($userData)){
				echo '<p>No activity found for the selected range and filter.</p>';
				return;
			}

			echo '<table class="data" style="margin:auto;max-width:800px;">';
			echo '<tr>';
			echo '<th><a href="javascript:void(0)" onclick="document.querySelector(\'#userusageform input[name=sort]\').value=\'username\';document.querySelector(\'#userusageform\').submit();">Username</a></th>';
			echo '<th><a href="javascript:void(0)" onclick="document.querySelector(\'#userusageform input[name=sort]\').value=\'minutes\';document.querySelector(\'#userusageform\').submit();">Total Estimated Time</a></th>';
			echo '<th>Days Active</th>';
			echo '<th>Avg Per Day</th>';
			echo '</tr>';

			foreach($userData as $row){
				$totalMins  = intval($row['total_minutes']);
				$totalHours = intdiv($totalMins, 60);
				$totalRem   = $totalMins % 60;
				$totalDisplay = ($totalHours > 0 ? $totalHours.'h ' : '').$totalRem.'m';

				$avgMins  = intval($row['avg_per_day']);
				$avgHours = intdiv($avgMins, 60);
				$avgRem   = $avgMins % 60;
				$avgDisplay = ($avgHours > 0 ? $avgHours.'h ' : '').$avgRem.'m';

				echo '<tr>';
				echo '<td>'.htmlentities($row['username']).'</td>';
				echo '<td>'.htmlentities($totalDisplay).'</td>';
				echo '<td>'.htmlentities($row['days_active']).'</td>';
				echo '<td>'.htmlentities($avgDisplay).'</td>';
				echo '</tr>';
			}
			echo '</table>';
		}
	}
}
