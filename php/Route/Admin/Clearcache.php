<?php
namespace OSM\Route\Admin;

//clear the browser cache and site data of one or more users from the admin tools;
//online users are cleared within seconds, others at their next check-in
class Clearcache extends \OSM\Tools\Route {
	public function action(){
		$this->requireAdmin();

		if (isset($_POST['clear'])){
			$emails = $_POST['clear']['email'] ?? [];
			if (!is_array($emails)){$emails = [];}
			//one or more addresses typed in, separated by commas, spaces or new lines
			$typed = preg_split('/[\s,;]+/', $_POST['clear']['emails'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
			$emails = array_merge($emails, $typed);

			$results = [];
			foreach (array_unique(array_map('trim', $emails)) as $email){
				if (!filter_var($email, FILTER_VALIDATE_EMAIL)){
					$results[$email] = ['invalid' => true];
					continue;
				}
				$email = strtolower($email);
				$results[$email] = \OSM\Tools\ClearCache::request($email);
				\OSM\Tools\Log::add('admin.clearCache', $email, $results[$email]);
			}
			$_SESSION['clearcacheResults'] = $results;
			$this->redirect('Admin\Clearcache');
		}

		if (isset($_POST['cancel'])){
			\OSM\Tools\ClearCache::cancel($_POST['cancel']);
			\OSM\Tools\Log::add('admin.clearCacheCancel', $_POST['cancel']);
			$this->redirect('Admin\Clearcache');
		}

		$namesByEmail = [];
		if (\OSM\Tools\Config::get('enableOneRoster')){
			$rows = \OSM\Tools\DB::selectRaw('select distinct email, name from tbl_oneroster where role = "student" order by email');
			foreach($rows as $row){
				$namesByEmail[ $row['email'] ] = $row['name'];
			}
		}

		$this->css = '
			.clearcache {max-width:700px;margin:auto;padding:10px;}
			.clearcache textarea {width:100%;height:80px;}
			.clearcache table {width:100%;}
			.clearcache .roster {overflow-y:scroll;max-height:400px;margin:10px 0;}
		';

		echo '<div class="clearcache">';
		echo '<h2>Clear User Cache</h2>';
		echo '<p>Clears the browser cache, cookies and site data (local storage, IndexedDB, service workers) of the users below, ';
		echo 'like the Clear Cache link in the monitor. Cookies of the sites in <code>cacheCleanupExclude</code> are kept. ';
		echo 'Users who are online are cleared within seconds on every device they are using. ';
		echo 'Users who are not online are cleared once, at their next check-in, if that is within ';
		echo htmlentities($this->duration(intval(\OSM\Tools\Config::get('cacheClearPendingTimeout')))).'.</p>';

		//results of the last request
		if ($results = $_SESSION['clearcacheResults'] ?? false){
			unset($_SESSION['clearcacheResults']);
			echo '<h3>Result</h3><table>';
			foreach ($results as $email => $result){
				if (isset($result['invalid'])){
					$text = 'not a valid email address; skipped';
				} elseif (isset($result['sessions'])){
					$text = 'online: clearing now on '.$result['sessions'].' session'.($result['sessions'] == 1 ? '' : 's');
				} else {
					$text = 'not online: will be cleared at next check-in (until '.date('Y-m-d H:i', $result['pending']).')';
				}
				echo '<tr><td>'.htmlentities($email).'</td><td>'.htmlentities($text).'</td></tr>';
			}
			echo '</table>';
		}

		echo '<form method="post">';
		echo '<h3>Users</h3>';
		echo '<textarea name="clear[emails]" placeholder="user@example.com, one or more, separated by commas or new lines"></textarea>';
		if (!empty($namesByEmail)){
			echo '<div class="roster"><table>';
			foreach($namesByEmail as $email => $name){
				echo '<tr><td><input name="clear[email][]" type="checkbox" value="'.htmlentities($email).'"></td><td>'.htmlentities($email).'</td><td>'.htmlentities($name).'</td></tr>';
			}
			echo '</table></div>';
		}
		echo '<br /><input type="submit" value="Clear Cache" />';
		echo '</form>';

		$pending = \OSM\Tools\ClearCache::pending();
		if (!empty($pending)){
			echo '<h3>Waiting for next check-in</h3>';
			echo '<table>';
			echo '<tr><th>Email</th><th>Name</th><th>Requested</th><th>Until</th><th></th></tr>';
			foreach ($pending as $email => $request){
				echo '<tr>';
				echo '<td>'.htmlentities($email).'</td>';
				echo '<td>'.htmlentities($namesByEmail[$email] ?? '').'</td>';
				echo '<td>'.htmlentities(isset($request['requested']) ? date('Y-m-d H:i', $request['requested']) : '').' '.htmlentities($request['requestedBy'] ?? '').'</td>';
				echo '<td>'.htmlentities(isset($request['expires']) ? date('Y-m-d H:i', $request['expires']) : '').'</td>';
				echo '<td><form method="post"><input type="hidden" name="cancel" value="'.htmlentities($email).'" /><input type="submit" value="Cancel" /></form></td>';
				echo '</tr>';
			}
			echo '</table>';
		}
		echo '</div>';
	}

	private function duration($seconds){
		if ($seconds >= 86400 && $seconds % 86400 == 0){return ($seconds / 86400).' day'.($seconds == 86400 ? '' : 's');}
		if ($seconds >= 3600 && $seconds % 3600 == 0){return ($seconds / 3600).' hour'.($seconds == 3600 ? '' : 's');}
		return $seconds.' seconds';
	}
}
