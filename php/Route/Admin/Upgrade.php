<?php
namespace OSM\Route\Admin;

class Upgrade extends \OSM\Tools\Route {
	public function action(){
		$this->requireAdmin();

		echo '<h1>OSM Upgrade Tools</h1>';

		if (isset($_POST['action'])){
			switch($_POST['action']){
				case 'importPermissions':
					self::importPermissions();
					break;
				case 'importConfig':
					self::importConfig();
					break;
				case 'importFilterlists':
					self::importFilterlists();
					break;
				default:
					echo '<h2>Not Implemented</h2>';
					break;

			}

			echo '<h2>Done</h2>';
			return;
		}


		echo '<form method="post">';
		echo '<select name="action">';
			echo '<option>Choose Task to Run</option>';
			echo '<option value="importPermissions">Import Permissions</option>';
			echo '<option value="importConfig">Import Config</option>';
			echo '<option value="importFilterlists">Import Filter Lists</option>';
			echo '<option value="importLogs">Import Logs</option>';
			echo '</select>';
		echo ' <input type="submit" value="Run" />';
		echo '</form>';
	}

	private static function importConfig(){
		echo '<pre>';
		$folders = glob($GLOBALS['dataDir'].'/config/*',\GLOB_ONLYDIR);
		foreach($folders as $folder){
			$id = basename($folder);
			$id = base64_decode($id);

			echo "$folder - $id\n";
			$files = glob($folder.'/*');
			foreach($files as $file){
				$contents = file_get_contents($file);
				$file = basename($file);

				if ($file == 'log'){continue;}

				echo $file."\n";
				echo htmlentities($contents)."\n";

				\OSM\Tools\DB::updateInsert('tbl_group_config',['groupid'=>$id,'name'=>$file],['value'=>$contents]);
			}

		}
		echo '</pre>';
	}

	private static function importFilterlists(){
		echo '<pre>';

		$files = [
			'blacklist' => $GLOBALS['dataDir'].'/filter_blacklist.txt',
			'whitelist' => $GLOBALS['dataDir'].'/filter_whitelist.txt',
			'trigger' => $GLOBALS['dataDir'].'/triggerlist.txt',
			'screenscrape' => $GLOBALS['dataDir'].'/screenscrape.txt',
		];

		foreach($files as $list => $file){
			if (!file_exists($file)){continue;}

			$file = file_get_contents($file);
			$file = str_replace("\r",'',$file);
			$file = explode("\n",$file);
			$data = [
				'list' => $list,
			];
			foreach($file as $line){
				$data['url'] = '';
				$data['action'] = '';
				$data['resourceType'] = '';
				$data['actionData'] = '';


				$line = explode("\t",$line);
				$count = count($line);
				if($list == 'whitelist' && $count == 1){
					$data['url'] = $line[0];
				} elseif ($list == 'whitelist' && $count == 2){
					$data['action'] = $line[0];
					$data['url'] = $line[1];
				} elseif ($list == 'whitelist' && $count == 3){
					$data['action'] = $line[0];
					$data['resourceType'] = $line[1];
					$data['url'] = $line[2];
				} elseif ($list == 'whitelist' && $count == 4){
					$data['action'] = $line[0];
					$data['resourceType'] = $line[1];
					$data['url'] = $line[2];
					$data['actionData'] = $line[3];
				} elseif ($list == 'blacklist' && $count == 1){
					$data['url'] = $line[0];
				} elseif ($list == 'blacklist' && $count == 2){
					$data['action'] = $line[0];
					$data['url'] = $line[1];
				} elseif ($list == 'blacklist' && $count == 3){
					$data['action'] = $line[0];
					$data['resourceType'] = $line[1];
					$data['url'] = $line[2];
				} elseif ($list == 'trigger' && $count == 2){
					$data['action'] = $line[0];
					$data['url'] = $line[1];
				} elseif ($list == 'trigger' && $count == 3){
					$data['action'] = $line[0];
					$data['resourceType'] = $line[1];
					$data['url'] = $line[2];
				} elseif ($list == 'screenscrape' && $count == 1){
					$data['url'] = $line[0];
				} elseif ($list == 'screenscrape' && $count == 2){
					$data['url'] = $line[0];
					$data['resourceType'] = $line[1];
				} elseif ($list == 'screenscrape' && $count == 3){
					$data['action'] = $line[0];
					$data['url'] = $line[0];
					$data['resourceType'] = $line[0];
				}

				if ($data['list'] == 'blacklist' && substr($data['action'],0,9) == 'REDIRECT:'){
					$data['actionData'] = substr($data['action'],9);
					$data['action'] = 'REDIRECT';
				}


				if ($data['url'] != ''){
					\OSM\Tools\DB::insert('tbl_filter_entry',$data);
				}
			}
		}
		echo '</pre>';
	}

	private static function importPermissions(){
		$permissions_file = $GLOBALS['dataDir'].'/permissions.tsv';
		if (file_exists($permissions_file)) {
		        $lines = file_get_contents($permissions_file);
		        $lines = explode("\n",$lines);
		        foreach ($lines as $line) {
		                $line = explode("\t",$line);

		                if (count($line) != 2) {continue;}

				$fields = ['username'=>$line[0],'groupid'=>$line[1]];

				$dbpermission = \OSM\Tools\DB::select('tbl_lab_permission',[
					'fields'=>$fields,
				]);

				if (count($dbpermission) > 0){continue;}

				\OSM\Tools\DB::insert('tbl_lab_permission',$fields);
		        }
		}

	}
}
