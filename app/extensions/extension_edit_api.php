<?php
/*
	FusionPBX Extension Edit JSON API
	Handles create and update operations from extension_edit.php
	
	POST /app/extensions/extension_edit_api.php - Create new extension
	PUT /app/extensions/extension_edit_api.php?id=uuid - Update existing extension
	PATCH /app/extensions/extension_edit_api.php?id=uuid - Update existing extension (partial)
*/

//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

//start output buffering to catch any unexpected output
ob_start();

//set response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

//get request method
$method = $_SERVER['REQUEST_METHOD'];
$extension_id = !empty($_GET['id']) ? trim($_GET['id']) : '';
$extension_uuid = !empty($_POST['extension_uuid']) ? trim($_POST['extension_uuid']) : '';

//determine action (add or update)
if (!empty($extension_id) && is_uuid($extension_id)) {
	$action = "update";
	$extension_uuid = $extension_id;
} elseif (!empty($extension_uuid) && is_uuid($extension_uuid)) {
	$action = "update";
} else {
	$action = "add";
	$extension_uuid = uuid();
}

//check permissions
if ($action == "add" && !permission_exists('extension_add')) {
	http_response_code(403);
	echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: extension_add required']);
	exit;
}
if ($action == "update" && !permission_exists('extension_edit')) {
	http_response_code(403);
	echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: extension_edit required']);
	exit;
}

//add multi-lingual support
$language = new text;
$text = $language->get();

//get JSON input or POST data
$input = [];
if ($method == 'POST' || $method == 'PUT' || $method == 'PATCH') {
	$json_input = json_decode(file_get_contents('php://input'), true);
	if (!empty($json_input)) {
		$input = $json_input;
	} elseif (!empty($_POST)) {
		$input = $_POST;
	}
} else {
	http_response_code(405);
	echo json_encode(['error' => 'Method Not Allowed', 'message' => 'Only POST, PUT, and PATCH methods are supported']);
	exit;
}

//check for limit on add
if ($action == 'add') {
	if (!empty($_SESSION['limit']['extensions']['numeric'])) {
		$sql = "SELECT count(extension_uuid) FROM v_extensions ";
		$sql .= "WHERE (domain_uuid = :domain_uuid ".(permission_exists('extension_domain') ? " OR domain_uuid IS NULL " : null).") ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$total_extensions = $database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		if ($total_extensions >= $_SESSION['limit']['extensions']['numeric']) {
			http_response_code(403);
			echo json_encode([
				'error' => 'Limit Exceeded',
				'message' => 'Maximum extensions limit reached: ' . $_SESSION['limit']['extensions']['numeric']
			]);
			exit;
		}
	}
}

//get and validate required fields
$domain_uuid = $input['domain_uuid'] ?? $_SESSION['domain_uuid'];
$extension = $input['extension'] ?? '';
$number_alias = $input['number_alias'] ?? null;
$password = $input['password'] ?? null;
$accountcode = $input['accountcode'] ?? '';
$effective_caller_id_name = $input['effective_caller_id_name'] ?? '';
$effective_caller_id_number = $input['effective_caller_id_number'] ?? '';
$outbound_caller_id_name = $input['outbound_caller_id_name'] ?? '';
$outbound_caller_id_number = $input['outbound_caller_id_number'] ?? '';
$emergency_caller_id_name = $input['emergency_caller_id_name'] ?? null;
$emergency_caller_id_number = $input['emergency_caller_id_number'] ?? null;
$directory_first_name = $input['directory_first_name'] ?? '';
$directory_last_name = $input['directory_last_name'] ?? '';
$directory_visible = $input['directory_visible'] ?? '';
$directory_exten_visible = $input['directory_exten_visible'] ?? '';
$max_registrations = $input['max_registrations'] ?? '';
$limit_max = $input['limit_max'] ?? '';
$limit_destination = $input['limit_destination'] ?? '';
$user_context = $input['user_context'] ?? '';
$missed_call_app = $input['missed_call_app'] ?? '';
$missed_call_data = $input['missed_call_data'] ?? '';
$toll_allow = $input['toll_allow'] ?? '';
$call_timeout = $input['call_timeout'] ?? '';
$call_group = $input['call_group'] ?? '';
$call_screen_enabled = $input['call_screen_enabled'] ?? '';
$user_record = $input['user_record'] ?? '';
$hold_music = $input['hold_music'] ?? '';
$auth_acl = $input['auth_acl'] ?? '';
$cidr = $input['cidr'] ?? '';
$sip_force_contact = $input['sip_force_contact'] ?? '';
$sip_force_expires = $input['sip_force_expires'] ?? '';
$nibble_account = $input['nibble_account'] ?? null;
$mwi_account = $input['mwi_account'] ?? '';
$sip_bypass_media = $input['sip_bypass_media'] ?? '';
$absolute_codec_string = $input['absolute_codec_string'] ?? '';
$force_ping = $input['force_ping'] ?? '';
$dial_string = $input['dial_string'] ?? '';
$extension_language = $input['extension_language'] ?? '';
$extension_type = $input['extension_type'] ?? '';
$enabled = $input['enabled'] ?? 'false';
$description = $input['description'] ?? '';

