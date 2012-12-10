<?php
/*
Copyright 2009-2011 Guillaume Boudreau, Andrew Hopkinson

This file is part of Greyhole.

Greyhole is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Greyhole is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Greyhole.  If not, see <http://www.gnu.org/licenses/>.
*/

date_default_timezone_set(date_default_timezone_get());

set_error_handler("gh_error_handler");
register_shutdown_function("gh_shutdown");

umask(0);

setlocale(LC_COLLATE, "en_US.UTF-8");
setlocale(LC_CTYPE, "en_US.UTF-8");

if (!defined('PHP_VERSION_ID')) {
    $version = explode('.', PHP_VERSION);
    define('PHP_VERSION_ID', ($version[0] * 10000 + $version[1] * 100 + $version[2]));
}

$constarray = get_defined_constants(true);
foreach($constarray['user'] as $key => $val) {
    eval(sprintf('$_CONSTANTS[\'%s\'] = ' . (is_int($val) || is_float($val) ? '%s' : "'%s'") . ';', addslashes($key), addslashes($val)));
}

// Cached df results
$last_df_time = 0;
$last_dfs = array();
$sleep_before_task = array();

if (!isset($config_file)) {
	$config_file = '/etc/greyhole.conf';
}

$trash_share_names = array('Greyhole Attic', 'Greyhole Trash', 'Greyhole Recycle Bin');

function recursive_include_parser($file) {
	
	$regex = '/^[ \t]*include[ \t]*=[ \t]*([^#\r\n]+)/im';
	$ok_to_execute = FALSE;

	if (is_array($file) && count($file) > 1) {
		$file = $file[1];
	}

	$file = trim($file);

	if (file_exists($file)) {
		if (is_executable($file)) {
			$perms = fileperms($file);

			// Not user-writable, or owned by root
			$ok_to_execute = !($perms & 0x0080) || fileowner($file) === 0;

			// Not group-writable, or group owner is root
			$ok_to_execute &= !($perms & 0x0010) || filegroup($file) === 0;

			 // Not world-writable
			$ok_to_execute &= !($perms & 0x0002);

			if (!$ok_to_execute) {
				Log::warn("Config file '{$file}' is executable but file permissions are insecure, only the file's contents will be included.");
			}
		}

		$contents = $ok_to_execute ? shell_exec(escapeshellcmd($file)) : file_get_contents($file);
		
		return preg_replace_callback($regex, 'recursive_include_parser', $contents);
	} else {
		return false;
	}
}

