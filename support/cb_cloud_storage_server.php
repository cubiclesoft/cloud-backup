<?php
	// Cloud-based backup service interface for Cloud Storage Server.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	if (!class_exists("CloudStorageServerFiles", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/sdk_cloud_storage_server_files.php";

	class CB_Service_cloud_storage_server
	{
		private $options, $servicehelper, $css, $remotebasefolderid, $remotetempfolderid, $remotemergefolderid, $incrementals, $summary;

		public function GetInitOptionKeys()
		{
			$result = array(
				"host" => "Host",
				"apikey" => "API key",
				"remote_path" => "Remote base path"
			);

			return $result;
		}

		public function Init($options, $servicehelper)
		{
			$this->options = $options;
			$this->servicehelper = $servicehelper;
			$this->css = new CloudStorageServerFiles();
			$this->remotebasefolderid = false;
			$this->incrementals = array();
			$this->summary = array();
			$this->remotetempfolderid = false;
			$this->remotemergefolderid = false;
		}

		public function SetAccessInfo()
		{
			global $rootpath;

			@mkdir($rootpath . "/cache");

			// Before anything can happen, the CA and server certificates need to be retrieved.
			if (!file_exists($rootpath . "/cache/css_ca.pem") || !file_exists($rootpath . "/cache/css_cert.pem"))
			{
				$this->css->SetAccessInfo($this->options["host"], false, false, false);

				$result = $css->GetSSLInfo();
				if (!$result["success"])  return array("success" => false, "error" => "Unable to get SSL information.", "errorcode" => "get_ssl_info_failed", "info" => $result);

				file_put_contents($rootpath . "/cache/css_ca.pem", $result["cacert"]);
				file_put_contents($rootpath . "/cache/css_cert.pem", $result["cert"]);
			}

			// Set access information.
			$this->css->SetAccessInfo($this->options["host"], $this->options["apikey"], $rootpath . "/cache/css_ca.pem", file_get_contents($rootpath . "/cache/css_cert.pem"));

			return array("success" => true);
		}

		public function BuildRemotePath()
		{
			$this->remotebasefolderid = false;

			$result = $this->css->GetRootFolderID();
			if (!$result["success"])  return $result;

			$result = $this->css->CreateFolder($result["body"]["id"], "/" . $this->options["remote_path"]);
			if (!$result["success"])  return $result;

			$this->remotebasefolderid = $result["id"];

			return array("success" => true);
		}

		public function Test()
		{
			$result = $this->SetAccessInfo();
			if (!$result["success"])  return $result;

			$result = $this->BuildRemotePath();

			return $result;
		}

		public function GetBackupInfo()
		{
			$result = $this->SetAccessInfo();
			if (!$result["success"])  return $result;

			$result = $this->BuildRemotePath();
			if (!$result["success"])  return $result;

			// Determine what incrementals are available.
			$result = $this->css->GetFolderList($this->remotebasefolderid);
			if (!$result["success"])  return $result;

			$this->incrementals = array();
			foreach ($result["folders"] as $info2)
			{
				if ($info2["name"] === "TEMP")
				{
					// Delete the TEMP folder.
					$result2 = $this->css->DeleteObject($info2["id"]);
					if (!$result2["success"])  return $result2;
				}
				else
				{
					// Ignore MERGE and other non-essential directories.
					if (is_numeric($info2["name"]) && preg_match('/^\d+$/', $info2["name"]))  $this->incrementals[(int)$info2["name"]] = $info2["id"];
				}
			}

			// Retrieve the summary information about the incrementals.  Used for determining later if retrieved, cached files are current.
			if (isset($result["files"]["summary.json"]))
			{
				$result2 = $this->css->DownloadFile(false, $result["files"]["summary.json"]["id"]);
				if (!$result2["success"])  return $result2;

				$this->summary = @json_decode($result2["body"], true);
				if (!is_array($this->summary))  $this->summary = array();
			}

			// Initialize summary.
			if (!isset($this->summary["incrementaltimes"]))  $this->summary["incrementaltimes"] = array();
			if (!isset($this->summary["lastbackupid"]))  $this->summary["lastbackupid"] = 0;
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
			$result = $this->css->CreateFolder($this->remotebasefolderid, "TEMP");
			if (!$result["success"])  return $result;

			$this->remotetempfolderid = $result["id"];

			return array("success" => true);
		}

		public function UploadBlock($blocknum, $part, $data)
		{
			$filename = $blocknum . "_" . $part . ".dat";

			// Cover over any Cloud Storage Server upload issues by retrying with exponential fallback (up to 30 minutes).
			$ts = time();
			$currsleep = 5;
			do
			{
				$result = $this->css->UploadFile($this->remotetempfolderid, $filename, $data, false);
				if (!$result["success"])  sleep($currsleep);
				$currsleep *= 2;
			} while (!$result["success"] && $ts > time() - 1800);

			return $result;
		}

		public function SaveSummary($summary)
		{
			$data = json_encode($summary);

			// Upload the summary.
			// Cover over any Cloud Storage Server upload issues by retrying with exponential fallback (up to 30 minutes).
			$ts = time();
			$currsleep = 5;
			do
			{
				$result = $this->css->UploadFile($this->remotebasefolderid, "summary.json", $data, false);
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
			$result = $this->css->RenameObject($this->remotetempfolderid, (string)count($incrementals));
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
			if (isset($this->incrementals[$id]))  $id = $this->incrementals[$id];
			$result = $this->css->GetFolderList($id);
			if (!$result["success"])  return $result;

			// Extract information.
			$blocklist = array();
			foreach ($result["files"] as $info2)
			{
				if (preg_match('/^(\d+)_(\d+)\.dat$/', $info2["name"], $matches))
				{
					if (!isset($blocklist[$matches[1]]))  $blocklist[$matches[1]] = array();
					$blocklist[$matches[1]][$matches[2]] = array("id" => $info2["id"], "name" => $info2["name"]);
				}
			}

			return array("success" => true, "blocklist" => $blocklist);
		}

		public function DownloadBlock($info)
		{
			$result = $this->css->DownloadFile(false, $info["id"]);
			if (!$result["success"])  return $result;

			return array("success" => true, "data" => $result["body"]);
		}

		public function StartMergeDown()
		{
			$result = $this->css->CreateFolder($this->remotebasefolderid, "MERGE");
			if (!$result["success"])  return $result;

			$this->remotemergefolderid = $result["id"];

			return array("success" => true);
		}

		public function MoveBlockIntoMergeBackup($blocknum, $parts)
		{
			foreach ($parts as $part)
			{
				$result = $this->css->MoveObject($part["id"], $this->remotemergefolderid);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public function MoveBlockIntoBase($blocknum, $parts)
		{
			foreach ($parts as $part)
			{
				$result = $this->css->MoveObject($part["id"], $this->incrementals[0]);
				if (!$result["success"])  return $result;
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
						$result = $this->css->DeleteObject($currop["info"]);
						if (!$result["success"])  return $result;

						break;
					}
					case "rename":
					{
						$result = $this->css->RenameObject($currop["info"][0], $currop["info"][1]);
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