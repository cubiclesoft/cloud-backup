<?php
	// Amazon Cloud Drive SDK class.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	// Load dependencies.
	if (!class_exists("HTTP", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/http.php";
	if (!class_exists("WebBrowser", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/web_browser.php";

	class AmazonCloudDrive
	{
		private $data, $web, $callbacks;

		public function __construct()
		{
			$this->web = new WebBrowser();

			$this->data = array(
				"clientid" => false,
				"clientsecret" => false,
				"clientscope" => false,
				"returnurl" => false,
				"refreshtoken" => false,
				"bearertoken" => false,
				"bearerexpirets" => -1,
				"metadataurl" => false,
				"contenturl" => false,
				"endpointexpirets" => -1,
			);

			$this->callbacks = array();
		}

		public function SetClientInfo($info)
		{
			$this->data = array_merge($this->data, $info);

			// Default CubicleSoft client ID and "secret" (Cloud Drive Management).  Improper use can lead to cross-domain MITM issues.
			if ($this->data["clientid"] === false || $this->data["clientsecret"] === false)
			{
				$this->data["clientid"] = "amzn1.application-oa2-client.3bf6aa83a94e482f8d27baff6425f363";
				$this->data["clientsecret"] = "8fc02cd959be668f23a5253fe755a3cabc06f939b1e8beab07f3e5da7fd8c24d";
				$this->data["clientscope"] = array("clouddrive:read_all", "clouddrive:write");
				$this->data["returnurl"] = "http://localhost:14847";
				$this->data["refreshtoken"] = false;
				$this->data["bearertoken"] = false;
				$this->data["bearerexpirets"] = -1;
				$this->data["metadataurl"] = false;
				$this->data["contenturl"] = false;
				$this->data["endpointexpirets"] = -1;
			}
		}

		public function GetClientInfo()
		{
			return $this->data;
		}

		public function AddClientInfoUpdatedNotify($callback)
		{
			if (is_callable($callback))  $this->callbacks[] = $callback;
		}

		public function GetLoginURL()
		{
			if ($this->data["clientid"] === false || $this->data["clientsecret"] === false)  $this->SetClientInfo(array());

			$url = "https://www.amazon.com/ap/oa?client_id=" . urlencode($this->data["clientid"]) . "&scope=" . urlencode(is_array($this->data["clientscope"]) ? implode(" ", $this->data["clientscope"]) : $this->data["clientscope"]) . "&response_type=code&redirect_uri=" . urlencode($this->data["returnurl"]);

			return $url;
		}

		public function InteractiveLogin__HandleRequest(&$state)
		{
			if (substr($state["url"], 0, strlen($this->data["returnurl"])) === $this->data["returnurl"])
			{
				echo self::ACD_Translate("Amazon redirected to '%s'.  Processing response.\n\n", $state["url"]);

				$url = HTTP::ExtractURL($state["url"]);

				if (isset($url["queryvars"]["error"]))  echo self::ACD_Translate("Unfortunately, an error occurred:  %s (%s)\n\nDid you deny/cancel the consent?\n\n", $url["queryvars"]["error_description"][0], $url["queryvars"]["error"][0]);
				else
				{
					echo self::ACD_Translate("Retrieving refresh token from Amazon...\n");

					$result = $this->UpdateRefreshToken($url["queryvars"]["code"][0]);
					if (!$result["success"])  echo self::ACD_Translate("Unfortunately, an error occurred while attempting to retrieve tokens and endpoint URLs:  %s (%s)\n\n", $result["error"], $result["errorcode"]);
					else  echo self::ACD_Translate("Refresh token, initial bearer token, and endpoint URLs successfully retrieved!\n\n");
				}

				return false;
			}

			echo "Retrieving '" . $state["url"] . "'...\n\n";

			return true;
		}

		public function InteractiveLogin()
		{
			if (!class_exists("simple_html_dom", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/simple_html_dom.php";
			if (!class_exists("TagFilter", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/tag_filter.php";

			echo self::ACD_Translate("***************\n");
			echo self::ACD_Translate("Starting interactive login for Amazon Cloud Drive.\n\n");
			echo self::ACD_Translate("During the next few minutes, you will be asked to sign into your Amazon.com account and then approve this application to have access to perform actions on your behalf within your Amazon.com account.  You may press Ctrl+C at any time to terminate this application.\n\n");
			echo self::ACD_Translate("Every web request made from this point on will be dumped to your console and take the form of \"Retrieving '[URL being retrieved]'...\".\n");
			echo self::ACD_Translate("***************\n\n");

			$html = new simple_html_dom();
			$web = new WebBrowser(array("httpopts" => array("pre_retrievewebpage_callback" => array($this, "InteractiveLogin__HandleRequest"))));
			$filteropts = TagFilter::GetHTMLOptions();

			$this->data["refreshtoken"] = false;
			$this->data["bearertoken"] = false;
			$this->data["bearerexpirets"] = -1;
			$this->data["metadataurl"] = false;
			$this->data["contenturl"] = false;
			$this->data["endpointexpirets"] = -1;

			$result = array(
				"url" => $this->GetLoginURL(),
				"options" => array()
			);

			do
			{
				$result2 = $web->Process($result["url"], "auto", $result["options"]);
				if (!$result2["success"])
				{
					if ($this->data["refreshtoken"] === false)  return $result2;

					return array("success" => true);
				}
				else if ($result2["response"]["code"] != 200)
				{
					return array("success" => false, "error" => self::ACD_Translate("Expected a 200 response from Amazon.  Received '%s'.", $result["response"]["line"]), "errorcode" => "unexpected_amazon_response", "info" => $result2);
				}
				else
				{
					$body = TagFilter::Run($result2["body"], $filteropts);
					$html->load($body);

					$title = $html->find('title', 0);
					if ($title)  echo trim($title->plaintext) . "\n\n";

					$h1 = $html->find('h1', 0);
					if ($h1)  echo trim($h1->plaintext) . "\n\n";

					$error = $html->find('div.error', 0);
					if ($error)  echo trim(preg_replace('/\s+/', " ", $error->plaintext)) . "\n\n";

					$text = $html->find('#ap-oaconsent-request-info', 0);
					if ($text)  echo trim(preg_replace('/\s+/', " ", $text->plaintext)) . "\n\n";

					$forms = $web->ExtractForms($result2["url"], $body);

					foreach ($forms as $num => $form)
					{
						if ($form->info["action"] === "https://www.amazon.com/ap/get" && $form->info["name"] === "ue_backdetect")  unset($forms[$num]);
					}

					$result = $web->InteractiveFormFill($forms);
					if ($result === false)  return array("success" => false, "error" => self::ACD_Translate("Expected at least one form to exist.  Received none."), "errorcode" => "invalid_amazon_response", "info" => $result2);
				}
			} while (1);
		}

		public function UpdateEndpoints()
		{
			if ($this->data["bearertoken"] === false)  return array("success" => false, "error" => self::ACD_Translate("Unable to update the endpoints.  Bearer/Authorization token is missing.  Has the configuration been run?"), "errorcode" => "no_bearer_token");

			if ($this->data["endpointexpirets"] <= time())
			{
				$result = $this->RunAPI("GET", "https://drive.amazonaws.com/drive/v1/account/endpoint");
				if (!$result["success"])  return $result;

				$data = $result["body"];
				$this->data["metadataurl"] = $data["metadataUrl"];
				$this->data["contenturl"] = $data["contentUrl"];
				$this->data["endpointexpirets"] = time() + 4 * 24 * 60 * 60;

				foreach ($this->callbacks as $callback)
				{
					if (is_callable($callback))  call_user_func_array($callback, array($this));
				}
			}

			return array("success" => true);
		}

		public function UpdateRefreshToken($code)
		{
			if ($this->data["clientid"] === false || $this->data["clientsecret"] === false)  $this->SetClientInfo(array());

			$this->data["bearertoken"] = false;

			$options = array(
				"postvars" => array(
					"grant_type" => "authorization_code",
					"code" => $code,
					"client_id" => $this->data["clientid"],
					"client_secret" => $this->data["clientsecret"],
					"redirect_uri" => $this->data["returnurl"]
				)
			);

			$result = $this->RunAPI("POST", "https://api.amazon.com/auth/o2/token", $options);
			if (!$result["success"])  return $result;

			$data = $result["body"];
			$this->data["refreshtoken"] = $data["refresh_token"];
			$this->data["bearertoken"] = $data["access_token"];
			$this->data["bearerexpirets"] = time() + (int)$data["expires_in"] - 30;

			$this->UpdateEndpoints();

			return array("success" => true);
		}

		public function UpdateBearerToken()
		{
			if ($this->data["refreshtoken"] === false)  return array("success" => false, "error" => self::ACD_Translate("Unable to update the bearer token.  Refresh token is missing.  Has the configuration been run?"), "errorcode" => "no_refresh_token");

			if ($this->data["bearerexpirets"] <= time())
			{
				$this->data["bearertoken"] = false;

				$options = array(
					"postvars" => array(
						"grant_type" => "refresh_token",
						"refresh_token" => $this->data["refreshtoken"],
						"client_id" => $this->data["clientid"],
						"client_secret" => $this->data["clientsecret"]
					)
				);

				$result = $this->RunAPI("POST", "https://api.amazon.com/auth/o2/token", $options);
				if (!$result["success"])  return $result;

				$data = $result["body"];
				$this->data["refreshtoken"] = $data["refresh_token"];
				$this->data["bearertoken"] = $data["access_token"];
				$this->data["bearerexpirets"] = time() + (int)$data["expires_in"] - 30;

				foreach ($this->callbacks as $callback)
				{
					if (is_callable($callback))  call_user_func_array($callback, array($this));
				}
			}

			$result = $this->UpdateEndpoints();
			if (!$result["success"])  return $result;

			return array("success" => true);
		}

		public function GetAccountInfo()
		{
			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			$result = $this->RunAPI("GET", $this->data["metadataurl"] . "account/info");
			if (!$result["success"])  return $result;

			return array("success" => true, "info" => $result["body"]);
		}

		public function GetAccountUsage()
		{
			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			$result = $this->RunAPI("GET", $this->data["metadataurl"] . "account/usage");
			if (!$result["success"])  return $result;

			return array("success" => true, "info" => $result["body"]);
		}

		public function GetRootFolderID()
		{
			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			$result = $this->RunAPI("GET", $this->data["metadataurl"] . "nodes?filters=" . urlencode("isRoot:true"));
			if (!$result["success"])  return $result;

			$folderid = $result["body"]["data"][0]["id"];

			return array("success" => true, "id" => $folderid);
		}

		public function GetFolderList($folderid)
		{
			if ($folderid === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid folder ID."), "errorcode" => "invalid_folder_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			$folders = array();
			$files = array();
			$nexttoken = false;
			do
			{
				$result = $this->RunAPI("GET", $this->data["metadataurl"] . "nodes/" . $folderid . "/children" . ($nexttoken !== false ? "&startToken=" . urlencode($nexttoken) : ""));
				if (!$result["success"])  return $result;

				foreach ($result["body"]["data"] as $item)
				{
					if ($item["kind"] === "FOLDER")  $folders[isset($item["name"]) ? $item["name"] : ""] = $item;
					else if ($item["kind"] === "FILE")  $files[isset($item["name"]) ? $item["name"] : ""] = $item;
				}

				$nexttoken = (isset($result["nextToken"]) ? $result["nextToken"] : false);
			} while ($nexttoken !== false);

			return array("success" => true, "folders" => $folders, "files" => $files);
		}

		public function GetObjectIDByName($folderid, $name)
		{
			if ($folderid === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid folder ID."), "errorcode" => "invalid_folder_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			// Escape the name.
			$name = str_replace(array(" ", "+", "-", "&", "|", "!", "(", ")", "{", "}", "[", "]", "^", "'", "\"", "~", "*", "?", ":", "\\"), array("\\ ", "\\+", "\\-", "\\&", "\\|", "\\!", "\\(", "\\)", "\\{", "\\}", "\\[", "\\]", "\\^", "\\'", "\\\"", "\\~", "\\*", "\\?", "\\:", "\\\\"), $name);

			$result = $this->RunAPI("GET", $this->data["metadataurl"] . "nodes/" . $folderid . "/children?filters=" . urlencode("name:" . $name));
			if (!$result["success"])  return $result;

			$info = (count($result["body"]["data"]) ? $result["body"]["data"][0] : false);

			return array("success" => true, "info" => $info);
		}

		public function CreateFolder($folderid, $name, $labels = array(), $properties = array())
		{
			if ($folderid === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid folder ID."), "errorcode" => "invalid_folder_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			$options = array(
				"name" => $name,
				"kind" => "FOLDER",
				"parents" => array($folderid)
			);

			if (count($labels))  $options["labels"] = $labels;
			if (count($properties))  $options["properties"] = $properties;

			$options = array(
				"headers" => array("Content-Type" => "application/json"),
				"body" => json_encode($options)
			);

			$result = $this->RunAPI("POST", $this->data["metadataurl"] . "nodes", $options);
			if (!$result["success"])  return $result;

			$result["id"] = $result["body"]["id"];

			return $result;
		}

		public function GetObjectByID($id)
		{
			if ($id === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid object ID."), "errorcode" => "invalid_object_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			return $this->RunAPI("GET", $this->data["metadataurl"] . "nodes/" . $id);
		}

		public function CopyObject($srcid, $destid)
		{
			if ($srcid === false || $destid === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid object ID."), "errorcode" => "invalid_object_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			$options = array(
				"headers" => array("Content-Type" => "application/json"),
				"body" => ""
			);

			return $this->RunAPI("PUT", $this->data["metadataurl"] . "nodes/" . $destid . "/children/" . $srcid, $options);
		}

		public function MoveObject($srcparentid, $srcid, $destid)
		{
			if ($srcparentid === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid parent folder ID."), "errorcode" => "invalid_parent_folder_id");
			if ($srcid === false || $destid === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid object ID."), "errorcode" => "invalid_object_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			$options = array(
				"fromParent" => $srcparentid,
				"childId" => $srcid
			);

			$options = array(
				"headers" => array("Content-Type" => "application/json"),
				"body" => json_encode($options)
			);

			return $this->RunAPI("POST", $this->data["metadataurl"] . "nodes/" . $destid . "/children", $options);
		}

		public function RenameObject($id, $newname)
		{
			if ($id === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid object ID."), "errorcode" => "invalid_object_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			$options = array(
				"name" => $newname
			);

			$options = array(
				"headers" => array("Content-Type" => "application/json"),
				"body" => json_encode($options)
			);

			return $this->RunAPI("PATCH", $this->data["metadataurl"] . "nodes/" . $id, $options);
		}

		public function TrashObject($id)
		{
			if ($id === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid object ID."), "errorcode" => "invalid_object_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			return $this->RunAPI("PUT", $this->data["metadataurl"] . "trash/" . $id);
		}

		public function RestoreObject($id)
		{
			if ($id === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid object ID."), "errorcode" => "invalid_object_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			return $this->RunAPI("POST", $this->data["metadataurl"] . "trash/" . $id . "/restore");
		}

		public function UploadFile($folderid, $destfilename, $data, $srcfilename, $fileid = false, $callback = false, $callbackopts = false)
		{
			if ($folderid === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid parent folder ID."), "errorcode" => "invalid_parent_folder_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			// Determine if there is a file at the target already.
			if ($fileid === false)
			{
				$result = $this->GetObjectIDByName($folderid, $destfilename);
				if (!$result["success"])  return $result;
				if ($result["info"] !== false)
				{
					if ($result["info"]["kind"] !== "FILE")  return array("success" => false, "error" => self::ACD_Translate("Parent folder already contains an object named '%s' that is not a file.", $destfilename), "errorcode" => "object_already_exists");

					$fileid = $result["info"]["id"];
				}
			}

			// Calculate MIME type.
			$mimetype = "application/octet-stream";
			$pos = strrpos($destfilename, ".");
			if ($pos !== false)
			{
				$ext = strtolower(substr($destfilename, $pos));
				if ($ext === ".jpg")  $mimetype = "image/jpeg";
				else if ($ext === ".png")  $mimetype = "image/png";
				else if ($ext === ".gif")  $mimetype = "image/gif";
			}

			$fileinfo = array(
				"name" => "content",
				"filename" => $destfilename,
				"type" => $mimetype
			);

			if ($srcfilename !== false)  $fileinfo["datafile"] = $srcfilename;
			else  $fileinfo["data"] = $data;

			if ($fileid === false)
			{
				$options = array(
					"name" => $destfilename,
					"kind" => "FILE",
					"parents" => array($folderid)
				);

				$options = array(
					"debug_callback" => $callback,
					"debug_callback_opts" => $callbackopts,
					"postvars" => array(
						"metadata" => json_encode($options)
					),
					"files" => array($fileinfo)
				);

				return $this->RunAPI("POST", $this->data["contenturl"] . "nodes?suppress=deduplication", $options, 201);
			}
			else
			{
				$options = array(
					"debug_callback" => $callback,
					"debug_callback_opts" => $callbackopts,
					"files" => array($fileinfo)
				);

				return $this->RunAPI("PUT", $this->data["contenturl"] . "nodes/" . $fileid . "/content", $options);
			}
		}

		public function DownloadFile__Internal($response, $body, &$opts)
		{
			fwrite($opts["fp"], $body);

			if (is_callable($opts["callback"]))  call_user_func_array($opts["callback"], array(&$opts));

			return true;
		}

		// Callback option only used when destination is a file.
		public function DownloadFile($destfileorfp, $fileid, $callback = false)
		{
			if ($fileid === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid file ID."), "errorcode" => "invalid_file_id");

			$result = $this->UpdateBearerToken();
			if (!$result["success"])  return $result;

			if ($destfileorfp === false)  $options = array();
			else
			{
				$fp = (is_resource($destfileorfp) ? $destfileorfp : fopen($destfileorfp, "wb"));
				if ($fp === false)  return array("success" => false, "error" => self::ACD_Translate("Invalid destination filename or handle."), "errorcode" => "invalid_filename_or_handle");

				$options = array(
					"read_body_callback" => array($this, "DownloadFile__Internal"),
					"read_body_callback_opts" => array("fp" => $fp, "fileid" => $fileid, "callback" => $callback)
				);
			}

			$result = $this->RunAPI("GET", $this->data["contenturl"] . "nodes/" . $fileid . "/content?download=true", $options, 200, false);

			if ($destfileorfp !== false && !is_resource($destfileorfp))  fclose($fp);

			return $result;
		}

		private static function ACD_Translate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}

		private function RunAPI($method, $url, $options = array(), $expected = 200, $decodebody = true)
		{
			$options2 = array(
				"method" => $method
			);

			$options2 = array_merge($options2, $options);

			if ($this->data["bearertoken"] !== false)
			{
				if (!isset($options2["headers"]))  $options2["headers"] = array();
				$options2["headers"]["Authorization"] = "Bearer " . $this->data["bearertoken"];
			}

			$result = $this->web->Process($url, "auto", $options2);

			if (!$result["success"])  return $result;
			if ($result["response"]["code"] != $expected)  return array("success" => false, "error" => self::ACD_Translate("Expected a %d response from Amazon.  Received '%s'.", $expected, $result["response"]["line"]), "errorcode" => "unexpected_amazon_response", "info" => $result);

			if ($decodebody)  $result["body"] = json_decode($result["body"], true);

			return $result;
		}
	}
?>