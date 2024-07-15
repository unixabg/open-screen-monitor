<?php
namespace OSM\Route\Monitor;

class Googleclassroom extends \OSM\Tools\Route {
	public function action(){
		$this->requireLogin();

		if (\OSM\Tools\Config::get('enableGoogleClassroom')) {
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

		die("TODO: this hasn't been updated yet");
		$_SESSION['allowedclients'] = [];
		foreach ($students as $student){
			$email = $student['profile']['emailAddress'];
			$email = str_replace("@","_",$email);
			$email = preg_replace("/[^a-zA-Z0-9-_]/","",$email);
			$_SESSION['allowedclients'][$email] = $student['profile']['name']['fullName'];
		}

		if (count($_SESSION['allowedclients']) > 0){
			asort($_SESSION['allowedclients']);
			$_SESSION['lab'] = ($_SESSION['userLabNames'][ $_GET['course'] ] ?? 'Unknown').' #'.$_GET['course'];
			header('Location: /?route=Monitor\Viewer');
			die();
		}

		//they don't have permission to this lab but are valid, redirect back
		header('Location: ?');
		die();
	}
}
