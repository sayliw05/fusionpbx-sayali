<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2024
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('xml_cdr_statistics')) {
		//access granted
	}
	else {
		http_response_code(403);
		echo json_encode(['error' => 'Access Denied', 'message' => 'Permission xml_cdr_statistics required']);
		exit;
	}

//set content type
	header('Content-Type: application/json');

//get post or get variables from http
	$direction = !empty($_GET["direction"]) ? trim($_GET["direction"]) : '';
	$caller_id_name = !empty($_GET["caller_id_name"]) ? trim($_GET["caller_id_name"]) : '';
	$caller_id_number = !empty($_GET["caller_id_number"]) ? trim($_GET["caller_id_number"]) : '';
	$caller_extension_uuid = !empty($_GET["caller_extension_uuid"]) ? trim($_GET["caller_extension_uuid"]) : '';
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
	$duration = !empty($_GET["duration"]) ? trim($_GET["duration"]) : '';
	$billsec = !empty($_GET["billsec"]) ? trim($_GET["billsec"]) : '';
	$hangup_cause = !empty($_GET["hangup_cause"]) ? trim($_GET["hangup_cause"]) : '';
	$read_codec = !empty($_GET["read_codec"]) ? trim($_GET["read_codec"]) : '';
	$write_codec = !empty($_GET["write_codec"]) ? trim($_GET["write_codec"]) : '';
	$leg = !empty($_GET["leg"]) ? trim($_GET["leg"]) : 'a';
	$show = !empty($_GET["show"]) ? trim($_GET["show"]) : '';

//assign default value for show all
	$showall = false;
	if (isset($_GET['showall']) && $_GET['showall'] === 'true' && permission_exists('xml_cdr_all')) {
		$showall = true;
	}

//show all call detail records to admin and superadmin. for everyone else show only the call details for extensions assigned to them
	if (!permission_exists('xml_cdr_domain')) {
		$sql_where = "c.domain_uuid = '".$_SESSION["domain_uuid"]."' and ( ";
		if (count($_SESSION['user']['extension']) > 0) {
			$x = 0;
			foreach($_SESSION['user']['extension'] as $row) {
				if ($x==0) {
					if ($row['user'] > 0) { $sql_where .= "c.caller_id_number = '".$row['user']."' \n"; }
				}
				else {
					if ($row['user'] > 0) { $sql_where .= "or c.caller_id_number = '".$row['user']."' \n"; }
				}
				if ($row['user'] > 0) { $sql_where .= "or c.destination_number = '".$row['user']."' \n"; }
				if ($row['user'] > 0) { $sql_where .= "or c.destination_number = '*99".$row['user']."' \n"; }
				$x++;
			}
		}
		$sql_where .= ") ";
	}
	else {
		if ($showall) {
			$sql_where = '';
		} else {
			$sql_where = "c.domain_uuid = '".$_SESSION['domain_uuid']."' ";
		}
	}
	if (isset($sql_where) && $sql_where != '') {
		$sql_where_ands[] = $sql_where;
		unset($sql_where);
	}

//if we do not see b-leg then use only a-leg to generate statistics
	if (!permission_exists('xml_cdr_b_leg')) {
		$leg = 'a';
	}

