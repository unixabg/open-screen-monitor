<?php
namespace OSM\Route\Admin;

class Synconeroster extends \OSM\Tools\Route {
	public function action(){
		$this->requireAdmin();

		set_time_limit(0);

		\OSM\Tools\DB::truncate('tbl_oneroster');
		$enrollments = \OSM\Tools\OneRoster::downloadData();
		foreach($enrollments as $enrollment){
			\OSM\Tools\DB::insert('tbl_oneroster',$enrollment);
		}
		echo 'Done (count: '.count($enrollments).')';
	}
}
