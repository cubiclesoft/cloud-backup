<?php
	// Cloud-based backup service interface for OpenDrive.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	if (!class_exists("OpenDrive", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/sdk_opendrive.php";

	class CB_Service_opendrive
	{
		private $options, $servicehelper, $opendrive, $remotebasefolderid, $remotetempfolderid, $remotemergefolderid, $incrementals, $summary;

		public function GetInitOptionKeys()
		{
			$result = array(
				"username" => "Username",
				"password" => "Password",
				"remote_path" => "Remote base path"
			);

			return $result;
		}

		public function Init($options, $servicehelper)
		{
			$this->options = $options;
			$this->servicehelper = $servicehelper;
			$this->opendrive = new OpenDrive();
			$this->remotebasefolderid = false;
			$this->incrementals = array();
			$this->summary = array();
			$this->remotetempfolderid = false;
			$this->remotemergefolderid = false;
		}

		public function Connect()
		{
			return $this->opendrive->Login($this->options["username"], $this->options["password"]);
		}

		public function BuildRemotePath()
		{
			$this->remotebasefolderid = false;

			$folderid = "0";
			$remotepath = explode("/", $this->options["remote_path"]);
			while (count($remotepath))
			{
				$remotefolder = trim(array_shift($remotepath));
				if ($remotefolder !== "")
				{
					$result = $this->opendrive->GetObjectIDByName($folderid, $remotefolder);
					if (!$result["success"])  return $result;

					$remotefolderid = (isset($result["info"]["FolderID"]) ? $result["info"]["FolderID"] : false);

					if ($remotefolderid === false)
					{
						$result = $this->opendrive->CreateFolder($folderid, $remotefolder);
						if (!$result["success"])  return $result;

						$remotefolderid = $result["body"]["FolderID"];
					}

					$folderid = $remotefolderid;
				}
			}

			$this->remotebasefolderid = $folderid;

			return array("success" => true);
		}

		public function Test()
		{
			$result = $this->Connect();
			if (!$result["success"])  return $result;

			$result = $this->BuildRemotePath();

			return $result;
		}

		public function GetBackupInfo()
		{
			$result = $this->Connect();
			if (!$result["success"])  return $result;

			$result = $this->BuildRemotePath();
			if (!$result["success"])  return $result;

			// Determine what incrementals are available.
			$result = $this->opendrive->GetFolderList($this->remotebasefolderid);
			if (!$result["success"])  return $result;

			$this->incrementals = array();
			if (isset($result["body"]["Folders"]))
			{
				foreach ($result["body"]["Folders"] as $info2)
				{
					if ($info2["Name"] === "TEMP")
					{
						// Delete the TEMP folder.
						$result2 = $this->opendrive->TrashFolder($info2["FolderID"], true);
						if (!$result2["success"])  return $result2;
					}
					else
					{
						// Ignore MERGE and other non-essential directories.
						if (is_numeric($info2["Name"]) && preg_match('/^\d+$/', $info2["Name"]))  $this->incrementals[(int)$info2["Name"]] = $info2["FolderID"];
					}
				}
			}

			// Retrieve the summary information about the incrementals.  Used for determining later if retrieved, cached files are current.
			if (isset($result["body"]["Files"]))
			{
				foreach ($result["body"]["Files"] as $info2)
				{
					if ($info2["Name"] === "summary.json")
					{
						$result2 = $this->opendrive->DownloadFile(false, $info2["FileId"]);
						if (!$result2["success"])  return $result2;

						$this->summary = @json_decode($result2["body"], true);
						if (!is_array($this->summary))  $this->summary = array();
					}
				}
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
			$result = $this->opendrive->CreateFolder($this->remotebasefolderid, "TEMP");
			if (!$result["success"])  return $result;

			$this->remotetempfolderid = $result["body"]["FolderID"];

			return array("success" => true);
		}

		public function UploadBlock($blocknum, $part, $data)
		{
			$filename = $blocknum . "_" . $part . ".dat";

			// Cover over any OpenDrive upload issues by retrying with exponential fallback (up to 30 minutes).
			$ts = time();
			$currsleep = 5;
			do
			{
				$result = $this->opendrive->UploadFile($this->remotetempfolderid, $filename, $data);
				if (!$result["success"])  sleep($currsleep);
				$currsleep *= 2;
			} while (!$result["success"] && $ts > time() - 1800);

			return $result;
		}

		public function SaveSummary($summary)
		{
			$data = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

			// Upload the summary.
			// Cover over any OpenDrive upload issues by retrying with exponential fallback (up to 30 minutes).
			$ts = time();
			$currsleep = 5;
			do
			{
				$result = $this->opendrive->UploadFile($this->remotebasefolderid, "summary.json", $data);
				if (!$result["success"])  sleep($currsleep);
				$currsleep *= 2;
			} while (!$result["success"] && $ts > time() - 1800);

			return $result;
		}

		public function FinishBackup($backupid, $nextblock)
		{
			$summary = $this->summary;
			$incrementals = $this->incrementals;

			$summary["incrementaltimes"][] = time();
			$summary["lastbackupid"] = $backupid;
			$summary["nextblock"] = $nextblock;

			// Move the TEMP folder to a new incremental.
			$result = $this->opendrive->RenameFolder($this->remotetempfolderid, (string)count($incrementals));
			if (!$result["success"])  return $result;

			$incrementals[] = $this->remotetempfolderid;

			$result = $this->SaveSummary($summary);
			if (!$result["success"])  return $result;

			$this->summary = $summary;
			$this->incrementals = $incrementals;

			// Reset backup status.
			$this->remotetempfolderid = false;

			return array("success" => true, "incrementals" => $this->incrementals, "summary" => $this->summary);
		}

		public function GetIncrementalBlockList($id)
		{
			$result = $this->opendrive->GetFolderList(isset($this->incrementals[$id]) ? $this->incrementals[$id] : $id);
			if (!$result["success"])  return $result;

			// Extract information.
			$blocklist = array();
			if (isset($result["body"]["Files"]))
			{
				foreach ($result["body"]["Files"] as $info2)
				{
					if (preg_match('/^(\d+)_(\d+)\.dat$/', $info2["Name"], $matches))
					{
						if (!isset($blocklist[$matches[1]]))  $blocklist[$matches[1]] = array();
						$blocklist[$matches[1]][$matches[2]] = array("id" => $info2["FileId"], "name" => $info2["Name"]);
					}
				}
			}

			return array("success" => true, "blocklist" => $blocklist);
		}

		public function DownloadBlock($info)
		{
			$result = $this->opendrive->DownloadFile(false, $info["id"]);
			if (!$result["success"])  return $result;

			return array("success" => true, "data" => $result["body"]);
		}

		public function StartMergeDown()
		{
			$result = $this->opendrive->GetObjectIDByName($this->remotebasefolderid, "MERGE");
			if (!$result["success"])  return $result;

			if ($result["info"] !== false)  $this->remotemergefolderid = $result["info"]["FolderID"];
			else
			{
				$result = $this->opendrive->CreateFolder($this->remotebasefolderid, "MERGE");
				if (!$result["success"])  return $result;

				$this->remotemergefolderid = $result["body"]["FolderID"];
			}

			return array("success" => true);
		}

		public function MoveBlockIntoMergeBackup($blocknum, $parts)
		{
			foreach ($parts as $part)
			{
				$result = $this->opendrive->MoveFileToFolder($part["id"], $this->remotemergefolderid);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public function MoveBlockIntoBase($blocknum, $parts)
		{
			foreach ($parts as $part)
			{
				$result = $this->opendrive->MoveFileToFolder($part["id"], $this->incrementals[0]);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		private function ProcessMergeOps()
		{
			$first = true;

			while (count($this->summary["mergeops"]))
			{
				$currop = array_shift($this->summary["mergeops"]);

				if (isset($currop["msg"]))  echo "\t" . $currop["msg"] . "\n";

				switch ($currop["type"])
				{
					case "delete":
					{
						$result = $this->opendrive->TrashFolder($currop["info"], true);
						if (!$result["success"])
						{
							// Ignore an initial 404 error.
							if ($first && $result["errorcode"] === "unexpected_opendrive_response" && $result["info"]["response"]["code"] == 404)  $result["nonfatal"] = true;
							else
							{
								return $result;
							}
						}

						break;
					}
					case "rename":
					{
						$result = $this->opendrive->RenameFolder($currop["info"][0], $currop["info"][1]);
						if (!$result["success"])  return $result;

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

				$first = false;
			}

			return array("success" => true);
		}

		public function FinishMergeDown()
		{
			// Queue up merge operations.
			$this->summary["mergeops"][] = array("msg" => "Deleting incremental '" . $this->incrementals[1] . "' (1)", "type" => "delete", "info" => $this->incrementals[1]);

			$incrementals = array();
			$incrementals[0] = $this->incrementals[0];
			$summary = $this->summary;
			$summary["incrementaltimes"] = array();
			$summary["incrementaltimes"][0] = $this->summary["incrementaltimes"][1];
			$summary["mergeops"] = array();
			foreach ($this->incrementals as $num => $id)
			{
				if ($num < 2)  continue;

				$this->summary["mergeops"][] = array("msg" => "Renaming incremental '" . $id . "' (" . $num . ") to " . count($incrementals) . ".", "type" => "rename", "info" => array($id, (string)count($incrementals)));

				$incrementals[] = $id;
				$summary["incrementaltimes"][] = $this->summary["incrementaltimes"][$num];
			}

			$this->summary["mergeops"][] = array("msg" => "Deleting MERGE '" . $this->remotemergefolderid . "'", "type" => "delete", "info" => $this->remotemergefolderid);
			$this->summary["mergeops"][] = array("msg" => "Saving summary.", "type" => "savesummary", "info" => array($summary, $incrementals));

			// Save the queue.
			$result = $this->SaveSummary($this->summary);
			if (!$result["success"])  return $result;

			// Atomically process merge operations.
			$result = $this->ProcessMergeOps();
			if (!$result["success"])  return $result;

			$this->remotemergefolderid = false;

			return array("success" => true, "incrementals" => $this->incrementals, "summary" => $this->summary);
		}
	}
?>