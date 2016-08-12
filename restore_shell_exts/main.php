<?php
	// Cloud-based backup restoration tool basic shell functions.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	function shell_cmd_ls($line)
	{
		if (is_array($line))  $args = $line;
		else
		{
			$options = array(
				"shortmap" => array(
					"a" => "all",
					"b" => "blocks",
					"l" => "long",
					"p" => "percentages",
					"r" => "regex",
					"R" => "recursive",
					"?" => "help"
				),
				"rules" => array(
					"all" => array("arg" => false),
					"blocks" => array("arg" => false),
					"long" => array("arg" => false),
					"percentages" => array("arg" => false),
					"regex" => array("arg" => true, "multiple" => true),
					"recursive" => array("arg" => false),
					"help" => array("arg" => false)
				)
			);
			$args = ParseCommandLine($options, $line);

			$args["origopts"] = $args["opts"];
		}

		if (count($args["params"]) > 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - List directory command\n";
			echo "Purpose:  Display a directory listing.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] [path]\n";
			echo "Options:\n";
			echo "\t-a         All files and directories.\n";
			echo "\t-b         Include physical block storage (implies -l).\n";
			echo "\t-l         Long listing format.\n";
			echo "\t-p         Percent of original file size (implies -l).\n";
			echo "\t-r=regex   Regular expression match.\n";
			echo "\t-R         Recursive scan.\n";
			echo "\t-?         This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " -laR -r=/[.]php\$/ /\n";

			return;
		}

		$path = (count($args["params"]) ? $args["params"][0] : "");
		$result = CalculateRealpath($path);
		if (!$result["success"])
		{
			CB_DisplayError("Unable to calculate path for '" . $path . "'.", $result, false);

			return;
		}
		$path = $result["path"];
		$dirinfo = $result["dir"][count($result["dir"]) - 1];
		$id = $dirinfo["id"];

		if (!isset($dirinfo["file"]))  $dirfiles = CB_GetDBFiles($id, false);
		else
		{
			// Handle extraction of a single file.
			$row = $dirinfo["file"];

			$dirfiles = array();
			$dirfiles[$row->name] = array(
				"id" => $row->id,
				"blocknum" => $row->blocknum,
				"sharedblock" => (int)$row->sharedblock,
				"name" => $row->name,
				"symlink" => $row->symlink,
				"attributes" => (int)$row->attributes,
				"owner" => $row->owner,
				"group" => $row->group,
				"filesize" => $row->realfilesize,
				"compressedsize" => $row->compressedsize,
				"lastmodified" => $row->lastmodified,
				"created" => $row->created,
			);

			$id = $row->pid;
		}

		if (!isset($args["opts"]["regex"]))  $args["opts"]["regex"] = array('/.*/');

		$blocks = isset($args["opts"]["blocks"]);
		$percentages = isset($args["opts"]["percentages"]);

		if ($blocks || $percentages)  $args["opts"]["long"] = true;

		// Calculate column widths.
		if (isset($args["opts"]["long"]))
		{
			$maxowner = 0;
			$maxgroup = 0;
			$maxfullsize = 0;
			$maxblocknumsize = 0;
			$maxpercentagesize = 0;
			foreach ($dirfiles as $name => $info)
			{
				if (isset($args["opts"]["all"]) || substr($name, 0, 1) != ".")
				{
					foreach ($args["opts"]["regex"] as $pattern)
					{
						if (preg_match($pattern, $name))
						{
							if (strlen($info["owner"]) > $maxowner)  $maxowner = strlen($info["owner"]);
							if (strlen($info["group"]) > $maxgroup)  $maxgroup = strlen($info["group"]);

							$fullsize = number_format($info["filesize"], 0);
							if (strlen($fullsize) > $maxfullsize)  $maxfullsize = strlen($fullsize);

							if ($blocks && strlen($info["blocknum"]) > $maxblocknumsize)  $maxblocknumsize = strlen($info["blocknum"]);

							if ($percentages)
							{
								$percentsize = strlen($info["blocknum"] > 0 ? number_format(($info["compressedsize"] + 12) / $info["filesize"] * 100, 0) . "%" : "-");
								if ($percentsize > $maxpercentagesize)  $maxpercentagesize = $percentsize;
							}

							break;
						}
					}
				}
			}
		}

		$output = false;
		if (isset($args["opts"]["recursive"]) && !isset($args["origopts"]["regex"]))
		{
			echo $path . "\n";

			$output = true;
		}
		foreach ($dirfiles as $name => $info)
		{
			if (isset($args["opts"]["all"]) || substr($name, 0, 1) != ".")
			{
				$found = false;

				foreach ($args["opts"]["regex"] as $pattern)
				{
					if (preg_match($pattern, $name))
					{
						$found = true;

						break;
					}
				}

				if ($found)
				{
					if (!$output && isset($args["opts"]["recursive"]))
					{
						echo $path . "\n";

						$output = true;
					}

					if (isset($args["opts"]["long"]))
					{
						// Attributes:  l/d i sst rwx rwx rwx
						$symlink = ($info["symlink"] !== "");
						$permflags = $info["attributes"];

						if ($symlink)  $attr = "l";
						else if (CB_IsDir($info["attributes"]))  $attr = "d";
						else if ($info["sharedblock"])  $attr = "i";
						else  $attr = "-";

						$attr .= ($symlink || $info["attributes"] & 0000400 ? "r" : "-");
						$attr .= ($symlink || $info["attributes"] & 0000200 ? "w" : "-");
						if ($info["attributes"] & 0004000)  $attr .= "s";
						else if ($symlink || $info["attributes"] & 0000100)  $attr .= "x";
						else  $attr .= "-";

						$attr .= ($symlink || $info["attributes"] & 0000040 ? "r" : "-");
						$attr .= ($symlink || $info["attributes"] & 0000020 ? "w" : "-");
						if ($info["attributes"] & 0002000)  $attr .= "s";
						else if ($symlink || $info["attributes"] & 0000010)  $attr .= "x";
						else  $attr .= "-";

						$attr .= ($symlink || $info["attributes"] & 0000004 ? "r" : "-");
						$attr .= ($symlink || $info["attributes"] & 0000002 ? "w" : "-");
						if ($info["attributes"] & 0001000)  $attr .= "t";
						else if ($symlink || $info["attributes"] & 0000001)  $attr .= "x";
						else  $attr .= "-";

						// Output:  Attributes Owner Group Created Filesize[ Blocknum]
						echo $attr . " " . sprintf("%-" . $maxowner . "s", $info["owner"]) . " " . sprintf("%-" . $maxgroup . "s", $info["group"]) . " " . sprintf("%" . $maxfullsize . "s", number_format($info["filesize"], 0)) . ($percentages ? " " . sprintf("%" . $maxpercentagesize . "s", ($info["blocknum"] > 0 ? number_format(($info["compressedsize"] + 12) / $info["filesize"] * 100, 0) . "%" : "-")) : "") . ($blocks ? " " . sprintf("%" . $maxblocknumsize . "s", $info["blocknum"]) : "") . " " . date("Y-M-d h:i A", $info["created"]) . "  ";
					}

					echo $name;

					if (isset($args["opts"]["long"]) && $info["symlink"] !== "")  echo " -> " . $info["symlink"];

					echo "\n";
				}
			}
		}

		if ($output)  echo "\n";

		if (isset($args["opts"]["recursive"]))
		{
			foreach ($dirfiles as $name => $info)
			{
				if ($info["symlink"] === "" && CB_IsDir($info["attributes"]))
				{
					$args["params"][0] = $path . $name;
					shell_cmd_ls($args);
				}
			}
		}
	}

	function shell_cmd_dir($line)
	{
		shell_cmd_ls($line);
	}

	function shell_cmd_cd($line)
	{
		global $pathstack;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Change directory command\n";
			echo "Purpose:  Change to another directory.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] path\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " /etc\n";

			return;
		}

		$path = $args["params"][0];

		$result = CalculateRealpath($path);
		if (!$result["success"])
		{
			CB_DisplayError("Unable to calculate path for '" . $path . "'.", $result, false);

			return;
		}

		$dirinfo = $result["dir"][count($result["dir"]) - 1];

		if (isset($dirinfo["file"]))
		{
			CB_DisplayError("The specified path '" . $path . "' is a file.", false, false);

			return;
		}

		$pathstack = $result["dir"];
	}

	function shell_cmd_chdir($line)
	{
		shell_cmd_cd($line);
	}

	function shell_cmd_stats($line)
	{
		global $servicehelper;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) > 0 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Stats command\n";
			echo "Purpose:  Display database-wide statistics.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options]\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . "\n";

			return;
		}

		$servicehelper->DisplayStats("");

		echo "\n";
	}

	function shell_cmd_users($line)
	{
		global $db;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) > 0 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Users command\n";
			echo "Purpose:  Display information about users/owners in the files database and whether or not those users exist in the host system.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options]\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . "\n";

			return;
		}

		if (!function_exists("posix_getpwnam"))  echo "[Notice] The PHP function posix_getpwnam() does not exist.  The POSIX PHP extension is not enabled or not available for this OS.\n";

		$result = $db->Query("SELECT", array(
			"DISTINCT owner",
			"FROM" => "?",
			"ORDER BY" => "owner"
		), "files");

		while ($row = $result->NextRow())
		{
			echo $row->owner . " - ";

			if (!function_exists("posix_getpwnam"))  echo "[Missing]\n";
			else
			{
				$user = @posix_getpwnam($row->owner);
				if ($user === false || !is_array($user))  echo "[Missing]\n";
				else  echo "Found (" . $user["uid"] . ")\n";
			}
		}
	}

	function shell_cmd_mapuser($line)
	{
		global $db;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Map user command\n";
			echo "Purpose:  Changes all instances of one user into another.  Only affects the cached copy of the files database.  Useful for adjusting users to the host prior to a restore.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] srcuser destuser\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " www www-data\n";

			return;
		}

		$db->Query("UPDATE", array("files", array(
			"owner" => $args["params"][1],
		), "WHERE" => "owner = ?"), $args["params"][0]);
	}

	function shell_cmd_groups($line)
	{
		global $db;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) > 0 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Groups command\n";
			echo "Purpose:  Display information about groups in the files database and whether or not those groups exist in the host system.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options]\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . "\n";

			return;
		}

		if (!function_exists("posix_getgrnam"))  echo "[Notice] The PHP function posix_getgrnam() does not exist.  The POSIX PHP extension is not enabled or not available for this OS.\n";

		$result = $db->Query("SELECT", array(
			"DISTINCT " . $db->QuoteIdentifier("group"),
			"FROM" => "?",
			"ORDER BY" => $db->QuoteIdentifier("group")
		), "files");

		while ($row = $result->NextRow())
		{
			echo $row->group . " - ";

			if (!function_exists("posix_getgrnam"))  echo "[Missing]\n";
			else
			{
				$group = @posix_getgrnam($row->group);
				if ($group === false || !is_array($group))  echo "[Missing]\n";
				else  echo "Found (" . $group["gid"] . ")\n";
			}
		}
	}

	function shell_cmd_mapgroup($line)
	{
		global $db;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Map group command\n";
			echo "Purpose:  Changes all instances of one group into another.  Only affects the cached copy of the files database.  Useful for adjusting groups to the host prior to a restore.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] srcgroup destgroup\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " www www-data\n";

			return;
		}

		$db->Query("UPDATE", array("files", array(
			"group" => $args["params"][1],
		), "WHERE" => $db->QuoteIdentifier("group") . " = ?"), $args["params"][0]);
	}

	function shell_cmd_restore($line)
	{
		global $rootpath, $blocklist, $servicehelper, $config, $cb_messages;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) > 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Restore command\n";
			echo "Purpose:  Restores a single file or directory from the backup to a temporary directory.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] [path]\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " /\n";

			return;
		}

		$path = (count($args["params"]) ? $args["params"][0] : "");
		$result = CalculateRealpath($path);
		if (!$result["success"])
		{
			CB_DisplayError("Unable to calculate path for '" . $path . "'.", $result, false);

			return;
		}
		$path = $result["path"];
		$dirinfo = $result["dir"][count($result["dir"]) - 1];
		$id = $dirinfo["id"];

		// Create the restoration directory.
		$basepath = $rootpath . "/restore";
		@mkdir($basepath);
		$basepath .= "/" . date("Ymd_His");
		@mkdir($basepath);

		if (!isset($dirinfo["file"]))  $dirfiles = CB_GetDBFiles($id, false);
		else
		{
			// Handle extraction of a single file.
			$row = $dirinfo["file"];

			$dirfiles = array();
			$dirfiles[$row->name] = array(
				"id" => $row->id,
				"blocknum" => $row->blocknum,
				"sharedblock" => (int)$row->sharedblock,
				"name" => $row->name,
				"symlink" => $row->symlink,
				"attributes" => (int)$row->attributes,
				"owner" => $row->owner,
				"group" => $row->group,
				"filesize" => $row->realfilesize,
				"lastmodified" => $row->lastmodified,
				"created" => $row->created,
			);

			$id = $row->pid;
		}
		$stack = array(array("pid" => $id, "path" => $basepath, "files" => $dirfiles));
		$sharedcache = array();
		while (count($stack))
		{
			$pos = count($stack) - 1;
			$pid = $stack[$pos]["pid"];
			$path = $stack[$pos]["path"];

			if (count($stack[$pos]["files"]))
			{
				$info = array_shift($stack[$pos]["files"]);

				$dirfile = $path . "/" . $info["name"];

				if (file_exists($dirfile))
				{
					CB_DisplayError("[Error] File '" . $dirfile . "' already exists.  Skipping.", false, false);

					continue;
				}

				if ($info["symlink"] !== "")
				{
					if (@symlink($info["symlink"], $dirfile) === false)
					{
						CB_DisplayError("[Error] Unable to create symbolic link '" . $dirfile . "' -> '" . $info["symlink"] . "'.", false, false);

						continue;
					}
				}
				else if (CB_IsDir($info["attributes"]))
				{
					if (@mkdir($dirfile) === false)
					{
						CB_DisplayError("[Error] Unable to create directory '" . $dirfile . "'.", false, false);

						continue;
					}

					$dirfiles = CB_GetDBFiles($info["id"], false);
					$stack[] = array("pid" => $info["id"], "path" => $dirfile, "files" => $dirfiles);
				}
				else if ($info["blocknum"] < 10)
				{
					// Zero byte file.
					file_put_contents($dirfile, "");
				}
				else if (!isset($blocklist[$info["blocknum"]]))
				{
					CB_DisplayError("[Error] Backup is missing block number " . $info["blocknum"] . " for file '" . $dirfile . "'.  Backup is corrupt.", false, false);

					continue;
				}
				else if ($info["sharedblock"])
				{
					CB_Log("[Retrieve] " . $dirfile);

					$x = $blocklist[$info["blocknum"]]["inc"];
					$filename = $rootpath . "/cache/restore_" . $x . "_" . $info["blocknum"] . ".dat";
					if (!file_exists($filename))
					{
						echo "\tRetrieving shared block " . $info["blocknum"] . "...\n";

						$servicehelper->DownloadFile($filename, $x, $info["blocknum"], $blocklist[$info["blocknum"]]["parts"], true);
					}

					// Load the index.
					if (!isset($sharedcache[$info["blocknum"]]))
					{
						if (count($sharedcache) >= 10)
						{
							foreach ($sharedcache as $info2)  fclose($info2["fp"]);

							$sharedcache = array();
						}

						$sharedcache[$info["blocknum"]] = array(
							"fp" => fopen($filename, "rb"),
							"index" => unserialize(file_get_contents($filename . ".idx"))
						);
					}

					if (!isset($sharedcache[$info["blocknum"]]["index"][$info["id"]]))
					{
						CB_DisplayError("[Error] Shared block " . $info["blocknum"] . " is missing ID " . $info["id"] . " for file '" . $dirfile . "'.  Backup is corrupt.", false, false);

						continue;
					}

					$servicehelper->ExtractSharedFile($dirfile, $sharedcache[$info["blocknum"]]["fp"], $sharedcache[$info["blocknum"]]["index"][$info["id"]]);
				}
				else
				{
					CB_Log("[Retrieve] " . $dirfile);

					$servicehelper->DownloadFile($dirfile, $blocklist[$info["blocknum"]]["inc"], $info["blocknum"], $blocklist[$info["blocknum"]]["parts"]);
				}

				// Adjust the symlink/directory/file to mirror the database.
				@chmod($dirfile, $info["attributes"] & 07777);
				if ($info["owner"] !== "")  @chown($dirfile, $info["owner"]);
				if ($info["group"] !== "")  @chgrp($dirfile, $info["group"]);
				@touch($dirfile, $info["lastmodified"]);
			}
			else
			{
				array_pop($stack);
			}

			if (count($config["notifications"]) && count($cb_messages) >= 25000)
			{
				echo "Sending notifications...\n";
				CB_SendNotifications($config["notifications"]);
			}
		}

		foreach ($sharedcache as $info2)  fclose($info2["fp"]);
	}

	function shell_cmd_help($line)
	{
		echo "help - List available shell functions\n";
		echo "\n";
		echo "Functions:\n";

		$functions = array("quit", "exit");

		$result = get_defined_functions();
		if (isset($result["user"]))
		{
			foreach ($result["user"] as $name)
			{
				if (strtolower(substr($name, 0, 10)) == "shell_cmd_")  $functions[] = substr($name, 10);
			}
		}

		sort($functions);
		foreach ($functions as $name)  echo "\t" . $name . "\n";

		echo "\n";
	}
?>