//build the sql where string
	if (!empty($start_epoch) && !empty($stop_epoch)) {
		$sql_where_ands[] = "c.start_epoch between :start_epoch and :stop_epoch";
		$parameters['start_epoch'] = $start_epoch;
		$parameters['stop_epoch'] = $stop_epoch;
	}
	if (!empty($direction)) {
		$sql_where_ands[] = "c.direction = :direction";
		$parameters['direction'] = $direction;
	}
	if (!empty($caller_id_name)) {
		$mod_caller_id_name = str_replace("*", "%", $caller_id_name);
		$sql_where_ands[] = "c.caller_id_name like :mod_caller_id_name";
		$parameters['mod_caller_id_name'] = $mod_caller_id_name;
	}
	if (!empty($caller_extension_uuid)) {
		$sql_where_ands[] = "c.extension_uuid = :caller_extension_uuid";
		$parameters['caller_extension_uuid'] = $caller_extension_uuid;
	}
	if (!empty($extension_uuid)) {
		$sql_where_ands[] = "c.extension_uuid = :extension_uuid";
		$parameters['extension_uuid'] = $extension_uuid;
	}
	if (!empty($caller_id_number)) {
		$mod_caller_id_number = str_replace("*", "%", $caller_id_number);
		$sql_where_ands[] = "c.caller_id_number like :mod_caller_id_number";
		$parameters['mod_caller_id_number'] = $mod_caller_id_number;
	}
	if (!empty($destination_number)) {
		$mod_destination_number = str_replace("*", "%", $destination_number);
		$sql_where_ands[] = "c.destination_number like :mod_destination_number";
		$parameters['mod_destination_number'] = $mod_destination_number;
	}
	if (!empty($context)) {
		$sql_where_ands[] = "c.context like :context";
		$parameters['context'] = '%'.$context.'%';
	}
	if (!empty($answer_stamp_begin) && !empty($answer_stamp_end)) {
		$sql_where_ands[] = "c.answer_stamp between :answer_stamp_begin and :answer_stamp_end";
		$parameters['answer_stamp_begin'] = $answer_stamp_begin.':00.000';
		$parameters['answer_stamp_end'] = $answer_stamp_end.':59.999';
	}
	else if (!empty($answer_stamp_begin)) {
		$sql_where_ands[] = "c.answer_stamp >= :answer_stamp_begin";
		$parameters['answer_stamp_begin'] = $answer_stamp_begin.':00.000';
	}
	else if (!empty($answer_stamp_end)) {
		$sql_where_ands[] = "c.answer_stamp <= :answer_stamp_end";
		$parameters['answer_stamp_end'] = $answer_stamp_end.':59.999';
	}
	if (!empty($end_stamp_begin) && !empty($end_stamp_end)) {
		$sql_where_ands[] = "c.end_stamp between :end_stamp_begin and :end_stamp_end";
		$parameters['end_stamp_begin'] = $end_stamp_begin.':00.000';
		$parameters['end_stamp_end'] = $end_stamp_end.':59.999';
	}
	else if (!empty($end_stamp_begin)) {
		$sql_where_ands[] = "c.end_stamp >= :end_stamp_begin";
		$parameters['end_stamp_begin'] = $end_stamp_begin.':00.000';
	}
	else if (!empty($end_stamp_end)) {
		$sql_where_ands[] = "c.end_stamp <= :end_stamp_end";
		$parameters['end_stamp_end'] = $end_stamp_end.':59.999';
	}
	if (!empty($duration)) {
		$sql_where_ands[] = "c.duration like :duration";
		$parameters['duration'] = '%'.$duration.'%';
	}
	if (!empty($billsec)) {
		$sql_where_ands[] = "c.billsec like :billsec";
		$parameters['billsec'] = '%'.$billsec.'%';
	}
	if (!empty($hangup_cause)) {
		$sql_where_ands[] = "c.hangup_cause like :hangup_cause";
		$parameters['hangup_cause'] = '%'.$hangup_cause.'%';
	}
	if (!empty($read_codec)) {
		$sql_where_ands[] = "c.read_codec like :read_codec";
		$parameters['read_codec'] = '%'.$read_codec.'%';
	}
	if (!empty($write_codec)) {
		$sql_where_ands[] = "c.write_codec like :write_codec";
		$parameters['write_codec'] = '%'.$write_codec.'%';
	}
	if (!empty($leg)) {
		$sql_where_ands[] = "c.leg = :leg";
		$parameters['leg'] = $leg;
	}
	//Exclude enterprise ring group legs
	if (!permission_exists('xml_cdr_enterprise_leg')) {
		$sql_where_ands[] = "c.originating_leg_uuid IS NULL";
	}
	//If you can't see lose_race, don't run stats on it
	elseif (!permission_exists('xml_cdr_lose_race')) {
		$sql_where_ands[] = "c.hangup_cause != 'LOSE_RACE'";
	}

	//if not admin or superadmin, only show own calls
	if (!permission_exists('xml_cdr_domain')) {
		if (is_array($_SESSION['user']['extension']) && count($_SESSION['user']['extension']) > 0) {
			foreach ($_SESSION['user']['extension'] as $row) {
				$user_extensions[] = $row['user'];
			}
			if (
				$caller_id_number != '' &&
				$destination_number != '' &&
				array_search($caller_id_number, $user_extensions) === false &&
				array_search($destination_number, $user_extensions) === false
				) {
				$sql_where_ors[] = "c.caller_id_number like :user_extension";
				$sql_where_ors[] = "c.destination_number like :user_extension";
				$sql_where_ors[] = "c.destination_number like :star_99_user_extension";
				$parameters['user_extension'] = $user_extension;
				$parameters['star_99_user_extension'] = '*99'.$user_extension;
			}
			if ($caller_id_number == '') {
				foreach ($user_extensions as $user_extension) {
					if (!empty($user_extension)) {
						$sql_where_ors[] = "c.caller_id_number like :user_extension";
						$parameters['user_extension'] = $user_extension;
					}
				}
			}
			if ($destination_number == '') {
				foreach ($user_extensions as $user_extension) {
					if (!empty($user_extension)) {
						$sql_where_ors[] = "c.destination_number like :user_extension";
						$sql_where_ors[] = "c.destination_number like :star_99_user_extension";
						$parameters['user_extension'] = $user_extension;
						$parameters['star_99_user_extension'] = '*99'.$user_extension;
					}
				}
			}
			if (sizeof($sql_where_ors) > 0) {
				$sql_where_ands[] = "( ".implode(" or ", $sql_where_ors)." )";
			}
		}
		else {
			$sql_where_ands[] = "1 <> 1";
		}
	}

