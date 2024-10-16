<?php

declare(strict_types=1);

// The Nette Tester command-line runner can be
// invoked through the command: ../vendor/bin/tester .

if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo 'Install Nette Tester using `composer install`';
	exit(1);
}


// configure environment
Tester\Environment::setup();
Tester\Environment::setupFunctions();
date_default_timezone_set('Europe/Prague');

if (PHP_VERSION_ID >= 80400) {
	error_reporting(E_ALL & ~E_DEPRECATED);
}


function getTempDir(): string
{
	$dir = __DIR__ . '/tmp';
	@mkdir($dir);
	return $dir;
}


function connectToDB(array $options = []): Nette\Database\Explorer
{
	$args = Tester\Environment::loadData() + ['username' => null, 'password' => null, 'options' => []];
	$args['options'] = $options + $args['options'];

	if ($args['dsn'] !== 'sqlite::memory:') {
		Tester\Environment::lock($args['dsn'], getTempDir());
	}

	$explorer = new Nette\Database\Explorer($args['dsn'], $args['username'], $args['password'], $args['options']);

	try {
		$explorer->connect();
	} catch (Nette\Database\ConnectionException $e) {
		Tester\Environment::skip("Connection to '$args[dsn]' failed. Reason: " . $e->getMessage());
	}

	$driverName = $explorer->getConnection()->getNativeConnection()->getAttribute(PDO::ATTR_DRIVER_NAME);
	$explorer->setCache(new Nette\Caching\Cache(new Nette\Caching\Storages\MemoryStorage));

	echo "Driver: $driverName\n";
	$GLOBALS['driverName'] = $driverName;
	return $explorer;
}


/** Replaces [] with driver-specific quotes */
function reformat($s): string
{
	$driverName = $GLOBALS['driverName'];
	if (is_array($s)) {
		if (isset($s[$driverName])) {
			return $s[$driverName];
		}

		$s = $s[0];
	}

	if ($driverName === 'mysql') {
		return strtr($s, '[]', '``');
	} elseif ($driverName === 'pgsql') {
		return strtr($s, '[]', '""');
	} elseif ($driverName === 'sqlsrv' || $driverName === 'sqlite') {
		return $s;
	} else {
		trigger_error("Unsupported driver $driverName", E_USER_WARNING);
	}
}