//voicemail fields
$voicemail_password = $input['voicemail_password'] ?? null;
$voicemail_enabled = $input['voicemail_enabled'] ?? 'false';
$voicemail_mail_to = $input['voicemail_mail_to'] ?? '';
$voicemail_transcription_enabled = $input['voicemail_transcription_enabled'] ?? '';
$voicemail_file = $input['voicemail_file'] ?? '';
$voicemail_local_after_email = $input['voicemail_local_after_email'] ?? '';

//prevent domain_uuid from being set by someone without permission
if (!permission_exists('extension_domain')) {
	$domain_uuid = $_SESSION['domain_uuid'];
}

//get domain name for user_context default
$sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
$parameters['domain_uuid'] = $domain_uuid;
$database = new database;
$domain_name = $database->select($sql, $parameters, 'column');
unset($sql, $parameters);

//validate required fields
$errors = [];
if (empty($extension)) {
	$errors[] = 'extension is required';
}
if (permission_exists('extension_enabled') && empty($enabled)) {
	$errors[] = 'enabled is required';
}

if (!empty($errors)) {
	http_response_code(400);
	echo json_encode([
		'error' => 'Validation Error',
		'message' => 'Required fields missing',
		'errors' => $errors
	]);
	exit;
}

//outbound caller id number - only allow numeric and +
if (!empty($outbound_caller_id_number)) {
	$outbound_caller_id_number = preg_replace('#[^\+0-9]#', '', $outbound_caller_id_number);
}

//validate and process CIDR
if (!empty($cidr)) {
	$cidrs = preg_split("/[\s,]+/", $cidr);
	$ips = array();
	foreach ($cidrs as $ipaddr) {
		$cx = strpos($ipaddr, '/');
		if ($cx) {
			$subnet = (int)(substr($ipaddr, $cx+1));
			$ipaddr = substr($ipaddr, 0, $cx);
		} else {
			$subnet = 32;
		}
		if (($addr = inet_pton($ipaddr)) !== false) {
			$ips[] = $ipaddr.'/'.$subnet;
		}
	}
	$cidr = implode(',', $ips);
}

//change toll allow delimiter
if (!empty($toll_allow)) {
	$toll_allow = str_replace(',', ':', $toll_allow);
}

//handle password - if update and no permission, get from database
if ($action == "update" && !permission_exists('extension_password') && empty($password)) {
	$sql = "SELECT password FROM v_extensions WHERE extension_uuid = :extension_uuid AND domain_uuid = :domain_uuid";
	$parameters['extension_uuid'] = $extension_uuid;
	$parameters['domain_uuid'] = $domain_uuid;
	$row = $database->select($sql, $parameters, 'row');
	if (is_array($row) && @sizeof($row) != 0) {
		$password = $row["password"];
	}
	unset($sql, $parameters, $row);
}

//generate password if needed
if ($action == "add" && empty($password)) {
	$password_length = $_SESSION['extension']['password_length']['numeric'] ?? 15;
	$password_strength = $_SESSION['extension']['password_strength']['numeric'] ?? 1;
	$password = generate_password($password_length, $password_strength);
}

//separate the language components into language, dialect and voice
$extension_dialect = 'us';
$extension_voice = 'callie';
if (!empty($extension_language)) {
	$language_array = explode("/", $extension_language);
	$extension_language = $language_array[0] ?? 'en';
	$extension_dialect = $language_array[1] ?? 'us';
	$extension_voice = $language_array[2] ?? 'callie';
} else {
	$extension_language = 'en';
}

//prepare mwi account
if (!empty($mwi_account) && strpos($mwi_account, '@') === false) {
	$mwi_account .= "@".$domain_name;
}