function parse_config() {
	global $_CONSTANTS, $storage_pool_drives, $shares_options, $minimum_free_space_pool_drives, $df_command, $config_file, $sticky_files, $db_options, $frozen_directories, $trash_share_names, $max_queued_tasks, $memory_limit, $delete_moves_to_trash, $greyhole_log_file, $email_to, $log_memory_usage, $check_for_open_files, $allow_multiple_sp_per_device, $df_cache_time;

	$deprecated_options = array(
		'delete_moves_to_attic' => 'delete_moves_to_trash',
		'storage_pool_directory' => 'storage_pool_drive',
		'dir_selection_groups' => 'drive_selection_groups',
		'dir_selection_algorithm' => 'drive_selection_algorithm'
	);

	$parsing_drive_selection_groups = FALSE;
	$shares_options = array();
	$storage_pool_drives = array();
	$frozen_directories = array();
	$config_text = recursive_include_parser($config_file);
	
	// Defaults
	Log::$level = DEBUG;
	$greyhole_log_file = '/var/log/greyhole.log';
	$email_to = 'root';
	$log_memory_usage = FALSE;
	$check_for_open_files = TRUE;
	$allow_multiple_sp_per_device = FALSE;
	$df_cache_time = 15;
	$delete_moves_to_trash = TRUE;
	$memory_limit = '128M';
	$db_engine = 'mysql';
	
	foreach (explode("\n", $config_text) as $line) {
		if (preg_match("/^[ \t]*([^=\t]+)[ \t]*=[ \t]*([^#]+)/", $line, $regs)) {
			$name = trim($regs[1]);
			$value = trim($regs[2]);
			if ($name[0] == '#') {
				continue;
			}
			
			foreach ($deprecated_options as $old_name => $new_name) {
			    if (mb_strpos($name, $old_name) !== FALSE) {
				    $fixed_name = str_replace($old_name, $new_name, $name);
				    #Log::warn("Deprecated option found in greyhole.conf: $name. You should change that to: $fixed_name");
				    $name = $fixed_name;
				}
			}

			$parsing_drive_selection_groups = FALSE;
			switch($name) {
				case 'log_level':
					Log::$level = $_CONSTANTS[$value];
					break;
				case 'delete_moves_to_trash': // or delete_moves_to_attic
				case 'log_memory_usage':
				case 'check_for_open_files':
				case 'allow_multiple_sp_per_device':
					global ${$name};
					${$name} = trim($value) === '1' || mb_stripos($value, 'yes') !== FALSE || mb_stripos($value, 'true') !== FALSE;
					break;
				case 'storage_pool_drive': // or storage_pool_directory
					if (preg_match("/(.*) ?, ?min_free ?: ?([0-9]+) ?([gmk])b?/i", $value, $regs)) {
						$storage_pool_drives[] = trim($regs[1]);
						if (strtolower($regs[3]) == 'g') {
							$minimum_free_space_pool_drives[trim($regs[1])] = (float) trim($regs[2]);
						} else if (strtolower($regs[3]) == 'm') {
							$minimum_free_space_pool_drives[trim($regs[1])] = (float) trim($regs[2]) / 1024.0;
						} else if (strtolower($regs[3]) == 'k') {
							$minimum_free_space_pool_drives[trim($regs[1])] = (float) trim($regs[2]) / 1024.0 / 1024.0;
						}
					}
					break;
				case 'wait_for_exclusive_file_access':
					$shares = explode(',', str_replace(' ', '', $value));
					foreach ($shares as $share) {
						$shares_options[$share]['wait_for_exclusive_file_access'] = TRUE;
					}
					break;
				case 'sticky_files':
					$last_sticky_files_dir = trim($value, '/');
					$sticky_files[$last_sticky_files_dir] = array();
					break;
				case 'stick_into':
					$sticky_files[$last_sticky_files_dir][] = '/' . trim($value, '/');
					break;
				case 'frozen_directory':
					$frozen_directories[] = trim($value, '/');
					break;
				case 'memory_limit':
					ini_set('memory_limit',$value);
					$memory_limit = $value;
					break;
				case 'drive_selection_groups': // or dir_selection_groups
				    if (preg_match("/(.+):(.*)/", $value, $regs)) {
    				    global $drive_selection_groups;
				        $group_name = trim($regs[1]);
						$drive_selection_groups[$group_name] = array_map('trim', explode(',', $regs[2]));
						$parsing_drive_selection_groups = TRUE;
					}
					break;
				case 'drive_selection_algorithm': // or dir_selection_algorithm
				    global $drive_selection_algorithm;
				    $drive_selection_algorithm = DriveSelection::parse($value, @$drive_selection_groups);
				    break;
				default:
					if (mb_strpos($name, 'num_copies') === 0) {
						$share = mb_substr($name, 11, mb_strlen($name)-12);
						if (mb_stripos($value, 'max') === 0) {
							$value = 9999;
						}
						$shares_options[$share]['num_copies'] = (int) $value;
					} else if (mb_strpos($name, 'delete_moves_to_trash') === 0) {
						$share = mb_substr($name, 22, mb_strlen($name)-23);
						$shares_options[$share]['delete_moves_to_trash'] = trim($value) === '1' || mb_strpos(strtolower(trim($value)), 'yes') !== FALSE || mb_strpos(strtolower(trim($value)), 'true') !== FALSE;
					} else if (mb_strpos($name, 'drive_selection_groups') === 0) { // or dir_selection_groups
						$share = mb_substr($name, 23, mb_strlen($name)-24);
    				    if (preg_match("/(.+):(.+)/", $value, $regs)) {
    						$group_name = trim($regs[1]);
    						$shares_options[$share]['drive_selection_groups'][$group_name] = array_map('trim', explode(',', $regs[2]));
    						$parsing_drive_selection_groups = $share;
    					}
					} else if (mb_strpos($name, 'drive_selection_algorithm') === 0) { // or dir_selection_algorithm
						$share = mb_substr($name, 26, mb_strlen($name)-27);
						if (!isset($shares_options[$share]['drive_selection_groups'])) {
						    $shares_options[$share]['drive_selection_groups'] = @$drive_selection_groups;
						}
						$shares_options[$share]['drive_selection_algorithm'] = DriveSelection::parse($value, $shares_options[$share]['drive_selection_groups']);
					} else {
						global ${$name};
						if (is_numeric($value)) {
							${$name} = (int) $value;
						} else {
							${$name} = $value;
						}
					}
			}
		} else if ($parsing_drive_selection_groups !== FALSE) {
			$value = trim($line);
			if (strlen($value) == 0 || $value[0] == '#') {
			    continue;
			}
		    if (preg_match("/(.+):(.+)/", $value, $regs)) {
				$group_name = trim($regs[1]);
				$drives = array_map('trim', explode(',', $regs[2]));
				if (is_string($parsing_drive_selection_groups)) {
				    $share = $parsing_drive_selection_groups;
    				$shares_options[$share]['drive_selection_groups'][$group_name] = $drives;
				} else {
    				$drive_selection_groups[$group_name] = $drives;
				}
			}
	    }
	}
	
	if (is_array($storage_pool_drives) && count($storage_pool_drives) > 0) {
		$df_command = "df -k";
		foreach ($storage_pool_drives as $key => $sp_drive) {
			$df_command .= " " . escapeshellarg($sp_drive);
			$storage_pool_drives[$key] = '/' . trim($sp_drive, '/');
		}
		$df_command .= " 2>&1 | grep '%' | grep -v \"^df: .*: No such file or directory$\"";
	} else {
		Log::warn("You have no storage_pool_drive defined. Greyhole can't run.");
		return FALSE;
	}

	exec('testparm -s ' . escapeshellarg(SambaHelper::$config_file) . ' 2> /dev/null', $config_text);
	foreach ($config_text as $line) {
		$line = trim($line);
		if (mb_strlen($line) == 0) { continue; }
		if ($line[0] == '[' && preg_match('/\[([^\]]+)\]/', $line, $regs)) {
			$share_name = $regs[1];
		}
		if (isset($share_name) && !isset($shares_options[$share_name]) && array_search($share_name, $trash_share_names) === FALSE) { continue; }
		if (isset($share_name) && preg_match('/^\s*path[ \t]*=[ \t]*(.+)$/i', $line, $regs)) {
			$shares_options[$share_name]['landing_zone'] = '/' . trim($regs[1], '/');
			$shares_options[$share_name]['name'] = $share_name;
		}
	}

    global $drive_selection_algorithm;
    if (isset($drive_selection_algorithm)) {
        foreach ($drive_selection_algorithm as $ds) {
            $ds->update();
        }
    } else {
        // Default drive_selection_algorithm
        $drive_selection_algorithm = DriveSelection::parse('most_available_space', null);
    }
	foreach ($shares_options as $share_name => $share_options) {
		if (array_search($share_name, $trash_share_names) !== FALSE) {
			global $trash_share;
			$trash_share = array('name' => $share_name, 'landing_zone' => $shares_options[$share_name]['landing_zone']);
			unset($shares_options[$share_name]);
			continue;
		}
		if ($share_options['num_copies'] > count($storage_pool_drives)) {
			$share_options['num_copies'] = count($storage_pool_drives);
		}
		if (!isset($share_options['landing_zone'])) {
			global $config_file;
			Log::warn("Found a share ($share_name) defined in $config_file with no path in " . SambaHelper::$config_file . ". Either add this share in " . SambaHelper::$config_file . ", or remove it from $config_file, then restart Greyhole.");
			return FALSE;
		}
		if (!isset($share_options['delete_moves_to_trash'])) {
		    $share_options['delete_moves_to_trash'] = $delete_moves_to_trash;
		}
		if (isset($share_options['drive_selection_algorithm'])) {
            foreach ($share_options['drive_selection_algorithm'] as $ds) {
                $ds->update();
            }
		} else {
		    $share_options['drive_selection_algorithm'] = $drive_selection_algorithm;
		}
		if (isset($share_options['drive_selection_groups'])) {
    		unset($share_options['drive_selection_groups']);
		}
		$shares_options[$share_name] = $share_options;
		
		// Validate that the landing zone is NOT a subdirectory of a storage pool drive!
		foreach ($storage_pool_drives as $key => $sp_drive) {
			if (mb_strpos($share_options['landing_zone'], $sp_drive) === 0) {
				Log::critical("Found a share ($share_name), with path " . $share_options['landing_zone'] . ", which is INSIDE a storage pool drive ($sp_drive). Share directories should never be inside a directory that you have in your storage pool.\nFor your shares to use your storage pool, you just need them to have 'vfs objects = greyhole' in their (smb.conf) config; their location on your file system is irrelevant.");
			}
		}
	}
	
	ini_set('memory_limit', $memory_limit);

	if (preg_match('/([0-9]+)([KMG]?)/i', $memory_limit, $re)) {
		$memory_limit = $re[1];
		$units = strtoupper($re[2]);
		switch($units) {
			case 'G': $memory_limit *= 1024;
			case 'M': $memory_limit *= 1024;
			case 'K': $memory_limit *= 1024;
		}
	}

	$db_engine = mb_strtolower($db_engine);
	$db_options = (object) array(
		'engine' => $db_engine,
		'schema' => "/usr/share/greyhole/schema-$db_engine.sql"
	);
	if ($db_options->engine == 'sqlite') {
		$db_options->db_path = $db_path;
		$db_options->dbh = null; // internal handle to use with sqlite
		if (!isset($max_queued_tasks)) {
			$max_queued_tasks = 1000;
		}
	} else {
		$db_options->host = $db_host;
		$db_options->user = $db_user;
		$db_options->pass = $db_pass;
		$db_options->name = $db_name;
		if (!isset($max_queued_tasks)) {
			$max_queued_tasks = 10000000;
		}
	}
	
	if (strtolower($greyhole_log_file) == 'syslog') {
		openlog("Greyhole", LOG_PID, LOG_USER);
	}
	
	return TRUE;
}

