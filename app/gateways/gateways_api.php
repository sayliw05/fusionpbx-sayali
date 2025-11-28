<?php
/*
	FusionPBX Gateway JSON API
	Handles list, delete, toggle, start, stop, copy operations from gateways.php
	
	GET /app/gateways/gateways_api.php - List all gateways
	GET /app/gateways/gateways_api.php?id=uuid - Get single gateway
	POST /app/gateways/gateways_api.php?action=delete - Delete gateways
	POST /app/gateways/gateways_api.php?action=toggle - Toggle enabled/disabled
	POST /app/gateways/gateways_api.php?action=start - Start gateways
	POST /app/gateways/gateways_api.php?action=stop - Stop gateways
	POST /app/gateways/gateways_api.php?action=copy - Copy gateways
*/

//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

//set response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

//get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = !empty($_GET['action']) ? trim($_GET['action']) : '';
$gateway_id = !empty($_GET['id']) ? trim($_GET['id']) : '';

//handle POST actions (delete, toggle, start, stop, copy)
if ($method == 'POST' && !empty($action)) {
	//get JSON input
	$input = json_decode(file_get_contents('php://input'), true);
	if (empty($input) && !empty($_POST)) {
		$input = $_POST;
	}
	
	//get gateway UUIDs from input
	$gateways = [];
	if (!empty($input['gateways']) && is_array($input['gateways'])) {
		foreach ($input['gateways'] as $gw) {
			if (!empty($gw['uuid']) && is_uuid($gw['uuid'])) {
				$gateways[] = ['uuid' => $gw['uuid'], 'checked' => 'true'];
			}
		}
	} elseif (!empty($input['gateway_uuid']) && is_uuid($input['gateway_uuid'])) {
		$gateways[] = ['uuid' => $input['gateway_uuid'], 'checked' => 'true'];
	} elseif (!empty($gateway_id) && is_uuid($gateway_id)) {
		$gateways[] = ['uuid' => $gateway_id, 'checked' => 'true'];
	}
	
	if (empty($gateways)) {
		http_response_code(400);
		echo json_encode(['error' => 'Bad Request', 'message' => 'No valid gateway UUIDs provided']);
		exit;
	}
	
	//process action
	switch ($action) {
		case 'delete':
			if (!permission_exists('gateway_delete')) {
				http_response_code(403);
				echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: gateway_delete required']);
				exit;
			}
			
			$obj = new gateways;
			$obj->delete($gateways);
			
			echo json_encode([
				'success' => true,
				'message' => 'Gateways deleted successfully',
				'count' => count($gateways)
			]);
			exit;
			
		case 'toggle':
			if (!permission_exists('gateway_edit')) {
				http_response_code(403);
				echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: gateway_edit required']);
				exit;
			}
			
			$obj = new gateways;
			$obj->toggle($gateways);
			
			echo json_encode([
				'success' => true,
				'message' => 'Gateways toggled successfully',
				'count' => count($gateways)
			]);
			exit;
			
		case 'start':
			if (!permission_exists('gateway_edit')) {
				http_response_code(403);
				echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: gateway_edit required']);
				exit;
			}
			
			$obj = new gateways;
			$obj->start($gateways);
			
			echo json_encode([
				'success' => true,
				'message' => 'Gateways started successfully',
				'count' => count($gateways)
			]);
			exit;
			
		case 'stop':
			if (!permission_exists('gateway_edit')) {
				http_response_code(403);
				echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: gateway_edit required']);
				exit;
			}
			
			$obj = new gateways;
			$obj->stop($gateways);
			
			echo json_encode([
				'success' => true,
				'message' => 'Gateways stopped successfully',
				'count' => count($gateways)
			]);
			exit;
			
		case 'copy':
			if (!permission_exists('gateway_add')) {
				http_response_code(403);
				echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: gateway_add required']);
				exit;
			}
			
			$obj = new gateways;
			$obj->copy($gateways);
			
			echo json_encode([
				'success' => true,
				'message' => 'Gateways copied successfully',
				'count' => count($gateways)
			]);
			exit;
			
		default:
			http_response_code(400);
			echo json_encode(['error' => 'Bad Request', 'message' => 'Invalid action. Supported: delete, toggle, start, stop, copy']);
			exit;
	}
}

