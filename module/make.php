<?php

// ver 1.1.0
if (!extension_loaded('zip')) {
	error('php-zip module not installed!');
}

const ARC = 'arc';
const SRC = 'src';
const ZIP = 'zip';
const EXC = array('.git', 'hideg.pwd', ARC, ZIP);

$modfn = get_mod_file_name();

function get_mod_file_name() {
	$cwd = basename(getcwd());

	if (basename(getcwd()) == 'module') {
		$modfn = basename(str_replace($cwd, '', getcwd()));
	} else {
		$modfn = basename(str_replace('addons/' . $cwd, '', getcwd()));
		$modfn .= '--' . $cwd;
	}

	return $modfn;
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
	// error was suppressed with the @-operator
	if (error_reporting() === 0) {
		return false;
	}

	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

$act = '';

if (isset($argv[1])) {
	$act = strtolower($argv[1]);
}

switch ($act) {
	case 'list':
		list_fcl();

		break;

	case 'extr':
		extr_fcl();

		break;

	case 'make':
		if (is_file('config.php')) {
			include 'config.php';

			$subst = get_defined_constants(true)['user'];
			$subst['XID'] = $modfn;

			$parts = explode('--' , $modfn);

			if (count($parts) === 2) {
				$mod0 = explode('-', $parts[0]);
				$mod1 = explode('-', $parts[1]);

				foreach ($mod0 as &$word) {
					$word = ucfirst($word);
				}
				foreach ($mod1 as &$word) {
					$word = ucfirst($word);
				}

				$mod0 = implode(' ', $mod0);
				$mod1 = implode(' ', $mod1);

				$subst['MOD'] = $mod0 . ' | ' . $mod1;
			}

			make();
		} else {
			error('file "config.php" is missing');
		}

		break;

	default:
		error('make.php list|extr|make');
}

exit(0);

function make() {
	global $modfn;

	delete_content(ZIP);
	mkzip(get_dir_path(SRC), get_file_path(ZIP, $modfn . '.ocmod.zip'));

	delete_content(ARC);
	mkdir_if_no_exist(ARC);

	$dr = get_dir_path(ARC);
	$fl = get_file_path($dr, $modfn . '.fcl');

	fcl('make', $fl, EXC);
	hideg($fl);

	unlink($fl);
}

function list_fcl() {
	global $modfn;

	exit_if_no_path(ARC);

	$dr = get_dir_path(ARC);
	$fl = get_file_path($dr, $modfn . '.fcl.g');

	hideg($fl);

	$fl = get_file_path($dr, $modfn . '.fcl');

	fcl('list', $fl);

	unlink($fl);
}

function extr_fcl() {
	global $modfn;

	exit_if_no_path(ARC);

	$dr = get_dir_path(ARC);
	$fl = get_file_path($dr, $modfn . '.fcl.g');

	hideg($fl);

	$fl = get_file_path($dr, $modfn . '.fcl');

	fcl('extr', $fl);

	unlink($fl);
}

function fcl($c, $f, $e = array(), $i = array()) {
	exit_if_no_path('/usr/local/bin/fcl');

	switch ($c) {
		case 'make':
			$c = 'make -q -f ';

			if ($e) {
				foreach ($e as $e) {
					$c .= '-E' . $e . ' ';
				}
			}

			$c .= $f;

			break;

		case 'extr':
			$c = 'extr -f ' . $f;

			break;

		case 'list':
			$c = 'list ' . $f;

			break;

		default:
			error('fcl command: "' . $c . '"');
	}

	$c = 'fcl ' . $c;

	out(shell_exec($c));
}

function hideg($file) {
	exit_if_no_path('/usr/local/bin/hideg');
	exit_if_no_path('hideg.pwd');

	out(shell_exec('hideg ' . $file));
}

function mkzip($source, $zipfile) {
	global $subst;

	$zip = new ZipArchive();

	if ($zip->open($zipfile, ZipArchive::CREATE) === true) {
		foreach (get_file_list($source) as $file) {
			$relative = substr($file, strlen($source));

			if (is_file($file)) {
				$content = replacer($file, $subst);
				$zip->addFromString($relative, $content);
			} elseif (is_dir($file)) {
				$zip->addEmptyDir($relative);
			}

			// $zip->setMtimeName($file, strtotime('2022-01-01 00:00:00'));
		}

		try {
			$zip->close();
		} catch (Exception $e) {
			error(' creating "' . $zipfile . '" error:' . "\n" . $e);
		}
	} else {
		error('can not create "' . $zipfile . '"!');
	}
}

function get_file_list($path) {
	$files = array();

	if (is_dir($path)) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			if ($file->isDir()) {
				// continue;
			}

			$files[] = $file->getPathname();
		}
	}

	return $files;
}