function clean_dir($dir) {
	if ($dir[0] == '.' && $dir[1] == '/') {
		$dir = mb_substr($dir, 2);
	}
	while (mb_strpos($dir, '//') !== FALSE) {
		$dir = str_replace("//", "/", $dir);
	}
	return $dir;
}

function explode_full_path($full_path) {
	return array(dirname($full_path), basename($full_path));
}

function gh_shutdown() {
	if ($err = error_get_last()) {
		Log::error("PHP Fatal Error: " . $err['message'] . "; BT: " . basename($err['file']) . '[L' . $err['line'] . '] ');
	}
}

function gh_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
	if (error_reporting() === 0) {
		// Ignored (@) warning
		return TRUE;
	}

	switch ($errno) {
	case E_ERROR:
	case E_PARSE:
	case E_CORE_ERROR:
	case E_COMPILE_ERROR:
		Log::critical("PHP Error [$errno]: $errstr in $errfile on line $errline");
		break;

	case E_WARNING:
	case E_COMPILE_WARNING:
	case E_CORE_WARNING:
	case E_NOTICE:
		global $greyhole_log_file;
		if ($errstr == "fopen($greyhole_log_file): failed to open stream: Permission denied") {
			// We want to ignore this warning. Happens when regular users try to use greyhole, and greyhole tries to log something.
			// What would have been logged will be echoed instead.
			return TRUE;
		}
		Log::warn("PHP Warning [$errno]: $errstr in $errfile on line $errline; BT: " . get_debug_bt());
		break;

	default:
		Log::warn("PHP Unknown Error [$errno]: $errstr in $errfile on line $errline");
		break;
	}

	// Don't execute PHP internal error handler
	return TRUE;
}

