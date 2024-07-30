<?php
namespace OSM\Route\Admin;

class Buildextension extends \OSM\Tools\Route {
	///////////////////////////
	// Certificate Functions //
	///////////////////////////

	private function convertPEMtoDER($pem){
		$pipes = [];
		$process = proc_open('openssl pkey -pubin -outform der',[
				0 => ["pipe", "r"], //stdin
				1 => ["pipe", "w"], //stdout
				2 => ["pipe", "w"], //stderr
			],$pipes);

		if (is_resource($process)) {
			fwrite($pipes[0], $pem);
			fclose($pipes[0]);

			$der = stream_get_contents($pipes[1]);
			fclose($pipes[1]);

			$return_value = proc_close($process);

			return $der;
		}

		return false;
	}

	private function digestAndSign($pem,$data){
		$pipes = [];
		$process = proc_open('openssl dgst -sha256 -sign '.$pem,[
				0 => ["pipe", "r"], //stdin
				1 => ["pipe", "w"], //stdout
				2 => ["pipe", "w"], //stderr
			],$pipes);

		if (is_resource($process)) {
			fwrite($pipes[0], $data);
			fclose($pipes[0]);

			$sig = stream_get_contents($pipes[1]);
			fclose($pipes[1]);

			$return_value = proc_close($process);

			return $sig;
		}

		return false;
	}

	private function appIDfromPubDER($pubDER){
		$hash = hash('sha256',$pubDER,true);
		$hash = substr($hash,0,16);
		$hashArr = unpack('C*',$hash);

		$id = '';
		foreach($hashArr as $char){
			$id .= chr(97+($char >> 4));
			$id .= chr(97+($char & 0x0f));
		}

		return ['bin'=>$hash,'text'=>$id];
	}



	//////////////////////////////
	// Google ProtoBuffer Stuff //
	//////////////////////////////
	//see https://developers.google.com/protocol-buffers/docs/encoding

	private function varint($value){
		switch(gettype($value)){
			case 'string':
				break;
			case 'integer':
				$value = dechex($value);
				$value = (strlen($value) % 2 == 1 ? '0' : '').$value;
				$value = pack('H*', $value);
				break;
			default:
				echo "varint called with unknown type (".gettype($value)."). not sure what will happen\n";
				break;
		}

		$chars = unpack('C*',$value);
		$chars = array_values($chars);
		$chars = array_reverse($chars);

		$varint = '';
		$extra = 0;
		$extraCount = 0;
		$charsCount = count($chars);
		for ($i=0;$i<$charsCount;$i++){
			//add extra from last byte
			$char = $chars[$i];
			$char = ($char << $extraCount) | $extra;
			$bits = 8 + $extraCount;

			$extra = $char >> 7;

			if ($i < $charsCount - 1 || ($bits > 7 && $extra > 0)){
				$varint .= chr(1 << 7 | ($char & 0x7f ));
				$extraCount = $bits - 7;
			} else {
				$varint .= chr(0 << 7 | $char);
				$extraCount = 0;
			}
		}
		if ($extraCount > 0){
			$varint .= chr(0 << 7 | $extra);
		}
		return $varint;
	}

	private function PBMessage($id,$value,$type = 'VARINT'){
		switch($type){
			case 'LEN':
				return $this->varint($id << 3 | 2).$this->varint(strlen($value)).$value;
			case 'VARINT':
				return $this->varint($id << 3 | 0).$this->varint($value);
			default:
				throw new \Exception('Invalid PBMessage Type');
		}
	}

	public function buildCRX(){
		//folder to pack into crx
		$extFolder = 'ExtensionSource';

		//create extension folder in osm-data dir
		$outputFolder = $GLOBALS['dataDir'].'/extension';
		is_dir($outputFolder) || mkdir($outputFolder);


		//CRX3 Definition
		//https://chromium.googlesource.com/chromium/src.git/+/refs/heads/main/components/crx_file/crx3.proto

		//////////////////////////
		// Start Making the CRX //
		//////////////////////////

		//make sure we have a private key to work with
		if (!file_exists($outputFolder.'/key.pem')){
			$privKey = openssl_pkey_new(['private_key_bits'=>2048]);
			openssl_pkey_export_to_file($privKey,$outputFolder.'/key.pem');
		}

		//get manifext for app version
		$manifest = json_decode(file_get_contents($extFolder.'/manifest.json'),true) or die("Error getting manifest file\n");
		$version = $manifest['version'];

		// zip extension
		$zip = new \ZipArchive();
		$zip->open($outputFolder.'/ext.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$files = glob($extFolder.'/*');
		if (count($files) == 0) {die("Invalid Extension Dir\n");}
		foreach (glob($extFolder.'/*') as $file){
			$basename = basename($file);
			if ($basename == 'manifest.json'){
				$file = file_get_contents($file);
				//$file = json_decode($file,true);
				//$file['update_url'] = $osmURLRoot.'?xml';
				//$file = json_encode($file, JSON_PRETTY_PRINT);
				$zip->addFromString($basename,$file);
			} else {
				$zip->addFile($file, $basename);
			}
		}
		$zip->close();
		$zip = file_get_contents($outputFolder.'/ext.zip');

		//get private key
		$privKey = openssl_pkey_get_private(file_get_contents($outputFolder.'/key.pem'));

		//get pub key from priv key
		$pubKey = openssl_pkey_get_details($privKey)['key'];
		$pubDER = $this->convertPEMtoDER($pubKey);

		//get app id from pub key
		$id = $this->appIDfromPubDER($pubDER);

		//make signed data
		$signedData = $this->PBMessage(1,$id['bin'],'LEN'); //protobuffer message (1:$id['bin'])

		//sign that data along with the zip contents
		$signatureIn = 	"CRX3 SignedData\x00".pack('V',strlen($signedData)).$signedData.$zip;
		$signature = $this->digestAndSign($outputFolder.'/key.pem',$signatureIn);

		//make a header that holds the public key, signature, and signed data
		$header = $this->PBMessage(2,
			$this->PBMessage(1,$pubDER,'LEN').
			$this->PBMessage(2,$signature,'LEN'),
			'LEN');
		$header .= $this->PBMessage(10000,$signedData,'LEN');

		//start packing crx
		$crx = "Cr24";
		$crx .= pack('V',3); //version
		$crx .= pack('V',strlen($header)); //total header size
		$crx .= $header; //header
		$crx .= $zip; //zip

		//start update xml
		$xml = [];
		$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml[] = ' <gupdate xmlns="http://www.google.com/update2/response" protocol="2.0">';
		$xml[] = '  <app appid="'.$id['text'].'">';
		$xml[] = '    <updatecheck codebase="'.$this->urlRoot().'?extfile=crx" version="'.$version.'" />';
		$xml[] = '  </app>';
		$xml[] = '</gupdate>';
		$xml = implode("\n",$xml);

		//save files
		file_put_contents($outputFolder.'/ext.crx',$crx);
		file_put_contents($outputFolder.'/update.xml',$xml);
	}

	public function action(){
		$this->requireAdmin();

		echo '<h1>Build Chrome Extension</h1>';

		if (isset($_POST['build'])){
			$this->buildCRX();
			echo '<h2>Build CRX Complete</h2>';
		}

		echo '<form method="post"><input name="build" type="submit" value="(Re)Build CRX" /></form>';

		echo '<br /><a href="/?extfile=xml">Update XML</a>';
		echo '<br /><a href="/?extfile=crx">Packed Extension (.crx)</a>';
		echo '<br /><a href="/?extfile=zip">Zipped Extension (.zip)</a>';
	}
}
