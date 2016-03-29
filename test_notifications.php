<?php
	// Cloud-based backup notification testing tool.
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

	// Ignore filters.
	foreach ($config["notifications"] as $num => $info)
	{
		$info["filter"] = "";

		$config["notifications"][$num] = $info;
	}

	echo "Queueing test notification...\n";
	CB_DisplayError("[Test] This is a test.", false, false);

	echo "Sending notifications...\n";
	CB_SendNotifications($config["notifications"]);

	echo "Done.\n";
?>