//handle GET requests (list or single gateway)
if ($method == 'GET') {
	//check permissions
	if (!permission_exists('gateway_view')) {
		http_response_code(403);
		echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: gateway_view required']);
		exit;
	}
	
	//add multi-lingual support
	$language = new text;
	$text = $language->get();
	
	//get query parameters
	$search = !empty($_GET["search"]) ? trim($_GET["search"]) : '';
	$show = !empty($_GET["show"]) ? trim($_GET["show"]) : '';
	
	//if single gateway requested
	if (!empty($gateway_id) && is_uuid($gateway_id)) {
		$sql = "SELECT gateway_uuid, domain_uuid, gateway, username, realm, proxy, register_proxy, outbound_proxy, ";
		$sql .= "enabled, description, profile, from_user, from_domain, register, expire_seconds, retry_seconds, ";
		$sql .= "distinct_to, auth_username, register_transport, contact_params, extension, ping, ping_min, ";
		$sql .= "ping_max, contact_in_ping, channels, caller_id_in_from, supress_cng, sip_cid_type, codec_prefs, ";
		$sql .= "extension_in_contact, context, hostname ";
		$sql .= "FROM v_gateways ";
		$sql .= "WHERE gateway_uuid = :gateway_uuid ";
		
		//apply domain filter if user doesn't have gateway_all permission
		if (!permission_exists('gateway_all')) {
			$sql .= "AND (domain_uuid = :domain_uuid ";
			if (permission_exists('gateway_domain')) {
				$sql .= "OR domain_uuid IS NULL ";
			}
			$sql .= ") ";
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		}
		
		$parameters['gateway_uuid'] = $gateway_id;
		
		$database = new database;
		$gateway = $database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		
		if (empty($gateway)) {
			http_response_code(404);
			echo json_encode(['error' => 'Not Found', 'message' => 'Gateway not found']);
			exit;
		}
		
		//enhance with status if ESL is connected
		$esl = event_socket::create();
		if ($esl->is_connected()) {
			$gateway_name = $gateway['gateway'];
			$cmd = 'sofia xmlstatus gateway ' . $gateway_name;
			$status_xml = trim(event_socket::api($cmd));
			
			if ($status_xml && $status_xml != "Invalid Gateway!") {
				try {
					$xml = new SimpleXMLElement($status_xml);
					$gateway['status'] = (string)$xml->status ?? 'unknown';
					$gateway['status_text'] = (string)$xml->status_text ?? '';
					
					if (isset($xml->registration)) {
						$gateway['registered'] = true;
						$gateway['registration_status'] = (string)$xml->registration->status ?? '';
					} else {
						$gateway['registered'] = false;
					}
				} catch (Exception $e) {
					$gateway['status'] = 'unknown';
					$gateway['registered'] = false;
				}
			} else {
				$gateway['status'] = 'unknown';
				$gateway['registered'] = false;
			}
		}
		
		echo json_encode([
			'success' => true,
			'gateway' => $gateway
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit;
	}
	
	//list all gateways
	//connect to event socket for gateway status (optional)
	$esl = event_socket::create();
	$esl_connected = $esl->is_connected();
	
	//build SQL query
	$sql = "SELECT gateway_uuid, domain_uuid, gateway, username, realm, proxy, register_proxy, outbound_proxy, ";
	$sql .= "enabled, description, profile, from_user, from_domain, register, expire_seconds, retry_seconds ";
	$sql .= "FROM v_gateways ";
	$sql .= "WHERE true ";
	
	//apply domain filter if user doesn't have gateway_all permission
	if (!($show == "all" && permission_exists('gateway_all'))) {
		$sql .= "AND (domain_uuid = :domain_uuid ";
		if (permission_exists('gateway_domain')) {
			$sql .= "OR domain_uuid IS NULL ";
		}
		$sql .= ") ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	}
	
	//apply search filter
	if (!empty($search)) {
		$search_lower = strtolower($search);
		$sql .= "AND (";
		$sql .= "LOWER(gateway) LIKE :search ";
		$sql .= "OR LOWER(username) LIKE :search ";
		$sql .= "OR LOWER(auth_username) LIKE :search ";
		$sql .= "OR LOWER(from_user) LIKE :search ";
		$sql .= "OR LOWER(from_domain) LIKE :search ";
		$sql .= "OR LOWER(proxy) LIKE :search ";
		$sql .= "OR LOWER(register_proxy) LIKE :search ";
		$sql .= "OR LOWER(outbound_proxy) LIKE :search ";
		$sql .= "OR LOWER(description) LIKE :search ";
		$sql .= ") ";
		$parameters['search'] = '%' . $search_lower . '%';
	}
	
	$sql .= "ORDER BY gateway ASC ";
	
	//execute query
	$database = new database;
	$gateways = $database->select($sql, $parameters ?? null, 'all');
	unset($sql, $parameters);
	
	//enhance gateway data with status if ESL is connected
	if ($esl_connected && !empty($gateways)) {
		foreach ($gateways as &$gateway) {
			//get gateway status from FreeSWITCH
			$gateway_name = $gateway['gateway'];
			$cmd = 'sofia xmlstatus gateway ' . $gateway_name;
			$status_xml = trim(event_socket::api($cmd));
			
			//parse status if valid
			if ($status_xml && $status_xml != "Invalid Gateway!") {
				try {
					$xml = new SimpleXMLElement($status_xml);
					$gateway['status'] = (string)$xml->status ?? 'unknown';
					$gateway['status_text'] = (string)$xml->status_text ?? '';
					
					//extract registration status
					if (isset($xml->registration)) {
						$gateway['registered'] = true;
						$gateway['registration_status'] = (string)$xml->registration->status ?? '';
					} else {
						$gateway['registered'] = false;
					}
				} catch (Exception $e) {
					$gateway['status'] = 'unknown';
					$gateway['registered'] = false;
				}
			} else {
				$gateway['status'] = 'unknown';
				$gateway['registered'] = false;
			}
		}
		unset($gateway); //unset reference
	}
	
	//return JSON response
	$response = [
		'success' => true,
		'count' => count($gateways),
		'gateways' => $gateways
	];
	
	//add metadata
	$response['metadata'] = [
		'timestamp' => date('c'),
		'domain_uuid' => $_SESSION['domain_uuid'] ?? null,
		'esl_connected' => $esl_connected
	];
	
	echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

//method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method Not Allowed', 'message' => 'Only GET and POST methods are supported']);

?>
