<?php
namespace OSM\Route\Admin;

class Upgrade extends \OSM\Tools\Route {

	// Schema version lives here as a class constant, NOT in Config::get(),
	// to prevent the admin UI from accidentally writing it to tbl_config.
	// When adding a schema change:
	// 1. Increment DB_SCHEMA_VERSION below
	// 2. Add a new entry to $migrations with the SQL to run
	// 3. Update setup.sql for fresh installs
	const DB_SCHEMA_VERSION = 5;

	private static $migrations = [
		1 => "INSERT INTO tbl_config (name, value) VALUES ('dbSchemaVersion', '1');",
		2 => "ALTER TABLE tbl_config MODIFY value TEXT NOT NULL DEFAULT '';",
		3 => "ALTER TABLE tbl_log MODIFY data TEXT NOT NULL DEFAULT '';",
		4 => "ALTER TABLE tbl_filter_log ADD INDEX idx_date_time_username (date, time, username);",
		5 => "ALTER TABLE tbl_filter_log ADD INDEX idx_date_deviceid (date, deviceid, username, time);",
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
		} else {
			echo '<p style="color:red;font-weight:bold;">Database schema is out of date. Run the following on the server CLI:</p>';

			for ($v = $applied + 1; $v <= $required; $v++){
				$sql = static::$migrations[$v] ?? '-- No migration defined for version '.$v;
				$sqlEscaped = str_replace('"','\"',$sql);
				$updateSql = 'UPDATE tbl_config SET value = \''.$v.'\' WHERE name = \'dbSchemaVersion\';';
				$updateEscaped = str_replace('"','\"',$updateSql);
				echo '<h3>Migration to version '.$v.'</h3>';
				echo '<pre style="background:#111;color:#0f0;padding:1em;">'.htmlentities($sql).'</pre>';
				echo '<p>Run from CLI (version is only stamped if migration succeeds):</p>';
				echo '<pre style="background:#111;color:#0f0;padding:1em;">';
				echo htmlentities('# As osm user:')."\n";
				echo htmlentities('mysql -u osm -p osm -e "'.$sqlEscaped.'" && \\')."\n";
				echo htmlentities('mysql -u osm -p osm -e "'.$updateEscaped.'"')."\n\n";
				echo htmlentities('# As root:')."\n";
				echo htmlentities('mysql -u root -p osm -e "'.$sqlEscaped.'" && \\')."\n";
				echo htmlentities('mysql -u root -p osm -e "'.$updateEscaped.'"');
				echo '</pre>';
			}
		}

		// Diagnostic table dump
		echo '<br /><details>';
		echo '<summary><b>Database Schema Diagnostics</b> (click to expand)</summary>';
		echo '<br />';

		$columns = \OSM\Tools\DB::selectRaw("
			SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME LIKE 'tbl_%'
			ORDER BY TABLE_NAME, ORDINAL_POSITION
		");

		$currentTable = '';
		foreach ($columns as $col){
			if ($col['TABLE_NAME'] !== $currentTable){
				if ($currentTable !== ''){
					echo '</tbody></table><br />';
				}
				$currentTable = $col['TABLE_NAME'];
				echo '<b>'.$currentTable.'</b>';
				echo '<table class="w3-table-all" style="max-width:800px;">';
				echo '<thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Default</th></tr></thead>';
				echo '<tbody>';
			}
			echo '<tr>';
			echo '<td>'.htmlentities($col['COLUMN_NAME']).'</td>';
			echo '<td>'.htmlentities($col['COLUMN_TYPE']).'</td>';
			echo '<td>'.htmlentities($col['IS_NULLABLE']).'</td>';
			echo '<td>'.htmlentities($col['COLUMN_DEFAULT'] ?? 'NULL').'</td>';
			echo '</tr>';
		}
		if ($currentTable !== ''){
			echo '</tbody></table>';
		}

		echo '</details>';
	}
}
