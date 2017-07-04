<?php
/*
 * Copyright(C) 2004, 2005, 2007-2008 op5 AB
 *
 * If you want to pass review in this file, you must remove more code than you
 * add. You absolutely may move it to other files, but you may not grow this
 * file even more.
 */

define('SKIP_KOHANA', true);
require('/opt/monitor/op5/ninja/index.php');

require_once('include/webconfig.inc.php');
require_once('include/probe.inc.php');
require_once('include/config.inc.php');
require_once('include/data.inc.php');
require_once('include/autoscan.inc.php');
require_once('classes/common_gui.php');

if (!host_nacoma_Model::can_user_write('new') || !service_nacoma_Model::can_user_write('new')) {
	$page->html_start('');
	deny_access("You are not allowed to create new hosts and services.");
}

$agent_ports = array(5666 => 'nrpe', 1248 => 'nsclient', 9999 => 'netware', 135 => 'wmi');

// temp object ids for host objects until we're done scanning everything. Should always be < 0
$next_host_id = -1;

# Given a port-number and a host, this function will try to determine
# if the proper agent is actually running on that port
function detect_agents(&$scan, $host)
{
	global $DEBUG;
	global $agent_ports;
	global $poller;
	$ret = 0;

	$inst = false;
	$agents = array();

	foreach ($scan['net'] as $port => $discard) {
		# the command to run must be one that returns OK
		$expect = false;
		switch ($port) {
		 case 1248:
			$cmd = probe("DETECT_NCP", $host['address']);
			$expect = array("NSClient", "2.0.1.0");
			break;
		 case 5666:
			$cmd = probe("DETECT_NRPE", $host['address']);
			break;
		 case 9999:
			$cmd = probe("DETECT_NRPE", $host['address']);
			break;
		 case 135:
			$cmd = probe("DETECT_WMI", array('address' => $host['address'], 'username' => isset($host['wmi_username'])?$host['wmi_username']:'', 'password' => isset($host['wmi_password'])?$host['wmi_password']:''));
			break;
		 default:
			continue 2;
		}

		if ($cmd['value'] === 0) {
			$ret = 1;
		}

		if ($expect) {
			# we need to have 'expect' as an array in order to cater to
			# both kinds of NSClient that we support
			$ret = 0;

			$lines = explode("\n", $cmd['buf']);
			foreach ($lines as $line) {
				foreach ($expect as $e) {
					if (strstr($line, $e)) {
						$ret = 1;
						break;
					}
				}
				if ($ret) {
					break;
				}
			}
		}

		if ($ret) {
			$agents[$port] = $agent_ports[$port];
		}
	}

	# If host has webserver, check if it's a Sensatronics device
	if (isset($scan['net'][80])) {
		$cmd = probe("DETECT_E4", $host['address']);
		if($cmd['value'] === 0){
			$lines = explode("\n", $cmd['buf']);

			$pattern = '/^HTTP OK: Status line output matched "Probe".*/';
			foreach ($lines as $line) {
				if (preg_match($pattern, $line, $match)) {
					if($DEBUG){
						echo "<pre>";
						echo "This is a Sensatronics E4";
						echo "</pre>";
					}
					$agents[9898] = 'sensatronics-e4';
				}
			}
		}

		$cmd = probe("DETECT_EM1", $host['address']);
		if($cmd['value'] === 0){
			$lines = explode("\n", $cmd['buf']);
			$pattern = '/^HTTP OK: Status line output matched "C".*/';
			foreach ($lines as $line) {
				if (preg_match($pattern, $line, $match)) {
					if($DEBUG){
						echo "<pre>";
						echo "This is a Sensatronics EM1";
						echo "</pre>";
					}
					$agents[9899] = 'sensatronics-em1';
				}
			}
		}
	}

	# remove the agent ports from the 'net' scan list
	foreach ($agents as $port => $discard) {
		unset($scan['net'][$port]);
	}

	# if we have both NRPE and NSClient installed and properly
	# detected, we need to set the agent detected as "nrpe" originally
	# to "nt_nrpe" which will disable all the unix-specific checks for
	# that agent.
	if (isset($agents[1248]) && isset($agents[5666])) {
		$agents[5666] = 'windows script';
	}

	if ($DEBUG) {
		echo "<pre>\nagents = "; print_r($agents); echo "</pre>\n";
	}

	return($agents);
}

function uniqueify_service_list($host_id, &$service_list, $cmd)
{
	$stmt = nacoma_db::prepare_statement(
		'SELECT service_description, command_name, check_command_args FROM service ' .
		'INNER JOIN command ON command.id = service.check_command ' .
		'WHERE host_name = :host_name');
	$stmt->execute(array('host_name' => $host_id));
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		foreach ($service_list as $check_type => $checklist) {
			foreach ($checklist as $check_name => $servicedesc) {
				if ($row['service_description'] == $servicedesc)
					unset($service_list[$check_type][$check_name]);
				if ($row['command_name'] == $cmd[$check_name]['check_command']
					&& $row['check_command_args'] == $cmd[$check_name]['check_command_args'])
					unset($service_list[$check_type][$check_name]);
			}
		}
	}
	foreach ($service_list as $check_type => $check_list) {
		if (empty($check_list))
			unset($service_list[$check_type]);
	}
}

// remove configured hosts and pretty_print the hosts found
function scan_complete($target_list, $tm_total = 0, $cut_domain = false) {
	global $poller, $next_host_id, $page;
	if($tm_total >= 2) $tm_total = $tm_total . ' seconds';
	elseif($tm_total === 0) $tm_total = 'less than 1 second';
	elseif($tm_total === 1) $tm_total = '1 second';

	if($cut_domain !== false) foreach($target_list as $ip => $dns_name) {
		$dns_name = str_replace($cut_domain, '', $dns_name);
		$target_list[$ip] = $dns_name;
	}

	filter_monitored_hosts($target_list);
	$targets = count($target_list);
	echo "Scan completed in $tm_total.<br />\n";
	echo "Found <strong>".count($target_list)."</strong> responding ";
	if(count($target_list) === 1) echo "host";
	else echo "hosts";
	echo ".<br />\n";

	echo "<br /><strong>NOTE:</strong> Only hosts that aren't previously " .
	  "configured will be listed.<br />\n";
	echo '</div>';
	echo "<form method='post' action='$_SERVER[PHP_SELF]' onsubmit='return processing_scan()'>\n";

	foreach($target_list as $ip => $dns_name) {
		$obj = new new_host_nacoma_Model();
		$obj->obj['host_name'] = $dns_name;
		$obj->obj['address'] = $ip;
		$obj->get_default_object_sanely();
		echo '<div style="clear: both;">';
		echo $page->help_for_object($obj);
		echo '</div>';
		$obj->id = $next_host_id--;
		$obj->prepare_draw();
		echo $page->pretty_print_one($obj);
	}

	if ($target_list) {
		if ($poller) {
			echo "<strong>NOTE:</strong> These hosts will be scaned by poller: $poller <br />";
			echo "<input type='hidden' name='poller_to_use' value='$poller' />";
		}

		echo '<div class="magic">';
		echo "<input type='hidden' name='action' value='list_services' />" .
		  "<input type='submit' name='x' value= \"Add services\" title='Add services' id='scan-host-for-services' class='button scan-host-for-services' onclick='loopElements(this.form)' />\n";
		  echo '<div id="progress_div" style="padding: 10px 0px;"></div>';
		echo '</div>';
	 }
	echo "</form>\n";
}

// fetch input required for ping-scanning
function ping_input() {
	global $page;
	$page->html_start('');

	echo "<h2>Network scan, step 1</h2>\n";
	echo "<p>Network ranges can be specified in a very free form.\n" .
	  "Each of the four parts of the IP-address may contain any\n" .
	  "combination of comma-separated numbers, 'from-to' ranges\n" .
	  "and single numbers, as such: <strong>10.1,2.0,4-10.1-50</strong>.<br />\n" .
	  "You can specify multiple ranges, separated by spaces, if you like." .
	  "<br/>Of course, you can also specify the IP subnet in standard CIDR notation, such as 10.0.0.0/24." .
	  "</p>\n";
	echo "<strong>NOTE1:</strong> Only hosts responding to ICMP ECHO " .
	  "requests (PING) will be detected.<br />\n";
	echo "<strong>NOTE2:</strong> The text in the field 'Top Domainname' will be " .
	  "removed from the autodetected hostnames.<br />\n";

	echo "</div><div class=\"object-table\"><form method=\"post\" action=\"$_SERVER[PHP_SELF]\">\n";
	echo "<table class=\"max zebra ObjTable\"><tr>";

	echo "<td class=\"VarList\">IP Range</td>" .
	  "<td><input type=\"text\" size=\"40\" name=\"ip_range\" class=\"medium\" /></td></tr><tr>\n" .
	  "<td class=\"VarList\">Top Domainname</td>" .
	  "<td><input type=\"text\" size=\"15\" name=\"cut_domain\" class=\"medium\" />" .
	  "</td></tr>";
	echo "<tr><td class\"VarList\">Poll from</td>" .
		"<td><select name=\"poller_to_use\" style=\"width: 208px;\">";
	foreach (get_merlin_nodes() as $node) {
		echo "<option value=\"$node\">$node</option>";
	}
	echo "</select></td></tr></table>\n";
	echo '<div class="magic max">';
	echo "<input type=\"hidden\" name=\"action\" value=\"ping_scan\" />
		<input type=\"submit\" value=\"Scan Ranges\" title=\"Scan Ranges\" name=\"x\" id=\"ping_scan\" class=\"button scan-ranges\" />
		<div id=\"progress_div\" style=\"padding: 10px 0px;\"></div>";
	echo "</div></form></div>\n";
	$page->html_end();
}

