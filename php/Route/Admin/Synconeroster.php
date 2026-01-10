<?php
namespace OSM\Route\Admin;

class Synconeroster extends \OSM\Tools\Route {
	public function action(){
		$this->requireAdmin();

		set_time_limit(0);

		$ignoreUsers = \OSM\Tools\Config::get('oneRosterUserIgnore');

		\OSM\Tools\DB::beginTransaction();

		\OSM\Tools\DB::delete('tbl_oneroster');
		$enrollments = \OSM\Tools\OneRoster::downloadData();
		foreach($enrollments as $enrollment){
			if (in_array($enrollment['email'],$ignoreUsers)){continue;}
			\OSM\Tools\DB::insert('tbl_oneroster',$enrollment);
		}

		//allow custom hooking here
		//make sure to set restrictive permissions on this file
		if (file_exists($GLOBALS['dataDir'].'/custom/sync-oneroster-append.php')){
			require_once($GLOBALS['dataDir'].'/custom/sync-oneroster-append.php');
		}

		\OSM\Tools\DB::commit();

		echo 'Done (count: '.count($enrollments).')';
	}
}
