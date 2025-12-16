<?php
/*
	FusionPBX XML CDR JSON API
	Handles list, search, and delete operations from xml_cdr.php
	
	GET /app/xml_cdr/xml_cdr_api.php - List all CDR records
	GET /app/xml_cdr/xml_cdr_api.php?id=uuid - Get single CDR record
	POST /app/xml_cdr/xml_cdr_api.php?action=delete - Delete CDR records
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
$cdr_id = !empty($_GET['id']) ? trim($_GET['id']) : '';

//handle POST actions (delete)
if ($method == 'POST' && !empty($action)) {
	//check permissions
	if (!permission_exists('xml_cdr_delete')) {
		http_response_code(403);
		echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: xml_cdr_delete required']);
		exit;
	}
	
	//get JSON input
	$input = json_decode(file_get_contents('php://input'), true);
	if (empty($input) && !empty($_POST)) {
		$input = $_POST;
	}
	
	//get CDR UUIDs from input
	$xml_cdrs = [];
	if (!empty($input['xml_cdrs']) && is_array($input['xml_cdrs'])) {
		foreach ($input['xml_cdrs'] as $cdr) {
			if (!empty($cdr['uuid']) && is_uuid($cdr['uuid'])) {
				$xml_cdrs[] = ['uuid' => $cdr['uuid'], 'checked' => 'true'];
			}
		}
	} elseif (!empty($input['xml_cdr_uuid']) && is_uuid($input['xml_cdr_uuid'])) {
		$xml_cdrs[] = ['uuid' => $input['xml_cdr_uuid'], 'checked' => 'true'];
	} elseif (!empty($cdr_id) && is_uuid($cdr_id)) {
		$xml_cdrs[] = ['uuid' => $cdr_id, 'checked' => 'true'];
	}
	
	if (empty($xml_cdrs)) {
		http_response_code(400);
		echo json_encode(['error' => 'Bad Request', 'message' => 'No valid CDR UUIDs provided']);
		exit;
	}
	
	//process action
	switch ($action) {
		case 'delete':
			$obj = new xml_cdr;
			$obj->delete($xml_cdrs);
			
			echo json_encode([
				'success' => true,
				'message' => 'CDR records deleted successfully',
				'count' => count($xml_cdrs)
			]);
			exit;
			
		default:
			http_response_code(400);
			echo json_encode(['error' => 'Bad Request', 'message' => 'Invalid action. Supported: delete']);
			exit;
	}
}

//handle GET requests (list or single CDR)
if ($method == 'GET') {
	//check permissions
	if (!permission_exists('xml_cdr_view')) {
		http_response_code(403);
		echo json_encode(['error' => 'Access denied', 'message' => 'Insufficient permissions: xml_cdr_view required']);
		exit;
	}
	
	//add multi-lingual support
	$language = new text;
	$text = $language->get();
	
	//set permissions
	$permission = array();
	$permission['xml_cdr_view'] = permission_exists('xml_cdr_view');
	$permission['xml_cdr_search_extension'] = permission_exists('xml_cdr_search_extension');
	$permission['xml_cdr_delete'] = permission_exists('xml_cdr_delete');
	$permission['xml_cdr_domain'] = permission_exists('xml_cdr_domain');
	$permission['xml_cdr_all'] = permission_exists('xml_cdr_all');
	$permission['xml_cdr_account_code'] = permission_exists('xml_cdr_account_code');
	$permission['xml_cdr_pdd'] = permission_exists('xml_cdr_pdd');
	$permission['xml_cdr_mos'] = permission_exists('xml_cdr_mos');
	$permission['xml_cdr_b_leg'] = permission_exists('xml_cdr_b_leg');
	$permission['xml_cdr_lose_race'] = permission_exists('xml_cdr_lose_race');
	$permission['xml_cdr_cc_agent_leg'] = permission_exists('xml_cdr_cc_agent_leg');
	$permission['xml_cdr_cc_side'] = permission_exists('xml_cdr_cc_side');
	$permission['xml_cdr_call_center_queues'] = permission_exists('xml_cdr_call_center_queues');
	
	//connect to database
	$database = database::new();
	
	//get query parameters
	$direction = !empty($_GET["direction"]) ? trim($_GET["direction"]) : '';
	$caller_id_name = !empty($_GET["caller_id_name"]) ? trim($_GET["caller_id_name"]) : '';
	$caller_id_number = !empty($_GET["caller_id_number"]) ? trim($_GET["caller_id_number"]) : '';
	$caller_destination = !empty($_GET["caller_destination"]) ? trim($_GET["caller_destination"]) : '';
	$extension_uuid = !empty($_GET["extension_uuid"]) ? trim($_GET["extension_uuid"]) : '';
	$destination_number = !empty($_GET["destination_number"]) ? trim($_GET["destination_number"]) : '';
	$context = !empty($_GET["context"]) ? trim($_GET["context"]) : '';
	$start_stamp_begin = !empty($_GET["start_stamp_begin"]) ? trim($_GET["start_stamp_begin"]) : '';
	$start_stamp_end = !empty($_GET["start_stamp_end"]) ? trim($_GET["start_stamp_end"]) : '';
	$answer_stamp_begin = !empty($_GET["answer_stamp_begin"]) ? trim($_GET["answer_stamp_begin"]) : '';
	$answer_stamp_end = !empty($_GET["answer_stamp_end"]) ? trim($_GET["answer_stamp_end"]) : '';
	$end_stamp_begin = !empty($_GET["end_stamp_begin"]) ? trim($_GET["end_stamp_begin"]) : '';
	$end_stamp_end = !empty($_GET["end_stamp_end"]) ? trim($_GET["end_stamp_end"]) : '';
	$start_epoch = !empty($_GET["start_epoch"]) ? trim($_GET["start_epoch"]) : '';
	$stop_epoch = !empty($_GET["stop_epoch"]) ? trim($_GET["stop_epoch"]) : '';
	$duration_min = !empty($_GET["duration_min"]) ? trim($_GET["duration_min"]) : '';
	$duration_max = !empty($_GET["duration_max"]) ? trim($_GET["duration_max"]) : '';
	$billsec = !empty($_GET["billsec"]) ? trim($_GET["billsec"]) : '';
	$hangup_cause = !empty($_GET["hangup_cause"]) ? trim($_GET["hangup_cause"]) : '';
	$status = !empty($_GET["status"]) ? trim($_GET["status"]) : '';
	$xml_cdr_uuid = !empty($_GET["xml_cdr_uuid"]) ? trim($_GET["xml_cdr_uuid"]) : '';
	$bleg_uuid = !empty($_GET["bleg_uuid"]) ? trim($_GET["bleg_uuid"]) : '';
	$accountcode = !empty($_GET["accountcode"]) ? trim($_GET["accountcode"]) : '';
	$read_codec = !empty($_GET["read_codec"]) ? trim($_GET["read_codec"]) : '';
	$write_codec = !empty($_GET["write_codec"]) ? trim($_GET["write_codec"]) : '';
	$remote_media_ip = !empty($_GET["remote_media_ip"]) ? trim($_GET["remote_media_ip"]) : '';
	$network_addr = !empty($_GET["network_addr"]) ? trim($_GET["network_addr"]) : '';
	$bridge_uuid = !empty($_GET["bridge_uuid"]) ? trim($_GET["bridge_uuid"]) : '';
	$tta_min = !empty($_GET['tta_min']) ? trim($_GET['tta_min']) : '';
	$tta_max = !empty($_GET['tta_max']) ? trim($_GET['tta_max']) : '';
	$recording = !empty($_GET['recording']) ? trim($_GET['recording']) : '';
	$order_by = !empty($_GET["order_by"]) ? trim($_GET["order_by"]) : '';
	$order = !empty($_GET["order"]) ? trim($_GET["order"]) : '';
	$cc_side = !empty($_GET["cc_side"]) ? trim($_GET["cc_side"]) : '';
	$call_center_queue_uuid = !empty($_GET["call_center_queue_uuid"]) ? trim($_GET["call_center_queue_uuid"]) : '';
	$ring_group_uuid = !empty($_GET["ring_group_uuid"]) ? trim($_GET["ring_group_uuid"]) : '';
	$ivr_menu_uuid = !empty($_GET["ivr_menu_uuid"]) ? trim($_GET["ivr_menu_uuid"]) : '';
	$leg = !empty($_GET["leg"]) ? trim($_GET["leg"]) : 'a';
	$show = !empty($_GET["show"]) ? trim($_GET["show"]) : '';
	$page = !empty($_GET['page']) ? intval($_GET['page']) : 0;
	$limit = !empty($_GET['limit']) ? intval($_GET['limit']) : 50;
	
	//validate order
	switch ($order) {
		case 'asc':
		case 'desc':
			break;
		default:
			$order = 'desc';
	}
	
	//check to see if permission does not exist
	if (!$permission['xml_cdr_b_leg']) {
		$leg = 'a';
	}
	
	//set defaults
	if (empty($order_by)) {
		$order_by = "start_stamp";
	}
	if (empty($order)) {
		$order = "desc";
	}
	
	//set the assigned extensions
	$extension_uuids = [];
	if (!$permission['xml_cdr_domain'] && isset($_SESSION['user']['extension']) && is_array($_SESSION['user']['extension'])) {
		foreach ($_SESSION['user']['extension'] as $row) {
			if (is_uuid($row['extension_uuid'])) {
				$extension_uuids[] = $row['extension_uuid'];
			}
		}
	}
	
	//set the time zone
	if (isset($_SESSION['domain']['time_zone']['name'])) {
		$time_zone = $_SESSION['domain']['time_zone']['name'];
	} else {
		$time_zone = date_default_timezone_get();
	}
	$parameters['time_zone'] = $time_zone;
	
	//set the sql time format
	$sql_time_format = 'HH12:MI am';
	if (!empty($_SESSION['domain']['time_format']['text'])) {
		$sql_time_format = $_SESSION['domain']['time_format']['text'] == '12h' ? "HH12:MI am" : "HH24:MI";
	}
	
	//if single CDR requested
	if (!empty($cdr_id) && is_uuid($cdr_id)) {
		$sql = "select \n";
		$sql .= "c.domain_uuid, \n";
		$sql .= "c.sip_call_id, \n";
		$sql .= "e.extension, \n";
		$sql .= "e.effective_caller_id_name as extension_name, \n";
		$sql .= "c.extension_uuid, \n";
		$sql .= "c.start_stamp, \n";
		$sql .= "c.end_stamp, \n";
		$sql .= "to_char(timezone(:time_zone, start_stamp), 'DD Mon YYYY') as start_date_formatted, \n";
		$sql .= "to_char(timezone(:time_zone, start_stamp), '".$sql_time_format."') as start_time_formatted, \n";
		$sql .= "c.start_epoch, \n";
		$sql .= "c.hangup_cause, \n";
		$sql .= "c.billsec as duration, \n";
		$sql .= "c.billmsec, \n";
		$sql .= "c.missed_call, \n";
		$sql .= "c.record_path, \n";
		$sql .= "c.record_name, \n";
		$sql .= "c.xml_cdr_uuid, \n";
		$sql .= "c.bridge_uuid, \n";
		$sql .= "c.direction, \n";
		$sql .= "c.billsec, \n";
		$sql .= "c.caller_id_name, \n";
		$sql .= "c.caller_id_number, \n";
		$sql .= "c.caller_destination, \n";
		$sql .= "c.source_number, \n";
		$sql .= "c.destination_number, \n";
		$sql .= "c.leg, \n";
		$sql .= "c.read_codec, \n";
		$sql .= "c.write_codec, \n";
		$sql .= "c.cc_side, \n";
		if ($permission['xml_cdr_account_code']) {
			$sql .= "c.accountcode, \n";
		}
		$sql .= "c.answer_stamp, \n";
		$sql .= "c.status, \n";
		$sql .= "c.sip_hangup_disposition, \n";
		if ($permission['xml_cdr_pdd']) {
			$sql .= "c.pdd_ms, \n";
		}
		if ($permission['xml_cdr_mos']) {
			$sql .= "c.rtp_audio_in_mos, \n";
		}
		$sql .= "(c.answer_epoch - c.start_epoch) as tta ";
		if (!empty($show) && $show == "all" && $permission['xml_cdr_all']) {
			$sql .= ", c.domain_name \n";
		}
		$sql .= "from v_xml_cdr as c \n";
		$sql .= "left join v_extensions as e on e.extension_uuid = c.extension_uuid \n";
		$sql .= "inner join v_domains as d on d.domain_uuid = c.domain_uuid \n";
		$sql .= "where c.xml_cdr_uuid = :xml_cdr_uuid \n";
		
		//apply domain filter if user doesn't have xml_cdr_all permission
		if (!($show == "all" && $permission['xml_cdr_all'])) {
			$sql .= "and c.domain_uuid = :domain_uuid \n";
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		}
		
		$parameters['xml_cdr_uuid'] = $cdr_id;
		
		$cdr_record = $database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		
		if (empty($cdr_record)) {
			http_response_code(404);
			echo json_encode(['error' => 'Not Found', 'message' => 'CDR record not found']);
			exit;
		}
		
		//determine status if not set
		if (empty($cdr_record['status'])) {
			$failed_array = array(
				"CALL_REJECTED", "CHAN_NOT_IMPLEMENTED", "DESTINATION_OUT_OF_ORDER",
				"EXCHANGE_ROUTING_ERROR", "INCOMPATIBLE_DESTINATION", "INVALID_NUMBER_FORMAT",
				"MANDATORY_IE_MISSING", "NETWORK_OUT_OF_ORDER", "NORMAL_TEMPORARY_FAILURE",
				"NORMAL_UNSPECIFIED", "NO_ROUTE_DESTINATION", "RECOVERY_ON_TIMER_EXPIRE",
				"REQUESTED_CHAN_UNAVAIL", "SUBSCRIBER_ABSENT", "SYSTEM_SHUTDOWN", "UNALLOCATED_NUMBER"
			);
			
			if ($cdr_record['billsec'] > 0) {
				$cdr_record['status'] = 'answered';
			} elseif ($cdr_record['hangup_cause'] == 'NO_ANSWER') {
				$cdr_record['status'] = 'no_answer';
			} elseif ($cdr_record['missed_call']) {
				$cdr_record['status'] = 'missed';
			} elseif (substr($cdr_record['destination_number'], 0, 3) == '*99') {
				$cdr_record['status'] = 'voicemail';
			} elseif ($cdr_record['hangup_cause'] == 'ORIGINATOR_CANCEL') {
				$cdr_record['status'] = 'cancelled';
			} elseif ($cdr_record['hangup_cause'] == 'USER_BUSY') {
				$cdr_record['status'] = 'busy';
			} elseif (in_array($cdr_record['hangup_cause'], $failed_array)) {
				$cdr_record['status'] = 'failed';
			}
		}
		
		echo json_encode([
			'success' => true,
			'cdr' => $cdr_record
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit;
	}
	
	//list all CDR records
	//build SQL query
	$sql = "select \n";
	$sql .= "c.domain_uuid, \n";
	$sql .= "c.sip_call_id, \n";
	$sql .= "e.extension, \n";
	$sql .= "e.effective_caller_id_name as extension_name, \n";
	$sql .= "c.extension_uuid, \n";
	$sql .= "c.start_stamp, \n";
	$sql .= "c.end_stamp, \n";
	$sql .= "to_char(timezone(:time_zone, start_stamp), 'DD Mon YYYY') as start_date_formatted, \n";
	$sql .= "to_char(timezone(:time_zone, start_stamp), '".$sql_time_format."') as start_time_formatted, \n";
	$sql .= "c.start_epoch, \n";
	$sql .= "c.hangup_cause, \n";
	$sql .= "c.billsec as duration, \n";
	$sql .= "c.billmsec, \n";
	$sql .= "c.missed_call, \n";
	$sql .= "c.record_path, \n";
	$sql .= "c.record_name, \n";
	$sql .= "c.xml_cdr_uuid, \n";
	$sql .= "c.bridge_uuid, \n";
	$sql .= "c.direction, \n";
	$sql .= "c.billsec, \n";
	$sql .= "c.caller_id_name, \n";
	$sql .= "c.caller_id_number, \n";
	$sql .= "c.caller_destination, \n";
	$sql .= "c.source_number, \n";
	$sql .= "c.destination_number, \n";
	$sql .= "c.leg, \n";
	$sql .= "c.read_codec, \n";
	$sql .= "c.write_codec, \n";
	$sql .= "c.cc_side, \n";
	if ($permission['xml_cdr_account_code']) {
		$sql .= "c.accountcode, \n";
	}
	$sql .= "c.answer_stamp, \n";
	$sql .= "c.status, \n";
	$sql .= "c.sip_hangup_disposition, \n";
	if ($permission['xml_cdr_pdd']) {
		$sql .= "c.pdd_ms, \n";
	}
	if ($permission['xml_cdr_mos']) {
		$sql .= "c.rtp_audio_in_mos, \n";
	}
	$sql .= "(c.answer_epoch - c.start_epoch) as tta ";
	if (!empty($show) && $show == "all" && $permission['xml_cdr_all']) {
		$sql .= ", c.domain_name \n";
	}
	$sql .= "from v_xml_cdr as c \n";
	$sql .= "left join v_extensions as e on e.extension_uuid = c.extension_uuid \n";
	$sql .= "inner join v_domains as d on d.domain_uuid = c.domain_uuid \n";
	
	//apply domain filter
	if (!empty($show) && $show == "all" && $permission['xml_cdr_all']) {
		$sql .= "where true \n";
	} else {
		$sql .= "where c.domain_uuid = :domain_uuid \n";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	}
	
	//apply extension filter if user doesn't have domain permission
	if (!$permission['xml_cdr_domain']) {
		if (isset($extension_uuids) && is_array($extension_uuids) && @sizeof($extension_uuids)) {
			$sql .= "and (c.extension_uuid = '".implode("' or c.extension_uuid = '", $extension_uuids)."') \n";
		} else {
			$sql .= "and false \n";
		}
	}
	
	//apply filters
	if (!empty($start_epoch) && !empty($stop_epoch)) {
		$sql .= "and start_epoch between :start_epoch and :stop_epoch \n";
		$parameters['start_epoch'] = $start_epoch;
		$parameters['stop_epoch'] = $stop_epoch;
	}
	if (!empty($direction)) {
		$sql .= "and direction = :direction \n";
		$parameters['direction'] = $direction;
	}
	if (!empty($caller_id_name)) {
		$mod_caller_id_name = str_replace("*", "%", $caller_id_name);
		if (strstr($mod_caller_id_name, '%')) {
			$sql .= "and caller_id_name like :caller_id_name \n";
			$parameters['caller_id_name'] = $mod_caller_id_name;
		} else {
			$sql .= "and caller_id_name = :caller_id_name \n";
			$parameters['caller_id_name'] = $mod_caller_id_name;
		}
	}
	if (!empty($caller_id_number)) {
		$mod_caller_id_number = str_replace("*", "%", $caller_id_number);
		$mod_caller_id_number = preg_replace("#[^\+0-9.%/]#", "", $mod_caller_id_number);
		if (strstr($mod_caller_id_number, '%')) {
			$sql .= "and caller_id_number like :caller_id_number \n";
			$parameters['caller_id_number'] = $mod_caller_id_number;
		} else {
			$sql .= "and caller_id_number = :caller_id_number \n";
			$parameters['caller_id_number'] = $mod_caller_id_number;
		}
	}
	if (!empty($extension_uuid) && is_uuid($extension_uuid)) {
		$sql .= "and e.extension_uuid = :extension_uuid \n";
		$parameters['extension_uuid'] = $extension_uuid;
	}
	if (!empty($caller_destination)) {
		$mod_caller_destination = str_replace("*", "%", $caller_destination);
		$mod_caller_destination = preg_replace("#[^\+0-9.%/]#", "", $mod_caller_destination);
		if (strstr($mod_caller_destination, '%')) {
			$sql .= "and caller_destination like :caller_destination \n";
			$parameters['caller_destination'] = $mod_caller_destination;
		} else {
			$sql .= "and caller_destination = :caller_destination \n";
			$parameters['caller_destination'] = $mod_caller_destination;
		}
	}
	if (!empty($destination_number)) {
		$mod_destination_number = str_replace("*", "%", $destination_number);
		$mod_destination_number = preg_replace("#[^\+0-9.%/]#", "", $mod_destination_number);
		if (strstr($mod_destination_number, '%')) {
			$sql .= "and destination_number like :destination_number \n";
			$parameters['destination_number'] = $mod_destination_number;
		} else {
			$sql .= "and destination_number = :destination_number \n";
			$parameters['destination_number'] = $mod_destination_number;
		}
	}
	if (!empty($context)) {
		$sql .= "and context like :context \n";
		$parameters['context'] = '%'.$context.'%';
	}
	if (!empty($start_stamp_begin) && !empty($start_stamp_end)) {
		$sql .= "and start_stamp between :start_stamp_begin::timestamptz and :start_stamp_end::timestamptz \n";
		$parameters['start_stamp_begin'] = $start_stamp_begin.':00.000 '.$time_zone;
		$parameters['start_stamp_end'] = $start_stamp_end.':59.999 '.$time_zone;
	} else {
		if (!empty($start_stamp_begin)) {
			$sql .= "and start_stamp >= :start_stamp_begin \n";
			$parameters['start_stamp_begin'] = $start_stamp_begin.':00.000 '.$time_zone;
		}
		if (!empty($start_stamp_end)) {
			$sql .= "and start_stamp <= :start_stamp_end \n";
			$parameters['start_stamp_end'] = $start_stamp_end.':59.999 '.$time_zone;
		}
	}
	if (is_numeric($duration_min)) {
		$sql .= "and billsec >= :duration_min \n";
		$parameters['duration_min'] = $duration_min;
	}
	if (is_numeric($duration_max)) {
		$sql .= "and billsec <= :duration_max \n";
		$parameters['duration_max'] = $duration_max;
	}
	if (!empty($hangup_cause)) {
		$sql .= "and hangup_cause like :hangup_cause \n";
		$parameters['hangup_cause'] = '%'.$hangup_cause.'%';
	}
	if (!$permission['xml_cdr_lose_race']) {
		$sql .= "and hangup_cause != 'LOSE_RACE' \n";
	}
	if (!empty($status)) {
		$sql .= "and status = :status \n";
		$parameters['status'] = $status;
	}
	if (!empty($xml_cdr_uuid)) {
		$sql .= "and xml_cdr_uuid = :xml_cdr_uuid \n";
		$parameters['xml_cdr_uuid'] = $xml_cdr_uuid;
	}
	if (!empty($read_codec)) {
		$sql .= "and read_codec like :read_codec \n";
		$parameters['read_codec'] = '%'.$read_codec.'%';
	}
	if (!empty($write_codec)) {
		$sql .= "and write_codec like :write_codec \n";
		$parameters['write_codec'] = '%'.$write_codec.'%';
	}
	if (!empty($leg)) {
		$sql .= "and leg = :leg \n";
		$parameters['leg'] = $leg;
	}
	if (is_numeric($tta_min)) {
		$sql .= "and (c.answer_epoch - c.start_epoch) >= :tta_min \n";
		$parameters['tta_min'] = $tta_min;
	}
	if (is_numeric($tta_max)) {
		$sql .= "and (c.answer_epoch - c.start_epoch) <= :tta_max \n";
		$parameters['tta_max'] = $tta_max;
	}
	if ($recording == 'true' || $recording == 'false') {
		if ($recording == 'true') {
			$sql .= "and c.record_path is not null and c.record_name is not null \n";
		}
		if ($recording == 'false') {
			$sql .= "and (c.record_path is null or c.record_name is null) \n";
		}
	}
	if (!$permission['xml_cdr_cc_agent_leg']) {
		$sql .= "and (cc_side is null or cc_side != 'agent') \n";
	}
	if (!empty($cc_side) && $permission['xml_cdr_cc_side']) {
		$sql .= "and cc_side = :cc_side \n";
		$parameters['cc_side'] = $cc_side;
	}
	if (!empty($call_center_queue_uuid) && $permission['xml_cdr_call_center_queues']) {
		$sql .= "and call_center_queue_uuid = :call_center_queue_uuid \n";
		$parameters['call_center_queue_uuid'] = $call_center_queue_uuid;
	}
	if (!empty($ring_group_uuid)) {
		$sql .= "and ring_group_uuid = :ring_group_uuid \n";
		$parameters['ring_group_uuid'] = $ring_group_uuid;
	}
	if (!empty($ivr_menu_uuid)) {
		$sql .= "and ivr_menu_uuid = :ivr_menu_uuid \n";
		$parameters['ivr_menu_uuid'] = $ivr_menu_uuid;
	}
	
	//apply ordering
	$sql .= order_by($order_by, $order);
	
	//apply pagination
	$offset = $limit * $page;
	$sql .= " limit :limit offset :offset \n";
	$parameters['limit'] = intval($limit);
	$parameters['offset'] = intval($offset);
	
	//execute query
	$cdr_records = $database->select($sql, $parameters ?? null, 'all');
	unset($sql, $parameters);
	
	//check if no records found
	if (empty($cdr_records) || !is_array($cdr_records) || count($cdr_records) == 0) {
		//return JSON response with no calls found message
		$response = [
			'success' => true,
			'count' => 0,
			'cdr_records' => [],
			'message' => 'No call found'
		];
		
		//add metadata
		$response['metadata'] = [
			'timestamp' => date('c'),
			'domain_uuid' => $_SESSION['domain_uuid'] ?? null,
			'page' => $page,
			'limit' => $limit,
			'order_by' => $order_by,
			'order' => $order
		];
		
		echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit;
	}
	
	//process results - determine status if not set
	$failed_array = array(
		"CALL_REJECTED", "CHAN_NOT_IMPLEMENTED", "DESTINATION_OUT_OF_ORDER",
		"EXCHANGE_ROUTING_ERROR", "INCOMPATIBLE_DESTINATION", "INVALID_NUMBER_FORMAT",
		"MANDATORY_IE_MISSING", "NETWORK_OUT_OF_ORDER", "NORMAL_TEMPORARY_FAILURE",
		"NORMAL_UNSPECIFIED", "NO_ROUTE_DESTINATION", "RECOVERY_ON_TIMER_EXPIRE",
		"REQUESTED_CHAN_UNAVAIL", "SUBSCRIBER_ABSENT", "SYSTEM_SHUTDOWN", "UNALLOCATED_NUMBER"
	);
	
	if (!empty($cdr_records)) {
		foreach ($cdr_records as &$cdr) {
			if (empty($cdr['status'])) {
				if ($cdr['billsec'] > 0) {
					$cdr['status'] = 'answered';
				} elseif ($cdr['hangup_cause'] == 'NO_ANSWER') {
					$cdr['status'] = 'no_answer';
				} elseif ($cdr['missed_call']) {
					$cdr['status'] = 'missed';
				} elseif (substr($cdr['destination_number'], 0, 3) == '*99') {
					$cdr['status'] = 'voicemail';
				} elseif ($cdr['hangup_cause'] == 'ORIGINATOR_CANCEL') {
					$cdr['status'] = 'cancelled';
				} elseif ($cdr['hangup_cause'] == 'USER_BUSY') {
					$cdr['status'] = 'busy';
				} elseif (in_array($cdr['hangup_cause'], $failed_array)) {
					$cdr['status'] = 'failed';
				}
			}
		}
		unset($cdr); //unset reference
	}
	
	//return JSON response
	$response = [
		'success' => true,
		'count' => count($cdr_records),
		'cdr_records' => $cdr_records
	];
	
	//add metadata
	$response['metadata'] = [
		'timestamp' => date('c'),
		'domain_uuid' => $_SESSION['domain_uuid'] ?? null,
		'page' => $page,
		'limit' => $limit,
		'order_by' => $order_by,
		'order' => $order
	];
	
	echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

//method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method Not Allowed', 'message' => 'Only GET and POST methods are supported']);

?>
