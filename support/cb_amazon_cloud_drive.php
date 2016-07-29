<?php
	// Cloud-based backup service interface for Amazon Cloud Drive.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	if (!class_exists("AmazonCloudDrive", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/sdk_amazon_cloud_drive.php";

	class CB_Service_amazon_cloud_drive
	{
		private $options, $servicehelper, $acd, $remotebasefolderid, $remotetempfolderid, $remotetempblocklist, $remotemergefolderid, $remotemergeblocklist, $incrementals, $summary;

		public function GetInitOptionKeys()
		{
			$result = array(
				"clientid" => "OAuth2 Client ID (Leave empty for the default)",
				"clientsecret" => "OAuth2 Client Secret (Leave empty for the default)",
				"returnurl" => "OAuth2 Return URL (Leave empty for the default)",
				"cb_embed" => "Amazon Prime/Unlimited Photos account (Y/N)",
				"cb_remote_path" => "Remote base path"
			);

			return $result;
		}

		// Updates the global configuration with the latest client information.
		public function ClientInfoUpdated__SaveConfig($obj)
		{
			global $config;

			if (isset($config["service_info"]))
			{
				$config["service_info"]["options"] = $this->acd->GetClientInfo();

				CB_SaveConfig($config);
			}
		}

		public function Init(&$options, $servicehelper)
		{
			if ($options["clientid"] === "")  $options["clientid"] = false;
			if ($options["clientsecret"] === "")  $options["clientsecret"] = false;
			$options["clientscope"] = array("clouddrive:read_all", "clouddrive:write");
			if ($options["returnurl"] === "")  $options["returnurl"] = false;
			if (is_string($options["cb_embed"]))  $options["cb_embed"] = (strtoupper(substr(trim($options["cb_embed"]), 0, 1)) === "Y");

			$this->servicehelper = $servicehelper;
			$this->acd = new AmazonCloudDrive();
			$this->acd->SetClientInfo($options);
			if (!isset($options["refreshtoken"]))
			{
				$this->acd->InteractiveLogin();
				$options = $this->acd->GetClientInfo();
			}
			if (isset($options["refreshtoken"]))  $this->acd->AddClientInfoUpdatedNotify(array($this, "ClientInfoUpdated__SaveConfig"));
			$this->options = $options;
			$this->remotebasefolderid = false;
			$this->incrementals = array();
			$this->summary = array();
			$this->remotetempfolderid = false;
			$this->remotemergefolderid = false;
		}

		public function BuildRemotePath()
		{
			$this->remotebasefolderid = false;

			$result = $this->acd->GetRootFolderID();
			if (!$result["success"])  return $result;

			$folderid = $result["id"];

			$remotepath = explode("/", $this->options["cb_remote_path"]);
			while (count($remotepath))
			{
				$remotefolder = trim(array_shift($remotepath));
				if ($remotefolder !== "")
				{
					$result = $this->acd->GetObjectIDByName($folderid, $remotefolder);
					if (!$result["success"])  return $result;

					$remotefolderid = (isset($result["info"]["id"]) ? $result["info"]["id"] : false);

					if ($remotefolderid === false)
					{
						$result = $this->acd->CreateFolder($folderid, $remotefolder);
						if (!$result["success"])  return $result;

						$remotefolderid = $result["body"]["id"];
					}

					$folderid = $remotefolderid;
				}
			}

			$this->remotebasefolderid = $folderid;

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
			$result = $this->acd->GetFolderList($this->remotebasefolderid);
			if (!$result["success"])  return $result;

			$this->incrementals = array();
			foreach ($result["folders"] as $info2)
			{
				if ($info2["name"] === "TEMP")
				{
					// "Delete" the TEMP folder.  Amazon Cloud Drive doesn't have an API to delete objects.  Sigh.
					$result2 = $this->acd->TrashObject($info2["id"]);
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
				$result2 = $this->acd->DownloadFile(false, $result["files"]["summary.json"]["id"]);
				if (!$result2["success"])  return $result2;

				$this->summary = @json_decode($result2["body"], true);
				if (!is_array($this->summary))  $this->summary = array();
			}

			// Initialize summary.
			if (!isset($this->summary["incrementaltimes"]))  $this->summary["incrementaltimes"] = array();
			if (!isset($this->summary["blocklists"]))  $this->summary["blocklists"] = array();
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
			foreach ($this->summary["blocklists"] as $key => $val)
			{
				if (!isset($this->incrementals[$key]))  unset($this->summary["blocklists"][$key]);
			}
			foreach ($this->incrementals as $key => $val)
			{
				if (!isset($this->summary["incrementaltimes"][$key]))  unset($this->incrementals[$key]);
				if (!isset($this->summary["blocklists"][$key]))  unset($this->incrementals[$key]);
			}

			ksort($this->incrementals);
			ksort($this->summary["incrementaltimes"]);
			ksort($this->summary["blocklists"]);

			return array("success" => true, "incrementals" => $this->incrementals, "summary" => $this->summary);
		}

		public function StartBackup()
		{
			$result = $this->acd->CreateFolder($this->remotebasefolderid, "TEMP");
			if (!$result["success"])  return $result;

			$this->remotetempfolderid = $result["body"]["id"];
			$this->remotetempblocklist = array();

			return array("success" => true);
		}

		public function UploadBlock($blocknum, $part, $data)
		{
			$filename = $blocknum . "_" . $part . ($this->options["cb_embed"] ? ".jpg" : ".dat");
			if ($this->options["cb_embed"])  $this->servicehelper->ApplyPhoto($data);

			// Cover over any Amazon Cloud Drive upload issues by retrying with exponential fallback (up to 30 minutes).
			$ts = time();
			$currsleep = 5;
			do
			{
				$result = $this->acd->UploadFile($this->remotetempfolderid, $filename, $data, false);
				if (!$result["success"])  sleep($currsleep);
				$currsleep *= 2;
			} while (!$result["success"] && $ts > time() - 1800);

			if ($result["success"])
			{
				// Save the part to the block list.
				if (!isset($this->remotetempblocklist[$blocknum]))  $this->remotetempblocklist[$blocknum] = array();
				$this->remotetempblocklist[$blocknum][$part] = array("pid" => $this->remotetempfolderid, "id" => $result["body"]["id"], "name" => $filename);
			}

			return $result;
		}

		// Saving a precalculated block list saves a bunch of API calls later but reduces the ability to accurately handle file IDs.
		public function SaveBlockList($folderid, $info)
		{
			$data = json_encode($info);

			// Upload the block list.
			// Cover over any Amazon Cloud Drive upload issues by retrying with exponential fallback (up to 30 minutes).
			$ts = time();
			$currsleep = 5;
			do
			{
				$result = $this->acd->UploadFile($folderid, "blocklist.json", $data, false);
				if (!$result["success"])  sleep($currsleep);
				$currsleep *= 2;
			} while (!$result["success"] && $ts > time() - 1800);

			return $result;
		}

		public function SaveSummary($summary)
		{
			$data = json_encode($summary);

			// Upload the summary.
			// Cover over any Amazon Cloud Drive upload issues by retrying with exponential fallback (up to 30 minutes).
			$ts = time();
			$currsleep = 5;
			do
			{
				$result = $this->acd->UploadFile($this->remotebasefolderid, "summary.json", $data, false);
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

			// Save the block list.
			$result = $this->SaveBlockList($this->remotetempfolderid, $this->remotetempblocklist);
			if (!$result["success"])  return $result;

			$summary["blocklists"][] = $result["body"]["id"];

			// Move the TEMP folder to a new incremental.
			$result = $this->acd->RenameObject($this->remotetempfolderid, (string)count($incrementals));
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
			if (isset($this->summary["blocklists"][$id]))  $id = $this->summary["blocklists"][$id];

			$result = $this->acd->DownloadFile(false, $id);
			if (!$result["success"])  return $result;

			$blocklist = @json_decode($result["body"], true);
			if (!is_array($blocklist))  return array("success" => false, "error" => "Block list on Amazon Cloud Drive is corrupt.", "errorcode" => "corrupt_data");

			return array("success" => true, "blocklist" => $blocklist);
		}

		public function DownloadBlock($info)
		{
			$result = $this->acd->DownloadFile(false, $info["id"]);
			if (!$result["success"])  return $result;

			if ($this->options["cb_embed"])  $this->servicehelper->UnapplyPhoto($result["body"]);

			return array("success" => true, "data" => $result["body"]);
		}

		public function StartMergeDown()
		{
			$result = $this->acd->GetObjectIDByName($this->remotebasefolderid, "MERGE");
			if (!$result["success"])  return $result;

			if ($result["info"] !== false)  $this->remotemergefolderid = $result["info"]["id"];
			else
			{
				$result = $this->acd->CreateFolder($this->remotebasefolderid, "MERGE");
				if (!$result["success"])  return $result;

				$this->remotemergefolderid = $result["body"]["id"];
			}

			$result = $this->GetIncrementalBlockList(0);
			if (!$result["success"])  return $result;

			$this->remotemergeblocklist = $result["blocklist"];

			return array("success" => true);
		}

		public function MoveBlockIntoMergeBackup($blocknum, $parts)
		{
			foreach ($parts as $part)
			{
				$result = $this->acd->MoveObject($part["pid"], $part["id"], $this->remotemergefolderid);
				if (!$result["success"])
				{
					if ($result["errorcode"] === "unexpected_amazon_response" && $result["info"]["response"]["code"] == 404)  $result["nonfatal"] = true;

					return $result;
				}
			}

			unset($this->remotemergeblocklist[$blocknum]);

			return array("success" => true);
		}

		public function MoveBlockIntoBase($blocknum, $parts)
		{
			$parts2 = array();
			foreach ($parts as $part)
			{
				$result = $this->acd->MoveObject($part["pid"], $part["id"], $this->incrementals[0]);
				if (!$result["success"])
				{
					if ($result["errorcode"] === "unexpected_amazon_response" && $result["info"]["response"]["code"] == 404)  $result["nonfatal"] = true;

					return $result;
				}

				$parts2[] = array("pid" => $this->incrementals[0], "id" => $part["id"], "name" => $part["name"]);
			}

			$this->remotemergeblocklist[$blocknum] = $parts2;

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
						$result = $this->acd->TrashObject($currop["info"]);
						if (!$result["success"])  return $result;

						break;
					}
					case "saveblocklist":
					{
						$result = $this->SaveBlockList($currop["info"][0], $currop["info"][1]);
						if (!$result["success"])  return $result;

						break;
					}
					case "rename":
					{
						$result = $this->acd->RenameObject($currop["info"][0], $currop["info"][1]);
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
			$this->summary["mergeops"][] = array("msg" => "Saving updated block list.", "type" => "saveblocklist", "info" => array($this->incrementals[0], $this->remotemergeblocklist));

			$incrementals = array();
			$incrementals[0] = $this->incrementals[0];
			$summary = $this->summary;
			$summary["incrementaltimes"] = array();
			$summary["incrementaltimes"][0] = $this->summary["incrementaltimes"][1];
			$summary["blocklists"] = array();
			$summary["blocklists"][0] = $this->summary["blocklists"][0];
			$summary["mergeops"] = array();
			foreach ($this->incrementals as $num => $id)
			{
				if ($num < 2)  continue;

				$this->summary["mergeops"][] = array("msg" => "Renaming incremental '" . $id . "' (" . $num . ") to " . count($incrementals) . ".", "type" => "rename", "info" => array($id, (string)count($incrementals)));

				$incrementals[] = $id;
				$summary["incrementaltimes"][] = $this->summary["incrementaltimes"][$num];
				$summary["blocklists"][] = $this->summary["blocklists"][$num];
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