<?php
	// Cloud-based backup restoration tool.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cb_functions.php";

	// Load and initialize the service helper.
	echo "Initializing...\n";
	$config = CB_LoadConfig();

	$servicehelper = new CB_ServiceHelper();
	$servicehelper->Init($config);

	// Initialize service access.
	$result = $servicehelper->StartService();

	$servicename = $result["servicename"];
	$service = $result["service"];
	$incrementals = $result["incrementals"];
	$incrementaltimes = $result["summary"]["incrementaltimes"];
	$lastbackupid = $result["summary"]["lastbackupid"];

	$services = CB_GetBackupServices();
	if (isset($services[$servicename]))  $servicename = $services[$servicename];

	function CleanupCache()
	{
		global $rootpath;

		$dir = @opendir($rootpath . "/cache");
		if ($dir)
		{
			while (($file = readdir($dir)) !== false)
			{
				if (substr($file, 0, 8) === "restore_")  @unlink($rootpath . "/cache/" . $file);
			}

			closedir($dir);
		}
	}

	@mkdir($rootpath . "/cache");

	// Clean up the cache.
	$filename = $rootpath . "/cache/restore_summary.dat";
	if (!file_exists($filename) || serialize($result["summary"]) !== file_get_contents($filename))  CleanupCache();
	file_put_contents($filename, serialize($result["summary"]));

	// Verify that the base and incrementals look good.
	$y = count($incrementals);
	if (!$y)  CB_DisplayError("Backup is missing.  As in either completely gone or never run.");
	for ($x = 0; $x < $y; $x++)
	{
		if (!isset($incrementals[$x]))  CB_DisplayError("Missing folder detected.  Expected " . ($x ? "incremental " : "base ") . $x . " to exist.  Backup is corrupt.");
		if (!isset($incrementaltimes[$x]))  CB_DisplayError("Missing folder meta information detected.  Expected timestamp " . ($x ? "incremental " : "base ") . $x . " to exist.  Backup is corrupt.");
	}

	echo "Available backups:\n";
	for ($x = 0; $x < $y; $x++)
	{
		echo "\t" . $x . ":  " . date("l, F j, Y @ g:i a", $incrementaltimes[$x]) . "\n";
	}

	do
	{
		echo "\n";
		echo "Select backup:  ";
		$backupnum = (int)fgets(STDIN);
		echo "\n";
		if ($backupnum < 0 || $backupnum >= $y)  CB_DisplayError("Invalid backup entered.  Please try again.", false, false);
	} while ($backupnum < 0 || $backupnum >= $y);

	// Retrieve blocklists.
	$blocklist = array();
	for ($x = 0; $x <= $backupnum; $x++)
	{
		$filename = $rootpath . "/cache/restore_" . $x . "_blocklist.dat";
		if (file_exists($filename))  $blocklist2 = unserialize(file_get_contents($filename));
		else
		{
			echo "Retrieving block list for " . ($x ? "incremental " : "base ") . $x . "...\n";

			$result = $service->GetIncrementalBlockList($x);
			if (!$result["success"])  CB_DisplayError("Unable to retrieve block list for the " . ($x ? "incremental " : "base ") . $x . ".", $result);

			$blocklist2 = $result["blocklist"];

			file_put_contents($filename, serialize($blocklist2));
		}

		unset($blocklist[0]);
		unset($blocklist[1]);

		foreach ($blocklist2 as $key => $val)
		{
			$blocklist[$key] = array("inc" => $x, "parts" => $val);
		}
	}

	if (!isset($blocklist[0]))  CB_DisplayError("Missing the files database for the " . ($backupnum ? "incremental " : "base ") . $backupnum . ".  Backup needs repair.");
	if (!isset($blocklist[1]))  CB_DisplayError("Missing the deleted blocks list for the " . ($backupnum ? "incremental " : "base ") . $backupnum . ".  Backup needs repair.");

	// Retrieve the files database.
	$filename = $rootpath . "/cache/restore_" . $backupnum . "_files.db";
	@unlink($filename . "-journal");
	if (!file_exists($filename))
	{
		echo "Retrieving files database...\n";
		$servicehelper->DownloadFile($filename, $blocklist[0]["inc"], 0, $blocklist[0]["parts"]);
	}

	require_once $rootpath . "/support/db.php";
	require_once $rootpath . "/support/db_sqlite.php";

	$db = new CSDB_sqlite();

	try
	{
		$db->Connect("sqlite:" . $filename);
	}
	catch (Exception $e)
	{
		CB_DisplayError("Unable to connect to SQLite database.  " . $e->getMessage());
	}

	$servicehelper->SetDB($db);

	function PathStackToStr($stack)
	{
		$result = "";
		foreach ($stack as $info)  $result .= $info["name"] . "/";

		return $result;
	}

	function CalculateRealpath($path)
	{
		global $rootpathstack, $pathstack, $db;

		$path = str_replace("\\", "/", $path);
		if (substr($path, 0, 1) === "/")  $result = $rootpathstack;
		else  $result = $pathstack;
		$parts = explode("/", $path);

		$symlinks = 0;

		while (count($parts))
		{
			$part = array_shift($parts);

			if ($part === "" || $part === ".")
			{
			}
			else if ($part === "..")
			{
				if (count($result) > 1)  array_pop($result);
			}
			else
			{
				try
				{
					$row = $db->GetRow("SELECT", array(
						"*",
						"FROM" => "?",
						"WHERE" => "pid = ? AND name = ?"
					), "files", $result[count($result) - 1]["id"], $part);
				}
				catch (Exception $e)
				{
					CB_DisplayError("A SQL error occurred while retrieving information about a path.  " . $e->getMessage());
				}

				if ($row === false)  return array("success" => false, "error" => "The path/filename '" . $path . "' does not exist.", "errorcode" => "invalid_path", "info" => $result);

				if ($row->symlink !== "")
				{
					// Set up to follow the symlink.
					$linkparts = explode("/", str_replace("\\", "/", $row->symlink));
					$parts = array_merge($linkparts, $parts);

					$path = implode("/", $parts);

					$symlinks++;
					if ($symlinks >= 20)  return array("success" => false, "error" => "The specified path follows too many symbolic links.  Probably an infinite loop.", "errorcode" => "too_many_symlinks", "info" => $result);
				}
				else if (CB_IsDir((int)$row->attributes))
				{
					$result[] = array("id" => $row->id, "name" => $row->name, "dir" => $row);
				}
				else
				{
					if (count($parts))  return array("success" => false, "error" => "File encountered at '" . $part . "' while resolving the path '" . $path . "'.  Expected a folder or a symbolic link.", "errorcode" => "file_encountered", "info" => $result);

					$result[] = array("id" => $row->id, "name" => $row->name, "file" => $row);
				}
			}
		}

		return array("success" => true, "dir" => $result, "path" => PathStackToStr($result));
	}

	// Load all restore functions.
	$dir = opendir($rootpath . "/restore_shell_exts");
	if ($dir !== false)
	{
		while (($file = readdir($dir)) !== false)
		{
			if (substr($file, -4) === ".php")  require_once $rootpath . "/restore_shell_exts/" . $file;
		}

		closedir($dir);
	}

	require_once $rootpath . "/support/cli.php";

	echo "Ready.  This is a command-line interface.  Enter 'help' to get a list of available commands.\n\n";

	$rootid = (int)$db->GetOne("SELECT", array("id", "FROM" => "?", "WHERE" => "pid = 0 AND name = ''"), "files");
	$rootpathstack = array(array("id" => $rootid, "name" => ""));
	$pathstack = $rootpathstack;
	echo $servicename . " [" . $backupnum . "]:" . PathStackToStr($pathstack) . ">";
	while (($line = fgets(STDIN)) !== false)
	{
		$line = trim($line);

		if ($line == "quit" || $line == "exit" || $line == "logout")  break;

		// Parse the command.
		$pos = strpos($line, " ");
		if ($pos === false)  $pos = strlen($line);
		$cmd = substr($line, 0, $pos);

		if ($cmd != "")
		{
			if (!function_exists("shell_cmd_" . $cmd))  echo "The shell command '" . $cmd . "' does not exist.\n";
			else
			{
				$cmd = "shell_cmd_" . $cmd;
				$cmd($line);
			}
		}

		echo $servicename . " [" . $backupnum . "]:" . PathStackToStr($pathstack) . ">";
	}
?>