<?php
namespace OSM\Route\Monitor;

class Oneroster extends \OSM\Tools\Route {
	public function action(){
		$this->requireLogin();

		$data = \OSM\Tools\DB::select('tbl_oneroster',['where'=>'class = :class','bindings'=>[':class'=> ($_GET['class'] ?? '')]]);

		$authValid = $this->isAdmin();
		$students = [];
		foreach($data as $row){
			if ($row['role'] == 'teacher'){
				if ($row['email'] == $_SESSION['email']){
					$authValid = true;
				}
			} elseif ($row['role'] == 'student'){
				$students[ $row['email'] ] = $row['name'];
			}
		}

		if (!$authValid){
			//they don't have permission to this class but are valid, redirect back
			header('Location: ?');
		} else {
			$groupID = 'user{'.$_GET['class'].'}';
			$_SESSION['groups'][ $groupID ] = [
				'name' => $_GET['class'],
				'type' => 'user',
			];

			foreach ($students as $email => $name) {
				$_SESSION['clients']['users'][$email] = $name;
				$_SESSION['groups'][ $groupID ]['clients'][$email] = $name;
			}
			asort($_SESSION['groups'][ $groupID ]['clients']);
			header('Location: /?route=Monitor\Viewer&groupID='.urlencode($groupID));
		}
		die();
	}
}
