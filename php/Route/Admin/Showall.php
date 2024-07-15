<?php
namespace OSM\Route\Admin;

class Showall extends \OSM\Tools\Route {
	public function action(){
		global $dataDir;

		$this->requireAdmin();

		$_SESSION['niceNames'] = [];
		foreach($this->labs() as $path => $lab){
			foreach($lab as $deviceID => $device){
				$_SESSION['niceNames'][$deviceID] = $device['niceName'];
			}
		}

		$_SESSION['groups']['osmshowall']['name'] = 'OSM Admin: Show All';
		header('Location: /?route=Monitor\Viewer&groupID=osmshowall');
		die();
	}
}