//set the time zone
	if (isset($_SESSION['domain']['time_zone']['name'])) {
		$time_zone = $_SESSION['domain']['time_zone']['name'];
	}
	else {
		$time_zone = date_default_timezone_get();
	}
	$parameters['time_zone'] = $time_zone;

//build the sql query for xml cdr statistics
	$sql = "select ";
	$sql .= "row_number() over() as hours, ";
	$sql .= "to_char(start_date at time zone :time_zone, 'DD Mon') as date, \n";
	$sql .= "to_char(start_date at time zone :time_zone, 'HH12:MI am') || ' - ' || to_char(end_date at time zone :time_zone, 'HH12:MI am') as time, \n";
	$sql .= "extract(epoch from start_date) as start_epoch, ";
	$sql .= "extract(epoch from end_date) as end_epoch, ";
	$sql .= "s_hour, start_date, end_date, volume, answered, (round(d.seconds / 60, 1)) as minutes, \n";
	$sql .= "(volume / (s_hour * 60)) as calls_per_minute, \n";
	$sql .= "(volume / s_hour) as calls_per_hour,  missed, \n";
	$sql .= "(answered::numeric / (s_hour * 60)) as cpm_answered, \n";
	$sql .= "(volume / (s_hour * 60)) as avg_min, \n";
	$sql .= "(round(100 * (answered::numeric / NULLIF(volume, 0)),2)) as asr, \n";
	$sql .= "(round(seconds / NULLIF(answered, 0) / 60, 2)) as aloc, seconds \n";
	$sql .= "from \n";
	$sql .= "( \n";
	$sql .= "	select \n";
	$sql .= "	(count(*) filter ( \n";
	$sql .= "		where start_stamp between s.start_date and s.end_date \n";
	$sql .= "	)) as volume, \n";
	$sql .= "	(count(*) filter ( \n";
	$sql .= "		where start_stamp between s.start_date and s.end_date \n";
	$sql .= "		and c.originating_leg_uuid IS NULL \n";
	$sql .= "		and (c.answer_stamp IS NOT NULL and c.bridge_uuid IS NOT NULL) \n";
	$sql .= "		and (c.cc_side IS NULL or c.cc_side !='agent') \n";
	$sql .= "	)) as answered, \n";
	$sql .= "	(count(*) filter ( \n";
	$sql .= "		where start_stamp between s.start_date and s.end_date \n";
	$sql .= "		and missed_call = true \n";
	$sql .= "	)) as missed, \n";
	$sql .= "	(sum(c.billsec) filter ( \n";
	$sql .= "		where c.start_stamp between s.start_date and s.end_date \n";
	$sql .= "	)) as seconds, \n";
	$sql .= "	s.start_date, \n";
	$sql .= "	s.end_date, \n";
	$sql .= "	s.s_hour \n";
	$sql .= "	from v_xml_cdr as c, \n";
	$sql .= "	( \n";
	$sql .= "		select h.s_id, h.s_start, h.s_end, h.s_hour, \n";
	$sql .= "			(date_trunc('hour', now()) + (interval '1 hour') - (h.s_start * (interval '1 hour'))) as start_date, \n";
	$sql .= "			(date_trunc('hour', now()) + (interval '1 hour') - (h.s_end * (interval '1 hour'))) as end_date  \n";
	$sql .= "		from ( \n";
	$sql .= "				select generate_series(0, 23) as s_id, generate_series(1, 24) as s_start, generate_series(0, 23) as s_end, 1 s_hour \n";
	$sql .= "				union \n";
	$sql .= "				select 25 s_id, 24 as s_start, 0 as s_end, 24 s_hour \n";
	$sql .= "				union \n";
	$sql .= "				select 26 s_id, 168 as s_start, 0 as s_end, 168 s_hour \n";
	$sql .= "				union \n";
	$sql .= "				select 27 s_id, 720 as s_start, 0 as s_end, 720 s_hour \n";
	$sql .= "			) as h \n";
	$sql .= "		where true \n";
	$sql .= "		group by s_id, s_hour, s_start, s_end \n";
	$sql .= "		order by s_id asc \n";
	$sql .= "	) as s \n";
	$sql .= "where true \n";

