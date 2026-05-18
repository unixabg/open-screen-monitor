<?php
namespace OSM\Route\Monitor;

class Googleclassroom extends \OSM\Tools\Route {
	public function action(){
		$this->requireLogin();
		$this->requireCurrentGoogle();

		if (!\OSM\Tools\Config::get('enableGoogleClassroom')) {
			die('OSM does not have Google Classroom enabled');
		}

		//sync clients in course
		$context = stream_context_create(['http'=>[
			'method'=>'GET',
			'header'=>'Authorization: Bearer '.$_SESSION['token']->access_token,
		]]);

		//links below are a reference for request and response
		//https://developers.google.com/classroom/reference/rest/v1/courses.students/list
		$students = [];
		$url = 'https://classroom.googleapis.com/v1/courses/'.urlencode($_GET['class']).'/students?pageSize=100';
		$data = file_get_contents($url, false, $context);
		if ($data !== false) {
			$data = json_decode($data,true);
			if (isset($data['students'])){
				$students = $data['students'];
				while (isset($data['nextPageToken']) && $data['nextPageToken'] != '') {
					$data = file_get_contents($url.'&pageToken='.urlencode($data['nextPageToken']), false, $context);
					if ($data === false) return false;
					$data = json_decode($data,true);
					$students = array_merge($students,$data['students']);
				}
			}
		}

		$groupID = 'user{'.$_GET['class'].'}';

		$_SESSION['groups'][$groupID] = [
			'name' => $_SESSION['userLabNames'][$_GET['class']] ?? $_GET['class'],
			'type' => 'user',
		];

		foreach ($students as $student) {
			$email = $student['profile']['emailAddress'];
			$name = $student['profile']['name']['fullName'];
			$_SESSION['clients']['users'][$email] = $name;
			$_SESSION['groups'][$groupID]['clients'][$email] = $name;
		}

		if (count($_SESSION['groups'][$groupID]['clients']) > 0) {
			asort($_SESSION['groups'][$groupID]['clients']);
			header('Location: /?route=Monitor\Viewer&groupID='.urlencode($groupID));
			\OSM\Tools\Log::add('viewer.googleclassroom', $groupID);
			die();
		}

		//they don't have permission to this lab but are valid, redirect back
		header('Location: ?');
		die();
	}
}
