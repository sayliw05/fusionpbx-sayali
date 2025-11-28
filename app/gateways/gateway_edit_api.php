<?php
/*
	FusionPBX Gateway Edit JSON API
	Handles create and update operations from gateway_edit.php
	
	POST /app/gateways/gateway_edit_api.php - Create new gateway
	PUT /app/gateways/gateway_edit_api.php?id=uuid - Update existing gateway
	PATCH /app/gateways/gateway_edit_api.php?id=uuid - Update existing gateway (partial)
*/

//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

//set response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

//get request method
$method = $_SERVER['REQUEST_METHOD'];
$gateway_id = !empty($_GET['id']) ? trim($_GET['id']) : '';
$gateway_uuid = !empty($_POST['gateway_uuid']) ? trim($_POST['gateway_uuid']) : '';

//determine action (add or update)
if (!empty($gateway_id) && is_uuid($gateway_id)) {
	$action = "update";
	$gateway_uuid = $gateway_id;
} elseif (!empty($gateway_uuid) && is_uuid($gateway_uuid)) {
	$action = "update";
} else {
	$action = "add";
	$gateway_uuid = uuid();
}

//check permissions
if ($action == "add" && !permission_exists('gateway_add')) {
	http_response_code(403);
	echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: gateway_add required']);
	exit;
}
if ($action == "update" && !permission_exists('gateway_edit')) {
	http_response_code(403);
	echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: gateway_edit required']);
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
	if (!empty($_SESSION['limit']['gateways']['numeric'])) {
		$sql = "SELECT count(gateway_uuid) FROM v_gateways ";
		$sql .= "WHERE (domain_uuid = :domain_uuid ".(permission_exists('gateway_domain') ? " OR domain_uuid IS NULL " : null).") ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$total_gateways = $database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		if ($total_gateways >= $_SESSION['limit']['gateways']['numeric']) {
			http_response_code(403);
			echo json_encode([
				'error' => 'Limit Exceeded',
				'message' => 'Maximum gateways limit reached: ' . $_SESSION['limit']['gateways']['numeric']
			]);
			exit;
		}
	}
}

//get and validate required fields
$domain_uuid = $input['domain_uuid'] ?? $_SESSION['domain_uuid'];
$gateway = $input['gateway'] ?? '';
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';
$distinct_to = $input['distinct_to'] ?? '';
$auth_username = $input['auth_username'] ?? '';
$realm = $input['realm'] ?? '';
$from_user = $input['from_user'] ?? '';
$from_domain = $input['from_domain'] ?? '';
$proxy = $input['proxy'] ?? '';
$register_proxy = $input['register_proxy'] ?? '';
$outbound_proxy = $input['outbound_proxy'] ?? '';
$expire_seconds = $input['expire_seconds'] ?? '';
$register = $input['register'] ?? 'false';
$register_transport = $input['register_transport'] ?? '';
$contact_params = $input['contact_params'] ?? '';
$retry_seconds = $input['retry_seconds'] ?? '';
$extension = $input['extension'] ?? '';
$ping = $input['ping'] ?? '';
$ping_min = $input['ping_min'] ?? '';
$ping_max = $input['ping_max'] ?? '';
$contact_in_ping = $input['contact_in_ping'] ?? '';
$channels = $input['channels'] ?? '0';
$caller_id_in_from = $input['caller_id_in_from'] ?? '';
$supress_cng = $input['supress_cng'] ?? '';
$sip_cid_type = $input['sip_cid_type'] ?? '';
$codec_prefs = $input['codec_prefs'] ?? '';
$extension_in_contact = $input['extension_in_contact'] ?? '';
$context = $input['context'] ?? '';
$profile = $input['profile'] ?? '';
$hostname = $input['hostname'] ?? '';
$enabled = $input['enabled'] ?? 'false';
$description = $input['description'] ?? '';

//prevent domain_uuid from being set by someone without permission
if (!permission_exists('gateway_domain')) {
	$domain_uuid = $_SESSION['domain_uuid'];
}

