<?php
namespace OSM\Route\Admin;

class Upgrade extends \OSM\Tools\Route {

	// Schema version lives here as a class constant, NOT in Config::get(),
	// to prevent the admin UI from accidentally writing it to tbl_config.
	// When adding a schema change:
	// 1. Increment DB_SCHEMA_VERSION below
	// 2. Add a new entry to $migrations with the SQL to run
	const DB_SCHEMA_VERSION = 2;

	private static $migrations = [
		1 => "INSERT INTO tbl_config (name, value) VALUES ('dbSchemaVersion', '1');",
		2 => "ALTER TABLE tbl_config MODIFY value TEXT NOT NULL DEFAULT '';",
	];

	public function action(){
		$this->requireAdmin();

		$required = self::DB_SCHEMA_VERSION;

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
			echo '<p>Or run directly from CLI:</p>';
			echo '<pre style="background:#111;color:#0f0;padding:1em;">';
			echo htmlentities('# As osm user:')."
";
			echo htmlentities('mysql -u osm -p osm -e "'.str_replace('"','\"',$sql).'"')."

";
			echo htmlentities('# As root:')."
";
			echo htmlentities('mysql -u root -p osm -e "'.str_replace('"','\"',$sql).'"');
			echo '</pre>';
		}

		echo '<p>After running all migrations, verify with:</p>';
		echo '<pre style="background:#111;color:#0f0;padding:1em;">SELECT value FROM tbl_config WHERE name = \'dbSchemaVersion\';</pre>';
	}
}