function get_debug_bt() {
	$bt = '';
	foreach (debug_backtrace() as $d) {
		if ($d['function'] == 'gh_error_handler' || $d['function'] == 'get_debug_bt') { continue; }
		if ($bt != '') {
			$bt = " => $bt";
		}
		$prefix = '';
		if (isset($d['file'])) {
			$prefix = basename($d['file']) . '[L' . $d['line'] . '] ';
		}
		foreach ($d['args'] as $k => $v) {
			if (is_object($v)) {
				$d['args'][$k] = 'stdClass';
			}
			if (is_array($v)) {
				$d['args'][$k] = str_replace("\n", "", var_export($v, TRUE));
			}
		}
		$bt = $prefix . $d['function'] .'(' . implode(',', $d['args']) . ')' . $bt;
	}
	return $bt;
}

function bytes_to_human($bytes, $html=TRUE) {
	$units = 'B';
	if (abs($bytes) > 1024) {
		$bytes /= 1024;
		$units = 'KB';
	}
	if (abs($bytes) > 1024) {
		$bytes /= 1024;
		$units = 'MB';
	}
	if (abs($bytes) > 1024) {
		$bytes /= 1024;
		$units = 'GB';
	}
	if (abs($bytes) > 1024) {
		$bytes /= 1024;
		$units = 'TB';
	}
	$decimals = (abs($bytes) > 100 ? 0 : (abs($bytes) > 10 ? 1 : 2));
	if ($html) {
		return number_format($bytes, $decimals) . " <span class=\"i18n-$units\">$units</span>";
	} else {
		return number_format($bytes, $decimals) . $units;
	}
}

function duration_to_human($seconds) {
	$displayable_duration = '';
	if ($seconds > 60*60) {
		$hours = floor($seconds / (60*60));
		$displayable_duration .= $hours . 'h ';
		$seconds -= $hours * (60*60);
	}
	if ($seconds > 60) {
		$minutes = floor($seconds / 60);
		$displayable_duration .= $minutes . 'm ';
		$seconds -= $minutes * 60;
	}
	$displayable_duration .= $seconds . 's';
	return $displayable_duration;
}

