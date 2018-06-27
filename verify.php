<?php
	// Cloud-based backup verification tool.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cb_functions.php";

	function CleanupCache()
	{
		global $rootpath;

		$dir = @opendir($rootpath . "/cache");
		if ($dir)
		{
			while (($file = readdir($dir)) !== false)
			{
				if (substr($file, 0, 7) === "verify_")  @unlink($rootpath . "/cache/" . $file);
			}

			closedir($dir);
		}
	}

	@mkdir($rootpath . "/cache");

	// Clean up the cache.
	CleanupCache();

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

	echo "Last backup ID:  " . $lastbackupid . "\n";

	// Verify that the base and incrementals look good.
	echo "Verifying incrementals...\n";
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

	require_once $rootpath . "/support/db.php";
	require_once $rootpath . "/support/db_sqlite.php";

	$db = new CSDB_sqlite();

	// Retrieve all block lists.
	$backups = array();
	$backups[0] = true;
	$backups[(int)($y / 2)] = true;
	$backups[$y - 1] = true;

	$blocklist = array();
	for ($x = 0; $x < $y; $x++)
	{
		echo "Retrieving block list for " . ($x ? "incremental " : "base ") . $x . "...\n";

		$result = $service->GetIncrementalBlockList($x);
		if (!$result["success"])  CB_DisplayError("Unable to retrieve block list for the " . ($x ? "incremental " : "base ") . $x . ".", $result);

		$blocklist2 = $result["blocklist"];

		unset($blocklist[0]);
		unset($blocklist[1]);

		$conflicts = 0;
		foreach ($blocklist2 as $key => $val)
		{
			if (isset($blocklist[$key]))
			{
				CB_DisplayError("[Error] Backup " . $x . " already has block number " . $key . ".", false, false);
				$conflicts++;
			}

			$blocklist[$key] = array("inc" => $x, "parts" => $val);

			@unlink($rootpath . "/cache/verify_" . $key . ".dat");
		}

		if ($conflicts)  CB_DisplayError("Backup " . $x . " has " . ($conflicts == 1 ? "1 block" : $conflicts . " blocks") . " conflicting.  Backup needs repair.");

		if (!isset($blocklist[0]))  CB_DisplayError("Missing the files database for the " . ($x ? "incremental " : "base ") . $x . ".  Backup needs repair.");
		if (!isset($blocklist[1]))  CB_DisplayError("Missing the deleted blocks list for the " . ($x ? "incremental " : "base ") . $x . ".  Backup needs repair.");

		if (isset($backups[$x]))
		{
			echo "Testing backup " . $x . "...\n";

			echo "\tRetrieving files database...\n";
			$dbfilename = $rootpath . "/cache/verify_files.db";
			@unlink($dbfilename);
			@unlink($dbfilename . "-journal");
			$servicehelper->DownloadFile($dbfilename, $blocklist[0]["inc"], 0, $blocklist[0]["parts"]);

			try
			{
				$db->Connect("sqlite:" . $dbfilename);
			}
			catch (Exception $e)
			{
				CB_DisplayError("Unable to connect to SQLite database.  " . $e->getMessage());
			}

			$servicehelper->SetDB($db);

			try
			{
				// Statistics.
				echo "\tRetrieving files database statistics...\n";
				$servicehelper->DisplayStats("\t");

				// Verify that all blocks in the database are in the block list.
				echo "\tRetrieving all unique blocks in the database...\n";
				$result = $db->Query("SELECT", array(
					"DISTINCT blocknum",
					"FROM" => "?"
				), "files");

				echo "\tVerifying that all blocks in the database are in the block list...\n";
				$missing = 0;
				while ($row = $result->NextRow())
				{
					if (!isset($blocklist[$row->blocknum]))
					{
						CB_DisplayError("[Error] Backup " . $x . " is missing block number " . $row->blocknum . ".", false, false);
						$missing++;
					}
				}

				if ($missing)  CB_DisplayError("Backup " . $x . " is missing " . ($missing == 1 ? "1 block" : $missing . " blocks") . ".  Backup is corrupt.");

				// Select a random shared block.
				echo "\tTesting shared block retrieval...\n";
				$blocknum = (int)$db->GetOne("SELECT", array("blocknum", "FROM" => "?", "WHERE" => "blocknum > 0 AND sharedblock = 1", "ORDER BY" => "RANDOM()", "LIMIT" => 1), "files");
				if ($blocknum >= 10)
				{
					$filename = $rootpath . "/cache/verify_" . $blocknum . ".dat";
					if (!file_exists($filename))  $servicehelper->DownloadFile($filename, $blocklist[$blocknum]["inc"], $blocknum, $blocklist[$blocknum]["parts"], true);

					// Load the index.
					$index = unserialize(file_get_contents($filename . ".idx"));

					$result = $db->Query("SELECT", array(
						"id",
						"FROM" => "?",
						"WHERE" => "blocknum = ?"
					), "files", $blocknum);

					while ($row = $result->NextRow())
					{
						if (!isset($index[$row->id]))  CB_DisplayError("Shared block is missing ID " . $row->id . ".  Backup is corrupt.");
					}
				}

				// Select a random non-shared block.
				echo "\tTesting non-shared block retrieval (under ~30MB)...\n";
				$blocknum = (int)$db->GetOne("SELECT", array("blocknum", "FROM" => "?", "WHERE" => "blocknum > 0 AND sharedblock = 0 AND realfilesize < 30000000", "ORDER BY" => "RANDOM()", "LIMIT" => 1), "files");
				if ($blocknum >= 10)
				{
					$filename = $rootpath . "/cache/verify_" . $blocknum . ".dat";
					if (!file_exists($filename))  $servicehelper->DownloadFile($filename, $blocklist[$blocknum]["inc"], $blocknum, $blocklist[$blocknum]["parts"]);
				}
			}
			catch (Exception $e)
			{
				CB_DisplayError("An error occurred while running a SQL query.  " . $e->getMessage());
			}

			$db->Disconnect();
		}
	}

	// Clean up the cache.
	CleanupCache();

	echo "Done.  The backup appears to be good.\n";
?>