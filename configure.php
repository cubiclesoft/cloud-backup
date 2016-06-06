<?php
	// Cloud-based backup configuration tool.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cb_functions.php";

	$config = CB_LoadConfig();

	if (!isset($config["blocksize"]))
	{
		echo "Welcome to the cloud-based backup configuration tool!\n\n";
		echo "It appears to be the first time you have launched this interactive utility.  Over the next few minutes, you will fill in some information about how you want your backup to function.  A configuration file will then be generated.  You can re-run this tool at any time or just make changes directly to the configuration file using a text editor.\n\n";
//		echo "There is also a useful YouTube video you can watch here:  ??????????\n\n";
		echo "Press 'enter' or 'return' to continue.";
		fgets(STDIN);
		echo "\n\n\n";
	}

	// To keep the configuration process fairly short, good defaults have been chosen here.
	if (!isset($config["blocksize"]))  $config["blocksize"] = 10485760;
	if (!isset($config["smallfilelimit"]))  $config["smallfilelimit"] = 1048576;
	if (!isset($config["backupretryrange"]))  $config["backupretryrange"] = 6 * 60 * 60;

	// Set the number of days to keep incrementals for.
	if (!isset($config["numincrementals"]))
	{
		echo "----------\n\n";
		echo "Set the number of incrementals to keep backups for.  This system keeps incremental backups of full files.  (No deltas!)  A good default here for most users is 31 where there will be a single nightly backup of changes.\n\n";
		echo "Number of incrementals to keep:  ";
		$numincrementals = (double)trim(fgets(STDIN));
		if ($numincrementals < 0)  $numincrementals = 0;

		$config["numincrementals"] = $numincrementals;

		CB_SaveConfig($config);

		echo "Number of incrementals written to configuration.\n\n";
	}

	// Set the data upload limit.
	if (!isset($config["uploaddatalimit"]))
	{
		echo "----------\n\n";
		echo "Set the data upload limit (in bytes).  This limits the amount of data to upload per backup run, which can be useful for annoying (and illegal) data caps.  Calculate your ideal limit with this formula:\n\n";
		echo "The lesser of (Monthly data cap in bytes / 31 * percent of the data cap to use for backups) OR (upload speed in bytes per sec * number of seconds to run the backup).\n\n";
		echo "Data upload limit in bytes:  ";
		$limit = (double)trim(fgets(STDIN));
		if ($limit < 0)  $limit = 0;

		$config["uploaddatalimit"] = $limit;

		CB_SaveConfig($config);

		echo "Upload data limit written to configuration.\n\n";
	}

	// Create the backup encryption key if it doesn't exist.
	if (!isset($config["encryption_key"]))
	{
		echo "----------\n\n";

		$rng = new CSPRNG(true);
		$data = array(
			"key1" => bin2hex($rng->GetBytes(32)),
			"iv1" => bin2hex($rng->GetBytes(16)),
			"key2" => bin2hex($rng->GetBytes(32)),
			"iv2" => bin2hex($rng->GetBytes(16)),
			"sign" => bin2hex($rng->GetBytes(20))
		);

		$config["encryption_key"] = $data;

		CB_SaveConfig($config);

		echo "New encryption key written to configuration.\n\n";
	}

	// Set up cloud service credentials/paths/etc.
	if (!isset($config["service_info"]))
	{
		$services = CB_GetBackupServices();

		echo "----------\n\n";
		echo "Available backup services:\n\n";
		foreach ($services as $key => $displayname)
		{
			echo "\t" . $key . ":  " . $displayname . "\n";
		}

		echo "\n";
		do
		{
			echo "Backup service:  ";
			$servicename = strtolower(trim(fgets(STDIN)));
			if (!isset($services[$servicename]))  echo "Error:  Invalid service name entered.\n";
		} while (!isset($services[$servicename]));

		$config["service_info"] = array(
			"service" => $servicename
		);

		$servicehelper = new CB_ServiceHelper();

		$result = $servicehelper->InitService($config);

		$service = $result["service"];

		$serviceopts = $service->GetInitOptionKeys();

		do
		{
			$options = array();
			echo "\n";
			foreach ($serviceopts as $key => $opt)
			{
				echo "[" . $services[$servicename] . "] Enter " . $opt . ":  ";
				$options[$key] = trim(fgets(STDIN));
			}

			$service->Init($options, $servicehelper);
			$result = $service->Test();
			if (!$result["success"])  CB_DisplayError("The backup service access test failed.  Try entering the information again.", $result, false);
		} while (!$result["success"]);

		$config["service_info"] = array(
			"service" => $servicename,
			"options" => $options
		);

		CB_SaveConfig($config);

		echo "Backup service information written to configuration.\n\n";
	}

	function SetupPaths($configkey, $pathsstr, $checkpaths = true)
	{
		global $config, $rootpath;

		do
		{
			if (count($config[$configkey]))
			{
				echo $pathsstr . ":\n\n";
				foreach ($config[$configkey] as $num => $path)
				{
					echo "\t[" . ($num + 1) . "] " . $path . "\n";
				}

				echo "\n";
			}

			echo "Command:  ";
			$cmd = trim(fgets(STDIN));
			$cmd2 = explode(" ", $cmd, 2);
			if (count($cmd2) == 2)
			{
				if (strtolower($cmd2[0]) === "add")
				{
					$path = ($checkpaths ? @realpath($cmd2[1]) : $cmd2[1]);
					if ($path !== false && $checkpaths && is_dir($path))  $config[$configkey][] = $path;
					else if ($path !== false && !$checkpaths && file_exists($path))  $config[$configkey][] = $path;
					else  echo "The path '" . ($path !== false ? $path : $cmd2[1]) . "' does not exist.\n";
				}
				else if (strtolower($cmd2[0]) === "remove")
				{
					unset($config[$configkey][(int)$cmd2[1] - 1]);
					$config[$configkey] = array_values($config[$configkey]);
				}
			}

			echo "\n";

		} while ($cmd !== "");

		CB_SaveConfig($config);
	}

	// Set up the local system paths/files to back up.
	if (!isset($config["backup_paths"]))  $config["backup_paths"] = array();
	echo "----------\n\n";
	echo "Adjust backup paths/files.  Leave 'Command' empty to go to exclusion configuration.  Example commands:\n";
	echo "\tadd /etc\n";
	echo "\tadd /var/www\n";
	echo "\tremove 4\n\n";
	SetupPaths("backup_paths", "Current paths being backed up");
	echo "Backup paths/files written to configuration.\n\n";

	// Set up local system path/file exclusions.  Certain files in $rootpath are always implicitly excluded.
	if (!isset($config["backup_exclusions"]))  $config["backup_exclusions"] = array();
	echo "----------\n\n";
	echo "Adjust backup path/file exclusions.  Leave 'Command' empty to go to monitored files.  Example commands:\n";
	echo "\tadd /var/log\n";
	echo "\tremove 3\n\n";
	SetupPaths("backup_exclusions", "Current paths being excluded from the backup");
	echo "Backup exclusions written to configuration.\n\n";

	// Set up local system honeypot.  Lightweight malware defense system.
	if (!isset($config["monitor"]))  $config["monitor"] = array();
	if (!isset($config["monitor_hashes"]))  $config["monitor_hashes"] = array();
	echo "----------\n\n";
	echo "Add the full path and filename of several files you will never make changes to or delete.  These don't even have to be files that you are backing up.  If the files ever change, any attempt to run the backup will fail.  In technical parlance, this is called a Honeypot.  Leave 'Command' empty to go to pre-backup commands.  Example commands:\n";
	echo "\tadd /home/youruser/somephoto.jpg\n";
	echo "\tadd /home/youruser/taxdocument.pdf\n";
	echo "\tadd /home/youruser/downloadedfile.zip\n";
	echo "\tremove 6\n\n";
	SetupPaths("monitor", "Current files being monitored", false);
	echo "Monitored files written to configuration.\n\n";

	// Set up commands to execute before running the backup.
	if (!isset($config["prebackup_commands"]))  $config["prebackup_commands"] = array();
	echo "----------\n\n";
	echo "Adjust pre-backup commands.  Leave 'Command' empty to go to notification configuration.  Example commands:\n";
	echo "\tadd /usr/bin/mysqldump -u root -pSomepassword thedatabase > /var/database/thedatabase.sql\n";
	echo "\tremove 5\n\n";
	SetupPaths("prebackup_commands", "Current commands being executed before the backup", false);
	echo "Pre-backup commands written to configuration.\n\n";

	// Set up notifications.
	if (!isset($config["notifications"]))  $config["notifications"] = array();
	echo "----------\n\n";
	echo "Adjust notifications.  Leave 'Command' empty to complete the configuration.  Example commands:\n";
	echo "\tadd\n";
	echo "\taddrecipient 1 someone@destination.com\n";
	echo "\tremoverecipient 1 3\n";
	echo "\tremove 1\n\n";
	do
	{
		if (count($config["notifications"]))
		{
			echo "Current notifications:\n\n";
			foreach ($config["notifications"] as $num => $notification)
			{
				echo "\t[" . ($num + 1) . "] " . $notification["from"] . " via " . ($notification["usemail"] ? "PHP mail()" : $notification["server"]) . "\n";
				echo "\t\tFilter:  " . $notification["filter"] . "\n";
				echo "\t\tSubject:  " . $notification["subject"] . "\n";
				echo "\t\tRecipients:\n";
				foreach ($notification["recipients"] as $num2 => $recipient)
				{
					echo "\t\t\t[" . ($num2 + 1) . "] " . $recipient . "\n";
				}
			}

			echo "\n";
		}

		echo "Command:  ";
		$cmd = trim(fgets(STDIN));
		$cmd2 = explode(" ", $cmd);
		if (strtolower($cmd2[0]) === "add")
		{
			echo "Filter (regular expression to apply to each backup line - optional):  ";
			$filter = trim(fgets(STDIN));
			echo "FROM e-mail address:  ";
			$from = trim(fgets(STDIN));
			echo "Subject line (e.g. [Backup] Some computer name):  ";
			$subject = trim(fgets(STDIN));
			echo "PHP mail() command (Y/N):  ";
			$usemail = (substr(strtoupper(trim(fgets(STDIN))), 0, 1) == "Y");
			if ($usemail)
			{
				$server = "";
				$port = 25;
				$secure = false;
				$username = "";
				$password = "";
			}
			else
			{
				echo "SMTP server (without port):  ";
				$server = trim(fgets(STDIN));
				echo "SMTP port (usually 25, 465, or 587):  ";
				$port = (int)trim(fgets(STDIN));
				echo "SMTP over SSL/TLS (Y/N):  ";
				$secure = (substr(strtoupper(trim(fgets(STDIN))), 0, 1) == "Y");
				echo "SMTP username (optional):  ";
				$username = trim(fgets(STDIN));
				echo "SMTP password (optional):  ";
				$password = trim(fgets(STDIN));
			}

			$data = array(
				"filter" => $filter,
				"from" => $from,
				"subject" => $subject,
				"usemail" => $usemail,
				"server" => $server,
				"port" => $port,
				"secure" => $secure,
				"username" => $username,
				"password" => $password,
				"recipients" => array()
			);

			$config["notifications"][] = $data;
		}
		else if (count($cmd2) == 3 && strtolower($cmd2[0]) === "addrecipient")
		{
			if (isset($config["notifications"][(int)$cmd2[1] - 1]))  $config["notifications"][(int)$cmd2[1] - 1]["recipients"][] = $cmd2[2];
		}
		else if (count($cmd2) == 3 && strtolower($cmd2[0]) === "removerecipient")
		{
			unset($config["notifications"][(int)$cmd2[1] - 1]["recipients"][(int)$cmd2[2] - 1]);
			$config["notifications"] = json_decode(json_encode($config["notifications"]), true);
		}
		else if (count($cmd2) == 2 && strtolower($cmd2[0]) === "remove")
		{
			unset($config["notifications"][(int)$cmd2[1] - 1]);
			$config["notifications"] = array_values($config["notifications"]);
		}

		echo "\n";

	} while ($cmd !== "");

	CB_SaveConfig($config);

	echo "Notification setup written to configuration.\n\n";

	echo "**********\n";
	echo "Configuration file is located at '" . $rootpath . "/config.dat'.\n\n";
	echo "Please make a copy of the file and store it somewhere safe.  It contains your encryption keys.  Without that file, backups are not able to be decrypted.\n\n";
	echo "Now you can run 'backup.php' to perform a backup with the new configuration.\n";
	echo "**********\n\n";

	echo "Done.\n";
?>