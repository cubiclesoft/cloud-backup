<?php
	// Cloud-based backup service interface for the local computer system (e.g. external attached storage).
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	class CB_Service_local
	{
		private $options, $servicehelper, $remotebasefolder, $remotetempfolder, $remotemergefolder, $incrementals, $summary;

		public function GetInitOptionKeys()
		{
			$result = array(
				"path" => "Base path"
			);

			return $result;
		}

		public function Init(&$options, $servicehelper)
		{
			$path = $options["path"];
			$path = str_replace("\\", "/", $path);
			while (substr($path, -1) === "/")  $path = substr($path, 0, -1);
			$options["path"] = $path;

			$this->options = $options;
			$this->servicehelper = $servicehelper;
			$this->remotebasefolder = false;
			$this->incrementals = array();
			$this->summary = array();
			$this->remotetempfolder = false;
			$this->remotemergefolder = false;
		}

		public function BuildRemotePath()
		{
			$this->remotebasefolder = false;

			$path = $this->options["path"];
			if (!is_dir($path))
			{
				$result = @mkdir($path, 0777, true);
				if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to locate or create base path '%s' or path exists but is not a directory.", $path), "errorcode" => "invalid_path");
			}

			$path2 = @realpath($path);
			if ($path2 === false)  return array("success" => false, "error" => self::LTranslate("Unable to get the real path for '%s'.", $path), "errorcode" => "invalid_path");

			$this->remotebasefolder = $path2;

			return array("success" => true);
		}

		public function Test()
		{
			$result = $this->BuildRemotePath();

			return $result;
		}

		public function GetBackupInfo()
		{
			$result = $this->BuildRemotePath();
			if (!$result["success"])  return $result;

			// Determine what incrementals are available.
			$result = $this->GetFolderList($this->remotebasefolder);
			if (!$result["success"])  return $result;

			$this->incrementals = array();
			foreach ($result["folders"] as $name)
			{
				if ($name === "TEMP")
				{
					// Delete the TEMP folder.
					$result2 = $this->DeleteFolder($this->remotebasefolder . "/TEMP");
					if (!$result2["success"])  return $result2;
				}
				else
				{
					// Ignore MERGE and other non-essential directories.
					if (is_numeric($name) && preg_match('/^\d+$/', $name))  $this->incrementals[(int)$name] = $name;
				}
			}

			// Retrieve the summary information about the incrementals.  Used for determining later if retrieved, cached files are current.
			if (isset($result["files"]["summary.json"]))
			{
				$data = file_get_contents($this->remotebasefolder . "/summary.json");

				$this->summary = @json_decode($data, true);
				if (!is_array($this->summary))  $this->summary = array();
			}

			// Initialize summary.
			if (!isset($this->summary["incrementaltimes"]))  $this->summary["incrementaltimes"] = array();
			if (!isset($this->summary["lastbackupid"]))  $this->summary["lastbackupid"] = 0;
			if (!isset($this->summary["nextblock"]))  $this->summary["nextblock"] = -1;
			if (!isset($this->summary["mergeops"]))  $this->summary["mergeops"] = array();

			// Process remaining merge operations to restore backup stability.
			$result = $this->ProcessMergeOps();
			if (!$result["success"])  return $result;

			// Unset missing incrementals.
			foreach ($this->summary["incrementaltimes"] as $key => $val)
			{
				if (!isset($this->incrementals[$key]))  unset($this->summary["incrementaltimes"][$key]);
			}
			foreach ($this->incrementals as $key => $val)
			{
				if (!isset($this->summary["incrementaltimes"][$key]))  unset($this->incrementals[$key]);
			}

			ksort($this->incrementals);
			ksort($this->summary["incrementaltimes"]);

			return array("success" => true, "incrementals" => $this->incrementals, "summary" => $this->summary);
		}

		public function StartBackup()
		{
			$result = @mkdir($this->remotebasefolder . "/TEMP");
			if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to create base path '%s'.", $path), "errorcode" => "invalid_path");

			$this->remotetempfolder = $this->remotebasefolder . "/TEMP";

			return array("success" => true);
		}

		public function UploadBlock($blocknum, $part, $data)
		{
			$filename = $this->remotetempfolder . "/" . $blocknum . "_" . $part . ".dat";

			$result = @file_put_contents($filename, $data);
			if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to write data to '%s'.", $filename), "errorcode" => "invalid_filename");

			return array("success" => true);
		}

		public function SaveSummary($summary)
		{
			$filename = $this->remotebasefolder . "/summary.json";

			$result = @file_put_contents($filename, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to write data to '%s'.", $filename), "errorcode" => "invalid_filename");

			return array("success" => true);
		}

		public function FinishBackup($backupid, $nextblock)
		{
			$summary = $this->summary;
			$incrementals = $this->incrementals;

			$summary["incrementaltimes"][] = time();
			$summary["lastbackupid"] = $backupid;
			$summary["nextblock"] = $nextblock;

			// Move the TEMP folder to a new incremental.
			$result = @rename($this->remotetempfolder, $this->remotebasefolder . "/" . (string)count($incrementals));
			if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to rename 'TEMP' to '%s'.", (string)count($incrementals)), "errorcode" => "rename_failed");

			$incrementals[] = (string)count($incrementals);

			$result = $this->SaveSummary($summary);
			if (!$result["success"])  return $result;

			$this->summary = $summary;
			$this->incrementals = $incrementals;

			// Reset backup status.
			$this->remotetempfolder = false;

			return array("success" => true, "incrementals" => $this->incrementals, "summary" => $this->summary);
		}

		public function GetIncrementalBlockList($id)
		{
			if (isset($this->incrementals[$id]))  $id = $this->incrementals[$id];
			$result = $this->GetFolderList($this->remotebasefolder . "/" . $id);
			if (!$result["success"])  return $result;

			// Extract information.
			$blocklist = array();
			foreach ($result["files"] as $name)
			{
				if (preg_match('/^(\d+)_(\d+)\.dat$/', $name, $matches))
				{
					if (!isset($blocklist[$matches[1]]))  $blocklist[$matches[1]] = array();
					$blocklist[$matches[1]][$matches[2]] = array("pid" => $id, "name" => $name);
				}
			}

			return array("success" => true, "blocklist" => $blocklist);
		}

		public function DownloadBlock($info)
		{
			$filename = $this->remotebasefolder . "/" . $info["pid"] . "/" . $info["name"];

			$data = @file_get_contents($filename);
			if ($data === false)  return array("success" => false, "error" => self::LTranslate("Unable to read data from '%s'.", $filename), "errorcode" => "invalid_filename");

			return array("success" => true, "data" => $data);
		}

		public function StartMergeDown()
		{
			if (!is_dir($this->remotebasefolder . "/MERGE"))
			{
				$result = @mkdir($this->remotebasefolder . "/MERGE");
				if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to create base path '%s'.", $path), "errorcode" => "invalid_path");
			}

			$this->remotemergefolder = $this->remotebasefolder . "/MERGE";

			return array("success" => true);
		}

		public function MoveBlockIntoMergeBackup($blocknum, $parts)
		{
			foreach ($parts as $part)
			{
				$srcfilename = $this->remotebasefolder . "/" . $part["pid"] . "/" . $part["name"];
				$destfilename = $this->remotemergefolder . "/" . $part["name"];

				$result = @rename($srcfilename, $destfilename);
				if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to move '%s' to '%s'.", $srcfilename, $destfilename), "errorcode" => "rename_failed");
			}

			return array("success" => true);
		}

		public function MoveBlockIntoBase($blocknum, $parts)
		{
			foreach ($parts as $part)
			{
				$srcfilename = $this->remotebasefolder . "/" . $part["pid"] . "/" . $part["name"];
				$destfilename = $this->remotebasefolder . "/" . $this->incrementals[0] . "/" . $part["name"];

				$result = @rename($srcfilename, $destfilename);
				if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to move '%s' to '%s'.", $srcfilename, $destfilename), "errorcode" => "rename_failed");
			}

			return array("success" => true);
		}

		private function ProcessMergeOps()
		{
			while (count($this->summary["mergeops"]))
			{
				$currop = array_shift($this->summary["mergeops"]);

				if (isset($currop["msg"]))  echo "\t" . $currop["msg"] . "\n";

				switch ($currop["type"])
				{
					case "delete":
					{
						$result = $this->DeleteFolder($currop["info"]);
						if (!$result["success"])  return $result;

						break;
					}
					case "rename":
					{
						$result = @rename($currop["info"][0], $currop["info"][1]);
						if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to move '%s' to '%s'.", $currop["info"][0], $currop["info"][1]), "errorcode" => "rename_failed");

						break;
					}
					case "savesummary":
					{
						$result = $this->SaveSummary($currop["info"][0]);
						if (!$result["success"])  return $result;

						$this->summary = $currop["info"][0];
						$this->incrementals = $currop["info"][1];

						break;
					}
					default:
					{
						return array("success" => false, "error" => "Unknown merge operation '" . $currop["type"] . "'.", "errorcode" => "unknown_merge_op");
					}
				}

				if ($currop["type"] !== "savesummary")
				{
					$result = $this->SaveSummary($this->summary);
					if (!$result["success"])  return $result;
				}
			}

			return array("success" => true);
		}

		public function FinishMergeDown()
		{
			// Queue up merge operations.
			$this->summary["mergeops"][] = array("msg" => "Deleting incremental '" . $this->incrementals[1] . "' (1)", "type" => "delete", "info" => $this->remotebasefolder . "/" . $this->incrementals[1]);

			$incrementals = array();
			$incrementals[0] = $this->incrementals[0];
			$summary = $this->summary;
			$summary["incrementaltimes"] = array();
			$summary["incrementaltimes"][0] = $this->summary["incrementaltimes"][1];
			$summary["mergeops"] = array();
			foreach ($this->incrementals as $num => $id)
			{
				if ($num < 2)  continue;

				$this->summary["mergeops"][] = array("msg" => "Renaming incremental '" . $id . "' (" . $num . ") to " . count($incrementals) . ".", "type" => "rename", "info" => array($this->remotebasefolder . "/" . $id, $this->remotebasefolder . "/" . (string)count($incrementals)));

				$incrementals[] = $id;
				$summary["incrementaltimes"][] = $this->summary["incrementaltimes"][$num];
			}

			$this->summary["mergeops"][] = array("msg" => "Deleting '" . $this->remotemergefolder . "'", "type" => "delete", "info" => $this->remotemergefolder);
			$this->summary["mergeops"][] = array("msg" => "Saving summary.", "type" => "savesummary", "info" => array($summary, $incrementals));

			// Save the queue.
			$result = $this->SaveSummary($this->summary);
			if (!$result["success"])  return $result;

			// Atomically process merge operations.
			$result = $this->ProcessMergeOps();
			if (!$result["success"])  return $result;

			$this->remotemergefolder = false;

			return array("success" => true, "incrementals" => $this->incrementals, "summary" => $this->summary);
		}

		private function GetFolderList($path)
		{
			$dir = @opendir($path);
			if ($dir === false)  return array("success" => false, "error" => self::LTranslate("Unable to open the directory '%s' for reading.", $path), "errorcode" => "opendir");

			$result = array("success" => true, "folders" => array(), "files" => array());
			while (($file = readdir($dir)) !== false)
			{
				if ($file !== "." && $file !== "..")
				{
					if (is_dir($path . "/" . $file))  $result["folders"][$file] = $file;
					else  $result["files"][$file] = $file;
				}
			}

			@closedir($dir);

			return $result;
		}

		private function DeleteFolder($path)
		{
			if (is_link($path))
			{
				$result = @unlink($path);
				if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to remove symbolic link '%s'.", $path), "errorcode" => "unlink");
			}
			else
			{
				$dir = @opendir($path);
				if ($dir === false)  return array("success" => false, "error" => self::LTranslate("Unable to open the directory '%s' for reading.", $path), "errorcode" => "opendir");

				while (($file = readdir($dir)) !== false)
				{
					if ($file !== "." && $file !== "..")
					{
						$filename = $path . "/" . $file;
						if (is_dir($filename))
						{
							$result = $this->DeleteFolder($filename);
							if (!$result["success"])  return $result;
						}
						else
						{
							$result = @unlink($filename);
							if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to remove '%s'.", $filename), "errorcode" => "unlink");
						}
					}
				}

				closedir($dir);

				$result = @rmdir($path);
				if ($result === false)  return array("success" => false, "error" => self::LTranslate("Unable to remove directory '%s'.", $path), "errorcode" => "rmdir");
			}

			return array("success" => true);
		}

		private static function LTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>