function get_share_landing_zone($share) {
	global $shares_options, $trash_share_names;
	if (isset($shares_options[$share]['landing_zone'])) {
		return $shares_options[$share]['landing_zone'];
	} else if (array_search($share, $trash_share_names) !== FALSE) {
		global $trash_share;
		return $trash_share['landing_zone'];
	} else {
		global $config_file;
		Log::warn("  Found a share ($share) with no path in " . SambaHelper::$config_file . ", or missing it's num_copies[$share] config in $config_file. Skipping.");
		return FALSE;
	}
}

$arch = exec('uname -m');
if ($arch != 'x86_64') {
	Log::debug("32-bit system detected: Greyhole will NOT use PHP built-in file functions.");

	function gh_filesize($filename) {
		$result = exec("stat -c %s ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (float) $result;
	}
	
	function gh_fileowner($filename) {
		$result = exec("stat -c %u ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (int) $result;
	}
	
	function gh_filegroup($filename) {
		$result = exec("stat -c %g ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (int) $result;
	}

	function gh_fileperms($filename) {
		$result = exec("stat -c %a ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return "0" . $result;
	}

	function gh_is_file($filename) {
		exec('[ -f '.escapeshellarg($filename).' ]', $tmp, $result);
		return $result === 0;
	}

	function gh_fileinode($filename) {
		// This function returns deviceid_inode to make sure this value will be different for files on different devices.
		$result = exec("stat -c '%d_%i' ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (string) $result;
	}

	function gh_file_deviceid($filename) {
		$result = exec("stat -c '%d' ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (string) $result;
	}
	
	function gh_rename($filename, $target_filename) {
		exec("mv ".escapeshellarg($filename)." ".escapeshellarg($target_filename)." 2>/dev/null", $output, $result);
		return $result === 0;
	}
} else {
	Log::debug("64-bit system detected: Greyhole will use PHP built-in file functions.");

	function gh_filesize($filename) {
		return filesize($filename);
	}
	
	function gh_fileowner($filename) {
		return fileowner($filename);
	}

	function gh_filegroup($filename) {
		return filegroup($filename);
	}

	function gh_fileperms($filename) {
		return mb_substr(decoct(fileperms($filename)), -4);
	}

	function gh_is_file($filename) {
		return is_file($filename);
	}

	function gh_fileinode($filename) {
		// This function returns deviceid_inode to make sure this value will be different for files on different devices.
		$stat = @stat($filename);
		if ($stat === FALSE) {
			return FALSE;
		}
		return $stat['dev'] . '_' . $stat['ino'];
	}

	function gh_file_deviceid($filename) {
		$stat = @stat($filename);
		if ($stat === FALSE) {
			return FALSE;
		}
		return $stat['dev'];
	}

	function gh_rename($filename, $target_filename) {
	    return @rename($filename, $target_filename);
	}
}

function memory_check() {
	global $memory_limit;
	$usage = memory_get_usage();
	$used = $usage/$memory_limit;
	$used = $used * 100;
	if ($used > 95) {
		Log::critical($used . '% memory usage, exiting. Please increase memory_limit in /etc/greyhole.conf');
	}
}

class metafile_iterator implements Iterator {
	private $path;
	private $share;
	private $flags;
	private $quiet;
	private $metafiles;
	private $metastores;
	private $dir_handle;

	public function __construct($share, $path, $flags=0, $check_symlink=TRUE) {
		$this->flags = $flags;
		$this->quiet = $flags & METAFILES_OPTION_QUIET !== 0;
		$this->share = $share;
		$this->path = $path;
	}

	public function rewind() {
		$this->metastores = Metastore::get_stores();
		$this->directory_stack = array($this->path);
		$this->dir_handle = NULL;
		$this->metafiles = array();
		$this->next();
	}

	public function current() {
		return $this->metafiles;
	}

	public function key() {
		return count($this->metafiles);
	}

	public function next() {
		$this->metafiles = array();
		while(count($this->directory_stack)>0 && $this->directory_stack !== NULL) {
			$this->dir = array_pop($this->directory_stack);
			if (!$this->quiet) {
				Log::debug("Loading metadata files for (dir) " . clean_dir($this->share . (!empty($this->dir) ? "/" . $this->dir : "")) . " ...");
			}
			for( $i = 0; $i < count($this->metastores); $i++ ) {
				$metastore = $this->metastores[$i];
				$this->base = "$metastore/".$this->share."/";
				if(!file_exists($this->base.$this->dir)) {
					continue;
				}	
				if($this->dir_handle = opendir($this->base.$this->dir)) {
					while (false !== ($file = readdir($this->dir_handle))) {
						memory_check();
						if($file=='.' || $file=='..')
							continue;
						if(!empty($this->dir)) {
							$full_filename = $this->dir . '/' . $file;
						}else
							$full_filename = $file;
						if(is_dir($this->base.$full_filename))
							$this->directory_stack[] = $full_filename;
						else{
							$full_filename = str_replace("$this->path/",'',$full_filename);
							if(isset($this->metafiles[$full_filename])) {
								continue;
							}						
							$this->metafiles[$full_filename] = Metastore::metafiles_for_file($this->share, "$this->dir", $file, $this->flags);
						}
					}
					closedir($this->dir_handle);
					$this->directory_stack = array_unique($this->directory_stack);
				}
			}
			if(count($this->metafiles) > 0) {
				break;
			}
			
		}
		if (!$this->quiet) {
			Log::debug('Found ' . count($this->metafiles) . ' metadata files.');
		}
		return $this->metafiles;
	}
	
	public function valid() {
		return count($this->metafiles) > 0;
	}
}

function kshift(&$arr) {
    if (count($arr) == 0) {
        return FALSE;
    }
    foreach ($arr as $k => $v) {
        unset($arr[$k]);
        break;
    }
    return array($k, $v);
}

function kshuffle(&$array) {
    if (!is_array($array)) { return $array; }
    $keys = array_keys($array);
    shuffle($keys);
    $random = array();
    foreach ($keys as $key) {
        $random[$key] = $array[$key];
    }
    $array = $random;
}

class DriveSelection {
    var $num_drives_per_draft;
    var $selection_algorithm;
    var $drives;
    var $is_custom;
    
    var $sorted_target_drives;
    var $last_resort_sorted_target_drives;
    
    function __construct($num_drives_per_draft, $selection_algorithm, $drives, $is_custom) {
        $this->num_drives_per_draft = $num_drives_per_draft;
        $this->selection_algorithm = $selection_algorithm;
        $this->drives = $drives;
        $this->is_custom = $is_custom;
    }
    
    function init(&$sorted_target_drives, &$last_resort_sorted_target_drives) {
        // Sort by used space (asc) for least_used_space, or by available space (desc) for most_available_space
        if ($this->selection_algorithm == 'least_used_space') {
			$sorted_target_drives = $sorted_target_drives['used_space'];
			$last_resort_sorted_target_drives = $last_resort_sorted_target_drives['used_space'];
        	asort($sorted_target_drives);
    		asort($last_resort_sorted_target_drives);
        } else if ($this->selection_algorithm == 'most_available_space') {
			$sorted_target_drives = $sorted_target_drives['available_space'];
			$last_resort_sorted_target_drives = $last_resort_sorted_target_drives['available_space'];
        	arsort($sorted_target_drives);
    		arsort($last_resort_sorted_target_drives);
		} else {
			Log::critical("Unknown drive_selection_algorithm found: " . $this->selection_algorithm);
		}
		// Only keep drives that are in $this->drives
        $this->sorted_target_drives = array();
		foreach ($sorted_target_drives as $sp_drive => $space) {
		    if (array_search($sp_drive, $this->drives) !== FALSE) {
		        $this->sorted_target_drives[$sp_drive] = $space;
		    }
		}
        $this->last_resort_sorted_target_drives = array();
		foreach ($last_resort_sorted_target_drives as $sp_drive => $space) {
		    if (array_search($sp_drive, $this->drives) !== FALSE) {
		        $this->last_resort_sorted_target_drives[$sp_drive] = $space;
		    }
		}
    }
    
    function draft() {
        $drives = array();
        $drives_last_resort = array();
        
        while (count($drives)<$this->num_drives_per_draft) {
            $arr = kshift($this->sorted_target_drives);
            if ($arr === FALSE) {
                break;
            }
            list($sp_drive, $space) = $arr;
			if (!StoragePoolHelper::is_drive_ok($sp_drive)) { continue; }
            $drives[$sp_drive] = $space;
        }
        while (count($drives)+count($drives_last_resort)<$this->num_drives_per_draft) {
            $arr = kshift($this->last_resort_sorted_target_drives);
            if ($arr === FALSE) {
                break;
            }
            list($sp_drive, $space) = $arr;
			if (!StoragePoolHelper::is_drive_ok($sp_drive)) { continue; }
            $drives_last_resort[$sp_drive] = $space;
        }
        
        return array($drives, $drives_last_resort);
    }
    
    static function parse($config_string, $drive_selection_groups) {
        $ds = array();
        if ($config_string == 'least_used_space' || $config_string == 'most_available_space') {
            global $storage_pool_drives;
            $ds[] = new DriveSelection(count($storage_pool_drives), $config_string, $storage_pool_drives, FALSE);
            return $ds;
        }
        if (!preg_match('/forced ?\((.+)\) ?(least_used_space|most_available_space)/i', $config_string, $regs)) {
            Log::critical("Can't understand the drive_selection_algorithm value: $config_string");
        }
        $selection_algorithm = $regs[2];
        $groups = array_map('trim', explode(',', $regs[1]));
        foreach ($groups as $group) {
            $group = explode(' ', preg_replace('/^([0-9]+)x/', '\\1 ', $group));
            $num_drives = trim($group[0]);
            $group_name = trim($group[1]);
			if (!isset($drive_selection_groups[$group_name])) {
				//Log::warn("Warning: drive selection group named '$group_name' is undefined.");
				continue;
			}
            if ($num_drives == 'all' || $num_drives > count($drive_selection_groups[$group_name])) {
                $num_drives = count($drive_selection_groups[$group_name]);
            }
            $ds[] = new DriveSelection($num_drives, $selection_algorithm, $drive_selection_groups[$group_name], TRUE);
        }
        return $ds;
    }

    function update() {
        // Make sure num_drives_per_draft and drives have been set, in case storage_pool_drive lines appear after drive_selection_algorithm line(s) in the config file
        if (!$this->is_custom && ($this->selection_algorithm == 'least_used_space' || $this->selection_algorithm == 'most_available_space')) {
            global $storage_pool_drives;
            $this->num_drives_per_draft = count($storage_pool_drives);
            $this->drives = $storage_pool_drives;
        }
    }
}

// Is it OK for a drive to be gone?
function gone_ok($sp_drive, $refresh=FALSE) {
	global $gone_ok_drives;
	if ($refresh || !isset($gone_ok_drives)) {
		$gone_ok_drives = get_gone_ok_drives();
	}
	if (isset($gone_ok_drives[$sp_drive])) {
		return TRUE;
	}
	return FALSE;
}

function get_gone_ok_drives() {
	global $gone_ok_drives;
	$gone_ok_drives = Settings::get('Gone-OK-Drives', TRUE);
	if (!$gone_ok_drives) {
		$gone_ok_drives = array();
		Settings::set('Gone-OK-Drives', $gone_ok_drives);
	}
	return $gone_ok_drives;
}

function mark_gone_ok($sp_drive, $action='add') {
	global $storage_pool_drives;
	if (array_search($sp_drive, $storage_pool_drives) === FALSE) {
		$sp_drive = '/' . trim($sp_drive, '/');
	}
	if (array_search($sp_drive, $storage_pool_drives) === FALSE) {
		return FALSE;
	}

	global $gone_ok_drives;
	$gone_ok_drives = get_gone_ok_drives();
	if ($action == 'add') {
		$gone_ok_drives[$sp_drive] = TRUE;
	} else {
		unset($gone_ok_drives[$sp_drive]);
	}

	Settings::set('Gone-OK-Drives', $gone_ok_drives);
	return TRUE;
}

function gone_fscked($sp_drive, $refresh=FALSE) {
	global $fscked_gone_drives;
	if ($refresh || !isset($fscked_gone_drives)) {
		$fscked_gone_drives = get_fsck_gone_drives();
	}
	if (isset($fscked_gone_drives[$sp_drive])) {
		return TRUE;
	}
	return FALSE;
}

function get_fsck_gone_drives() {
	global $fscked_gone_drives;
	$fscked_gone_drives = Settings::get('Gone-FSCKed-Drives', TRUE);
	if (!$fscked_gone_drives) {
		$fscked_gone_drives = array();
		Settings::set('Gone-FSCKed-Drives', $fscked_gone_drives);
	}
	return $fscked_gone_drives;
}

function mark_gone_drive_fscked($sp_drive, $action='add') {
	global $fscked_gone_drives;
	$fscked_gone_drives = get_fsck_gone_drives();
	if ($action == 'add') {
		$fscked_gone_drives[$sp_drive] = TRUE;
	} else {
		unset($fscked_gone_drives[$sp_drive]);
	}

	Settings::set('Gone-FSCKed-Drives', $fscked_gone_drives);
}

class FSCKLogFile {
	const PATH = '/usr/share/greyhole';

	private $path;
	private $filename;
	private $lastEmailSentTime = 0;
	
	public function __construct($filename, $path=self::PATH) {
		$this->filename = $filename;
		$this->path = $path;
	}
	
	public function emailAsRequired() {
		$logfile = "$this->path/$this->filename";
		if (!file_exists($logfile)) { return; }

		$last_mod_date = filemtime($logfile);
		if ($last_mod_date > $this->getLastEmailSentTime()) {
			global $email_to;
			Log::warn("Sending $logfile by email to $email_to");
			mail($email_to, $this->getSubject(), $this->getBody());

			$this->lastEmailSentTime = $last_mod_date;
			Settings::set("last_email_$this->filename", $this->lastEmailSentTime);
		}
	}

	private function getBody() {
		$logfile = "$this->path/$this->filename";
		if ($this->filename == 'fsck_checksums.log') {
			return file_get_contents($logfile) . "\nNote: You should manually delete the $logfile file once you're done with it.";
		} else if ($this->filename == 'fsck_files.log') {
			global $fsck_report;
			$fsck_report = unserialize(file_get_contents($logfile));
			unlink($logfile);
			return get_fsck_report() . "\nNote: This report is a complement to the last report you've received. It details possible errors with files for which the fsck was postponed.";
		} else {
			return '[empty]';
		}
	}
	
	private function getSubject() {
		if ($this->filename == 'fsck_checksums.log') {
			return 'Mismatched checksums in Greyhole file copies';
		} else if ($this->filename == 'fsck_files.log') {
			return 'fsck_files of Greyhole shares on ' . exec('hostname');
		} else {
			return 'Unknown FSCK report';
		}
	}
	
	private function getLastEmailSentTime() {
		if ($this->lastEmailSentTime == 0) {
			$setting = Settings::get("last_email_$this->filename");
			if ($setting) {
				$this->lastEmailSentTime = (int) $setting;
			}
		}
		return $this->lastEmailSentTime;
	}
	
	public static function loadFSCKReport($what) {
		$logfile = self::PATH . '/fsck_files.log';
		if (file_exists($logfile)) {
			global $fsck_report;
			$fsck_report = unserialize(file_get_contents($logfile));
		} else {
			initialize_fsck_report($what);
		}
	}

	public static function saveFSCKReport() {
		global $fsck_report;
		$logfile = self::PATH . '/fsck_files.log';
		file_put_contents($logfile, serialize($fsck_report));
	}
}

function gh_dir_uuid($dir) {
	$dev = exec('df ' . escapeshellarg($dir) . ' 2> /dev/null | grep \'/dev\' | awk \'{print $1}\'');
	if (empty($dev) || strpos($dev, '/dev/') !== 0) {
		return FALSE;
	}
	return trim(exec('blkid '.$dev.' | awk -F\'UUID="\' \'{print $2}\' | awk -F\'"\' \'{print $1}\''));
}

function fix_all_symlinks() {
	global $shares_options;
	foreach ($shares_options as $share_name => $share_options) {
		fix_symlinks_on_share($share_name);
	}
}

function fix_symlinks_on_share($share_name) {
	global $shares_options, $storage_pool_drives;
	$share_options = $shares_options[$share_name];
	echo "Looking for broken symbolic links in the share '$share_name'... Please be patient... ";
	chdir($share_options['landing_zone']);
	exec("find -L . -type l", $result);
	foreach ($result as $file_to_relink) {
		if (is_link($file_to_relink)) {
			$file_to_relink = substr($file_to_relink, 2);
			foreach ($storage_pool_drives as $sp_drive) {
				if (!StoragePoolHelper::is_drive_ok($sp_drive)) { continue; }
				$new_link_target = clean_dir("$sp_drive/$share_name/$file_to_relink");
				if (gh_is_file($new_link_target)) {
					unlink($file_to_relink);
					symlink($new_link_target, $file_to_relink);
					echo ".";
					break;
				}
			}
		}
	}
	echo "Done.\n";
}

function schedule_fsck_all_shares($fsck_options=array()) {
	global $shares_options;
	foreach ($shares_options as $share_name => $share_options) {
		$full_path = $share_options['landing_zone'];
		$query = sprintf("INSERT INTO tasks (action, share, additional_info, complete) VALUES ('fsck', '%s', %s, 'yes')",
			DB::escape_string($full_path),
			(!empty($fsck_options) ? "'" . implode('|', $fsck_options) . "'" : "NULL")
		);
		DB::query($query) or Log::critical("Can't insert fsck task: " . DB::error());
	}
}
?>
