<?php
/*
 * FusionPBX Active Calls JSON API
 * Version: MPL 1.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is FusionPBX
 *
 * The Initial Developer of the Original Code is
 * Mark J Crane <markjcrane@fusionpbx.com>
 * Portions created by the Initial Developer are Copyright (C) 2008-2025
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s):
 * Mark J Crane <markjcrane@fusionpbx.com>
 * Tim Fry <tim@fusionpbx.com>
 */

//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

//set response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

//check permissions
if (!permission_exists('call_active_view')) {
	http_response_code(403);
	echo json_encode([
		'success' => false,
		'error' => 'Access denied',
		'message' => 'Permission call_active_view required'
	]);
	exit;
}

//get request parameters
$show = trim($_GET['show'] ?? '');
$show_all = ($show === 'all' && permission_exists('call_active_all'));

//get domain information
global $domain_uuid, $user_uuid, $settings, $database, $config;

if (empty($domain_uuid)) {
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';
}

if (empty($user_uuid)) {
	$user_uuid = $_SESSION['user_uuid'] ?? '';
}

if (!($config instanceof config)) {
	$config = config::load();
}

if (!($database instanceof database)) {
	$database = database::new();
}

if (!($settings instanceof settings)) {
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid, 'user_uuid' => $user_uuid]);
}

$domain_name = $_SESSION['domain_name'] ?? '';

//get active calls from event socket
$calls = [];

//connect to event socket
$event_socket = event_socket::create();

if ($event_socket && $event_socket->is_connected()) {
	//get channels as JSON
	$switch_cmd = 'show channels as json';
	$json = trim(event_socket::api($switch_cmd));
	
	if (!empty($json)) {
		$results = json_decode($json, true);
		
		if (isset($results["rows"]) && is_array($results["rows"])) {
			foreach ($results["rows"] as $row) {
				//extract domain name from context
				$caller_context = $row['context'] ?? '';
				$caller_domain_name = '';
				
				if (!empty($caller_context) && $caller_context != "public" && $caller_context != "default") {
					if (strpos($caller_context, '@') !== false) {
						$parts = explode('@', $caller_context);
						$caller_domain_name = end($parts);
					} else {
						$caller_domain_name = $caller_context;
					}
				} else if (!empty($row['presence_id']) && strpos($row['presence_id'], '@') !== false) {
					$parts = explode('@', $row['presence_id']);
					$caller_domain_name = end($parts);
				}
				
				//filter by domain if not showing all
				if (!$show_all && $caller_domain_name !== $domain_name) {
					continue;
				}
				
				//map channel data to active call format
				//based on active_calls_service.php get_active_calls() method
				//and active_calls.php JavaScript event handling
				$call = [
					'unique_id' => $row['uuid'] ?? '',
					'uuid' => $row['uuid'] ?? '', //alias for compatibility
					'call_direction' => $row['direction'] ?? '',
					'answer_state' => 'ringing', //default state for active channels
					'channel_call_state' => 'RINGING',
					'event_name' => 'CHANNEL_CALLSTATE',
					
					//channel created time (convert from epoch to microseconds)
					'caller_channel_created_time' => isset($row['created_epoch']) ? intval($row['created_epoch']) * 1000000 : 0,
					
					//codec information
					'channel_read_codec_name' => $row['read_codec'] ?? '',
					'channel_read_codec_rate' => $row['read_rate'] ?? '',
					'channel_write_codec_name' => $row['write_codec'] ?? '',
					'channel_write_codec_rate' => $row['write_rate'] ?? '',
					
					//channel name/profile
					'caller_channel_name' => $row['name'] ?? '',
					
					//context/domain
					'caller_context' => $caller_context,
					'domain_name' => $caller_domain_name,
					'domain_uuid' => $domain_uuid, //add domain_uuid from session
					
					//caller information
					'caller_caller_id_name' => $row['initial_cid_name'] ?? '',
					'caller_caller_id_number' => $row['initial_cid_num'] ?? '',
					'caller_destination_number' => $row['initial_dest'] ?? '',
					
					//application
					'application' => $row['application'] ?? '',
					'application_name' => $row['application'] ?? '',
					'application_data' => $row['application_data'] ?? '',
					
					//security
					'secure' => $row['secure'] ?? '',
					
					//additional variables (from channel variables, if available)
					'variable_call_direction' => $row['variable_call_direction'] ?? $row['direction'] ?? '',
					'variable_domain_uuid' => $row['variable_domain_uuid'] ?? $domain_uuid,
					'variable_user_exists' => $row['variable_user_exists'] ?? '',
					'variable_from_user_exists' => $row['variable_from_user_exists'] ?? '',
					'other_leg_rdnis' => $row['other_leg_rdnis'] ?? '',
					'other_leg_unique_id' => $row['other_leg_unique_id'] ?? '',
				];
				
				//only add if unique_id is present
				if (!empty($call['unique_id'])) {
					$calls[] = $call;
				}
			}
		}
	}
} else {
	//event socket not connected
	http_response_code(503);
	echo json_encode([
		'success' => false,
		'error' => 'Event socket connection failed',
		'message' => 'Unable to connect to FreeSWITCH event socket'
	]);
	exit;
}

//return JSON response
$response = [
	'success' => true,
	'count' => count($calls),
	'calls' => $calls,
	'domain_name' => $domain_name,
	'show_all' => $show_all
];

//add metadata
$response['metadata'] = [
	'timestamp' => date('c'),
	'domain_uuid' => $domain_uuid,
	'domain_name' => $domain_name,
	'esl_connected' => ($event_socket && $event_socket->is_connected())
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;

?>
