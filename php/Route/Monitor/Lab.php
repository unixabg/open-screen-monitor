<?php
namespace OSM\Route\Monitor;

class Lab extends \OSM\Tools\Route {
	public function action(){
		$this->requireLogin();

		$labs = $this->labs();
		if (isset($labs[$_GET['lab']]) && (in_array($_GET['lab'],$this->myLabs()) || $_SESSION['admin'] )) {
			$groupID = 'lab{'.$_GET['lab'].'}';

			$_SESSION['groups'][ $groupID ] = [
				'name' => $_GET['lab'],
				'type' => 'device',
			];

			foreach ($labs[$_GET['lab']] as $clientID=>$clientInfo) {
				$_SESSION['clients']['devices'][$clientID] = $clientInfo['niceName'];
				$_SESSION['groups'][ $groupID ]['clients'][$clientID] = $clientInfo['niceName'];
			}
			asort($_SESSION['groups'][ $groupID ]['clients']);
			header('Location: /?route=Monitor\Viewer&groupID='.urlencode($groupID));
		} else {
			//they don't have permission to this lab but are valid, redirect back
			header('Location: ?');
		}
		die();
	}
}
