#!/usr/bin/php
<?php
/*
Version 0.1

ToDo:
- class tvheadend
  - detection or configuration of .hts directory
  - $lang detection or configuration
  - correct dvr log filename

$entry = array(
	'channelnumber' => 1,
	'channelname' => 'TV',
	'recordstart' => '2000-01-01 00:00:00',
	'recordend' => '2001-12-31 23:59:59',
	'title' => 'Title',
	'subtitle' => 'Subtitle',
	'description' => 'Description or Overview',
	'season' => 1,
	'episode' => 1,
	'category' =>,
	'path' => '/path/to/file.mpg',
	'programstart' => '2000-01-01 10:00:00',
	'programend' => '2000-01-01 10:30:00',
	'id',
*/

$longopts = array(
	'help',
	'nodryrun',
	'mode:',
	'source:',
	'destination:',
	'title:',
);
$opt = getopt ('h', $longopts);
// help
if(isset($opt['h']) || isset($opt['help'])) {
	echo '
Usage: dvr_mover.php [OPTIONS]
Default: dvr_mover.php --mode hardlink --source mythtv --destination tvheadend

Options:
--help        This help text
--nodryrun    Do not simulate (this will copy / move / delete files)
--mode        How to process files (see section "mode")
--source      Source system (Allowed values: mythtv)
--destination Destination system (Allowed values: tvheadend)
--title       Search only for recordings with specified title

Mode:
  keep        Point to the file location of source
  copy        Copy file from source to destination
  move        Move file from source to destination
  hardlink    Create a hardlink on destination to file on source
  delete_missing_on_source
              Files not exists on destination will be deleted on source
';
	exit;
}
// nodryrun
$run = isset($opt['nodryrun']);
// mode
if(!isset($opt['mode'])) $opt['mode'] = 'hardlink';
if(!in_array($opt['mode'],array('keep','copy','move','hardlink','delete_missing_on_source'))) exit;
// source
if(!isset($opt['source'])) $opt['source'] = 'mythtv';
if(!in_array($opt['source'],array('mythtv'))) exit;
// destination
if(!isset($opt['destination'])) $opt['destination'] = 'tvheadend';
if(!in_array($opt['destination'],array('tvheadend'))) exit;
// title
if(!isset($opt['title'])) $opt['title'] = false;

$src = new $opt['source']($opt);
$dst = new $opt['destination']($opt);

$log = array();

$i = 0;
$j = 0;
while($entry = $src->getEntry()) {
	$dst->setEntry($entry);

	$path = $dst->getPath();

	if(!file_exists($path)) {
		// Create directory
		if($run && in_array($opt['mode'],array('copy','move','hardlink'))) {
			// Create destination directory if necessary
			$dst_file_parts = pathinfo($path);
			if(!file_exists($dst_file_parts['dirname'])) {
				mkdir($dst_file_parts['dirname'], 0777, true);
			}
		}

		// Create file
		switch($opt['mode']) {
			case 'keep':
				// Use existing video file
				$path = $entry['path'];
				$log[] = 'using file "' . $path . '"';
			break;
			case 'copy':
				// Create hardlink to video file
				$log[] = 'copy file "' . $entry['path'] . '" to "' . $path . '"';
				if($run) {
					copy($entry['path'], $path);
				}
			break;
			case 'move':
				// Create hardlink to video file
				$log[] = 'move file "' . $entry['path'] . '" to "' . $path . '"';
				if($run) {
					rename($entry['path'], $path);
				}
			break;
			case 'hardlink':
				// Create hardlink to video file
				$log[] = 'hardlink file "' . $entry['path'] . '" to "' . $path . '"';
				if($run) {
					link($entry['path'], $path);
				}
			break;
		}

		// Create data
		if(in_array($opt['mode'],array('keep','copy','move','hardlink'))) {
			$log[] = 'create data for ' . $entry['title'];
			if($run) {
				$ok = $dst->saveEntry($path);
			}
			if($ok || !$run) $i++;
			else  $log[] = 'Log file for mythtv ' . $entry['id'] . 'exists.';
		}

		// Delete data on source
		if(in_array($opt['mode'],array('move','delete_missing_on_source'))) {
			if($opt['mode'] == 'delete_missing_on_source') {
				$log[] = 'delete file ' . $entry['path'];
				if($run) {
					unlink($entry['path']);
				}
			}
			$log[] = 'delete data for ' . $entry['title'] . ' on source';
			if($run) {
				$src->deleteData($entry['id']);
			}
			$j++;
		}
	}
}
if($i) $log[] = 'create ' . $i . ' files.';
if($j) $log[] = 'delete ' . $j . ' files.';

if($log) {
	if($run) {
		echo implode("\n", $log) . "\n";
	} else {
		echo 'Would ' . implode("\nWould ", $log) . "\nUse --nodryrun to do this.\n";
	}
}

class mythtv {
	private $config;
	private $mysqli;
	private $result;

