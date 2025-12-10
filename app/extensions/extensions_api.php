<?php
/*
	FusionPBX Extensions JSON API
	Handles list, delete, toggle operations from extensions.php
	
	GET /app/extensions/extensions_api.php - List all extensions
	GET /app/extensions/extensions_api.php?id=uuid - Get single extension
	POST /app/extensions/extensions_api.php?action=delete - Delete extensions
	POST /app/extensions/extensions_api.php?action=toggle - Toggle enabled/disabled
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
$extension_id = !empty($_GET['id']) ? trim($_GET['id']) : '';

//handle POST actions (delete, toggle)
if ($method == 'POST' && !empty($action)) {
	//get JSON input
	$input = json_decode(file_get_contents('php://input'), true);
	if (empty($input) && !empty($_POST)) {
		$input = $_POST;
	}
	
	//get extension UUIDs from input
	$extensions = [];
	if (!empty($input['extensions']) && is_array($input['extensions'])) {
		foreach ($input['extensions'] as $ext) {
			if (!empty($ext['uuid']) && is_uuid($ext['uuid'])) {
				$extensions[] = ['uuid' => $ext['uuid'], 'checked' => 'true'];
			}
		}
	} elseif (!empty($input['extension_uuid']) && is_uuid($input['extension_uuid'])) {
		$extensions[] = ['uuid' => $input['extension_uuid'], 'checked' => 'true'];
	} elseif (!empty($extension_id) && is_uuid($extension_id)) {
		$extensions[] = ['uuid' => $extension_id, 'checked' => 'true'];
	}
	
	if (empty($extensions)) {
		http_response_code(400);
		echo json_encode(['error' => 'Bad Request', 'message' => 'No valid extension UUIDs provided']);
		exit;
	}
	
	//process action
	switch ($action) {
		case 'delete_extension':
		case 'delete_extension_voicemail':
			if (!permission_exists('extension_delete')) {
				http_response_code(403);
				echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: extension_delete required']);
				exit;
			}
			
			$obj = new extension;
			if ($action == 'delete_extension_voicemail' && permission_exists('voicemail_delete')) {
				$obj->delete_voicemail = true;
			}
			$obj->delete($extensions);
			
			echo json_encode([
				'success' => true,
				'message' => 'Extensions deleted successfully',
				'count' => count($extensions)
			]);
			exit;
			
		case 'toggle':
			if (!permission_exists('extension_enabled')) {
				http_response_code(403);
				echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: extension_enabled required']);
				exit;
			}
			
			$obj = new extension;
			$obj->toggle($extensions);
			
			echo json_encode([
				'success' => true,
				'message' => 'Extensions toggled successfully',
				'count' => count($extensions)
			]);
			exit;
			
		default:
			http_response_code(400);
			echo json_encode(['error' => 'Bad Request', 'message' => 'Invalid action. Supported: delete_extension, delete_extension_voicemail, toggle']);
			exit;
	}
}

//handle GET requests (list or single extension)
if ($method == 'GET') {
	//check permissions
	if (!permission_exists('extension_view')) {
		http_response_code(403);
		echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: extension_view required']);
		exit;
	}
	
	//add multi-lingual support
	$language = new text;
	$text = $language->get();
	
	//initialize the database object
	$database = new database;
	
	//get query parameters
	$search = !empty($_GET["search"]) ? trim($_GET["search"]) : '';
	$show = !empty($_GET["show"]) ? trim($_GET["show"]) : '';
	$domain_uuid_param = !empty($_GET["domain_uuid"]) ? trim($_GET["domain_uuid"]) : '';
	$domain_name_param = !empty($_GET["domain_name"]) ? trim($_GET["domain_name"]) : '';
	$order_by = !empty($_GET["order_by"]) ? trim($_GET["order_by"]) : 'extension';
	$order = !empty($_GET["order"]) ? trim($_GET["order"]) : 'asc';
	$sort = $order_by == 'extension' ? 'natural' : null;
	
	//if domain_name is provided, look up domain_uuid
	$filter_domain_uuid = null;
	if (!empty($domain_uuid_param) && is_uuid($domain_uuid_param)) {
		$filter_domain_uuid = $domain_uuid_param;
	} elseif (!empty($domain_name_param)) {
		//look up domain_uuid from domain_name
		$sql_domain = "SELECT domain_uuid FROM v_domains WHERE domain_name = :domain_name AND domain_enabled = 'true' LIMIT 1";
		$parameters_domain = ['domain_name' => $domain_name_param];
		$filter_domain_uuid = $database->select($sql_domain, $parameters_domain, 'column');
		unset($sql_domain, $parameters_domain);
	}
	
	//if single extension requested
	if (!empty($extension_id) && is_uuid($extension_id)) {
		$sql = "select e.*, ";
		$sql .= "( ";
		$sql .= "	select device_uuid ";
		$sql .= "	from v_device_lines ";
		$sql .= "	where domain_uuid = e.domain_uuid ";
		$sql .= "	and user_id = e.extension ";
		$sql .= "	limit 1 ";
		$sql .= ") AS device_uuid ";
		$sql .= "from v_extensions as e ";
		$sql .= "where e.extension_uuid = :extension_uuid ";
		
		//apply domain filter if user doesn't have extension_all permission
		if (!permission_exists('extension_all')) {
			$sql .= "AND domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		}
		
		$parameters['extension_uuid'] = $extension_id;
		
		$extension = $database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		
		if (empty($extension)) {
			http_response_code(404);
			echo json_encode(['error' => 'Not Found', 'message' => 'Extension not found']);
			exit;
		}
		
		//get registration status if permission exists
		if (permission_exists('extension_registered')) {
			$obj = new registrations;
			$registrations = $obj->get('all');
			
			$extension_number = $extension['extension'].'@'.$_SESSION['domains'][$extension['domain_uuid']]['domain_name'];
			$extension_number_alias = $extension['number_alias'];
			if (!empty($extension_number_alias)) {
				$extension_number_alias .= '@'.$_SESSION['domains'][$extension['domain_uuid']]['domain_name'];
			}
			
			$found_count = 0;
			if (is_array($registrations)) {
				foreach ($registrations as $array) {
					if ($extension_number == $array['user'] || ($extension_number_alias != '' && $extension_number_alias == $array['user'])) {
						$found_count++;
					}
				}
			}
			$extension['registered'] = $found_count > 0;
			$extension['registration_count'] = $found_count;
		}
		
		echo json_encode([
			'success' => true,
			'extension' => $extension
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit;
	}
	
	//list all extensions
	//determine which domain_uuid to use for filtering
	$domain_uuid_to_filter = null;
	if (!empty($filter_domain_uuid)) {
		//use explicitly provided domain_uuid (from domain_uuid or domain_name parameter)
		//only allow if user has extension_all permission or if it matches their session domain
		if (permission_exists('extension_all') || $filter_domain_uuid == $_SESSION['domain_uuid']) {
			$domain_uuid_to_filter = $filter_domain_uuid;
		} else {
			//user doesn't have permission to view other domains, use session domain
			$domain_uuid_to_filter = $_SESSION['domain_uuid'];
		}
	} elseif (!($show == "all" && permission_exists('extension_all'))) {
		//use session domain_uuid (default behavior)
		$domain_uuid_to_filter = $_SESSION['domain_uuid'];
	}
	
	//build SQL query
	$sql = "select e.*, ";
	$sql .= "( ";
	$sql .= "	select device_uuid ";
	$sql .= "	from v_device_lines ";
	$sql .= "	where domain_uuid = e.domain_uuid ";
	$sql .= "	and user_id = e.extension ";
	$sql .= "	limit 1 ";
	$sql .= ") AS device_uuid ";
	if (permission_exists("extension_device_address")) {
		$sql .= ",( ";
		$sql .= "	select device_address ";
		$sql .= "	from v_devices ";
		$sql .= "	where device_uuid in ( ";
		$sql .= "		select device_uuid ";
		$sql .= "		from v_device_lines ";
		$sql .= "		where domain_uuid = e.domain_uuid ";
		$sql .= "		and user_id = e.extension ";
		$sql .= "		limit 1) ";
		$sql .= ") AS device_address ";
	}
	if (permission_exists("extension_device_template")) {
		$sql .= ",( ";
		$sql .= "	select device_template ";
		$sql .= "	from v_devices ";
		$sql .= "	where device_uuid in ( ";
		$sql .= "		select device_uuid ";
		$sql .= "		from v_device_lines ";
		$sql .= "		where domain_uuid = e.domain_uuid ";
		$sql .= "		and user_id = e.extension ";
		$sql .= "		limit 1) ";
		$sql .= ") AS device_template ";
	}
	$sql .= "from v_extensions as e ";
	$sql .= "where true ";
	
	//apply domain filter
	if (!empty($domain_uuid_to_filter)) {
		$sql .= "and domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $domain_uuid_to_filter;
	}
	
	//apply search filter
	if (!empty($search)) {
		$search_lower = strtolower($search);
		$sql .= "and ( ";
		$sql .= " lower(extension) like :search ";
		$sql .= " or lower(number_alias) like :search ";
		$sql .= " or lower(effective_caller_id_name) like :search ";
		$sql .= " or lower(effective_caller_id_number) like :search ";
		$sql .= " or lower(outbound_caller_id_name) like :search ";
		$sql .= " or lower(outbound_caller_id_number) like :search ";
		$sql .= " or lower(emergency_caller_id_name) like :search ";
		$sql .= " or lower(emergency_caller_id_number) like :search ";
		$sql .= " or lower(directory_first_name) like :search ";
		$sql .= " or lower(directory_last_name) like :search ";
		if (permission_exists("extension_call_group")) {
			$sql .= " or lower(call_group) like :search ";
		}
		$sql .= " or lower(user_context) like :search ";
		$sql .= " or lower(enabled) like :search ";
		$sql .= " or lower(description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%' . $search_lower . '%';
	}
	
	$sql .= order_by($order_by, $order, null, null, $sort);
	
	//execute query
	$extensions = $database->select($sql, $parameters ?? null, 'all');
	unset($sql, $parameters);
	
	//get registration status if permission exists
	if (permission_exists('extension_registered') && !empty($extensions)) {
		$obj = new registrations;
		if (!empty($show) && $show == 'all') {
			$obj->show = 'all';
		}
		$registrations = $obj->get('all');
		
		foreach ($extensions as &$extension) {
			$extension_number = $extension['extension'].'@'.$_SESSION['domains'][$extension['domain_uuid']]['domain_name'];
			$extension_number_alias = $extension['number_alias'];
			if (!empty($extension_number_alias)) {
				$extension_number_alias .= '@'.$_SESSION['domains'][$extension['domain_uuid']]['domain_name'];
			}
			
			$found_count = 0;
			if (is_array($registrations)) {
				foreach ($registrations as $array) {
					if ($extension_number == $array['user'] || ($extension_number_alias != '' && $extension_number_alias == $array['user'])) {
						$found_count++;
					}
				}
			}
			$extension['registered'] = $found_count > 0;
			$extension['registration_count'] = $found_count;
		}
		unset($extension); //unset reference
	}
	
	//return JSON response
	$response = [
		'success' => true,
		'count' => count($extensions),
		'extensions' => $extensions
	];
	
	//add metadata
	$response['metadata'] = [
		'timestamp' => date('c'),
		'domain_uuid' => $domain_uuid_to_filter ?? $_SESSION['domain_uuid'] ?? null,
		'domain_name' => $domain_name_param ?? null
	];
	
	echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

//method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method Not Allowed', 'message' => 'Only GET and POST methods are supported']);

?>

