<?php
/*
	FusionPBX Gateway JSON API
	Returns gateway data in JSON format for external applications
	
	Usage: GET /app/gateways/gateways_api.php
	Returns: JSON array of gateway objects
*/

//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

//check permissions
if (permission_exists('gateway_view')) {
	//access granted
}
else {
	http_response_code(403);
	header('Content-Type: application/json');
	echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions']);
	exit;
}

//add multi-lingual support
$language = new text;
$text = $language->get();

//get query parameters
$search = !empty($_GET["search"]) ? trim($_GET["search"]) : '';
$show = !empty($_GET["show"]) ? trim($_GET["show"]) : '';
$format = !empty($_GET["format"]) ? trim($_GET["format"]) : 'json';

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

//set response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

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

?>

