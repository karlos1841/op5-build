<?php

function partition_scan($obj_id)
{
        global $page;
        $page->html_start();
        $host = new host_nacoma_Model($obj_id);
	$username = "api";
	$password = "jioa891oo";
	$run_as_user = "sudo -u monitor";
	$linux_scanner_path = "/opt/plugins/custom/emca/bin/linux-scanner.pl";
	$aix_scanner_path = "/opt/plugins/custom/emca/bin/aix-scanner.pl";
	$windows_disk_scanner_path = "/opt/plugins/custom/emca/bin/windows-disk-scaner.pl";
	$windows_interface_scanner_path = "/opt/plugins/custom/emca/bin/windows-interface-scanner.pl";

	echo "<script type=\"text/javascript\">
		function runScan() {
		}
		function updateBar(width) {
			document.getElementById('scanProgress').style.display = \"block\";
			var elem = document.getElementById(\"scanBar\");
			elem.style.width = width + '%';
			document.getElementById(\"label\").innerHTML = width * 1 + '%';
		}
		function stopScan() {
			document.getElementById('scanProgress').style.display = \"none\";
		}
	</script>";

	echo "<style type=\"text/css\">
		#scanProgress {
			position: relative;
			width: 100px;
			height: 20px;
			background-color: grey;
		}
		#scanBar {
			position: absolute;
			width: 1%;
			height: 100%;
			background-color: red;
		}
		#label {
			text-align: center;
			line-height: 20px;
			color: white;
			font-weight: bold;
		}
	</style>";

	#echo var_dump(ssh2_auth_none($connection, 'monitor'));
	#array_push($hostgroups, get_object_name_by_id('hostgroup', $hostgroup));

	# ALL HOSTGROUPS IN OP5
	$all_hostgroups = preg_split('/\n/', shell_exec("mon query ls hostgroups -c name"), -1, PREG_SPLIT_NO_EMPTY);
	# ALL NODES IN OP5
	$lines = preg_split('/\n/', shell_exec("mon node show"));
	# Empty by default, gets updated in distributed monitoring
	$run_ssh = "";

	# HOSTGROUP SCANNER UI
	echo '<p><h2>Hostgroup scanner</h2></p>';
	echo '<form action="" method="post">';
	echo 'Select hostgroup <select name="hostgroups">';
	foreach($all_hostgroups as $hostgroup)
	{
		echo "<option value=\"$hostgroup\">$hostgroup</option>";
	}
	echo '</select>';
	echo '<p><input type="submit" name="scan_hg" value="Scan hostgroup" title="Scan hostgroup" onclick="runScan()" /></p>';
	echo '</form>';
	echo "<div id=\"scanProgress\" style=\"display:none;\">
		<div id=\"scanBar\"><div id=\"label\">0%</div></div>
	</div>";


	if($lines[0] != "No nodes configured")
	{
		$nodegroups = array();
		foreach(preg_grep("/HOSTGROUP=/", $lines) as $line)
		{
			array_push($nodegroups, str_replace("HOSTGROUP=", "", $line));
		}

		$nodes = array();
		foreach(preg_grep("/NAME=/", $lines) as $line)
		{
			array_push($nodes, str_replace("NAME=", "", $line));
		}

		$hostgroups = array();
		foreach($host->obj['hostgroups'] as $hostgroup)
		{
			array_push($hostgroups, get_object_name_by_id('hostgroup', $hostgroup));
		}


		$sizeof = min(count($nodegroups), count($nodes));
		foreach($hostgroups as $hostgroup)
		{
			for($i = 0; $i < $sizeof; $i++)
			{
				if($hostgroup == $nodegroups[$i])
				{
					$run_ssh = "ssh " . $nodes[$i];
					break;
				}
			}
		}
	}

	$match = shell_exec("{$run_as_user} {$run_ssh} /opt/plugins/check_nrpe -H {$host->obj['address']}");
	if(preg_match('/I \(.*\) seem to be doing fine/', $match))
	{
		#Windows scanner
		echo '<form action="" method="post">';
		echo "<p><h2><input type='hidden' name='windows' />Windows scanner for {$host->obj['host_name']}</h2></p>";
       		echo '<p><input type="checkbox" name="partition" /> Partitions scan</p>';
		echo '<p><input type="checkbox" name="network" /> Network scan</p>';
		echo '<p><input type="submit" name="scan" value="Scan" title="Scan" /></p>';
		echo '</form>';
	}
	elseif(preg_match('/^NRPE .*/', $match))
	{
		$match = shell_exec("{$run_as_user} {$run_ssh} /opt/plugins/check_nrpe -H {$host->obj['address']} -c check_uname");
		if(preg_match('/^Linux/', $match))
		{
			#Linux scanner
			echo '<form action="" method="post">';
			echo "<p><h2><input type='hidden' name='linux' />Linux scanner for {$host->obj['host_name']}</h2></p>";
       			echo '<p><input type="checkbox" name="partition" /> Partitions scan</p>';
			echo '<p><input type="checkbox" name="io" /> IO scan</p>';
			echo '<p><input type="checkbox" name="network" /> Network scan</p>';
			echo '<p><input type="submit" name="scan" value="Scan" title="Scan" /></p>';
			echo '</form>';
		}
		else
		{
			#Unix scanner
			echo '<form action="" method="post">';
			echo "<p><h2><input type='hidden' name='unix' />Unix scanner for {$host->obj['host_name']}</h2></p>";
			echo '<p><input type="checkbox" name="partition" /> Partitions scan</p>';
			echo '<p><input type="checkbox" name="io" /> IO scan</p>';
			echo '<p><input type="checkbox" name="network" /> Network scan</p>';
			echo '<p><input type="submit" name="scan" value="Scan" title="Scan" /></p>';
			echo '</form>';
		}
	}
	else
	{
		echo "Could not get the essential information about the host.\n";
		echo "Make sure the agent is installed\n";
	}

	if(isset($_POST["scan_hg"]))
	{
		$all_hosts = preg_split('/,/', shell_exec("mon query ls hostgroups -c members name -e " . $_POST["hostgroups"]), -1, PREG_SPLIT_NO_EMPTY);
		$num_hosts = count($all_hosts);
		$output = "";
		flush();
		$num = 0;
		foreach($all_hosts as $host)
		{
			$num++;
			$progress = round($num / $num_hosts * 100);
			echo "<script type=text/javascript>updateBar($progress);</script>";
			flush();
			$output = $output . "Performing scan on $host\n";

			if(!empty($run_ssh))
			{
				$hostgroups = preg_split('/,/', shell_exec("mon query ls hosts -c groups name -e " . $host), -1, PREG_SPLIT_NO_EMPTY);
				foreach($hostgroups as $hostgroup)
				{
					for($i = 0; $i < $sizeof; $i++)
					{
						if($hostgroup == $nodegroups[$i])
						{
							$run_ssh = "ssh " . $nodes[$i];
							break;
						}
					}
				}
			}

			$match = shell_exec("{$run_as_user} {$run_ssh} /opt/plugins/check_nrpe -H {$host}");
			if(preg_match('/I \(.*\) seem to be doing fine/', $match))
			{
				$out = shell_exec("{$run_as_user} {$run_ssh} {$windows_disk_scanner_path} -u {$username} -p {$password} -o host -n {$host} --disk;{$windows_interface_scanner_path} -P {$password} -p 1248 -m 5666 -T host -n {$host}");
				$output = $output . $out;
			}
			elseif(preg_match('/^NRPE .*/', $match))
			{
				$match = shell_exec("{$run_as_user} {$run_ssh} /opt/plugins/check_nrpe -H {$host} -c check_uname");
				if(preg_match('/^Linux/', $match))
				{
					$out = shell_exec("{$run_as_user} {$run_ssh} {$linux_scanner_path} -u {$username} -p {$password} -o host -n {$host} --partitions --io --network");
					$output = $output . $out;
				}
				else
				{
					$out = shell_exec("{$run_as_user} {$run_ssh} {$aix_scanner_path} -u {$username} -p {$password} -o host -n {$host} --partitions --io --network");
					$output = $output . $out;
				}
			}
			else
			{
				$output = $output . "Could not get the essential information about {$host}.\n";
				$output = $output . "Make sure the agent is installed\n";
			}
		}
		echo "<script type=text/javascript>stopScan();</script>";
		echo "<p><textarea readonly id='scan_output' rows='20' cols='120'>{$output}</textarea></p>";
	}

	if(isset($_POST["scan"]))
	{
		flush();
		#var_dump($_POST);
		$command = "";
		if(isset($_POST["linux"]))
		{
			$command = "{$linux_scanner_path} -u {$username} -p {$password} -o host -n {$host->obj['host_name']}";
			if(isset($_POST["partition"]))
			{
				$command = $command . " --partitions";
			}
			if(isset($_POST["io"]))
			{
				$command = $command . " --io";
			}
			if(isset($_POST["network"]))
			{
				$command = $command . " --network";
			}
		}

		if(isset($_POST["unix"]))
		{
			$command = "{$aix_scanner_path} -u {$username} -p {$password} -o host -n {$host->obj['host_name']}";
			if(isset($_POST["partition"]))
			{
				$command = $command . " --partitions";
			}
			if(isset($_POST["io"]))
			{
				$command = $command . " --io";
			}
			if(isset($_POST["network"]))
			{
				$command = $command . " --network";
			}
		}

		if(isset($_POST["windows"]))
		{

			if(isset($_POST["partition"]))
			{
				$command = $command . "{$windows_disk_scanner_path} -u {$username} -p {$password} -o host -n {$host->obj['host_name']} --disk;";
			}
			if(isset($_POST["network"]))
			{
				$command = $command . "{$windows_interface_scanner_path} -P {$password} -p 1248 -m 5666 -T host -n {$host->obj['host_name']};";
			}
		}

		$output = shell_exec("{$run_as_user} {$run_ssh} {$command}");
		if(!empty($output))
		{
			echo "<p><textarea readonly id='scan_output' rows='20' cols='120'>{$output}</textarea></p>";
		}
		else
		{
			echo "<p><textarea readonly id='scan_output' rows='20' cols='120'>No output returned</textarea></p>";
		}
	}

	$page->html_end();

}
if($_REQUEST['action'] === 'partition_scan') {
        partition_scan($_REQUEST['obj_id']);
}
?>