function get_dir_path(string $dir1, string $dir2 = '') {
	$dir1 = trim($dir1);
	$dir1 = rtrim($dir1, DIRECTORY_SEPARATOR);
	$dir1 .= DIRECTORY_SEPARATOR;

	if ($dir2) {
		$dir2 = trim($dir2);
		$dir2 = trim($dir2, DIRECTORY_SEPARATOR);
		$dir2 .= DIRECTORY_SEPARATOR;
	}

	return $dir1 . $dir2;
}

function get_file_path(string $dir, string $file) {
	return get_dir_path($dir) . trim(trim($file), DIRECTORY_SEPARATOR);
}

function replacer($file, $variables = array()) {
	$content = '';

	if ($pointer = fopen($file, 'r')) {
		while (!feof($pointer)) {
			$line = fgets($pointer);

			if (strpos($line, '<insertfile>') !== false) {
				$ifile = get_string_between($line, '<insertfile>', '</insertfile>');

				if (empty($ifile) || !is_file($ifile)) {
					error('in "' . $file . '" - check placeholder file "' . $ifile . '"');
				}

				$ifile = preg_replace('/[^a-z0-9]+$/i', '', $ifile);
				$line = file_get_contents($ifile);
			}

			while (strpos($line, '<insertvar>') !== false) {
				$ivar = get_string_between($line, '<insertvar>', '</insertvar>');
				$ivar = preg_replace('/[^a-z0-9]+$/i', '', $ivar);

				if (empty($ivar) || !array_key_exists($ivar, $variables)) {
					out('check placeholder var "' . $ivar . '" in "' . $file . '"');

					exit(1);
				}

				$search = '<insertvar>' . $ivar . '</insertvar>';
				$replace = $variables[$ivar];
				$line = str_replace($search, $replace, $line);
			}

			$content .= $line;
		}

		fclose($pointer);
	}

	return $content;
}

function exit_if_no_path($path) {
	if (!file_exists($path)) {
		out('check "' . $path . '"!');

		exit(1);
	}
}

function mkdir_if_no_exist($dir) {
	if (is_dir($dir)) {
		return true;
	}

	if (!file_exists($dir)) {
		return mkdir($dir);
	}

	error('can not create ' . $dir . '!', 1);

	exit(1);
}

function delete_content($path) {
	try {
		$iterator = new DirectoryIterator($path);
		foreach ($iterator as $fileinfo) {
			if ($fileinfo->isDot()) {
				continue;
			}
			if ($fileinfo->isDir()) {
				if (delete_content($fileinfo->getPathname())) {
					@rmdir($fileinfo->getPathname());
				}
			}
			if ($fileinfo->isFile()) {
				@unlink($fileinfo->getPathname());
			}
		}
	} catch (Exception $e) {
		// write log
		return false;
	}

	return true;
}

function get_string_between($string, $start, $end) {
	$ini = strpos($string, $start);

	if ($ini === false) {
		return '';
	}

	$ini += strlen($start);
	$len = strpos($string, $end, $ini) - $ini;

	return substr($string, $ini, $len);
}

function out($text, $exit = 0) {
	echo $text . "\n";
}

function error($text) {
	out('ERROR: ' . $text);

	exit(1);
}