// do the ping scan
function ping_scan()
{
	global $page;
	global $poller;
	$page->html_start('');

	if(empty($_REQUEST['ip_range'])) {
		echo "<cite>You must specify an IP-range to scan</cite><br />\n";
		ping_input();
	}
	$cut_domain = false;
	if(!empty($_REQUEST['cut_domain'])) {
		$cut_domain = $_REQUEST['cut_domain'];
		if($cut_domain{0} !== '.') $cut_domain = '.' . $cut_domain;
	}

	// set it up and do the scan.
	$ip_range = $_REQUEST['ip_range'];
	$tm_start = time();
	$target_list = ping_sweep($ip_range);

	$tm_end = time();
	$tm_total = $tm_end - $tm_start;

	scan_complete($target_list, $tm_total, $cut_domain);

	$page->html_end();
}

// shows autoscan results
function autoscan_results() {
	global $page;
	$page->html_start('');
	$target_list = array();
	if (isset($_REQUEST['scan'])) {
		$scan = $_REQUEST['scan'];
		$list = autoscan_result_get_for_scan($scan, 1);
		scan_complete($list);
	} else {
		// display info message
	}
	$page->html_end();
}

function autoscan_list()
{
	global $page;
	$page->html_start('');
	echo "<img class=\"show\" alt=\"\" src=\"images/icons/arrow.gif\">\n";
	echo "<a class=\"arrow\" href=\"autoscan.php\" title=\"Go to autoscan configuration page\">Network autoscan configuration</a>\n";
	echo "</div><div class=\"object-table\">";
	echo "<table class= \"ObjTable\">\n";
	echo "<tbody>\n";
	echo "<tr>\n";
	echo "<td>\n";
	echo "<table class= \"max\" style=\"width: 100%;\">\n";
	echo "<tbody>\n";
	echo "<tr>\n";
	echo "<td class=\"CmdBar\" style=\"width: 5%; min-width: 30px\">N </td>\n";
	echo "<td class=\"CmdBar\" style=\"width: 20%;\">Name </td>\n";
	echo "<td class=\"CmdBar\" style=\"width: 30%;\">Query </td>\n";
	echo "<td class=\"CmdBar\">Description </td>\n";
	echo "</tr>\n";
	echo "</tbody>\n";
	echo "</table>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td>\n";
	echo "<table class= \"max zebra\">\n";
	echo "<tbody>\n";

	//SELECT id, count, epoch, criteria, name, description, enabled FROM autoscan_settings
	$new_host_list = autoscan_settings_get_list();
	$index = 1;
	foreach ($new_host_list as $row) {
		$scan_id = $row[0];
		$scan_count = $row[1];
		$scan_criteria = $row[3];
		$scan_name = $row[4];
		$scan_desc = $row[5];
		if (is_null($scan_count)) {
			// Recalculate result count
			autoscan_settings_set_count($scan_id, 0);
			$scan_count = autoscan_result_get_count_for_scan($scan_id, 1);
			autoscan_settings_set_count($scan_id, $scan_count, true);
		}
		echo "<tr>\n";
		echo "<td class=\"CmdBar\" style=\"width: 5%; min-width: 30px\"> " . $index . " </td>\n";

		echo "<td style=\"width: 20%;\">\n";

		echo "<a style=\"border: 0px none ; color: red; border-bottom: 1px dotted;\" href=\"host_wizard.php?action=autoscan_result&scan=" . $scan_id . "\">". $scan_name . " (" . $scan_count . " new hosts)</a>\n";
		echo "</td>\n";

		echo "<td style=\"width: 30%;\">\n";
		echo $scan_criteria;
		echo "</td>\n";

		if ($index % 2)
			echo "<td>\n";
		else
			echo "<td>\n";
		echo $scan_desc;
		echo "</td>\n";
		echo "</tr>\n";
		$index++;
	}
	unset($new_host_list);

	echo "</tbody>\n";
	echo "</table>\n";
	echo "</td>\n";
	echo "</tr>\n";
	echo "</tbody>\n";
	echo "</table>\n";
	$page->html_end();
}

# sanitizes a string for use both in Naemon's configuration
# as well as on commandline
function config_command_sanitize($str)
{
	# we must take care of exclamation marks specifically
	# here, or Naemon will end up splitting the argument
	# list on any un-escaped exclamation marks found in the
	# escaped string.
	return str_replace('!', '\'\!\'', escapeshellarg($str));
}

# snmp interface scan complete, so add services and such
function snmp_scan_complete() {
	global $DEBUG, $page, $input;
	global $SUPPORT;
	$page->html_start('');

	require_once('include/snmp_functions.inc.php');

	if(empty($_REQUEST['p_host_list']) || empty($_REQUEST['p_if_list'])) {
		echo "Data shiftaround error. Contact $SUPPORT<br />\n";
		if(!$DEBUG) $page->html_end();
	}

	if(empty($_REQUEST['interface_services'])) {
		echo "No interface services checked.<br />\n";
		$page->html_end();
	}

	if(empty($_REQUEST['snmp_version']) ||
	   empty($_REQUEST['service']))
	{
		echo "No snmp version, community info or master service object data.<br />\n";
		echo "Pressing reload without posting form data won't work.<br />\n";
		$page->html_end();
	}

	$master_service = $_REQUEST['service']['new'];
	$community = config_command_sanitize($_REQUEST['community']);
	# v3 ---------------------------------------
	$v3_seclevel = $_REQUEST['v3_seclevel'];
	$v3_authprot = $_REQUEST['v3_authprot'];
	$v3_authpass = config_command_sanitize($_REQUEST['v3_authpass']);
	$v3_privprot = $_REQUEST['v3_privprot'];
	$v3_privpass = config_command_sanitize($_REQUEST['v3_privpass']);
	$v3_secname = config_command_sanitize($_REQUEST['v3_secname']);
	# v3 ---------------------------------------
	$snmp_version = $_REQUEST['snmp_version'];
	if($DEBUG) {
		echo "community: $community; version: $snmp_version<br />\n";
	}

	$p_host_list = $_REQUEST['p_host_list'];
	$p_if_list = $_REQUEST['p_if_list'];
	$interface_services = $_REQUEST['interface_services'];

	$commands_to_use = array('check_snmpif_status_v1' => false,
							 'check_snmpif_errors_v1' => false,
							 'check_traffic_bps_v1' => false);
    if ($snmp_version == '2c') {
	    $commands_to_use = array("check_snmpif_status_v2" => false,
	    						 "check_snmpif_errors_v2" => false,
	    						 "check_traffic_bps_v2" => false);
    }
    if ($snmp_version == '3') {
	    $commands_to_use = array("check_snmpif_status_v3" => false,
	    						 "check_snmpif_errors_v3" => false,
	    						 "check_traffic_bps_v3" => false);
    }
	foreach($commands_to_use as $cmd => $val) {
		if(!command_nacoma_Model::get_id_for($cmd)) {
			echo '<table><tr><td rowspan=2><img src="images/icons/shield-critical.png"'.
			  ' /></td>';
			echo "<td>Unable to find required check command '$cmd', so unable to create services for it.".
			  "</td></tr>";
			echo '<tr><td>Please import it from '.
			  '<a href="/monitor/op5/nacoma/metadata.php">Check Command Import</a>'.
			  '</td></tr></table>';
			unset($commands_to_use[$cmd]);
			continue;
		}
	}

	$snmp_service_mold =
	  array('Traffic' => "check_traffic_bps_v1!$community!%IFINDEX%!70!90",
			'Errors' => "check_snmpif_errors_v1!$community!%IFINDEX%!1.5!2.5",
			'Status' => "check_snmpif_status_v1!$community!%IFINDEX%!c");
	if ($snmp_version == '2c') {
		$snmp_service_mold =
		  array('Traffic' => "check_traffic_bps_v2!$community!%IFINDEX%!70!90",
				'Errors' => "check_snmpif_errors_v2!$community!%IFINDEX%!1.5!2.5",
				'Status' => "check_snmpif_status_v2!$community!%IFINDEX%!c");
	}
    if ($snmp_version == '3') {
        $params = "-L $v3_seclevel ";
        if ($v3_secname != '') {
            $params = $params . "-U $v3_secname ";
        }
        if (strtolower($v3_seclevel) == 'noauthnopriv') {
        } elseif (strtolower($v3_seclevel) == 'authnopriv') {
            $params = $params . "-a $v3_authprot -A $v3_authpass ";
        } elseif (strtolower($v3_seclevel) == 'authpriv') {
            $params = $params . "-a $v3_authprot -A $v3_authpass ";
            $params = $params . "-P $v3_privprot -X $v3_privpass ";
        }

	    $snmp_service_mold =
	      array('Traffic' => "check_traffic_bps_v3!$params!%IFINDEX%!70!90",
			    'Errors' => "check_snmpif_errors_v3!$params!%IFINDEX%!1.5!2.5",
			    'Status' => "check_snmpif_status_v3!$params!%IFINDEX%!c");
    }
	foreach($snmp_service_mold as $k => $v) {
		$ary = explode('!', $v, 2);
		if(!isset($commands_to_use[$ary[0]])) continue;
		$service_mold[$k]['check_command'] = $ary[0];
		$service_mold[$k]['check_command_args'] = $ary[1];
	}

	if($DEBUG) {
		echo "<pre>\n"; print_r($commands_to_use); echo "</pre>\n";
		echo "<pre>\n"; print_r($snmp_service_mold); echo "</pre>\n";
	}
	# init some vars
	$srv_list = false;
	$added = array();
	$errors = 0;
	foreach($interface_services as $host_id => $srv) {
		$host_error_header_printed = false;
		if(!isset($p_host_list[$host_id])) {
			# some serious error happened. A host that has
			# services selected wasn't passed along
			continue;
		}
		$host = $p_host_list[$host_id];

		foreach($srv as $interface_id => $int_ary) {
			if(empty($p_if_list[$host_id][$interface_id])) {
				# some serious error happened. An interface with selected
				# services didn't get its data passed along
				continue;
			}
			$interface = $p_if_list[$host_id][$interface_id];
			foreach($int_ary as $srv => $val) {
				$service = new service_nacoma_Model();
				$service->get_default_object();
				$service->set_master_object_id($host_id);

				if(empty($val) || strtolower($val) !== 'on') continue;
				if(empty($service_mold[$srv])) continue;
				$service->normalize($master_service);
				$service->normalize($service_mold[$srv]);

				$desc = $interface['interface_name'] . ' ' . $srv;

				$service->obj['service_description'] = clean_illegal_chars($desc);

				# properly substitute the check_command_args with real values
				if(!empty($service->obj['check_command_args'])) {
					$service->obj['check_command_args'] =
					  str_replace('%IFINDEX%', $interface_id,
								  $service->obj['check_command_args']);
					$service->obj['check_command_args'] =
					  str_replace('%SPEED%', $interface['ifSpeed'],
								  $service->obj['check_command_args']);
				}

				$srv_list[] = $service->obj;
				if ($service->save_object() === false) {
					if (!$host_error_header_printed) {
						$tmp_host = new host_nacoma_Model($host_id);
						$host_name = $tmp_host->get_object_name();
						$host_error_header_printed = true;
						echo "<p>Errors encountered while saving services " .
							"for host <strong>$host_name</strong>\n";
					}
					echo "<p>Failed to save service <strong>" .
						$service->obj['service_description'] . "</strong>\n";
					$page->print_validation_error_table($service);
					$errors++;
				}
				else {
					if (empty($added[$host_id]))
						$added[$host_id] = 1;
					else
						$added[$host_id]++;
				}
			}
		}
	}

	echo "<br />\n";
	if (!empty($errors)) {
		echo "<strong>$errors errors encountered.<strong><br />\n";
	}
	if (empty($added)) {
		echo "<strong>No services added.</strong><br />\n";
	} else foreach($added as $host_id => $num) {
		echo "Added $num services to host \n";
		echo "<a href='edit.php?obj_type=service&master_obj_id=$host_id'>" .
		  $p_host_list[$host_id]['host_name'] . "</a><br />\n";
	}

	$page->html_end();
}