//build the extension array
$x = 0;
$array['extensions'][$x]["domain_uuid"] = is_uuid($domain_uuid) ? $domain_uuid : null;
$array['extensions'][$x]["extension_uuid"] = $extension_uuid;
$array['extensions'][$x]["extension"] = $extension;
if (permission_exists('number_alias')) {
	$array['extensions'][$x]["number_alias"] = $number_alias;
}
if (!empty($password)) {
	$array['extensions'][$x]["password"] = $password;
}
if (permission_exists('extension_accountcode')) {
	$array['extensions'][$x]["accountcode"] = $accountcode;
} else {
	if ($action == "add") {
		$array['extensions'][$x]["accountcode"] = get_accountcode();
	}
}
if (permission_exists("effective_caller_id_name")) {
	$array['extensions'][$x]["effective_caller_id_name"] = $effective_caller_id_name;
}
if (permission_exists("effective_caller_id_number")) {
	$array['extensions'][$x]["effective_caller_id_number"] = $effective_caller_id_number;
}
if (permission_exists("outbound_caller_id_name")) {
	$array['extensions'][$x]["outbound_caller_id_name"] = $outbound_caller_id_name;
}
if (permission_exists("outbound_caller_id_number")) {
	$array['extensions'][$x]["outbound_caller_id_number"] = $outbound_caller_id_number;
}
if (permission_exists("emergency_caller_id_name")) {
	$array['extensions'][$x]["emergency_caller_id_name"] = $emergency_caller_id_name;
}
if (permission_exists("emergency_caller_id_number")) {
	$array['extensions'][$x]["emergency_caller_id_number"] = $emergency_caller_id_number;
}
if (permission_exists("extension_directory")) {
	$array['extensions'][$x]["directory_first_name"] = $directory_first_name;
	$array['extensions'][$x]["directory_last_name"] = $directory_last_name;
	$array['extensions'][$x]["directory_visible"] = $directory_visible;
	$array['extensions'][$x]["directory_exten_visible"] = $directory_exten_visible;
}
if (permission_exists("extension_max_registrations")) {
	$array['extensions'][$x]["max_registrations"] = $max_registrations;
} else {
	if ($action == "add") {
		$array['extensions'][$x]["max_registrations"] = $_SESSION['extension']['max_registrations']['numeric'] ?? '';
	}
}
if (permission_exists("extension_limit")) {
	$array['extensions'][$x]["limit_max"] = $limit_max;
	$array['extensions'][$x]["limit_destination"] = $limit_destination;
}
if (permission_exists("extension_user_context")) {
	$array['extensions'][$x]["user_context"] = $user_context;
} else {
	if ($action == "add") {
		$array['extensions'][$x]["user_context"] = $domain_name;
	}
}
if (permission_exists('extension_missed_call')) {
	$array['extensions'][$x]["missed_call_app"] = $missed_call_app;
	$array['extensions'][$x]["missed_call_data"] = $missed_call_data;
}
if (permission_exists('extension_toll')) {
	$array['extensions'][$x]["toll_allow"] = $toll_allow;
}
if (!empty($call_timeout)) {
	$array['extensions'][$x]["call_timeout"] = $call_timeout;
}
if (permission_exists("extension_call_group")) {
	$array['extensions'][$x]["call_group"] = $call_group;
}
$array['extensions'][$x]["call_screen_enabled"] = $call_screen_enabled;
if (permission_exists('extension_user_record')) {
	$array['extensions'][$x]["user_record"] = $user_record;
}
if (permission_exists('extension_hold_music')) {
	$array['extensions'][$x]["hold_music"] = $hold_music;
}
if (permission_exists("extension_advanced")) {
	$array['extensions'][$x]["auth_acl"] = $auth_acl;
	if (permission_exists("extension_cidr")) {
		$array['extensions'][$x]["cidr"] = $cidr;
	}
	$array['extensions'][$x]["sip_force_contact"] = $sip_force_contact;
	$array['extensions'][$x]["sip_force_expires"] = $sip_force_expires;
	if (permission_exists('extension_nibble_account')) {
		if (!empty($nibble_account)) {
			$array['extensions'][$x]["nibble_account"] = $nibble_account;
		}
	}
	$array['extensions'][$x]["mwi_account"] = $mwi_account;
	$array['extensions'][$x]["sip_bypass_media"] = $sip_bypass_media;
	if (permission_exists('extension_absolute_codec_string')) {
		$array['extensions'][$x]["absolute_codec_string"] = $absolute_codec_string;
	}
	if (permission_exists('extension_force_ping')) {
		$array['extensions'][$x]["force_ping"] = $force_ping;
	}
	if (permission_exists('extension_dial_string')) {
		$array['extensions'][$x]["dial_string"] = $dial_string;
	}
}
if (permission_exists('extension_language')) {
	$array['extensions'][$x]["extension_language"] = $extension_language;
	$array['extensions'][$x]["extension_dialect"] = $extension_dialect;
	$array['extensions'][$x]["extension_voice"] = $extension_voice;
}
if (permission_exists('extension_type')) {
	$array['extensions'][$x]["extension_type"] = $extension_type;
}
if (permission_exists('extension_enabled')) {
	$array['extensions'][$x]["enabled"] = $enabled;
}
$array['extensions'][$x]["description"] = $description;

