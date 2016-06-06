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
			$this->summary = array("incrementaltimes" => array(), "lastbackupid" => 0);
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
						if (!is_array($this->summary))  $this->summary = array("incrementaltimes" => array(), "lastbackupid" => 0);
					}
				}
			}

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
			$data = json_encode($summary);

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

		public function FinishBackup($backupid)
		{
			$summary = $this->summary;
			$incrementals = $this->incrementals;

			$summary["incrementaltimes"][] = time();
			$summary["lastbackupid"] = $backupid;

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

		public function MoveBlockIntoMergeBackup($parts)
		{
			foreach ($parts as $part)
			{
				$result = $this->opendrive->MoveFileToFolder($part["id"], $this->remotemergefolderid);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public function MoveBlockIntoBase($parts)
		{
			foreach ($parts as $part)
			{
				$result = $this->opendrive->MoveFileToFolder($part["id"], $this->incrementals[0]);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public function FinishMergeDown()
		{
			// When this function is called, the first incremental is assumed to be empty.
			echo "\tDeleting incremental '" . $this->incrementals[1] . "' (1)\n";
			$result = $this->opendrive->TrashFolder($this->incrementals[1], true);
			if (!$result["success"])  return $result;

			unset($this->incrementals[1]);

			// Rename later incrementals.
			$incrementals = array();
			$incrementals[0] = $this->incrementals[0];
			unset($this->incrementals[0]);
			$summary = $this->summary;
			$summary["incrementaltimes"] = array();
			$summary["incrementaltimes"][0] = $this->summary["incrementaltimes"][1];
			foreach ($this->incrementals as $num => $id)
			{
				echo "\tRenaming incremental '" . $id . "' (" . $num . ") to " . count($incrementals) . ".\n";
				$result = $this->opendrive->RenameFolder($id, (string)count($incrementals));
				if (!$result["success"])  return $result;

				$incrementals[] = $id;
				$summary["incrementaltimes"][] = $this->summary["incrementaltimes"][$num];
			}

			// Save the summary information.
			echo "\tSaving summary.\n";
			$result = $this->SaveSummary($summary);
			if (!$result["success"])  return $result;

			$this->summary = $summary;
			$this->incrementals = $incrementals;

			// Reset merge down status.
			echo "\tDeleting MERGE '" . $this->remotemergefolderid . "'\n";
			$result = $this->opendrive->TrashFolder($this->remotemergefolderid, true);
			if (!$result["success"])  return $result;

			$this->remotemergefolderid = false;

			return array("success" => true, "incrementals" => $this->incrementals, "summary" => $this->summary);
		}
	}
?>