<?php
namespace OSM\Route\Admin;

class Upgrade extends \OSM\Tools\Route {

	// When adding a schema change:
	// 1. Increment dbSchemaVersion in Config.php
	// 2. Add a new entry here with the SQL to run
	private static $migrations = [
		1 => "-- Baseline schema (next branch). If you are seeing this,\n-- run setup.sql from sample_config/setup.sql and then:\nINSERT INTO tbl_config (name, value) VALUES ('dbSchemaVersion', '1');",
	];

	public function action(){
		$this->requireAdmin();

		$required = intval(\OSM\Tools\Config::get('dbSchemaVersion'));

		$row = \OSM\Tools\DB::select('tbl_config',['where'=>'name = :name','bindings'=>[':name'=>'dbSchemaVersion']]);
		$applied = isset($row[0]) ? intval($row[0]['value']) : 0;

		echo '<h1>OSM Database Schema</h1>';
		echo '<p><b>Applied schema version:</b> '.$applied.'</p>';
		echo '<p><b>Required schema version:</b> '.$required.'</p>';

		if ($applied >= $required){
			echo '<p style="color:green;font-weight:bold;">Database schema is up to date.</p>';
			return;
		}

		echo '<p style="color:red;font-weight:bold;">Database schema is out of date. Run the following on the server CLI:</p>';

		for ($v = $applied + 1; $v <= $required; $v++){
			$sql = static::$migrations[$v] ?? '-- No migration defined for version '.$v;
			echo '<h3>Migration to version '.$v.'</h3>';
			echo '<pre style="background:#111;color:#0f0;padding:1em;">'.htmlentities($sql).'</pre>';
		}

		echo '<p>After running all migrations, update the applied version:</p>';
		echo '<pre style="background:#111;color:#0f0;padding:1em;">UPDATE tbl_config SET value = \''.$required.'\' WHERE name = \'dbSchemaVersion\';</pre>';
	}
}