//concatenate the 'ands's array, add to where clause
	if (is_array($sql_where_ands) && @sizeof($sql_where_ands) > 0) {
		$sql .= "and ".implode(" and ", $sql_where_ands)." ";
	}

	$sql .= "	group by s.s_id, s.start_date, s.end_date, s.s_hour \n";
	$sql .= "	order by s.s_id asc \n";
	$sql .= ") as d; \n";
	
	$database = new database;
	$stats = $database->select($sql, $parameters, 'all');

//set the hours
	$hours = 23;

//prepare graph data
	$graph = [
		'volume' => [],
		'minutes' => [],
		'call_per_min' => [],
		'missed' => [],
		'asr' => [],
		'aloc' => []
	];

	$x = 0;
	foreach ($stats as $row) {
		if ($x <= $hours) {
			$graph['volume'][] = [$row['start_epoch'] * 1000, (int)$row['volume']];
			$graph['minutes'][] = [$row['start_epoch'] * 1000, round($row['minutes'] ?? 0, 2)];
			$graph['call_per_min'][] = [$row['start_epoch'] * 1000, round($row['avg_min'] ?? 0, 2)];
			$graph['missed'][] = [$row['start_epoch'] * 1000, (int)$row['missed']];
			$graph['asr'][] = [$row['start_epoch'] * 1000, round(($row['asr'] ?? 0) / 100, 2)];
			$graph['aloc'][] = [$row['start_epoch'] * 1000, round($row['aloc'] ?? 0, 2)];
		}
		$x++;
	}

//prepare statistics table data
	$statistics = [];
	foreach ($stats as $row) {
		$statistics[] = [
			'hours' => $row['hours'],
			'date' => $row['date'],
			'time' => $row['time'],
			'volume' => (int)$row['volume'],
			'minutes' => round($row['minutes'] ?? 0, 2),
			'calls_per_minute' => round($row['calls_per_minute'] ?? 0, 2),
			'calls_per_hour' => round($row['calls_per_hour'] ?? 0, 2),
			'avg_min' => round($row['avg_min'] ?? 0, 2),
			'cpm_answered' => round($row['cpm_answered'] ?? 0, 2),
			'answered' => (int)($row['answered'] ?? 0),
			'missed' => (int)($row['missed'] ?? 0),
			'asr' => round($row['asr'] ?? 0, 2),
			'aloc' => round($row['aloc'] ?? 0, 2),
			'seconds' => (int)($row['seconds'] ?? 0),
			'start_epoch' => $row['start_epoch'],
			'stop_epoch' => $row['end_epoch'],
		];
	}

//return JSON response
	echo json_encode([
		'success' => true,
		'hours' => $hours,
		'graph' => $graph,
		'statistics' => $statistics,
		'metadata' => [
			'timestamp' => date('c'),
			'domain_uuid' => $_SESSION['domain_uuid'] ?? null,
		]
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;

?>
