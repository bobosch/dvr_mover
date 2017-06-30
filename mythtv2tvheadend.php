#!/usr/bin/php
<?php
/*
Version 0.0

ToDo:
- Command line options
  default: --mode hardlink --source mythtv --destination tvheadend
  mode: keep (point to the file location of source)
        copy (copy file from source to destination)
        move (move file from source to destination)
        hardlink (create a hardlink on destination to file on source)
        delete_missing (delete files not exists on source on destination)
        delete_existing (delete files that exists on both on destination)
        (to synchronize use delete_missing + hardlink)
        (to overwrite use delete_existing + hardlink)
  source: mythtv
  destination: tvheadend
  title: Search only for recordings with specified title

- class tvheadend
  - detection or configuration of .hts directory
  - $lang detection or configuration
  - correct dvr log filename
*/

$src = new mythtv();
$dst = new tvheadend();

$i = 0;
while($entry = $src->getEntry()) {
	$dst->setEntry($entry);

	$filename = $dst->getFilename();

	// Create destination directory if necessary
	$dst_file_parts = pathinfo($filename);
	if(!file_exists($dst_file_parts['dirname'])) {
		mkdir($dst_file_parts['dirname'], 0777, true);
	}
	// Create hardlink
	link($entry['filename'], $filename);

	$ok = $dst->saveEntry($filename);

	if($ok) $i++;
	else echo 'Log file for mythtv ' . $entry['id'] . 'exists.';
}
echo 'Linked ' . $i . 'files.';

class mythtv {
	private $config;
	private $result;

	public function __construct() {
		// Get database configuration
		$xml = simplexml_load_file ('/etc/mythtv/config.xml');
		$db = $xml->UPnP->MythFrontend->DefaultBackend;

		// Connect to database
		$mysqli = new mysqli((string)$db->DBHostName, (string)$db->DBUserName, (string)$db->DBPassword, (string)$db->DBName, (int)$db->DBPort);
		if ($mysqli->connect_errno) {
			echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
		}
		$mysqli->set_charset('utf8');

		// Filter by hostname
		$hostname = 'hostname="' . mysqli_real_escape_string($mysqli, gethostname()) . '"';

		// Get configuration
		$this->config = array();
		$result = $mysqli->query('SELECT value,data FROM settings WHERE ' . $hostname);
		while ($row = $result->fetch_row()) {
			$this->config[$row[0]] = $row[1];
		}
		
		// Select entries
		$this->result = $mysqli->query('
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
				CONCAT("' . mysqli_real_escape_string($mysqli, $this->config['RecordFilePrefix']) . '/", recorded.basename) as filename,
				recorded.progstart as programstart,
				recorded.progend as programend,
				recorded.recordedid as id
			FROM channel,recorded
			WHERE channel.chanid = recorded.chanid AND recorded.' . $hostname
		);
	}

	/**
	 * Get all information of one recording
	 *
	 * @return array $entry Recording information
	 */
	public function getEntry() {
		do {
			$entry = $this->result->fetch_assoc();
		} while ($entry && !file_exists($entry['filename']));
		return $entry;
	}
}

class tvheadend {
	private $config;
	private $config_name;
	private $dvr_path;
	private $entry;
	private $episode;

	public function __construct() {
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
	 * Get filename in new location
	 *
	 * @return string $filename Recording filename
	 */
	public function getFilename() {
		$entry = $this->entry;

		// Remove all unsafe characters from filename : All characters that could possibly cause problems for filenaming will be replaced with an underscore.
		if($this->config['clean-title']) {
			$replace = array('/', '\\', ':');
			$entry['title'] = str_replace($replace, '_', $entry['title']);
			$entry['subtitle'] = str_replace($replace, '_', $entry['subtitle']);
		}

		// Format string : The string allows you to manually specify the full path generation using predefined modifiers.
		$src_file_parts = pathinfo($entry['filename']);
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

		$filename = $this->config['storage'] . '/' . strtr($this->config['pathname'], $tr);
		return $filename;
	}

	/**
	 * Save information of one recording in system
	 *
	 * @params string $filename Recording filename
	 */
	public function saveEntry($filename) {
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
			'comment' => 'mythtv ' . $this->entry['id'],
			'episode' => $this->episode,
			'files' => array(array(
				'filename' => $filename,
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