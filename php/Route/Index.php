<?php
namespace OSM\Route;

class Index extends \OSM\Tools\Route {
	function action(){
		if (isset($_SESSION['token']) && \OSM\Tools\Google::checkToken($_SESSION['token'])) {
			$this->css = '
				.columns {display:flex;justify-content:space-around;}
				.columns ul li {width:500px;}
				.welcome {padding-bottom:50px;}
			';
			echo '<h2 class="welcome">Hello '.htmlentities($_SESSION['name'].' ('.$_SESSION['email'].')').'</h2>';
			echo '<div class="columns">';
			//user is authenticated
			//the user is at the home (show labs) screen
			if (\OSM\Tools\Config::get('enableLab')){
				echo '<div>';
				$labs = $this->labs();

				echo '<h3>Here are the labs you have access to:</h3>';
				echo '<ul class="list-group">';
				if ($_SESSION['admin']) {
					//show all devices
					foreach (array_keys($labs) as $lab) {
						echo '<li class="list-group-item"><a href="/?route=Monitor\\Lab&lab='.urlencode($lab).'">'.htmlentities($lab).'</a> - ('.count($labs[$lab]).' devices)</li>';
					}
				} else {
					//show just what they can access
					foreach ($this->myLabs() as $permission) {
						if (!isset($labs[$permission])){continue;}
						echo '<li class="list-group-item"><a href="/?route=Monitor\\Lab&lab='.urlencode($permission).'">'.htmlentities($permission).'</a> - ('.count($labs[$permission]).' devices)</li>';
					}
				}
				echo '</ul>';
				echo '</div>';
			}
			if (\OSM\Tools\Config::get('enableOneRoster')){
				echo '<div>';
				echo '<h3>Here are the classes you have access to:</h3>';
				echo '<ul class="list-group">';
				if ($_SESSION['admin']) {
					$data = \OSM\Tools\DB::selectRaw('SELECT class, count(*) as count FROM tbl_oneroster WHERE role = "student" GROUP BY class');
					foreach ($data as $row) {
						echo '<li class="list-group-item"><a href="/?route=Monitor\\Oneroster&class='.urlencode($row['class']).'">'.htmlentities($row['class']).'</a> - ('.$row['count'].' users)</li>';
					}
				} else {
					$data = \OSM\Tools\DB::selectRaw('SELECT class FROM tbl_oneroster WHERE role = "teacher" and email = :email',[':email' => $_SESSION['email']]);
					foreach ($data as $row) {
						echo '<li class="list-group-item"><a href="/?route=Monitor\\Oneroster&class='.urlencode($row['class']).'">'.htmlentities($row['class']).'</a></li>';
					}
				}
				echo '</ul>';
				echo '</div>';
			}
			if (\OSM\Tools\Config::get('enableGoogleClassroom')){
				echo '<div>';
				//sync courses
				$context = stream_context_create(['http' =>[
					'method'=>'GET',
					'header'=>'Authorization: Bearer '.$_SESSION['token']->access_token,
				]]);

				//links below are a reference for request and response
				//https://developers.google.com/classroom/reference/rest/v1/courses/list
				$courses = array();
				$url = 'https://classroom.googleapis.com/v1/courses?pageSize=100&courseStates=ACTIVE'.($_SESSION['admin'] ? '':'&teacherId=me');
				$data = file_get_contents($url, false, $context);
				if (!empty($data)) {
					$data = json_decode($data,true);
					$courses = $data['courses'];
					while (isset($data['nextPageToken']) && $data['nextPageToken'] != '') {
						$data = file_get_contents($url.'&pageToken='.urlencode($data['nextPageToken']), false, $context);
						if (!empty($data)) {
							$data = json_decode($data,true);
							if (!empty($data['courses'])) {
								$courses = array_merge($courses,$data['courses']);
							} else {
								break;
							}
						}
					}
				}
				echo 'Here are your Google Classroom classes: <ul style="text-align:left;">';
				$_courses = [];
				foreach ($courses as $course) {
					$_courses[$course['id']]=$course['name'];
				}
				asort($_courses);
				//save names so we can use them when a user activates a lab
				$_SESSION['userLabNames'] = $_courses;
				foreach($_courses as $id=>$name){
					echo '<li><a href="/?route=Monitor\\Googleclassroom&class='.urlencode($id).'">'.htmlentities($name).'</a></li>';
				}
				echo '</ul>';
				echo '</div>';
			}
			if ($_SESSION['admin']) {
				echo '<div>';
				echo '<h3>Admin Tools</h3>';
				echo '<ul class="list-group">';
					echo '<li class="list-group-item"><a href="/?route=Admin\Config">Config Editor</a></li>';
					echo '<li class="list-group-item"><a href="/?route=Admin\Buildextension">Build Extension</a></li>';
					echo '<li class="list-group-item"><a href="/?route=Admin\Permissions">Permissions</a></li>';
					echo '<li class="list-group-item"><a href="/?route=Admin\Serverfilter">Server Filter Lists</a></li>';
					if (\OSM\Tools\Config::get('enableLab')) {
						echo '<li class="list-group-item"><a href="/?route=Admin\Syncdevices" >Sync Devices</a></li>';
					}
					echo '<li class="list-group-item"><a href="/usagereport.php" >Usage Report</a></li>';
					echo '<li class="list-group-item"><a href="/?route=Monitor\Filterlog">View Browsing History</a></li>';
					if (\OSM\Tools\Config::get('showNonEnterpriseDevices')){
						echo '<li class="list-group-item"><a href="/?non-enterprise-device">Non Enterprise Devices</a></li>';
						echo '<li class="list-group-item"><a href="/?route=Admin\Showall">Show All</a></li>';
					}
				echo '</ul>';
				echo '</div>';
			}
			echo '</div>';
		} else {
			//user needs to login, show them the login screen
			echo '<h1>Login</h1>';
			echo '<center><div id="myGoogleSignin"></div></center>';
			echo '<a href="'.\OSM\Tools\Google::getLoginLink().'"><img src="google_signin.png" alt="Google Signin" /></a><br />';
		}
	}
}


