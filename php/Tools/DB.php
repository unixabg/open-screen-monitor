<?php
namespace OSM\Tools;

class DB {
	private static $dbconn;
	private static function conn(){
		if (is_null(self::$dbconn)){
			$config = file_get_contents($GLOBALS['dataDir'].'/db.json');
			$config = json_decode($config,true);

			foreach(['hostname','user','password','dbname'] as $key){
				if (!isset($config[$key])){
					die('{datadir}/db.json missing '.$key);
				}
			}

			self::$dbconn = new \PDO(
				"mysql:host=".$config['hostname'].";dbname=".$config['dbname'],$config['user'],$config['password'],[
					\PDO::MYSQL_ATTR_FOUND_ROWS => true,
				]
			);
			self::$dbconn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		}
		return self::$dbconn;
	}

	public static function beginTransaction() {
		return self::conn()->beginTransaction();
	}

	public static function commit() {
		return self::conn()->commit();
	}

	public static function rollBack() {
		return self::conn()->rollBack();
	}

	private static function executeQuery($sql,$bindings = []){
		$query = self::conn()->prepare($sql);
		$query->execute($bindings);
		return $query;
	}

	public static function truncate($table){
		self::executeQuery('TRUNCATE TABLE '.$table);
	}

	public static function insert($table, $fields, $replace = false){
		$sql = [];
		$bindings = [];
		foreach(array_keys($fields) as $i => $key){
			$sql[] = '`'.$key.'` = :binding'.$i;
			$bindings[':binding'.$i] = $fields[$key];
		}
		$sql = ($replace ? 'REPLACE':'INSERT')." INTO `$table` SET ".implode(', ',$sql);
		return self::executeQuery($sql,$bindings);
	}

	//replaces by pk
	public static function replace($table, $fields){
		return self::insert($table,$fields,true);
	}

	public static function update($table, $whereFields, $setFields, $insertOnZero = false){
		$bindings = [];

		$set = [];
		foreach(array_keys($setFields) as $i => $key){
			$set[] = '`'.$key.'` = :set'.$i;
			$bindings[':set'.$i] = $setFields[$key];
		}

		$where = [];
		foreach(array_keys($whereFields) as $i => $key){
			$where[] = '`'.$key.'` = :where'.$i;
			$bindings[':where'.$i] = $whereFields[$key];
		}
		$where = implode(' AND ',$where);
		$where = ($where == '' ? '' : ' WHERE '.$where);

		$sql = 'UPDATE `'.$table.'` SET '.implode(', ',$set).$where;
		$query = self::executeQuery($sql, $bindings);
		if ($query->rowCount() > 0) {
			return $query;
		} else {
			return self::insert($table, array_merge($whereFields, $setFields));
		}
	}

	public static function updateInsert($table, $whereFields, $setFields){
		return self::update($table, $whereFields, $setFields, true);
	}

	public static function selectRaw($sql, $bindings = []){
		$query = self::executeQuery($sql,$bindings);
		return $query->fetchAll(\PDO::FETCH_ASSOC);
	}

	public static function select($table, $options = []){
		$options = array_merge([
			'select' => '*',
			'where' => '',
			'order' => '',
			'limit' => '',
			'bindings' => [],
			'fields' => [],
		],$options);

		$where = [];
		if ($options['where'] != ''){$where[] = '('.$options['where'].')';}
		$bindings = $options['bindings'];
		foreach(array_keys($options['fields']) as $i => $key){
			$where[] = '`'.$key.'` = :binding'.$i;
			$bindings[':binding'.$i] = $options['fields'][$key];
		}
		$where = implode(' AND ',$where);
		$where = ($where != '' ? ' WHERE '.$where : '');

		$order = ($options['order'] == '' ? '' : ' ORDER BY '.$options['order']);
		$limit = ($options['limit'] == '' ? '' : ' LIMIT '.$options['limit']);

		return self::selectRaw(
			'SELECT '.$options['select'].' FROM `'.$table.'`'.$where.$order.$limit,
			$bindings
		);
	}

	public static function delete($table, $options){
		$options = array_merge([
			'where' => '',
			'bindings' => [],
			'fields' => [],
			'limit' => '',
		],$options);

		$where = [];
		if ($options['where'] != ''){$where[] = '('.$options['where'].')';}
		$bindings = $options['bindings'];
		foreach(array_keys($options['fields']) as $i => $key){
			$where[] = '`'.$key.'` = :binding'.$i;
			$bindings[':binding'.$i] = $options['fields'][$key];
		}
		$where = implode(' AND ',$where);
		$where = ($where == '' ? '' : ' WHERE '.$where);

		$limit = ($options['limit'] == '' ? '' : ' LIMIT '.$options['limit']);

		return self::executeQuery('DELETE FROM `'.$table.'`'.$where.$limit,$bindings);
	}
}
