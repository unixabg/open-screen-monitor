<?php
namespace OSM\Tools;

class Route {
	public $js = '';
	public $css = '';
	public $title = 'Open Screen Monitor';
	public $leftHeader = '';

	public function urlRoot(){
		$https = ($_SERVER['HTTPS'] ?? '') != '';
		return ($https ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].'/';
	}

	public function requireLogin($redirect = true){
                $validuntil = $_SESSION['validuntil'] ?? 0;
                if ($validuntil < time()){
			if ($redirect){
				header('Location: '.$this->urlRoot());
				die();
			} else {
	                        http_response_code(403);
	                        die('Authentication Required');
			}
                }
	}

	public function isAdmin(){
		return ($_SESSION['admin'] ?? false);
	}

	public function requireAdmin(){
		$this->requireLogin();
		if (!$this->isAdmin()){
			die('Permission Denied');
		}
	}

	public function redirect($route,$raw = false){
		if (!$raw){
			$route = '/index.php'.($route == '' ? '' : '?route='.$route);
		}
		header('Location: '.$route);
		die();
	}

	public function render(){
		//get the html here so if a redirect needs to happen we haven't already sent anything.
		//We also are doing it via output buffering so we can use echo in the action and not keep a running variable;
		ob_start();
		$this->action();
		$html = ob_get_clean();

		echo '<html>';
		echo '<head>';
			echo '<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>';

			//bootstrap (depends on jquery);
			echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">';
			echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js" integrity="sha384-BBtl+eGJRgqQAUMxJ7pMwbEyER4l1g+O15P+16Ep7Q9Q+zqX6gSbd85u4mG4QzX+" crossorigin="anonymous"></script>';

			//fontawesome
			//echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.2/css/fontawesome.min.css" integrity="sha384-BY+fdrpOd3gfeRvTSMT+VUZmA728cfF9Z2G42xpaRkUGu2i3DyzpTURDo5A6CaLK" crossorigin="anonymous">';

			//google fonts
			//echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />';
			echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" />';

			//google charts
			echo '<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>';

			echo '<style>';
			echo '* {margin:0;padding:0;}';
			echo 'html, body {height: 100%;}';
			echo 'html, body, div, h1 {margin: 0;padding: 0;border: 0 none;}';

			echo '.header {display:flex;flex-direction:row;justify-content: space-between;}';

			//table format
			echo 'table {border-collapse: collapse;border-spacing: 0;border: 1px solid #ddd;}';
			echo 'th, td {text-align: left;border: 1px solid #ddd;padding: 16px;}';
			echo 'tr:nth-child(even) {background-color: #f2f2f2;}';

			echo $this->css;
			echo '</style>';

			echo '<script>'.$this->js.'</script>';
			echo '<title>'.$this->title.'</title>';
		echo '</head>';
		echo '<body>';
			echo '<div class="header">';
				echo '<div class="leftHeader">'.$this->leftHeader.'</div>';
				echo '<h1 style="text-align:center;">'.htmlentities($this->title).'</h1>';
				echo '<div style="display:inline; float:right; padding-top:5px; padding-right:10px;">';
					echo 'Version '.Config::get('version');
					echo '<br /><a href="/">Home</a> ';
					if (isset($_SESSION['token'])){
						echo '| <a href="/?logout">Logout</a>';
					}
				echo '</div>';
			echo '</div>';
			echo '<div class="content">';
			echo $html;
			echo '</div>';
		echo '</body>';
		echo '</html>';
	}

	public function myLabs(){
		$labs = [];
		if ($email = ($_SESSION['email'] ?? false)){
			$rows = \OSM\Tools\DB::select('tbl_lab_permission',['fields'=>['username'=>$email]]);
			foreach($rows as $row){
				$labs[] = $row['groupid'];
			}
		}
		return $labs;
	}

	public function labs(){
		return $this->deviceParse('labs');
	}

	public function deviceNames(){
		return $this->deviceParse('deviceNames');
	}

	private function deviceParse($mode){
		$toReturn = [];
		$rows = \OSM\Tools\DB::select('tbl_lab_device',['order'=>'path']);
		foreach($rows as $row){
			$niceName = [];
			foreach($row as $i => $value) {
				if (in_array($i,['deviceid','path','lastSynced'])){continue;}
				if ($value == ''){continue;}
				$niceName[] = $value;
			}
			$row['niceName'] = implode(' - ',$niceName);
			if ($row['niceName'] == ''){$row['niceName'] = $row['deviceid'];}

			if ($mode == 'labs'){
				$toReturn[ $row['path'] ][ $row['deviceid'] ] = $row;
			} elseif ($mode == 'deviceNames'){
				$toReturn[ $row['deviceid'] ] = $row['niceName'];
			}
		}
		return $toReturn;
	}

	public function inSubnet($ip, $subnet){
		$subnet = explode('/', $subnet);

		$bits = intval($subnet[1] ?? 32);
		if ($bits < 0 || 32 < $bits){$bits = 32;}

		$subnet = ip2long($subnet[0]);
		$ip = ip2long($ip);
		$mask = -1 << (32 - $bits);
		$subnet &= $mask;
		return ($ip & $mask) == $subnet;
	}

}
