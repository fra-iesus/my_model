<?php

function class_autoload ($class) {
	global $AUTOLOAD_DIRS;
	if (class_exists($class, false)) {
		return true;
	}
	$dirs = is_array($AUTOLOAD_DIRS) && sizeof($AUTOLOAD_DIRS) ? $AUTOLOAD_DIRS : [];
	array_push($dirs, __DIR__);
	$parts = explode('\\', $class);
	foreach ($dirs as $dir) {
		$path = $parts;
		while (sizeof($path)) {
			$file = $dir . DIRECTORY_SEPARATOR . join(DIRECTORY_SEPARATOR, $path) . '.php';
			if (is_file($file)) {
				require_once($file);
				if (class_exists($class, false) || trait_exists($class, false) || interface_exists($class, false)) {
					return true;
				}
			}
			array_shift($path);
		}
	}
	if ( sizeof($parts) ) {
		if (!class_exists('MyModel')) {
			require_once(__DIR__ . "/MyModel.php");
		}
		if (sizeof($parts) > 2 && $parts[2] == 'Listing') {
			$eval = 'namespace ' . $parts[0] . '\\' . $parts[1] . ';
			class ' . $parts[2] . ' extends \MyListing {}';
		} elseif (sizeof($parts) > 1) {
			$eval = 'namespace ' . $parts[0] . ';
			class ' . $parts[1] . ' extends \MyModel {}';
		} else {
			$eval = 'class ' . $parts[0] . ' extends \MyModel {}';
		}
		eval($eval);
		return true;
	}
	#throw new Exception("Unable to load $class.");
	return false;
}
spl_autoload_register("class_autoload", true, false);

?>