<?php
namespace OSM\Route\Admin;

class Config extends \OSM\Tools\Route {
	public function action(){
		$this->requireAdmin();

		$config = \OSM\Tools\Config::get();
		//unset variables that shouldn't be set by user
		unset($config['version']);

		echo '<h2>Config Editor (must be a valid JSON object)</h2>';
		if (isset($_POST['config'])){
			foreach($config as $key=>$value){
				if (isset($_POST['config'][$key])){
					$newValue = $_POST['config'][$key];

					if (is_null(json_decode($newValue))){continue;}

					\OSM\Tools\DB::replace('tbl_config',[
						'name' => $key,
						'value' => $newValue,
					]);
				}
			}
			$this->redirect('');
		}

		$this->css = '
			.config textarea {width:400px;height:100px;}
		';

		echo '<form method="post">';
		echo '<table class="config">';
		ksort($config);
		foreach($config as $key=>$value){
			$key = htmlentities($key);
			echo '<tr>';
			echo '<td>'.$key.'</td>';
			echo '<td><textarea name="config['.$key.']">'.htmlentities(json_encode($value,JSON_PRETTY_PRINT)).'</textarea></td>';
			echo '</tr>';
		}
		echo '</table>';
		echo '<br /><input type="submit" value="Save Config"/>';
		echo '</form>';
	}
}