// scan hosts for snmp services (interface stuff only)
function snmp_scan() {
	global $DEBUG, $page, $input;

	$page->html_start('');
    ?>
	<script type="text/javascript" language="JavaScript">
	<!--
	function toggle_snmp_fields(version) {
      if (version == '1' || version == '2c') {
        document.getElementById('snmp_v2_fields').style.display = '';
        document.getElementById('snmp_v3_fields').style.display = 'none';
      } else {
        document.getElementById('snmp_v3_fields').style.display = '';
        document.getElementById('snmp_v2_fields').style.display = 'none';
      }
    }
	function toggle_v3_seclevel_fields(seclevel) {
      if (seclevel == 'noAuthNoPriv') {
        document.getElementById('v3_auth').style.display = 'none';
        document.getElementById('v3_priv').style.display = 'none';
      } else if (seclevel == 'authNoPriv') {
        document.getElementById('v3_auth').style.display = '';
        document.getElementById('v3_priv').style.display = 'none';
      } else if (seclevel == 'authPriv') {
        document.getElementById('v3_auth').style.display = '';
        document.getElementById('v3_priv').style.display = '';
      }
    }
	//-->
	</script>
    <?php

	$host_list = false;

	echo "<h2>SNMP Interface scan</h2>\n";

	if(empty($_REQUEST['host_list']) ||
	   empty($_REQUEST['snmp_version']))
	{
		echo "<div></div><br /><strong>Note</strong>: Interfaces that aren't up and in use won't be listed.</div>\n";
		$page->form_start('foo_form');

		# pretty-print the data input table
		echo '<div class="object-table"><table class="max ObjTable zebra">';
		echo "<tr class='header'><td colspan=2>Input scan parameters</td></tr>\n";

		echo "<tr><td class=\"VarList\">SNMP Version</td>\n";
		echo "<td>\n";
		echo "  <select name='snmp_version' onchange=\"toggle_snmp_fields(this.options[this.selectedIndex].value)\">\n";
		echo "  <option name='1'>1</option>\n";
		echo "  <option name='2'>2c</option>\n";
		echo "  <option name='3' selected='selected'>3</option>\n";
		echo "  </select>\n";
		echo "</td></tr>\n";

		echo "<tr id='snmp_v2_fields' style=\"display: none;\">\n";
		echo "<td class=\"VarList\">SNMP Community</td>\n";
		echo "<td>";
		echo "    <input type=\"text\" name=\"snmp_community\" value=\"public\" size=\"20\" />\n";
		echo "</td></tr>\n";
        # v3----------------------------------
		echo "<tr id='snmp_v3_fields'>\n";
		echo "<td class=\"VarList\">SNMPv3 </td>\n";
		echo "<td>";
        echo "<div id='v3_level' style='float: left'>";
        echo "Security level ";
		echo "  <select name='v3_seclevel' onchange=\"toggle_v3_seclevel_fields(this.options[this.selectedIndex].value)\">\n";
		echo "  <option name='authPriv'>authPriv</option>\n";
		echo "  <option name='authNoPriv'>authNoPriv</option>\n";
		echo "  <option name='noAuthNoPriv'>noAuthNoPriv</option>\n";
		echo "  </select>\n";
        echo " &nbsp; Security name";
		echo "    <input type=\"text\" name=\"v3_secname\" value=\"\" size=\"10\" />\n";
        echo "</div>";
        echo "<div id='v3_auth' style='float: left;'>";
        echo " &nbsp; Authprot";
		echo "  <select name='v3_authprot'>\n";
		echo "  <option name='SHA'>SHA</option>\n";
		echo "  <option name='MD5'>MD5</option>\n";
		echo "  </select>\n";
        echo " &nbsp; Authpass";
		echo "    <input type=\"text\" name=\"v3_authpass\" value=\"\" size=\"10\" />\n";
        echo "</div>";
        echo "<div id='v3_priv' style='float: left;'>";
        echo " &nbsp; Privprot";
		echo "  <select name='v3_privprot'>\n";
		echo "  <option name='AES'>AES</option>\n";
		echo "  <option name='DES'>DES</option>\n";
		echo "  </select>\n";
        echo " &nbsp; Privpass";
		echo "    <input type=\"text\" name=\"v3_privpass\" value=\"\" size=\"10\" />\n";
        echo "</div>";
		echo "</td></tr>\n";
        # v3----------------------------------

		echo "<tr><td class=\"VarList\">\n";
		echo "Hosts to scan</td><td>\n";
		if ($input->obj_id) {
			$host = new host_nacoma_Model($input->obj_id);
			$hosts = array($host->get_object_name() => $host->get_object_name());
		} else {
			$hosts = array();
		}

		$page->make_multi_selection('host', $hosts, 'host_list');
		echo "</td></tr>\n";
		echo "<tr><td class\"VarList\">Poll from</td>" .
			"<td><select name=\"poller_to_use\" style=\"width: 208px;\">";
		foreach (get_merlin_nodes() as $node) {
			echo "<option value=\"$node\">$node</option>";
		}
		echo "</select></td></tr>";
		echo "</table><br />\n";

		$page->hidden_var('action', 'snmp_scan');
		$page->submit_button('Scan hosts', 'x');
		$page->form_end();
		echo "</div>\n";
		$page->html_end();
	}

	require_once('include/snmp_functions.inc.php');

	$community = $_REQUEST['snmp_community'];
    # v3 ---------------------------------------
	$v3_seclevel = $_REQUEST['v3_seclevel'];
	$v3_authprot = $_REQUEST['v3_authprot'];
	$v3_authpass = $_REQUEST['v3_authpass'];
	$v3_privprot = $_REQUEST['v3_privprot'];
	$v3_privpass = $_REQUEST['v3_privpass'];
	$v3_secname = $_REQUEST['v3_secname'];
    # v3 ---------------------------------------
	$snmp_version = $_REQUEST['snmp_version'];
	if($DEBUG) {
		echo "community: $community; version: $snmp_version<br />\n";
	}


	$host_list = $_REQUEST['host_list'];
	$query = 'SELECT id, host_name, address FROM host WHERE ';
	$filters = array_map(function($item) { return 'host_name = '.nacoma_db::quote_string($item); }, $host_list);
	$query .= implode(" OR ", $filters);
	if($DEBUG) echo "query: $query<br />\n";
	$result = nacoma_db::exec_query($query);
	$host_list = nacoma_db::fetch_result($result);
        unset($result);
	$scan_list = array();
	foreach($host_list as $id => $host) {
        if ($snmp_version == '3') {
			$scan_list[$id] = do_snmp_scan('snmp_interface_scan_v3', array($host['address'], $v3_secname, $v3_seclevel, $v3_authprot, $v3_authpass, $v3_privprot, $v3_privpass, $snmp_version));
			if ($scan_list[$id] === false)
				echo "<p>Node {$host['address']} either doesn't support SNMP, or the authentication settings is incorrect.</p>\n";
			else if (empty($scan_list[$id]))
				echo "<p>Node {$host['address']} doesn't seem to have any interfaces</p>\n";
        } else {
		    $scan_list[$id] = do_snmp_scan('snmp_interface_scan', array($host['address'], $community, $snmp_version));
			if ($scan_list[$id] === false)
				echo "<p>Node {$host['address']} either doesn't support SNMP, or the community name (\"$community\") is incorrect.</p>\n";
			else if (empty($scan_list[$id]))
				echo "<p>Node {$host['address']} doesn't seem to have any interfaces</p>\n";
        }
		if(empty($scan_list[$id])) unset($scan_list[$id]);
	}

	if(empty($scan_list)) {
		echo "No active interfaces found<br />\n";
		$page->html_end();
	}

	# print the master service object
	$page->form_start();

	$host_list = array();
	foreach($scan_list as $host_id => $somethinguninteresting) {
		$host = new host_nacoma_Model($host_id);
		$host_list[$host_id] = $host->get_object();
	}

	print_initial_service();

	echo "<p><strong>Check a box to add a servicecheck with default values. ";
	echo "If you don't check any boxes, no services will be added.</strong></p>\n";

	# Print the service selection table (the checkbox thingie)
	$if_srv_list = array('Status', 'Traffic', 'Errors');

	# preserve community and version to next round
	echo "<input type='hidden' name='community' value='$community' />\n";
	echo "<input type='hidden' name='snmp_version' value='$snmp_version' />\n";
    # v3-------------------------------------------
	echo "<input type='hidden' name='v3_seclevel' value='$v3_seclevel' />\n";
	echo "<input type='hidden' name='v3_authprot' value='$v3_authprot' />\n";
	echo "<input type='hidden' name='v3_authpass' value='$v3_authpass' />\n";
	echo "<input type='hidden' name='v3_privprot' value='$v3_privprot' />\n";
	echo "<input type='hidden' name='v3_privpass' value='$v3_privpass' />\n";
	echo "<input type='hidden' name='v3_secname' value='$v3_secname' />\n";
    # v3-------------------------------------------

	foreach($scan_list as $host_id => $scan) {
		if(empty($scan)) {
			echo "No interfaces found on " . $host_list[$host_id]['host_name'] . "<br />\n";
			continue;
		}

		# quick-link to host variables and preserve it to next round
		# so we can match against db (don't add if host stuff has changed)
		$host = $host_list[$host_id];
		foreach($host as $k => $v) {
			echo "<input type='hidden' name='p_host_list[$host_id][$k]' value='$v' />\n";
		}
		echo "<table align='center' class='max'><tr><td class='CmdBar'>";
		echo "$host[host_name]";
		if($host['address'] !== $host['host_name']) echo "@ $host[address]";
		echo "</td></tr>\n";

		echo "<tr><td>\n";
		echo "<table class='max zebra'>\n";
		echo "  <tr class='header'><td>Interface</td>\n";
		foreach($if_srv_list as $foo) {
			echo "  <td>$foo</td>\n";
		}
		echo "</tr>";
		$foo = 1;
		echo "<tr>
			<td align=right><strong>Select all:</strong></td>
			<td align=center><input type=\"checkbox\" op5_xtra=\"Status|".$host_id."\" class=\"check_all_snmp\"></td>
			<td align=center><input type=\"checkbox\" op5_xtra=\"Traffic|".$host_id."\" class=\"check_all_snmp\"></td>
			<td align=center><input type=\"checkbox\" op5_xtra=\"Errors|".$host_id."\" class=\"check_all_snmp\"></td>
			</tr>";

		# find all existing services for this particular host
		# so we don't list an already existing service as being
		# possible to add
		$host = new host_nacoma_Model($host_id);
		$service_ids = $host->get_sub_object_ids('service', 'host_name');
		$existing_services = array();
		foreach ($service_ids as $service_id) {
			$service = new service_nacoma_Model($service_id);
			$existing_services[$service->obj['service_description']] = true;
		}

		foreach($scan as $interface_id => $int_ary) {
			# preserve the snmp info so we don't have to scan it twice
			$interface_name = snmp_get_if_name($int_ary);
			$interface_name = str_replace('"', '&quot;', $interface_name);

			foreach($int_ary as $k => $v) {
				echo "  <input type='hidden' name='p_if_list[$host_id][$interface_id][$k]' value='$v' />\n";
			}
			echo "<tr>\n";
			echo "<td width='80%'>";
			echo "<input class='snmp_if_name' type='text' name='p_if_list[$host_id][$interface_id][interface_name]' " .
				"value=\"$interface_name\" size='95'/>\n";
			echo "</td>\n";
			foreach ($if_srv_list as $check_type) {
				echo "<td align='center'>\n";
				echo "  <input type='checkbox' name='" .
				  'interface_services[' . $host_id . ']' . '[' . $interface_id . '][' . $check_type . "]' ";

				$desc = $interface_name . ' ' . $check_type;

				$clean_desc = clean_illegal_chars($desc);
				if (isset($existing_services[$desc]) || isset($existing_services[$clean_desc]))
				{
					echo "checked='checked' disabled='disabled'";
				}
				echo "/>\n";
				echo "</td>\n";
			}
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "<td></tr></table><br /><br />\n";
	}

	$page->hidden_var('action', 'snmp_scan_complete');
	$page->submit_button('Add selected services', 'x');
	$page->form_end();
	$page->html_end();
}

/**
 * Choose whether to use wmi or agent scan
 */
function choose_win_check_type($obj_id) {
	global $page;
	$page->html_start();

	$page->form_start('win_check_type_form');
	echo '<div class="object-table">';
	echo '<table class="max zebra ObjTable">'.
		'<tr><td class="CmdBar" colspan="3">Choose scan type:</td></tr><tr><td>Scan type</td><td>';
	echo '<input type="radio" name="action" value="check_windows_services" class=\"auto\" />Scan using NSClient<br />';
	echo '<input type="radio" name="action" id="check_wmi_services" value="check_wmi_services" class=\"auto\" />Scan over WMI';
	echo '<div class="wmi_auth" style="display: none;"><br />Username: <input type="text" name="wmi_username"><br />';
	echo 'Password: <input type="password" name="wmi_password"></div>';
	echo '</td></tr>';
	echo "<tr><td class\"VarList\">Poll from</td>" .
		"<td><select name=\"poller_to_use\" style=\"width: 208px;\">";
	foreach (get_merlin_nodes() as $node) {
		echo "<option value=\"$node\">$node</option>";
	}
	echo "</select></td></tr>";
	echo "</table>";
	echo '</div>';
	echo '<div id="error_msg"></div>';
	echo '<div class="magic max>';
	$page->submit_button('Scan', 'x');
	echo '</div>';
	$page->hidden_var('obj_id', $obj_id);
	$page->form_end();
	echo '<script type="text/javascript">$(\'#win_check_type_form input:radio\').change(function() {
		if ($(\'#check_wmi_services\').is(\':checked\'))
			$(\'.wmi_auth\').show();
		else
			$(\'.wmi_auth\').hide();
		});</script>';

	$page->html_end();
}

function check_wmi_services($obj_id) {
	global $page;
	$page->html_start('');

	$host = new host_nacoma_Model($obj_id);
	if (empty($host->obj) || empty($host->obj['address'])) {
		echo '<table><tr><td><img src="images/icons/shield-critical.png" /></td>';
		echo "<td>ERROR: Unable to find any host address to scan</td></tr></table>";
		$page->html_end();
		return false;
	}

	$address = $host->obj['address'];

	if (empty($address)) {
		echo '<table><tr><td><img src="images/icons/shield-critical.png" /></td>';
		echo "<td>ERROR: Unable to find any host address to scan</td></tr></table>";
		$page->html_end();
		return false;
	}

	$services = wmi_service_scan($address, $_REQUEST['wmi_username'], $_REQUEST['wmi_password']);
	$svcs = array();
	if ($services === false) {
		echo '<div>WMI service scan failed.</div>';
	}else{
		foreach ($services as $svc) {
			$svcs[$svc] = $svc;
		}
	}
	$page->form_start('win_service_form'); # if you change this - edit common.js
	echo '<div class="object-table">';
	echo '<table class="max zebra ObjTable">'.
		'<tr><td class="CmdBar" colspan="3">Select services:</td></tr><tr><td></td><td></td><td>';
	$page->make_multi_selection($svcs, false, 'wmi_services');
	echo '</td></tr></table>';
	echo '<div class="magic max>';
	$page->submit_button('Add Selected Services', 'x');
	echo '</div>';
	$page->hidden_var('action', 'wmi_service_complete');
	$page->hidden_var('master_obj_id', $host->id);
	$page->hidden_var('wmi_username', $_REQUEST['wmi_username']);
	$page->hidden_var('wmi_password', $_REQUEST['wmi_password']);
	$page->form_end();
	$page->html_end();
}

function wmi_service_complete() {
	global $page;
	$cmd_obj = new command_nacoma_Model();

	# get check ID for the custom nrpe check command
	$cmd = $cmd_obj->get_object_by_name('check_wmip_service');

	$page->html_start('');
	if (empty($cmd)) {
		# missing check command?
		echo '<table><tr><td rowspan=2><img src="images/icons/shield-critical.png"'.
			' /></td>';
		echo "<td>Unable to find required check command 'check_wmip_service'".
			".</td></tr>";
		echo '<tr><td>Suggested solution is to import it from '.
			'<a href="/monitor/op5/nacoma/metadata.php">Check Command Import</a>'.
				'</td></tr></table>';
		$page->html_end();
		return false;
	}

	# wrap all service names between ' to make it possble
	# to use service names with spaces in their names
	$added_services = input_nacoma_Model::services_to_cmd_args($_REQUEST['wmi_services']);

	$host_id = $_REQUEST['master_obj_id'];
	$host_obj = new host_nacoma_Model($host_id);

	if (!is_object($host_obj)) {
		echo '<table><tr><td rowspan=2><img src="images/icons/shield-critical.png"'.
			' /></td>';
		echo "<td>ERROR: Unable to find the host object (#".$host_id.").</td></tr>";
		echo '<tr><td>Unable to continue.</td></tr></table>';

		$page->html_end();
		return false;
	}

	$service_obj = new service_nacoma_Model();
	$service_obj->get_default_object();
	$service_obj->set_master_object_id($host_id);
	$service_obj->obj['check_command'] = $cmd['id'];
	$count = 0;
	foreach ($_REQUEST['wmi_services'] as $service) {
		$service_obj->id = 'new';
		$service_obj->obj['id'] = 'new';
		$service_obj->obj['service_description'] = 'Service ' . $service;
		$service_obj->obj['check_command_args'] = $_REQUEST['wmi_username'].'!'.$_REQUEST['wmi_password'].'!'.$service;
		if ($service_obj->save_object() === false) {
			echo "<p>Error saving object $service</p>\n";
			$page->print_validation_error_table($service_obj);
			continue;
		}
		$count++;
	}
	echo '<table><tr><td rowspan=2><img src="images/icons/services.png" /></td>';
	echo '<td>OK, added <a href="edit.php?obj_type=service&amp;host_id='.
		$host_id.'">'.$count.' new services</a> for <a href="edit.php?obj_type=host&amp;host_id='.$host_id.'"><strong>'.
		get_object_name_by_id('host', $host_id).'</strong></a></td></tr>';
	echo '<tr><td></td></tr></table>';
	echo top_bar_nacoma_Model::add_unsaved_warning();

	$page->html_end();
	return true;
}

/**
* 	Check for started services on a windows host through NSClient++
* 	and a vbs script on the client
* 	Will return list of services as an array
*
* 	Assumes installed windows agent with CheckExternalScripts.dll module
* 	active.
*/
function check_windows_services($obj_id=false) {
	global $DEBUG, $page;
	$services = false;
	$host_address = false;

	# get object info to fetch host address
	$host_obj = new host_nacoma_Model($obj_id);

	if (is_object($host_obj)) {
		$host = $host_obj->get_object();
		if (!empty($host)) {
			$host_address = isset($host['address']) ? $host['address'] : false;
		}
	}

	$page->html_start('');

	if (empty($host_address)) {
		echo '<table><tr><td><img src="images/icons/shield-critical.png" /></td>';
		echo "<td>ERROR: Unable to find any host address to scan</td></tr></table>";
		$page->html_end();
		return false;
	}

	$services = nrpe_win_service_scan($host_address);
	if ($services !== false) {
		echo '</div>'; #pad
		$page->form_start('win_service_form'); # if you change this - edit common.js

		print_initial_service();

		echo '<div class="object-table">';
		echo '<table class="max zebra ObjTable">'.
			'<tr><td class="CmdBar" colspan="3">Select services:</td></tr><tr><td></td><td></td><td>';
		$page->hidden_var('obj_type', 'service');
		$page->hidden_var('action', 'win_service_complete');
		$page->hidden_var('master_obj_id', $obj_id);
		$page->make_multi_selection($services, false, 'win_services');
		echo '</td></tr><tr><td class="HelpList"><a href="#" title="Click to view help on" tabindex=\'-1\' data-help="helpContent" style="border: 0px"><img src="images/icons/shield-help.png" alt="Help" title="Help" /></a></td><td class="VarList">';
		echo 'service description</td><td><input type="text"'.
			' name="service_description" id="service_description">';
		echo "</td></tr></table>";
		echo '</div>';
		echo '<div id="error_msg"></div>';
		echo '<div class="magic max>';
		$page->submit_button('Add Selected Services', 'x');
		echo '</div>';
		$page->form_end();
	}

	$page->html_end();
}

/**
*	Create new service with data from check_windows_services()
*/
function win_service_complete()
{
	global $page;

	$cmd_obj = new command_nacoma_Model();

	# get check ID for the custom nrpe check command
	$cmd = $cmd_obj->get_object_by_name('check_nrpe_win_services');

	$page->html_start('');
	if (empty($cmd)) {
		# missing check command?
		echo '<table><tr><td rowspan=2><img src="images/icons/shield-critical.png"'.
			' /></td>';
		echo "<td>Unable to find required check command 'check_nrpe_win_services'".
			".</td></tr>";
		echo '<tr><td>Suggested solution is to import it from '.
			'<a href="/monitor/op5/nacoma/metadata.php">Check Command Import</a>'.
				'</td></tr></table>';
		$page->html_end();
		return false;
	}

	# wrap all service names between ' to make it possble
	# to use service names with spaces in their names
	$added_services = input_nacoma_Model::services_to_cmd_args($_REQUEST['win_services']);

	$host_id = $_REQUEST['master_obj_id'];

	$host_obj = new host_nacoma_Model($host_id);

	if (!is_object($host_obj)) {
		echo '<table><tr><td rowspan=2><img src="images/icons/shield-critical.png"'.
			' /></td>';
		echo "<td>ERROR: Unable to find the host object (#".$host_id.").</td></tr>";
		echo '<tr><td>Unable to continue.</td></tr></table>';

		$page->html_end();
		return false;
	}

	if(!empty($_REQUEST['service'])) {
		$master_service = $_REQUEST['service']['new'];
	} else {
		echo "<br />No Initial Service Settings chosen, so I can't set up proper services.";

		$page->html_end();
		return false;
	}

	$service = new service_nacoma_Model();
	$service->get_default_object();
	$service->set_master_object_id($host_id);
	$service->normalize($master_service);
	$service->obj['service_description'] = $_REQUEST['service_description'];
	$service->obj['check_command'] = $cmd['id'];
	$service->obj['check_command_args'] = $added_services;

	if ($service->save_object() === false) {
		echo "<p>Errors encountered while saving services.</p>\n";
		$page->print_validation_error_table($service);
		echo '<form><input type="button" value="Go back and try again" '.
			'onclick="javascript:self.location.href=\'host_wizard.php?action='.
				'check_windows_services&obj_id='.$host_id.'\';" /></form>';
	} else {
		echo '<table><tr><td rowspan=2><img src="images/icons/services.png" /></td>';
		echo '<td>OK, added new <a href="edit.php?obj_type=service&amp;host_id='.
			$host_id.'&amp;obj_id='.$service->id.'">service for <strong>'.
			get_object_name_by_id('host', $host_id).'</strong></a></td></tr>';
		echo '<tr><td></td></tr></table>';
		echo '<form><input type="button" value="Scan for more services"'.
			' onclick="javascript:self.location.href=\'host_wizard.php?action='.
			'check_windows_services&obj_id='.$host_id.'\';" /></form>';
		echo top_bar_nacoma_Model::add_unsaved_warning();
	}

	$page->html_end();
	return true;
}

function check_logserver_filters($obj_id)
{
	global $DEBUG, $page;

	$page->html_start('');
	?>
	<script type="text/javascript">
	<!--
	function onSelectedFilterUpdated(selectFilter) {
		var name = selectFilter.ownerDocument.getElementById('service_description');
		name.value = selectFilter.value;
	}
	var ol = window.onload;
	window.onload = function() {
		if (ol)
			ol();
		var filters = this.document.getElementsByName('logserver_filters');
		if (filters.length > 0)
			onSelectedFilterUpdated(filters[0]);
	};
	-->
	</script>
	<?php
	$plugins_path = '/opt/plugins/';
	$check = 'check_ls_log -a -H %s -l %s -p %s -r %s';

	# get object info to fetch host address
	$host_obj = new host_nacoma_Model($obj_id);

	echo "<h2>Logserver filter scan</h2>\n";

	if (is_object($host_obj)) {
		$host = $host_obj->get_object();
		if (!empty($host)) {
			$host_address = isset($host['address']) ? $host['address'] : false;
		}
	}

	if (empty($host_address)) {
		echo '<table><tr><td><img src="images/icons/shield-critical.png" /></td>';
		echo "<td>ERROR: Unable to find any host address to scan</td></tr></table>";
		$page->html_end();
		return false;
	}

	if(empty($_REQUEST['logserver_login']) ||
	   empty($_REQUEST['logserver_password']) ||
	   empty($_REQUEST['logserver_address']))
	{
		$page->form_start('logserver_form');
		$page->hidden_var('obj_type', 'service');
		$page->hidden_var('obj_id', $obj_id);

		# pretty-print the data input table
		echo '<div class="object-table"><table class="max ObjTable zebra">';
		echo "<tr class=\"header\"><td colspan=\"2\">Logserver 3.x user information</td></tr>\n";
		echo "<tr>\n";
		echo "<td class=\"VarList\">Username</td>\n";
		echo "<td>\n";
		echo "    <input type=\"text\" name=\"logserver_login\" size=\"20\" />\n";
		echo "</td></tr>\n";
		echo "<tr>\n";
		echo "<td class\"VarList\">Password</td>\n";
		echo "<td>\n";
		echo "    <input type=\"password\" name=\"logserver_password\" size=\"20\" />\n";
		echo "</td></tr>\n";
		echo "<tr>\n";
		echo "<td class\"VarList\">Logserver address</td>\n";
		echo "<td>\n";
		echo "    <input type=\"text\" name=\"logserver_address\" size=\"20\" />\n";
		echo "</td></tr>\n";

		echo "</table><br />\n";

		$page->hidden_var('action', 'check_logserver_filters');
		$page->submit_button('Retrieve filters', 'x');
		$page->form_end();
		echo "</div>\n";
		$page->html_end();
	}

	echo "</div>\n";


	$filters = fetch_logserver_filters($plugins_path.sprintf($check, $host_address, $_REQUEST['logserver_login'], $_REQUEST['logserver_password'], $_REQUEST['logserver_address']));

	if ($filters[0] === false) {
		echo '<table><tr><td><img src="images/icons/shield-critical.png" /></td>';
		echo '<td>ERROR: Could not retrieve a list of filters:<br />'.
			$filters[1].'</td></tr></table>';
		$page->html_end();
		return false;
	}

	$page->form_start('logservice_filter_form'); # edit common.js

	$page->hidden_var('obj_type', 'service');
	$page->hidden_var('action', 'logserver_filter_complete');
	$page->hidden_var('master_obj_id', $obj_id);
	$page->hidden_var('login', $_REQUEST['logserver_login']);
	$page->hidden_var('password', $_REQUEST['logserver_password']);
	$page->hidden_var('address', $_REQUEST['logserver_address']);

	print_initial_service();
	echo '<div class="object-table">';
	echo '<table class="max zebra ObjTable">';
	echo '<tr class="header"><td colspan="2">Service details</td></tr>';
	echo '<tr><td class=\"VarList\">Select filter</td><td>';
	$page->make_drop_down($filters[1], false, 'logserver_filters', 'xlarge', 'onSelectedFilterUpdated(this)');
	echo '</td></tr>';
	echo '<tr><td class=\"VarList\">Earliest event to retrieve</td>';
	echo '<td><input type="text" name="history_length" id="history_length" /> minutes ago';
	echo '</td></tr><tr><td class\"VarList\">';
	echo 'Service Description</td><td><input type="text"'.
		' name="service_description" id="service_description" onfocus="this.select()" /></td></tr>';
	echo "</td></tr></table>";
	echo '</div>';
	echo '<div id="error_msg"></div><br />';
	$page->submit_button('Add Selected Filter', 'x');
	$page->form_end();
	$page->html_end();
	return true;
}

function fetch_logserver_filters($cmd)
{
	global $DEBUG;

	if ($DEBUG) echo "Running command '".$cmd."'\n";
	if (empty($cmd))
	{
		return array(false, null);
	}

	exec($cmd, $output, $retval);

	if ($retval != 0) {
		$fixed_output = trim(substr($output[0], strpos($output[0], '-') + 1));
		return array(false, $fixed_output);
	}

	$output = implode('', $output);
	$start = strpos($output, "'") + 1;
	$stop = strrpos($output, "'");
	$filters = substr($output, $start, $stop - $start);

	return array(true, explode("', '", $filters));
}

function logserver_filter_complete()
{
	global $page;

	$cmd_obj = new command_nacoma_Model();

	# get check ID for the custom nrpe check command
	$cmd = $cmd_obj->get_object_by_name('check_ls_log');

	$page->html_start('');
	if (empty($cmd)) {
		# missing check command?
		echo '<table><tr><td rowspan=2><img src="images/icons/shield-critical.png"'.
			' /></td>';
		echo "<td>Unable to find required check command 'check_ls_log'".
			".</td></tr>";
		echo '<tr><td>Suggested solution is to import it from '.
			'<a href="/monitor/op5/nacoma/metadata.php">Check Command Import</a>'.
				'</td></tr></table>';
		$page->html_end();
		return false;
	}

	$host_id = $_REQUEST['master_obj_id'];

	$host_obj = new host_nacoma_Model($host_id);

	if (!is_object($host_obj)) {
		echo '<table><tr><td rowspan=2><img src="images/icons/shield-critical.png"'.
			' /></td>';
		echo "<td>ERROR: Unable to find the host object (#".$host_id.").</td></tr>";
		echo '<tr><td>Unable to continue.</td></tr></table>';

		$page->html_end();
		return false;
	}

	$added_service_args = "'".$_REQUEST['logserver_filters']."'!".$_REQUEST['history_length']."!'".$_REQUEST['login']."'!'".$_REQUEST['password']."'!'".$_REQUEST['address']."'";

	if(!empty($_REQUEST['service'])) {
		$master_service = $_REQUEST['service'];
	} else {
		echo "<br />No Initial Service Settings chosen, so I can't set up proper services.";

		$page->html_end();
		return false;
	}

	$service = new service_nacoma_Model();
	$service->get_default_object();
	$service->set_master_object_id($host_id);
	$service->normalize($master_service);
	$service->obj['service_description'] = $_REQUEST['service_description'];
	$service->obj['check_command'] = $cmd['id'];
	$service->obj['check_command_args'] = $added_service_args;

	if ($service->save_object() === false) {
		echo "<p>Errors encountered while saving services.</p>\n";
		$page->print_validation_error_table($service);
		echo '<form><input type="button" value="Go back and try again" '.
			'onclick="javascript:self.location.href=\'host_wizard.php?action='.
				'check_logserver_filters&obj_id='.$host_id.'\';" /></form>';
	} else {
		echo '<table><tr><td rowspan=2><img src="images/icons/services.png" /></td>';
		echo '<td>OK, added new <a href="edit.php?obj_type=service&amp;host_id='.
			$host_id.'&amp;obj_id='.$service->id.'">service for <strong>'.
			get_object_name_by_id('host', $host_id).'</strong></a></td></tr>';
		echo '<tr><td></td></tr></table>';
		echo '<form><input type="button" value="Scan for more services"'.
			' onclick="javascript:self.location.href=\'host_wizard.php?action='.
			'check_logserver_filters&obj_id='.$host_id.'\';" /></form>';
		echo top_bar_nacoma_Model::add_unsaved_warning();
	}

	$page->html_end();
	return true;
}

# This function is only used from "new_host_service_scan" so far.
# Given a host-address and a partition letter, it tries to find out
# if that partition exists or not.
function win_host_has_partition($address, $partition)
{
	$options = array('address' => $address, 'partition' => $partition);
	$ret = probe("WIN_HOST_HAS_PARTITION", $options);

	# check if a partition exists or not.
	if ($ret['value'] === 0)
	  return true;

	return false;
}

# scan new (or existing) hosts for services
function new_host_service_scan() {
	global $page;
	$cmd = get_chkcommands();

	$hosts_in_db = false;

	$master_host = false;
	$Monitor = array();

	$page->html_start('');

	# this makes this function SO much more generic.
	if(func_num_args() === 1) {
		$obj_list = func_get_arg(0);
		$hosts_in_db = true;
	}
	else {
		$obj_list = $_REQUEST['new_host'];
	}

	echo "</div><h2>Network Probe: Service Scan</h2>\n";
	echo "<p><strong>Note:</strong> All new services will inherit the Initial Service Settings.<br />\n";
	echo "If you choose not to enter a value for one or more required variable, those variables must be set in the selected template.\n";
	echo "Checkbox options must be selected, because NOT selecting anything is also considered a value.</p>";

	# check for illegal input in case hosts aren't already added
	# (hoisted out of the other loops for optimization)
	if(!$hosts_in_db) {
		foreach($obj_list as $obj_key => $obj) {
			# check if we're supposed to add this host
			if($obj['Add this host?'] !== '1') {
				unset($obj_list[$obj_key]);
				continue;
			}

			# check if host exists already
			if(host_nacoma_Model::get_id_for($obj['host_name'])) {
				echo '</div><div class="magic max">';
				echo "A host named $obj[host_name] already exists.<br />\n";

				unset($obj_list[$obj_key]);
				continue;
			}

			$host_obj = host_nacoma_Model::host_data_to_object($obj);
			if(!$host_obj->validate_object()) {
				echo "Failed to validate host object $obj[host_name]<br />\n";
				echo "The host will not be added<br />\n";
				$page->print_validation_error_table($host_obj);
				if(isset($obj_list[$obj_key])) unset($obj_list[$obj_key]);
				continue;
			}
			// skip not selected and non-complete hosts
			if(!$hosts_in_db && empty($obj['Add this host?'])) {
				echo "Skipping not selected host <strong>$obj[host_name]</strong>.<br />\n";
				continue;
			}

			foreach (array('host_name', 'alias', 'address') as $k)
				$obj_list[$obj_key][$k] = isset($host_obj->obj[$k])?$host_obj->obj[$k]:$host_obj->obj['host_name'];
		}
	}

	# check to see if there are any objects left to configure.
	# It's ok if we just deleted the master template, because we've
	# already copied it to the $master variable
	if(!count($obj_list)) {
		echo "<strong>No objects left to configure.</strong>";
		$page->html_end();
	}

	echo "<br />";

	?>

	<div id="progress_div"></div>
	<script type="text/javascript" language="JavaScript">
	<!--
	show_progress('progress_div', 'Searching....please wait...');
	//-->
	</script>

	<?php

	echo "<form method='post' action='$_SERVER[PHP_SELF]' id='service_add_form'>\n";

	print_initial_service();

	echo "<p><strong>Check a box to add a servicecheck with default values.</strong> " .
	  "Some services require you to alter a few parameters (notably those " .
	  "requiring authentication of some form). <strong>If you don't check any " .
	  "boxes, no services will be added.</strong></p>\n";

	// create the rather grand looking table of services
	$props = get_object_properties('new_host');
	$v_index = $props['var_index'];
	foreach($obj_list as $obj_key => $obj) {
		$cmd_unset = array();
		// make sure network scan is always performed for hosts in db.
		if($hosts_in_db) {
			$Monitor['net'] = true;
		}

		// do the actual scanning
		$client = array();
		if(isset($obj['Autodetect Services']['net']) || isset($Monitor['net'])) {
			$scan = nmap_scan($obj['address']);
			if($scan === false) {
				nmap_error();
			}

			# first find out which agenst are installed, and try to
			# be clever about which ones we should cater to
			$client[$obj['host_name']] = detect_agents($scan, $obj);

			foreach($scan['net'] as $p => $name) {
				/* fix 'uncertain' service detection stuff */
				$scan['net'][$p] = str_replace('?', '', $name);
			}

			foreach($scan['net'] as $p => $name) {
				if(!isset($cmd["net_" . $name]['service_description'])) {
					continue;
				}
				$service[$obj['host_name']]['net']['net_' . $name] =
				  $cmd['net_' . $name]['service_description'];
			}
		}

		// pass host info object data to next round
		foreach($obj as $k => $v) {
			if(empty($v) && $v !== '0') continue;
			if($k === 'Service Checks' || $k === 'Autodetect Services') {
				$Monitor = array_merge($Monitor, $v);
			}
			elseif(isset($v_index[$k])) {
				if(!is_array($v)) {
					echo "<input type='hidden' name='host[$obj_key][$k]' value='$v' />\n";
				}
				else foreach($v as $v_k => $v_v) {
					echo "<input type='hidden' name='host[$obj_key][$k][$v_k]' value='$v_v' />\n";
				}
			}
		}


		// set up client monitoring.
		foreach($Monitor as $agnt => $discard) {
			if($agnt !== 'net' && $agnt !== 'snmp')
			  $client[$obj['host_name']][] = $agnt;
		}

		/* set up agent based services.
		 * Everything starting with the clients 'basic' name in the
		 * chkcommands will be added as a selectable default service */
		if(!empty($client[$obj['host_name']]) &&
		   is_array($client[$obj['host_name']]))
		{
			$client[$obj['host_name']] = array_unique($client[$obj['host_name']]);
			foreach($cmd as $name => $cmd_obj)
				foreach($client[$obj['host_name']] as $c) {
					if(!strncmp($name, $c, strlen($c)))
					  $service[$obj['host_name']][$c][$name] = $cmd[$name]['service_description'];
				}
		}

		if(array_key_exists($obj['host_name'], $client))  {
			foreach ($client[$obj['host_name']] as $c) {
				switch ($c){
				 case 'nsclient':
					$retval = 0;
					$scan_result = false;
					$output = array();
					$part_list = array();
					$ret = probe("WIN_PARTITION_SCAN", $obj['address']);

					$lines = explode("\n", $ret['buf']);
					if (strpos($lines[0], "|") !== false)
						$scan_result = $lines[0];

					$last = false;
					if ($scan_result != false) {
						$diskinfo = explode("|", $scan_result);
						$raw = array_pop(explode(' ', $diskinfo[0], 2));
						$partition = explode(',', $raw);
						foreach ($partition as $part) {
							$part = trim($part);
							$foo = explode(' ', $part, 2);
							$part = $foo[0]{0};
							# Don't list C partition, it is handled by the original service-adding code
							if (!strcasecmp($part, 'c'))
							  continue;

							$last = $part_list[] = $part;
						}
					}

					# if there is an extraordinary amount of partitions on the system,
					# we're scanning, we might have to try finding the other ones by
					# querying them one-by-one
					$set = false;

					if (!isset($diskinfo[1]) || empty($diskinfo[1])) {
						$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
						if ($scan_result == false)
							$set = strpos($alphabet, 'C') + 1;
						elseif ($last)
							$set = strpos($alphabet, $last) === false ? false : strpos($alphabet, $last) + 1;
					}

					if ($set) for ($i = $set; $i < strlen($alphabet); $i++) {
						if (win_host_has_partition($obj['address'], $alphabet{$i})) {
							$part_list[] = $alphabet{$i};
						}
					}

					foreach ($part_list as $partition) {
						$service[$obj['host_name']]['nsclient']["nsclient_disk_".$partition] = "Disk usage $partition:";
						$cmd_unset[] = "nsclient_disk_$partition";
						$cmd["nsclient_disk_".$partition]["service_description"] = "Disk usage $partition:";
						$cmd["nsclient_disk_".$partition]["check_command"] = "check_nt_disk";
						$cmd["nsclient_disk_".$partition]["check_command_args"] = $partition . "!85%!95%";
					}
					break;

				 case 'wmi':
					$ret = probe('WMI_PARTITION_SCAN', array('address' => $obj['address'], 'username' => isset($obj['wmi_username'])?$obj['wmi_username']:'', 'password' => isset($obj['wmi_password'])?$obj['wmi_password']:''));
					if (!$ret['value']) {
						$ret = $ret['buf'];
						$ret = trim($ret);
						$partitions = explode("\n", $ret);
						array_shift($partitions);
						array_shift($partitions);

						foreach ($partitions as $partition) {
							$part_cleaned = substr($partition, 0, -1);
							$service[$obj['host_name']]['wmi']['wmi_disk_'.$part_cleaned] = "Disk Usage $partition";
							$cmd_unset[] = "wmi_disk_$part_cleaned";
							$cmd["wmi_disk_".$part_cleaned]["service_description"] = "Disk Usage $partition";
							$cmd["wmi_disk_".$part_cleaned]["check_command"] = "check_wmip_disk!$partition!85!95";
						}
					}

					$ret = probe('WMI_NETIF_SCAN', array('address' => $obj['address'], 'username' => $obj['wmi_username'], 'password' => $obj['wmi_password']));
					if (!$ret['value']) {
						$ret = $ret['buf'];
						$ret = trim($ret);
						$netifs = explode("\n", $ret);
						array_shift($netifs);
						array_shift($netifs);

						foreach ($netifs as $if) {
							if (strpos($if, 'Loopback') !== false)
								continue;
							$service[$obj['host_name']]['wmi']['wmi_netif_'.$if] = "Network Usage $if";
							$cmd_unset[] = "wmi_netif_$if";
							$cmd["wmi_netif_".$if]["service_description"] = "Network Usage $if";
							$cmd["wmi_netif_".$if]["check_command"] = "check_wmip_network!$if!85!95";
						}
					}

					$svcs = wmi_service_scan($obj['address'], $obj['wmi_username'], $obj['wmi_password']);
					if ($svcs === false) {
						echo '<div>WMI service scan failed.</div>';
						return;
					}
					foreach ($svcs as $svc) {
						$service[$obj['host_name']]['wmi']['wmi_service_'.$svc] = "Service $svc";
						$cmd_unset[] = "wmi_service_$svc";
						$cmd["wmi_service_".$svc]["service_description"] = "Service $svc";
						$cmd["wmi_service_".$svc]["check_command"] = "check_wmip_service!$svc!";
					}

					// let's assume scan failures come in groups, after, say, auth errors
					if ($ret['value'] === 1) {
						echo '<table><tr><td><img src="/monitor/op5/nacoma/images/icons/shield-critical_16x16.png"/></td><td><strong>There was an error scanning '.$obj['address'].' for WMI checks:</strong> ' . $ret['buf'] . '</td></tr></table>';
					}
					break;
				}

				foreach ($service[$obj['host_name']] as $check_type => $checklist) {
					foreach($checklist as $check_name => $_) {
						if (strpos($check_name, 'wmi') === 0) {
							$command = $obj['wmi_username'].'!'.$obj['wmi_password'].'!'.$cmd[$check_name]['check_command_args'];
							$page->hidden_var('check_def['.$obj_key.']['.$check_name.']', $command);
						} else {
							$command = $cmd[$check_name]['check_command'];
							$page->hidden_var('check_def['.$obj_key.']['.$check_name.'][check_command]', $cmd[$check_name]['check_command']);
							$page->hidden_var('check_def['.$obj_key.']['.$check_name.'][check_command_args]', $cmd[$check_name]['check_command_args']);
						}
					}
				}
			}
		}
		# All done finding windows partitions

		if ($hosts_in_db && !empty($service[$obj['host_name']])) {
			uniqueify_service_list($obj_key, $service[$obj['host_name']], $cmd);
		}

		$alias = array_key_exists('alias', $obj) ? "(".$obj['alias'].")" : "";
		echo "<div class=\"max\" style=\"margin-bottom: 1em; clear: both; float: left;\"><table class='max zebra ObjTable'>" .
		  "<tr><td class='CmdBar' colspan=\"2\">" . ($hosts_in_db ? '<a href="edit.php?obj_type=host&obj_id='.$obj['id'].'">' : '') . "$obj[host_name] @ $obj[address] " .
		  $alias.($hosts_in_db ? '</a>' : '') . "</td></tr>\n";

		// pretty-print the list of checkboxes
		if(empty($service[$obj['host_name']])) {
			if (isset($service[$obj['host_name']]))
				unset($service[$obj['host_name']]);

			echo "<tr><td colspan=\"2\">No additional services found for this host</td></tr>\n";
		}
		else {
			make_checklist_checkboxes($service[$obj['host_name']],
									  "check[$obj_key]");
		}

		echo "</table>\n";

		# Make sure additional partitions that got added
		# this time around doesn't carry over to next host
		foreach ($cmd_unset as $cus) {
			unset($service[$obj['host_name']]['nsclient'][$cus]);
			unset($cmd[$cus]);
		}
	}

	// pass this info to next round
	if($hosts_in_db) {
		echo "<input type='hidden' name='hosts_in_db' value='$hosts_in_db' />\n";
	}

	echo "<div class=\"magic max\">\n" .
	  "<input type='hidden' name='action' value='new_host_service_scan' />" .
	  "<input id='finish_submit' type='submit' name='x' value=\"Finish\" title='Finish' class='button continue-to-step-3' />\n" .
	  "</div></div></form>\n";
	?>

	<script type="text/javascript" language="JavaScript">
	<!--
	document.getElementById('progress_div').innerHTML = '';

	$('#service_add_form').submit(function () {
		setDisabled('finish_submit', 'processing');
		loopElements(this);
		return true;
	});
	//-->
	</script>

	<?php
	$page->html_end();
}

// last outpost. If we get past this, we're happy.
function add_hosts_and_services() {
	global $page;
	global $DEBUG, $SUPPORT;

	$cmd = get_chkcommands();

	$page->html_start('');

	# Need to add dynamic partition checks to static $cmd
	if(isset($_REQUEST['check'])) {
		foreach($_REQUEST['check'] as $id => $checkArr  ){
			foreach($checkArr as $check => $val  ){
				if (preg_match("/^wmi_disk_/", $check)) {
					$partition = preg_replace("/.*_.*_/", "", $check);
					$cmd[$check]["service_description"] = "Disk Usage $partition:";
					$cmd[$check]["check_command_args"] = $_REQUEST['check_def'][$id][$check];
				} else if (preg_match("/^wmi_netif/", $check)) {
					$netif = preg_replace("/[^_]*_[^_]*_/", "", $check);
					$cmd[$check]['service_description'] = 'Network Usage '.$netif;
					$cmd[$check]["check_command_args"] = $_REQUEST['check_def'][$id][$check];
				} else if (preg_match("/^wmi_service_/", $check)) {
					$service = preg_replace("/.*_.*_/", "", $check);
					$service = preg_replace('/\+|\(|\)/', '', $service);
					$cmd[$check]["service_description"] = "Service $service";
					$cmd[$check]["check_command"] = $_REQUEST['check_def'][$id][$check];
				} else if (preg_match('/^wmi/', $check)) {
					$cmd[$check]["check_command_args"] = $_REQUEST['check_def'][$id][$check];
				} else if (preg_match("/^nsclient_disk_/", $check)) {
					$partition = preg_replace("/.*_.*_/","",$check);
					$cmd[$check]["service_description"] = "Disk usage $partition:";
					$cmd[$check]["check_command"] = $_REQUEST['check_def'][$id][$check]['check_command'];
					$cmd[$check]["check_command_args"] = $_REQUEST['check_def'][$id][$check]['check_command_args'];
				}
			}
		}
	}

	$hosts_in_db = false;
	if(isset($_REQUEST['hosts_in_db'])) $hosts_in_db = true;
	if($DEBUG) echo "hosts_in_db = ".var_export($hosts_in_db, true)."<br />\n";

	$host_obj_list = $check_list = $hostext_obj_list = $master_service = false;

	if(!empty($_REQUEST['host'])) {
		foreach ($_REQUEST['host'] as $id => $host_data) {
			if ($id <= 0) {
				// new host, no real id yet
				$host = host_nacoma_Model::host_data_to_object($host_data);
			} else {
				$host = new host_nacoma_Model($id);
			}

			if($host) {
				$host_obj_list[$id] = $host;
			}
		}
	}
	else {
		bug("No host objects chosen, so I can't relate services to anything.", true);
	}
	if(!empty($_REQUEST['service'])) {
		$master_service = $_REQUEST['service']['new'];
	}
	else {
		bug("No Initial Service Settings chosen, so I can't set up proper services.", true);
	}
	if(!empty($_REQUEST['check'])) {
		$check_list = $_REQUEST['check'];
	}

	// loop it and build up the services
	$service_obj_list = false;
	$request_reloaded = false;
	foreach($host_obj_list as $host_idx => $host_obj) {
		if($host_obj->validate_object() !== true)
		{
			echo "New host object {$host_obj->get_object_name()} didn't pass validation process, so not adding:<br />\n";
			echo $page->print_validation_error_table($host_obj);
			continue;
		}

		if($host_obj->save_object() !== true) {
			echo "New host object {$host_obj->get_object_name()} didn't pass validation process, so not adding:<br />\n";
			echo $page->print_validation_error_table($host_obj);
			continue;
		}
		$host_obj_key = $host_obj->id;

		// not all hosts have services associated with them
		if(empty($check_list[$host_idx]))
			continue;
		// loop the check list
		$chk_list = $check_list[$host_idx];

		foreach($chk_list as $chk_name => $status) {
			if(empty($cmd[$chk_name]))
				continue;

			// set up the service object
			$service_obj = new service_nacoma_Model();
			$service_obj->get_default_object();

			$service_obj->normalize($master_service);
			try {
				$service_obj->normalize($cmd[$chk_name]);
			} catch (NacomaRelationException $ex) {
				# this code is copy-pasted from metadata.php. copy-paste makes god kill kittens. why do we hate the kittens?
				$metadata_commands = array();
				$metadata_files = glob("/opt/plugins/metadata/*.metadata");
				foreach($metadata_files as $meta_data_file){
					$meta_array = my_parse_ini_file($meta_data_file);
					if(isset($meta_array['commands'])) {
						$metadata_commands = array_merge($meta_array['commands'], $metadata_commands);
					}
				}
				if (isset($metadata_commands[$service_obj->obj['check_command']]))
					echo "Unable to find required check command \"<b>{$service_obj->obj['check_command']}</b>\". Suggested solution is to import it from <a href=\"/monitor/op5/nacoma/metadata.php\">Check Command Import</a><br />\n";
				else
					echo "The check-command \"<b>{$service_obj->obj['check_command']}</b>\" is required to add one or more services you selected, but it does not exist in this configuration. Please contact $SUPPORT.<br />\n";
				continue;
			}

			$service_obj->obj['host_name'] = $host_obj_key;

			// validate the object or bail out.
			if(!$service_obj->validate_object()) {
				echo "New service object {$service_obj->get_object_name()} didn't pass validation process, so not adding:<br />\n";
				echo $page->print_validation_error_table($service_obj);
				continue;
			}
			$service_obj_list[] = $service_obj;
		}
	}

	// print some statistics and quick-links to services and host-objects
	$host_count = $service_count = false;
	if(!$hosts_in_db)
		$host_count = count($host_obj_list);

	if(!empty($service_obj_list)) {
		$service_count = count($service_obj_list);
		foreach ($service_obj_list as $obj) {
			$obj->save_object();
		}
	}

	// die here if we didn't add anything
	if($request_reloaded && !$host_count && !$service_count) {
		echo "<br /><p>Added a total of 0 objects. Form data posted twice?</p>\n";
		$page->html_end();
	}

	if(!$hosts_in_db) {
		echo "<h2>Done adding new host</h2>\n" .
		  "<p><strong>\n" .
		  "Added $host_count host.<br />\n";
	}
	else {
		echo "<h2>Done adding services</h2>\n" .
		  "<strong>\n";
	}
	if(empty($service_count)) $service_count = 0;
	echo "Added $service_count services.</strong><br />\n";

	echo "<table style=\"margin-top: 7px\">\n";
	$i = 1;
	foreach($host_obj_list as $obj_key => $obj) {
		echo "<tr><td style=\"padding-right: 30px; font-size: 11px;\">";
		echo "  <img src='images/icons/host.png' alt='' style=\"margin-bottom: -3px;\" /> <a href='edit.php?obj_type=host&obj_id=".$obj->id."'>".$obj->get_object_name()."</a>";
		echo "</td><td style=\"font-size: 11px;\">\n";
		echo "<img src='images/icons/services.png' alt='' style=\"margin-bottom: -3px;\" /> <a href='edit.php?obj_type=service&host_id=".$obj->id."'>Services for <strong>".$obj->get_object_name()."</strong></a></td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	top_bar_nacoma_Model::add_unsaved_warning();

	$page->html_end();
}

/**
 * Render a "Initial service settings" form.
 */
function print_initial_service()
{
	global $page;

	$page->html_start('');

	$init_serv = new service_nacoma_Model();
	$init_serv->prepare_draw();
	$init_serv->obj = array_intersect_key($init_serv->obj, array('template' => true, 'contact_groups' => true));

	echo $page->help_for_object($init_serv);

	$init_serv->topic = 'Initial service settings';

	$page->pretty_print_one($init_serv);
}

if (isset($_REQUEST['poller_to_use']) && $_REQUEST['poller_to_use'] && $_REQUEST['poller_to_use'] != "This server") {
	$poller = $_REQUEST['poller_to_use'];
} else {
	$poller = false;
}

// core logic..
if ($input->action === 'scan' && $input->obj_id) {
	$page->html_start('');
	// someone pressed the 'scan' link from the host config screen
	$host = new host_nacoma_Model($input->obj_id);
	$host_list = array($host->id => $host->obj);

	new_host_service_scan($host_list);

	$page->html_end();
}
elseif(!isset($_REQUEST['action'])) {
	// list the options...
	$page->html_start('');
	echo "<br /><img src=\"images/icons/arrow.gif\" alt=\"\" class=\"show\" /> &nbsp;<a title='Perform a pingsweep to autodetect and add several hosts automagically' href='?action=ping_input' class=\"arrow\">Network scan</a>\n";

	$num_hosts = 1;
	if(!empty($_REQUEST['num_hosts'])) {
		$num_hosts = (int)$_REQUEST['num_hosts'];
	}

	echo '</div>';
	$page->form_start('foo_form', 'post');
	for ($i=0; $i<$num_hosts; $i++) {
		$obj = new new_host_nacoma_Model();
		$obj->get_default_object();
		echo '<div style="clear: both;">';
		echo $page->help_for_object($obj);
		echo '</div>';
		$obj->id = $next_host_id--;
		$obj->prepare_draw();
		echo $page->pretty_print_one($obj);
	}
	echo "<table class=\"max\"><tr><td class=\"HelpList\"></td><td class=\"VarList\">Poll from</td>" .
		"<td><select name=\"poller_to_use\" style=\"width: 208px;\">";
	foreach (get_merlin_nodes() as $node) {
		echo "<option value=\"$node\">$node</option>";
	}
	echo "</select></td></tr></table>\n";
?>
	<input type='hidden' name='action' value='list_services' />
	<input type='submit' name='x' value="Add services" title='Add services' id="scanBtn" class='button scan-host-for-services' onclick="loopElements(this.form);" />
	</div>
<?php
	$page->form_end();
	$page->html_end();
}

elseif($_REQUEST['action'] === 'ping_input') {
	ping_input();
}
elseif($_REQUEST['action'] === 'ping_scan') {
	ping_scan();
}
elseif($_REQUEST['action'] === 'autoscan_complete') {
	autoscan_list();
}
elseif($_REQUEST['action'] === 'autoscan_result') {
	autoscan_results();
}
elseif($_REQUEST['action'] === 'list_services') {
	new_host_service_scan();
}
elseif($_REQUEST['action'] === 'new_host_service_scan') {
	add_hosts_and_services();
}
elseif($_REQUEST['action'] === 'choose_win_check_type') {
	choose_win_check_type($_REQUEST['obj_id']);
}
elseif($_REQUEST['action'] === 'check_windows_services'
	&& !empty($_REQUEST['obj_id'])) {
	check_windows_services($_REQUEST['obj_id']);
}
elseif($_REQUEST['action'] === 'check_wmi_services'
	&& !empty($_REQUEST['obj_id'])) {
	check_wmi_services($_REQUEST['obj_id']);
}
elseif($_REQUEST['action'] === 'wmi_service_complete') {
	wmi_service_complete();
}
elseif($_REQUEST['action'] === 'win_service_complete') {
	win_service_complete();
}
elseif($_REQUEST['action'] === 'check_logserver_filters'
	&& !empty($_REQUEST['obj_id'])) {
	check_logserver_filters($_REQUEST['obj_id']);
}
elseif($_REQUEST['action'] === 'logserver_filter_complete') {
	logserver_filter_complete();
}
elseif($_REQUEST['action'] === 'snmp_scan') {
	snmp_scan();
}
elseif($_REQUEST['action'] === 'snmp_scan_complete') {
	snmp_scan_complete();
}
else {
	$page->html_start('');
	// exception handler fallthrough
	echo "<strong>Exception handler fallthrough (Unknown action; $_REQUEST[action])</strong>\n";
	echo "<pre>\n"; print_r($_REQUEST); echo "</pre>\n";
	$page->html_end();
}

$page->html_end();