	public function __construct($opt) {
		// Get database configuration
		$xml = simplexml_load_file ('/etc/mythtv/config.xml');
		$db = $xml->UPnP->MythFrontend->DefaultBackend;

		// Connect to database
		$this->mysqli = new mysqli((string)$db->DBHostName, (string)$db->DBUserName, (string)$db->DBPassword, (string)$db->DBName, (int)$db->DBPort);
		if ($this->mysqli->connect_errno) {
			echo "Failed to connect to MySQL: (" . $this->mysqli->connect_errno . ") " . $this->mysqli->connect_error;
		}
		$this->mysqli->set_charset('utf8');

		// Filter by hostname
		$hostname = 'hostname="' . mysqli_real_escape_string($this->mysqli, gethostname()) . '"';

		// Get configuration
		$this->config = array();
		$result = $this->mysqli->query('SELECT value,data FROM settings WHERE ' . $hostname);
		while ($row = $result->fetch_row()) {
			$this->config[$row[0]] = $row[1];
		}

		// Build query
		$query = '
			SELECT
				channel.channum as channelnumber,
				channel.callsign as channelname,
				recorded.starttime as recordstart,
				recorded.endtime as recordend,
				recorded.title,
				recorded.subtitle,
				recorded.description,
				recorded.season,
				recorded.episode,
				recorded.category,
				CONCAT("' . mysqli_real_escape_string($this->mysqli, $this->config['RecordFilePrefix']) . '/", recorded.basename) as path,
				recorded.progstart as programstart,
				recorded.progend as programend,
				recorded.recordedid as id
			FROM channel,recorded
			WHERE channel.chanid = recorded.chanid AND recorded.' . $hostname;

		if($opt['title']) {
			$query .= ' AND recorded.title = "' . mysqli_real_escape_string($this->mysqli, $opt['title']) . '"';
		}

		// Select entries
		$this->result = $this->mysqli->query($query);
	}

	/**
	 * Get all information of one recording
	 *
	 * @return array $entry Recording information
	 */
	public function getEntry() {
		do {
			$entry = $this->result->fetch_assoc();
		} while ($entry && !file_exists($entry['path']));
		return $entry;
	}

	public function deleteData($id) {
		$this->mysqli->query('DELETE FROM recorded WHERE recordedid = ' . $id);
	}
}

class tvheadend {
	private $config;
	private $config_name;
	private $dvr_path;
	private $entry;
	private $episode;
	private $opt;

	public function __construct($opt) {
		$this->dvr_path = '/root/.hts/tvheadend/dvr/';

		// Get dvr configuration
		$path = $this->dvr_path . 'config/';
		$dh = opendir($path);
		while (($filename = readdir($dh)) !== false) {
			$file = $path . $filename;
			if (filetype($file) == 'file') {
				break;
			}
		}
		$json = file_get_contents($file);
		$this->config = json_decode($json, true);
		$this->config_name = $filename;
		$this->opt = $opt;
	}
	
	/**
	 * Store information of one recording in class and prepare some values
	 *
	 * @params array $entry Recording information
	 */
	public function setEntry($entry) {
		if ($entry['season'] || $entry['episode']) {
			$this->episode = 'S' . $entry['season'] . '-E' . $entry['episode'];
		} else {
			$this->episode = '';
		}

		$this->entry = $entry;
	}

	/**
	 * Get path in new location
	 *
	 * @return string $path Recording path
	 */
	public function getPath() {
		$entry = $this->entry;

		// Remove all unsafe characters from filename : All characters that could possibly cause problems for filenaming will be replaced with an underscore.
		if($this->config['clean-title']) {
			$replace = array('/', '\\', ':');
			$entry['title'] = str_replace($replace, '_', $entry['title']);
			$entry['subtitle'] = str_replace($replace, '_', $entry['subtitle']);
		}

		// Format string : The string allows you to manually specify the full path generation using predefined modifiers.
		$src_file_parts = pathinfo($entry['path']);
		$tr = array(
			'$s' => $entry['subtitle'],
			'$t' => $entry['title'],
			'$e' => $this->episode,
			'$c' => $entry['channelname'],
		);
		$delimiters = array(' ','-','_','.',',',';');
		foreach ($tr as $key => $value) {
			foreach($delimiters as $delimiter) {
				$tr[substr($key,0,1) . $delimiter . substr($key,1,1)] = (empty($value) ? '' : $delimiter) . $value;
			}
		}
		$tr['$g'] = $entry['category'];
		$tr['$n'] = '';
		$tr['$x'] = $src_file_parts['extension'];
		$tr['%F'] = date('Y-m-d', strtotime($entry['recordstart']));
		$tr['%R'] = date('H:i', strtotime($entry['recordstart']));

		$path = $this->config['storage'] . '/' . strtr($this->config['pathname'], $tr);
		return $path;
	}

	/**
	 * Save information of one recording in system
	 *
	 * @params string $path Recording path
	 */
	public function saveEntry($path) {
		$lang = 'ger';
		$log_path = $this->dvr_path . 'log/';

		// Create dvr log file content
		$new = array(
			'enabled' => true,
			'start' => strtotime($this->entry['programstart']),
			'start_extra' => 0,
			'stop' => strtotime($this->entry['programend']),
			'stop_extra' => 0,
			'channelname' => $this->entry['channelname'],
			'title' => array(
				$lang => $this->entry['title'],
			),
			'subtitle' => array(
				$lang => $this->entry['subtitle'],
			),
			'description' => array(
				$lang => $this->entry['description'],
			),
			'pri' => 6,
			'config_name' => $this->config_name,
			'creator' => 'dvr_mover',
			'parent' => '',
			'child' => '',
			'comment' => $this->opt['source'] . ' ' . $this->entry['id'],
			'episode' => $this->episode,
			'files' => array(array(
				'filename' => $path,
				'start' => strtotime($this->entry['recordstart']),
				'stop' => strtotime($this->entry['recordend']),
			))
		);
		$log_content = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		
		// Write dvr log file
		$log_filename = md5($log_content);
		if(!file_exists($log_path . $log_filename)) {
			file_put_contents($log_path . $log_filename, $log_content);
			return true;
		} else {
			return false;
		}
	}
}
?>