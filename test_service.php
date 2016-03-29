<?php
	// Cloud-based backup service testing tool.
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

	echo "Initializing service...\n";
	$servicehelper = new CB_ServiceHelper();
	$result = $servicehelper->InitService($config);

	$servicename = $result["servicename"];
	$service = $result["service"];

	echo "Testing " . $servicename . "...\n";
	$service->Test();

	echo "Done.\n";
?>