//handle voicemail if voicemail app exists
if (is_dir($_SERVER["DOCUMENT_ROOT"].PROJECT_PATH.'/app/voicemails')) {
	$voicemail_id = $extension;
	if (permission_exists('number_alias') && !empty($number_alias)) {
		$voicemail_id = $number_alias;
	}
	
	if ($voicemail_id !== null) {
		//get the voicemail_uuid
		$sql = "SELECT voicemail_uuid FROM v_voicemails WHERE voicemail_id = :voicemail_id AND domain_uuid = :domain_uuid";
		$parameters['voicemail_id'] = $voicemail_id;
		$parameters['domain_uuid'] = $domain_uuid;
		$row = $database->select($sql, $parameters, 'row');
		$voicemail_uuid = null;
		if (is_array($row) && @sizeof($row) != 0) {
			$voicemail_uuid = $row["voicemail_uuid"];
		}
		unset($sql, $parameters, $row);
		
		//if voicemail_uuid does not exist then get a new uuid
		if (!is_uuid($voicemail_uuid)) {
			$voicemail_uuid = uuid();
			$voicemail_tutorial = 'true';
			if (!permission_exists('voicemail_transcription_enabled')) {
				$voicemail_transcription_enabled = $_SESSION['voicemail']['transcription_enabled']['boolean'] ?? 'false';
			}
		}
		
		//set voicemail password if empty
		if (empty($voicemail_password)) {
			$voicemail_password_length = $_SESSION['voicemail']['password_length']['numeric'] ?? 15;
			$voicemail_password = generate_password($voicemail_password_length, 1);
		}
		
		//add the voicemail to the array
		$array["voicemails"][$x]["domain_uuid"] = $domain_uuid;
		$array["voicemails"][$x]["voicemail_uuid"] = $voicemail_uuid;
		$array["voicemails"][$x]["voicemail_id"] = $voicemail_id;
		$array["voicemails"][$x]["voicemail_password"] = $voicemail_password;
		$array["voicemails"][$x]["voicemail_mail_to"] = $voicemail_mail_to;
		if (permission_exists('voicemail_file')) {
			$array["voicemails"][$x]["voicemail_file"] = $voicemail_file;
		}
		if (permission_exists('voicemail_local_after_email')) {
			$array["voicemails"][$x]["voicemail_local_after_email"] = $voicemail_local_after_email;
		}
		$array["voicemails"][$x]["voicemail_transcription_enabled"] = $voicemail_transcription_enabled;
		$array["voicemails"][$x]["voicemail_tutorial"] = $voicemail_tutorial ?? null;
		$array["voicemails"][$x]["voicemail_enabled"] = $voicemail_enabled;
		$array["voicemails"][$x]["voicemail_description"] = $description;
	}
}

//save to the database
$database->app_name = 'extensions';
$database->app_uuid = 'e68d9689-2769-e013-28fa-6214bf47fca3';
if (is_uuid($extension_uuid)) {
	$database->uuid($extension_uuid);
}
$database->save($array);
$message = $database->message;

//reload the access control list
if (permission_exists("extension_cidr")) {
	$event_socket = event_socket::create();
	if ($event_socket->is_connected()) {
		event_socket::api("reloadacl");
	}
}

//synchronize configuration
if (is_writable($_SESSION['switch']['extensions']['dir'] ?? '')) {
	try {
		$ext = new extension;
		$ext->xml();
		unset($ext);
	} catch (Exception $e) {
		error_log("Error in extension->xml(): " . $e->getMessage());
		// Continue anyway - the extension is saved in the database
	}
}

//return success response
// Discard any buffered output and ensure clean JSON output
ob_end_clean();
echo json_encode([
	'success' => true,
	'action' => $action,
	'extension_uuid' => $extension_uuid,
	'message' => $action == 'add' ? 'Extension created successfully' : 'Extension updated successfully',
	'extension' => [
		'extension_uuid' => $extension_uuid,
		'extension' => $extension,
		'enabled' => $enabled
	]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;

?>

