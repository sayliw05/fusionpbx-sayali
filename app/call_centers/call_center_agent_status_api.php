<?php
/*
 * FusionPBX Call Center Agent Status JSON API
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
if (!permission_exists('call_center_agent_view')) {
	http_response_code(403);
	echo json_encode([
		'success' => false,
		'error' => 'Access denied',
		'message' => 'Permission call_center_agent_view required'
	]);
	exit;
}

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

//setup the event socket connection
$esl = event_socket::create();
$esl_connected = $esl->is_connected();

//get the agents from the database
$sql = "SELECT * FROM v_call_center_agents ";
$sql .= "WHERE domain_uuid = :domain_uuid ";
$sql .= "ORDER BY agent_name ASC ";
$parameters['domain_uuid'] = $domain_uuid;
$database = new database;
$agents = $database->select($sql, $parameters, 'all');
unset($sql, $parameters);

//get the agent list from event socket
$agent_list = [];
$call_center_tiers = [];
if ($esl_connected) {
	$switch_cmd = 'callcenter_config agent list';
	$event_socket_str = trim(event_socket::api($switch_cmd));
	$agent_list = csv_to_named_array($event_socket_str, '|');
	
	$switch_cmd = 'callcenter_config tier list';
	$event_socket_str = trim(event_socket::api($switch_cmd));
	$call_center_tiers = csv_to_named_array($event_socket_str, '|');
}

//get the call center queues from the database
$sql = "SELECT q.*, d.domain_name ";
$sql .= "FROM v_call_center_queues as q, v_domains as d ";
$sql .= "WHERE q.domain_uuid = :domain_uuid ";
$sql .= "AND d.domain_uuid = :domain_uuid ";
$sql .= "AND q.domain_uuid = d.domain_uuid ";
$sql .= "ORDER BY queue_name ASC ";
$parameters['domain_uuid'] = $domain_uuid;
$database = new database;
$call_center_queues = $database->select($sql, $parameters, 'all');
unset($sql, $parameters);

//add the status to the call_center_queues array
$x = 0;
if (!empty($call_center_queues) && $esl_connected) {
	foreach ($call_center_queues as $queue) {
		//set the queue id
		$queue_id = $queue['queue_extension'].'@'.$queue['domain_name'];
		
		//get the queue list from event socket
		$switch_cmd = "callcenter_config queue list agents ".$queue_id;
		$event_socket_str = trim(event_socket::api($switch_cmd));
		$queue_list = csv_to_named_array($event_socket_str, '|');
		$call_center_queues[$x]['queue_list'] = $queue_list;
		$x++;
	}
}

//get the agent status from mod_callcenter and update the agent status in the agents array
$x = 0;
if (!empty($agents)) {
	foreach ($agents as $row) {
		//add the domain name
		$domain_name = $_SESSION['domains'][$row['domain_uuid']]['domain_name'] ?? $domain_name;
		$agents[$x]['domain_name'] = $domain_name;
		
		//update the queue status
		$i = 0;
		$agents[$x]['queues'] = [];
		if (!empty($call_center_queues)) {
			foreach ($call_center_queues as $queue) {
				$agents[$x]['queues'][$i] = [
					'agent_name' => $row['agent_name'],
					'queue_name' => $queue['queue_name'],
					'call_center_agent_uuid' => $row['call_center_agent_uuid'],
					'call_center_queue_uuid' => $queue['call_center_queue_uuid'],
					'queue_status' => 'Logged Out'
				];
				
				if (!empty($queue['queue_list'])) {
					foreach ($queue['queue_list'] as $queue_list) {
						if ($row['call_center_agent_uuid'] == $queue_list['name']) {
							$agents[$x]['queues'][$i]['queue_status'] = 'Available';
							break;
						}
					}
				}
				$i++;
			}
		}
		
		//update the agent status
		$agents[$x]['agent_status'] = 'Logged Out'; // default
		if (!empty($agent_list)) {
			foreach ($agent_list as $r) {
				if ($r['name'] == $row['call_center_agent_uuid']) {
					$agents[$x]['agent_status'] = $r['status'];
					break;
				}
			}
		}
		
		//increment x
		$x++;
	}
}

//return JSON response
$response = [
	'success' => true,
	'count' => count($agents),
	'agents' => $agents,
	'domain_name' => $domain_name
];

//add metadata
$response['metadata'] = [
	'timestamp' => date('c'),
	'domain_uuid' => $domain_uuid,
	'domain_name' => $domain_name,
	'esl_connected' => $esl_connected,
	'queues_count' => count($call_center_queues)
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;

?>
