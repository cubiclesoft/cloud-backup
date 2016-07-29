<?php
	// Cloud-based backup tool.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cb_functions.php";
	require_once $rootpath . "/support/cli.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"d" => "defrag",
			"f" => "force",
			"p" => "skipprebackup",
			"?" => "help"
		),
		"rules" => array(
			"defrag" => array("arg" => false),
			"force" => array("arg" => false),
			"skipprebackup" => array("arg" => false),
			"help" => array("arg" => false)
		)
	);
	$args = ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "Cloud-based backup command-line tool\n";
		echo "Purpose:  Perform incremental backups to a cloud service provider.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options]\n";
		echo "Options:\n";
		echo "\t-d   Defragment shared blocks.\n";
		echo "\t-f   Bypass the most recent backup check and perform a backup anyway.\n";
		echo "\t-p   Skip running the pre-backup commands.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . " -f\n";
		echo "\tphp " . $args["file"] . " -p\n";

		exit();
	}

	// Load and initialize the service helper.
	echo "Initializing...\n";
	$config = CB_LoadConfig();

	// Terminate if the backup was run too recently.
	// Does not send an e-mail notification due to CB_ServiceHelper() not being initialized.
	if (!isset($args["opts"]["force"]) && file_exists($rootpath . "/files_id.dat") && filemtime($rootpath . "/files_id.dat") > time() - $config["backupretryrange"])  CB_DisplayError("The backup was run too recently.  Next backup can be run " . date("l, F j, Y @ g:i a", filemtime($rootpath . "/files_id.dat") + $config["backupretryrange"]) . ".  Use the -f option to force the backup to proceed anyway.");

	$servicehelper = new CB_ServiceHelper();
	$servicehelper->Init($config);

	// Verify that monitored files have not changed.
	foreach ($config["monitor"] as $filename)
	{
		if (!file_exists($filename))  CB_DisplayError("The monitored file '" . $filename . "' no longer exists.  Backup attempt terminated.");
		if (!is_file($filename))  CB_DisplayError("The monitored file '" . $filename . "' is not a file (probably a directory).  Backup attempt terminated.");

		$fp = @fopen($filename, "rb");
		if ($fp === false)  CB_DisplayError("The monitored file '" . $filename . "' was not able to be opened.  Backup attempt terminated.");

		// Calculate file hash.
		$hash = hash_init("sha256");
		do
		{
			$data = @fread($fp, 1048576);
			if ($data === false)  $data = "";
			hash_update($hash, $data);

		} while ($data !== "");
		$hash2 = hash_final($hash);
		fclose($fp);

		if (!isset($config["monitor_hashes"][$filename]))
		{
			CB_Log("[Monitor] First hash calculation of '" . $filename . "' resulted in a SHA256 hash value of '" . $hash2 . "'.");

			$config["monitor_hashes"][$filename] = $hash2;

			CB_SaveConfig($config);
		}
		else if ($config["monitor_hashes"][$filename] !== $hash2)
		{
			CB_DisplayError("The monitored file '" . $filename . "' is no longer the same.  Original hash was '" . $config["monitor_hashes"][$filename] . "'.  New hash is '" . $hash2 . "'.  Possible system compromise detected.  Backup attempt terminated.");
		}
	}

	// Run pre-backup commands.
	if (!isset($args["opts"]["skipprebackup"]))
	{
		foreach ($config["prebackup_commands"] as $cmd)
		{
			echo "Executing:  " . $cmd . "\n";
			system($cmd);
			echo "\n";
		}
	}

	// Initialize service access.
	$result = $servicehelper->StartService();

	$servicename = $result["servicename"];
	$service = $result["service"];
	$incrementals = $result["incrementals"];
	$lastbackupid = $result["summary"]["lastbackupid"];

	$services = CB_GetBackupServices();
	if (isset($services[$servicename]))  $servicename = $services[$servicename];

	// Merge down incrementals.
	while (count($incrementals) > $config["numincrementals"] + 1)
	{
		echo "Merging down one incremental (" . count($incrementals) . " > " . ($config["numincrementals"] + 1) . ")...\n";
		$result = $servicehelper->MergeDown($incrementals);

		$incrementals = $result["incrementals"];
	}

	// Clean up any local leftovers from the last run (e.g. early termination).
	echo "Connecting to latest file database...\n";
	@unlink($rootpath . "/files2.db-journal");
	@unlink($rootpath . "/files2.db");
	@unlink($rootpath . "/deleted.dat");

	require_once $rootpath . "/support/db.php";
	require_once $rootpath . "/support/db_sqlite.php";

	$db = new CSDB_sqlite();

	// Locate and use the latest files.db file (always block 0).
	if (file_exists($rootpath . "/files_id.dat") && $lastbackupid !== (int)file_get_contents($rootpath . "/files_id.dat"))
	{
		@unlink($rootpath . "/files.db");
		@unlink($rootpath . "/files_id.dat");
	}
	if (!file_exists($rootpath . "/files.db") && count($incrementals))  $servicehelper->DownloadFile($rootpath . "/files.db", max(array_keys($incrementals)), 0);

	try
	{
		$db->Connect("sqlite:" . $rootpath . "/files.db");
	}
	catch (Exception $e)
	{
		CB_DisplayError("Unable to connect to SQLite database.  " . $e->getMessage());
	}

	// Create database tables.
	if (!$db->TableExists("files"))
	{
		try
		{
			$db->Query("CREATE TABLE", array("files", array(
				"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
				"pid" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"blocknum" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"sharedblock" => array("INTEGER", 1, "UNSIGNED" => true, "NOT NULL" => true),
				"name" => array("STRING", 1, 255, "NOT NULL" => true),
				"symlink" => array("STRING", 2, "NOT NULL" => true),
				"attributes" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"owner" => array("STRING", 1, 255, "NOT NULL" => true),
				"group" => array("STRING", 1, 255, "NOT NULL" => true),
				"filesize" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"realfilesize" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"compressedsize" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"lastmodified" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"created" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"lastdatachange" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
			),
			array(
				array("KEY", array("pid"), "NAME" => "files_pid"),
				array("KEY", array("blocknum"), "NAME" => "files_blocknum"),
			)));
		}
		catch (Exception $e)
		{
			CB_DisplayError("Unable to create the database table 'files'.  " . $e->getMessage());
		}
	}

	// Now that the latest version is set up, copy it to a temporary file.
	$db->Disconnect();
	copy($rootpath . "/files.db", $rootpath . "/files2.db");

	try
	{
		$db->Connect("sqlite:" . $rootpath . "/files2.db");
	}
	catch (Exception $e)
	{
		CB_DisplayError("Unable to connect to SQLite database.  " . $e->getMessage());
	}

	echo "Starting backup...\n";
	$deletefp = fopen($rootpath . "/deleted.dat", "wb");
	if ($deletefp === false)  CB_DisplayError("Unable to create deleted block tracker file '" . $rootpath . "/deleted.dat'.");

	$servicehelper->SetDB($db);

	// Handle defragmentation mode.
	if (isset($args["opts"]["defrag"]))
	{
		// Find all shared blocks that are at least 2 times more empty than the small file limit and delete them.
		$result = $db->Query("SELECT", array(
			"blocknum, compressedsize",
			"FROM" => "?",
			"WHERE" => "blocknum > 0 AND sharedblock = 1",
			"ORDER BY" => "blocknum",
		), "files");

		$keepsize = $config["blocksize"] - ($config["smallfilelimit"] * 2);
		if ($keepsize < 0)  CB_DisplayError("The small file limit in the configuration prevents defragmentation.");
		$lastblocknum = false;
		while ($row = $result->NextRow())
		{
			if ($lastblocknum !== $row->blocknum)
			{
				if ($lastblocknum !== false && $size < $keepsize)
				{
					CB_Log("[Defrag] Remove block " . $lastblocknum);

					$db->Query("DELETE", array("files", "WHERE" => "blocknum = ?"), $lastblocknum);

					fwrite($deletefp, CB_PackInt64((double)$lastblocknum));
				}

				$lastblocknum = $row->blocknum;
				$size = 0;
			}

			// There is an 8 byte ID + 4 byte size overhead in a shared block entry.
			$size += $row->compressedsize + 12;
		}

		if ($lastblocknum !== false && $size < $keepsize)
		{
			CB_Log("[Defrag] Remove block " . $lastblocknum);

			$db->Query("DELETE", array("files", "WHERE" => "blocknum = ?"), $lastblocknum);

			fwrite($deletefp, CB_PackInt64((double)$lastblocknum));
		}
	}

	// Initialize exclusions.
	$exclusions = array();
	$exclusions[$rootpath . "/cache"] = true;
	$exclusions[$rootpath . "/config.dat"] = true;
	$exclusions[$rootpath . "/deleted.dat"] = true;
	$exclusions[$rootpath . "/files.db"] = true;
	$exclusions[$rootpath . "/files_id.dat"] = true;
	$exclusions[$rootpath . "/files2.db"] = true;
	$exclusions[$rootpath . "/files2.db-journal"] = true;
	$exclusions[$rootpath . "/support/cb_functions.php"] = true;
	foreach ($config["backup_exclusions"] as $path)
	{
		$path = realpath($path);
		if ($path !== false)  $exclusions[str_replace("\\", "/", $path)] = true;
	}

	// Start the service.
	$result = $service->StartBackup();
	if (!$result["success"])  CB_DisplayError("Starting " . $servicename . " backup failed.", $result);

	// Process each backup path.
	foreach ($config["backup_paths"] as $path)
	{
		try
		{
			// Start a database transaction.
			$db->BeginTransaction();

			// Create missing base path portions.  Only done one time with no updates later.
			$basepid = 0;
			$basepath = realpath($path);
			if ($basepath === false || !is_dir($basepath))
			{
				CB_DisplayError("[Notice] Unable to process '" . $path . "'.  Must be a valid directory.", false, false);

				continue;
			}
			$basepath = str_replace("\\", "/", $basepath);
			if (substr($basepath, -1) == "/")  $basepath = substr($basepath, 0, -1);
			$parts = explode("/", $basepath);
			$path2 = "";
			foreach ($parts as $part)
			{
				$path2 .= $part . "/";
				$pid = $db->GetOne("SELECT", array(
					"id",
					"FROM" => "?",
					"WHERE" => "pid = ? AND name = ?"
				), "files", $basepid, $part);

				if ($pid === false)
				{
					$info = stat($path2);
					if ($info !== false)
					{
						$db->Query("INSERT", array("files", array(
							"pid" => $basepid,
							"blocknum" => "0",
							"sharedblock" => "0",
							"name" => $part,
							"symlink" => "",
							"attributes" => $info["mode"],
							"owner" => CB_GetUserName($info["uid"]),
							"group" => CB_GetGroupName($info["gid"]),
							"filesize" => $info["size"],
							"realfilesize" => $info["size"],
							"compressedsize" => "0",
							"lastmodified" => $info["mtime"],
							"created" => $info["ctime"],
							"lastdatachange" => $info["mtime"],
						), "AUTO INCREMENT" => "id"));

						$pid = $db->GetInsertID();
					}
				}

				if ($pid === false)
				{
					$basepid = 0;

					break;
				}

				$basepid = $pid;
			}

			// Check for weird failures.
			if ($basepid === 0)
			{
				CB_DisplayError("[Notice] Unable to process '" . $basepath . "'.  Something about the path is broken.", false, false);

				continue;
			}

			// Initialize the tracking stack.
			$stack = array();
			$srcfiles = CB_GetDirFiles($basepath);
			$dbfiles = CB_GetDBFiles($basepid);
			$diff = CB_GetFilesDiff($dbfiles, $srcfiles);
			$stack[] = array("path" => $basepath, "pid" => $basepid, "diff" => $diff);
			while (count($stack))
			{
				$pos = count($stack) - 1;
				$pid = $stack[$pos]["pid"];
				$path = $stack[$pos]["path"];

				if (count($stack[$pos]["diff"]["remove"]))
				{
					$info = array_shift($stack[$pos]["diff"]["remove"]);

					if (isset($exclusions[$path]) || isset($exclusions[$path . "/" . $info["name"]]))  continue;

					CB_Log("[Remove] " . $path . "/" . $info["name"]);

					// Recursively remove directories and files.
					if (CB_IsDir($info["attributes"]))
					{
						$path2 = $path . "/" . $info["name"];
						$dbfiles = CB_GetDBFiles($info["id"]);
						$stack[] = array(
							"path" => $path2,
							"pid" => $info["id"],
							"diff" => array(
								"remove" => $dbfiles,
								"add" => array(),
								"update" => array(),
								"traverse" => array()
							)
						);
					}

					$db->Query("DELETE", array("files", "WHERE" => "id = ?"), $info["id"]);

					// Write block numbers to the packed deletion file.
					if ($info["blocknum"] !== "0")
					{
						if (!$info["sharedblock"])  $numleft = 0;
						else
						{
							$numleft = (int)$db->GetOne("SELECT", array(
								"COUNT(*)",
								"FROM" => "?",
								"WHERE" => "blocknum = ?",
							), "files", $info["blocknum"]);
						}

						if (!$numleft)  fwrite($deletefp, CB_PackInt64((double)$info["blocknum"]));
					}
				}
				else if (count($stack[$pos]["diff"]["traverse"]))
				{
					$info = array_shift($stack[$pos]["diff"]["traverse"]);

					if (isset($exclusions[$path]) || isset($exclusions[$path . "/" . $info["name"]]))  continue;

					$path2 = $path . "/" . $info["name"];
					$srcfiles = CB_GetDirFiles($path2);
					$dbfiles = CB_GetDBFiles($info["id"]);
					$diff = CB_GetFilesDiff($dbfiles, $srcfiles);
					$stack[] = array("path" => $path2, "pid" => $info["id"], "diff" => $diff);
				}
				else if (count($stack[$pos]["diff"]["add"]))
				{
					$info = array_shift($stack[$pos]["diff"]["add"]);

					if (isset($exclusions[$path]) || isset($exclusions[$path . "/" . $info["name"]]))  continue;

					CB_Log("[Add] " . $path . "/" . $info["name"]);

					$db->Query("INSERT", array("files", array(
						"pid" => $pid,
						"blocknum" => "0",
						"sharedblock" => "0",
						"name" => $info["name"],
						"symlink" => $info["symlink"],
						"attributes" => $info["attributes"],
						"owner" => $info["owner"],
						"group" => $info["group"],
						"filesize" => $info["filesize"],
						"realfilesize" => $info["filesize"],
						"compressedsize" => "0",
						"lastmodified" => $info["lastmodified"],
						"created" => $info["created"],
						"lastdatachange" => $info["lastmodified"],
					), "AUTO INCREMENT" => "id"));

					$id = $db->GetInsertID();

					if ($info["symlink"] !== "")
					{
					}
					else if (CB_IsDir($info["attributes"]))
					{
						$path2 = $path . "/" . $info["name"];
						$srcfiles = CB_GetDirFiles($path2);
						$stack[] = array(
							"path" => $path2,
							"pid" => $id,
							"diff" => array(
								"remove" => array(),
								"add" => $srcfiles,
								"update" => array(),
								"traverse" => array()
							)
						);
					}
					else
					{
						// Upload the file.
						$servicehelper->UploadFile($id, $path . "/" . $info["name"]);
					}
				}
				else if (count($stack[$pos]["diff"]["update"]))
				{
					$info = array_shift($stack[$pos]["diff"]["update"]);

					if (isset($exclusions[$path]) || isset($exclusions[$path . "/" . $info["name"]]))  continue;

					CB_Log("[Update] " . $path . "/" . $info["name"]);

					$db->Query("UPDATE", array("files", array(
						"blocknum" => (isset($info["orig_filesize"]) || isset($info["orig_lastmodified"]) ? "0" : $info["blocknum"]),
						"sharedblock" => (isset($info["orig_filesize"]) || isset($info["orig_lastmodified"]) ? "0" : $info["sharedblock"]),
						"name" => $info["name"],
						"symlink" => $info["symlink"],
						"attributes" => $info["attributes"],
						"owner" => $info["owner"],
						"group" => $info["group"],
						"filesize" => $info["filesize"],
						"compressedsize" => (isset($info["orig_filesize"]) || isset($info["orig_lastmodified"]) ? "0" : $info["compressedsize"]),
						"lastmodified" => $info["lastmodified"],
						"created" => $info["created"],
						"lastdatachange" => (isset($info["orig_filesize"]) && !isset($info["orig_lastmodified"]) ? time() : $info["lastmodified"]),
					), "WHERE" => "id = ?"), $info["id"]);

					// Write block numbers to the packed deletion file.
					if ($info["blocknum"] !== "0" && (isset($info["orig_filesize"]) || isset($info["orig_lastmodified"])))
					{
						if (!$info["sharedblock"])  $numleft = 0;
						else
						{
							$numleft = (int)$db->GetOne("SELECT", array(
								"COUNT(*)",
								"FROM" => "?",
								"WHERE" => "blocknum = ?",
							), "files", $info["blocknum"]);
						}

						if (!$numleft)  fwrite($deletefp, CB_PackInt64((double)$info["blocknum"]));
					}

					if ($info["symlink"] !== "")
					{
					}
					else if (CB_IsDir($info["attributes"]))
					{
						$path2 = $path . "/" . $info["name"];
						$srcfiles = CB_GetDirFiles($path2);
						$dbfiles = CB_GetDBFiles($info["id"]);
						$diff = CB_GetFilesDiff($dbfiles, $srcfiles);
						$stack[] = array("path" => $path2, "pid" => $info["id"], "diff" => $diff);
					}
					else if (isset($info["orig_filesize"]) || isset($info["orig_lastmodified"]))
					{
						// Upload the file.
						$servicehelper->UploadFile($info["id"], $path . "/" . $info["name"]);
					}
				}
				else
				{
					array_pop($stack);
				}

				if ($config["uploaddatalimit"] > 0 && $servicehelper->GetBytesSent() >= $config["uploaddatalimit"])  break;

				if (count($config["notifications"]) && count($cb_messages) >= 25000)
				{
					echo "Sending notifications...\n";
					CB_SendNotifications($config["notifications"]);
				}
			}

			// Commit the transaction.
			$db->Commit();
		}
		catch (Exception $e)
		{
			$db->Rollback();

			// This only aborts the current path.  Other paths might be fine.
			CB_DisplayError("[Error] An error occurred while processing the backup.  Backup of '" . $basepath . "' aborted.  " . $e->getMessage(), false, false);
		}

		if ($config["uploaddatalimit"] > 0 && $servicehelper->GetBytesSent() >= $config["uploaddatalimit"])  break;
	}

	// Upload shared block data.
	echo "Finalizing backup...\n";
	$servicehelper->UploadSharedData();

	fclose($deletefp);

	// Upload deleted block file.
	$servicehelper->UploadFile(0, $rootpath . "/deleted.dat", 1);

	// Upload file database.
	$db->Disconnect();
	$servicehelper->UploadFile(0, $rootpath . "/files2.db", 0);

	// Finalize the service side of things.
	$lastbackupid++;
	$result = $service->FinishBackup($lastbackupid);
	if (!$result["success"])  CB_DisplayError("Unable to finish the " . $servicename . " backup.", $result);

	$incrementals = $result["incrementals"];

	// Finalize local files.
	unlink($rootpath . "/deleted.dat");
	unlink($rootpath . "/files.db");
	rename($rootpath . "/files2.db", $rootpath . "/files.db");
	file_put_contents($rootpath . "/files_id.dat", (string)$lastbackupid);

	// Merge down incrementals.
	while (count($incrementals) > $config["numincrementals"] + 1)
	{
		echo "Merging down one incremental (" . count($incrementals) . " > " . ($config["numincrementals"] + 1) . ")...\n";
		$result = $servicehelper->MergeDown($incrementals);

		$incrementals = $result["incrementals"];
	}

	CB_Log("[Info] " . number_format($servicehelper->GetBytesSent(), 0) . " bytes sent.");

	if ($config["uploaddatalimit"] > 0 && $servicehelper->GetBytesSent() >= $config["uploaddatalimit"])  CB_Log("[Warning] Upload data limit reached.  Backup is incomplete.");

	if (count($config["notifications"]))
	{
		echo "Sending notifications...\n";
		CB_SendNotifications($config["notifications"]);
	}

	echo "Done.\n";
?>