//validate required fields
$errors = [];
if (empty($gateway)) {
	$errors[] = 'gateway is required';
}
if ($register == "true") {
	if (empty($username)) {
		$errors[] = 'username is required when register is true';
	}
	if (empty($password)) {
		$errors[] = 'password is required when register is true';
	}
}
if (empty($proxy)) {
	$errors[] = 'proxy is required';
}
if (empty($expire_seconds)) {
	$errors[] = 'expire_seconds is required';
}
if (empty($register)) {
	$errors[] = 'register is required';
}
if (empty($retry_seconds)) {
	$errors[] = 'retry_seconds is required';
}
if (empty($context)) {
	$errors[] = 'context is required';
}
if (empty($profile)) {
	$errors[] = 'profile is required';
}
if (empty($enabled)) {
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

//build the gateway array
$x = 0;
$array['gateways'][$x]["domain_uuid"] = is_uuid($domain_uuid) ? $domain_uuid : null;
$array['gateways'][$x]["gateway_uuid"] = $gateway_uuid;
$array['gateways'][$x]["gateway"] = $gateway;
$array['gateways'][$x]["username"] = $username;
$array['gateways'][$x]["password"] = $password;
$array['gateways'][$x]["distinct_to"] = $distinct_to;
$array['gateways'][$x]["auth_username"] = $auth_username;
$array['gateways'][$x]["realm"] = $realm;
$array['gateways'][$x]["from_user"] = $from_user;
$array['gateways'][$x]["from_domain"] = $from_domain;
$array['gateways'][$x]["proxy"] = $proxy;
$array['gateways'][$x]["register_proxy"] = $register_proxy;
$array['gateways'][$x]["outbound_proxy"] = $outbound_proxy;
$array['gateways'][$x]["expire_seconds"] = $expire_seconds;
$array['gateways'][$x]["register"] = $register;
$array['gateways'][$x]["register_transport"] = $register_transport;
$array['gateways'][$x]["contact_params"] = $contact_params;
$array['gateways'][$x]["retry_seconds"] = $retry_seconds;
$array['gateways'][$x]["extension"] = $extension;
$array['gateways'][$x]["ping"] = $ping;
$array['gateways'][$x]["ping_min"] = $ping_min;
$array['gateways'][$x]["ping_max"] = $ping_max;
$array['gateways'][$x]["contact_in_ping"] = $contact_in_ping;
$array['gateways'][$x]["channels"] = $channels;
$array['gateways'][$x]["caller_id_in_from"] = $caller_id_in_from;
$array['gateways'][$x]["supress_cng"] = $supress_cng;
$array['gateways'][$x]["sip_cid_type"] = $sip_cid_type;
$array['gateways'][$x]["codec_prefs"] = $codec_prefs;
$array['gateways'][$x]["extension_in_contact"] = $extension_in_contact;
$array['gateways'][$x]["context"] = $context;
$array['gateways'][$x]["profile"] = $profile;
$array['gateways'][$x]["hostname"] = empty($hostname) ? null : $hostname;
$array['gateways'][$x]["enabled"] = $enabled;
$array['gateways'][$x]["description"] = $description;

//update gateway session variable
if ($enabled == 'true') {
	$_SESSION['gateways'][$gateway_uuid] = $gateway;
} else {
	unset($_SESSION['gateways'][$gateway_uuid]);
}

//save to the database
$database = new database;
$database->app_name = 'gateways';
$database->app_uuid = '297ab33e-2c2f-8196-552c-f3567d2caaf8';
if (is_uuid($gateway_uuid)) {
	$database->uuid($gateway_uuid);
}
$database->save($array);
$message = $database->message;

//remove xml file (if any) if not enabled
if ($enabled != 'true' && !empty($_SESSION['switch']['sip_profiles']['dir'])) {
	$gateway_xml_file = $_SESSION['switch']['sip_profiles']['dir']."/".$profile."/v_".$gateway_uuid.".xml";
	if (file_exists($gateway_xml_file)) {
		unlink($gateway_xml_file);
	}
}

//synchronize configuration
save_gateway_xml();

//clear the cache
$esl = event_socket::create();
$hostname = trim(event_socket::api('switchname'));
$cache = new cache;
$cache->delete("configuration:sofia.conf:".$hostname);

//rescan the external profile to look for new or stopped gateways
$esl = event_socket::create();
$response = event_socket::api('sofia profile external rescan');
usleep(1000);

//clear the apply settings reminder
$_SESSION["reload_xml"] = false;

//return success response
echo json_encode([
	'success' => true,
	'action' => $action,
	'gateway_uuid' => $gateway_uuid,
	'message' => $action == 'add' ? 'Gateway created successfully' : 'Gateway updated successfully',
	'gateway' => [
		'gateway_uuid' => $gateway_uuid,
		'gateway' => $gateway,
		'enabled' => $enabled
	]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

?>

