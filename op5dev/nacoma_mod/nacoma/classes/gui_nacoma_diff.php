<?php

require_once(dirname(__FILE__) . '/../include/webconfig.inc.php');
require_once(dirname(__FILE__) . '/../include/timeperiods.php');
require_once(dirname(__FILE__) . '/../include/import.inc.php');
require_once(dirname(__FILE__) . '/../include/probe.inc.php');

/**
 * The gui_nacoma_Model is responsible for drawing all the various elements
 * of the page.
 */
class gui_nacoma_Model
{
	private $form_name = false;
	private $html_started = false;

	/**
	 * Constructor. Responsible for validating that the user is
	 * authorized to use the GUI and to make sure this is no
	 * poller system in a distributed setup.
	 */
	public function __construct()
	{
		if (!validate_user()) {
			# Unauthorized ...

			$msg = "<p>Access denied</p>\n" .
			"<p class=\"note\">It appears as though you aren't authorized to access the configuration interface.</p>
			<p>Note that at least one of your groups must be authorized for <strong>'configuration_information'</strong> to be able to change any configuration.</p>";
			if (PHP_SAPI !== 'cli') {
				echo $msg;
			} else {
				print strip_tags($msg);
			}
			$this->html_end();
		}

		if (is_poller()) {
			$this->html_start();
			echo "<p><strong>This is a poller system!</strong></p>\n";
			echo "It appears this is a poller system in a distributed setup.\n";
			echo "<br /><br />\n";
			echo "No configuration is supposed to be made on a poller system.\n";
			$this->html_end();
		}
	}

	/**
	 * Import configuration from files to database
	 * @param $reason Reason why import was run
	 * @param $user The user running the import
	 */
	function run_import($reason, $user = false)
	{
		global $import_errors, $import_warnings;
		global $SUPPORT;

		session_write_close();
		if (PHP_SAPI == 'cli') {
			echo "Nacoma import in progress. $reason\n";
			$success = import_config($user, false, $error_message);
			if (!$success) {
				echo "Error: $error_message\n";
				foreach ($import_errors as $i_errors) {
					echo "Error: $i_errors\n";
				}
				foreach ($import_warnings as $i_warnings) {
					echo "Warning: $i_warnings\n";
				}
				exit(1);
			} else {
				echo "Configuration has been imported, overwriting database changes.\n";
			}
		} else {
			echo "<div class='note'>";
			echo "<p class='import_in_progess'><strong>Import is in progress, please do not reload the page.<br>
			This might take some time depending on the size of your configuration.</strong></p>";
			if(ob_get_level() > 0) {
				ob_flush();
			}
			flush();
			echo "<p><strong>$reason<br />\n";
			echo "<script>$('.import_in_progess').remove();</script>";
			$success = import_config($user, false, $error_message);
			$errors = $this->print_problem_array('Import errors', $import_errors);

			$this->print_problem_array('Import warnings', $import_warnings);
			if (!$success || $errors) {
				echo "<p>$error_message</p>";
				echo "If you have manually made modifications to the configuration, " .
					"look it over to make sure you haven't done anything wrong with it.<br />\n";
				echo "You can run the command<pre>\n/usr/bin/asmonitor /usr/bin/naemon --precache-objects --verify-config /opt/monitor/etc/naemon.cfg</pre> to " .
					"verify that the configuration you have created is valid. If the " .
					"configuration is valid and you still see this message " .
					"you have most likely found a template that is not currently in " .
					"use that references an object that doesn't exist.<br />" .
					"If that is not the case, then this is a bug.<br />" .
					"Please contact $SUPPORT so that we can help you fix this problem.<br />\n";

				echo "<p>Irrecoverable errors encountered while importing. Bailing out<br />\n";
				$this->html_end();
			}
			echo "Configuration has been imported, overwriting database changes.</strong><br />\n</div>";
		}
	}

	/**
	 * Import the configuration if there are changes in the files
	 * @param $user The user running the import
	 */
	function import_if_changes($user = false)
	{
		$this->html_start();
		if (!get_last_import_time()) {
			return $this->run_import('No imported configuration exists', $user);
		}
		if (!changes_since_last_import())
			return true;

		$this->run_import('Config files have changed since last import', $user);
	}

	/**
	 * Print HTML header
	 * @param $subtitle string: Page subtitle
	 */
	function html_start($subtitle = false)
	{
		global $DEBUG, $Version;

		if($this->html_started)
		  return true;

		$this->html_started = true;

		$user = get_user();
		$date_str = date('d M h:i:s T Y');

		if(PHP_SAPI !== 'cli') {
			if (!headers_sent()) {
				header('Cache-Control: no-cache');
				header('Content-Type: text/html; charset=utf-8');
			}
			require_once( dirname(__FILE__) . '/../templates/html_start.php');
		}
	}

	/**
	 * Print HTML footer
	 */
	function html_end($exit_code=0)
	{
		global $DEBUG;
		if (PHP_SAPI !== 'cli')
			require_once( dirname(__FILE__).'/../templates/html_end.php');
		exit($exit_code);
	}

	/**
	 * Create a form
	 * @param $name Form name
	 * @param $method Form method
	 */
	public function form_start($name = 'foo_form', $method = 'post')
	{
		global $input;

		$this->form_name = $name;

		$https_s = isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on' ? 's' : '';
		// websiterunner.php tries to view a web page through CLI,
		// which means that no headers are sent. The HTTP_HOST index is
		// relying on the Host: header, so we have to use a fallback if
		// it doesn't exist.
		$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		$absolute_uri = 'http'.$https_s.'://'.$host.$_SERVER['PHP_SELF'];

		$attributes = array(
			'name' => $name,
			'id' => $name,
			'onsubmit' => 'return check_required_objects(this)',
			'method' => strtolower($method),
		);
		if ($input->obj_type == 'timeperiod' && $name == 'foobar_form') {
			$attributes['onsubmit'] = 'return check_required_objects(this) && timeperiod_add_custom_vars(this)';
		}
		echo form::open($absolute_uri, $attributes);
	}

	/**
	 * Close a form
	 * @param $return Unused
	 */
	function form_end($return = false)
	{
		global $DEBUG;

		if ($DEBUG && !$this->form_name)
		  echo "Form closed when none started!<br />\n";

		$this->form_name = false;
		echo "\n</form>\n";
	}

	/**
	 * Get messages stored in session, deletes them after retreival
	 *
	 * @return array of strings
	 */
	function get_flashed_messages() {
		if(!session_id() || !isset($_SESSION['nacoma_flash'])) {
			return array();
		}
		$messages = $_SESSION['nacoma_flash'];
		unset($_SESSION['nacoma_flash']);
		return $messages;
	}

	/**
	 * Draw a form submit button
	 *
	 * @param $text The text on the button, or FALSE for default
	 * @param $name The form name of the button, or FALSE for default
	 * @param $skinny If false, the button will take up a comfortable amount of space. If true, it will be narrower
	 * @param $extra An array of HTML attribute key-vals to append/replace the automatic ones
	 */
	function submit_button($text = false, $name = false, $skinny = false, $extra = false)
	{
		if (!$text)
			$text = 'Submit';
		if (!$name)
			$name = 'action';
		$output = array(
			'type' => 'submit',
			'name' => $name,
			'value' => $text,
			'title' => $text,
			'class' => 'object-submit'
		);

		if (is_array($extra))
			$output = array_merge($output, $extra);

		$attributes = '';
		foreach ($output as $key => $val) {
			$attributes .= " $key=\"".htmlspecialchars($val).'"';
		}

		if (!$skinny)
			echo '<div class="magic">';
		echo "<input".$attributes." />\n";
		if (!$skinny)
			echo '</div>';
	}

	/**
	 * Add a hidden form variable
	 * @param $name Name of the variable
	 * @param $value Value of the variable
	 * @param $return Unused
	 */
	function hidden_var($name, $value, $return = false)
	{
		$name = str_replace('"', '&quot;', $name);
		$value = str_replace('"', '&quot;', $value);
		echo "<input type=\"hidden\" name=\"$name\" value=\"$value\" />\n";
	}

	/**
	 * Print the current object on the webpage
	 * @param $obj class_object A class related to a specific object type
	 */
	function draw_object($obj)
	{
		$obj->prepare_draw();
		$this->print_related_object($obj);
		$this->pretty_print_one($obj);
	}

	/**
	 * Create a filtered selection inside a webform
	 * @param $values Array of values in the form
	 * @param $dflt The default selected value
	 * @param $select_name The form name of this variable
	 * @param $required If not true, an empty selectable value will be inserted
	 */
	function make_filtered_selection($values, $dflt = '', $select_name = 'selection', $required = false)
	{
		// scheisse, I don't want to re-architect everything
		ob_start();
		Select_Nacomawidget::make_selection($values, $dflt, $select_name, 'xlarge', $required);
		$out = ob_get_clean();
		return $out;
	}

	/**
	 * Generic function to create selection lists from any type
	 * of object that also has a group-type (hosts, services and
	 * contacts).
	 * The input_nacoma_Model understands the format we create here and
	 * can parse them correctly, which aids the various programs
	 * rather nicely.
	 * @param $obj_type Object type
	 * @param $exclude Objects to exclude from listing
	 * @param $target_type Ultimate target type
	 */
	function make_multi_obj_selection($obj_type, $exclude = array(), $target_type = false)
	{
		# allow single-item exclusion (the most common kind)
		# but special-case 'new' for when we're for example
		# propagating variables from an object that doesn't exist
		if (!empty($exclude) && !is_array($exclude)) {
			if ($exclude === 'new')
				$exclude = false;
			else
				$exclude = array($exclude);
		}

		if (!$target_type)
			$target_type = $obj_type;

		switch ($obj_type) {
		 case 'host': case 'service': case 'contact':
			$group_type = $obj_type.'group';

			echo "&nbsp;<strong><label for=\"selected_{$obj_type}_groups[]\">" . ucfirst($target_type) . "s";
			if ($target_type !== $obj_type) {
				echo " assigned to " . $obj_type . "s";
			}
			echo " in the following ". $group_type . "s</label></strong>\n<br />";
			Mselect_Nacomawidget::make_multi_selection($group_type, false, 'selected_' . $obj_type . '_groups', $exclude);
			echo "<br />\n";
			break;
		 default:
			 break;
		}

		echo "&nbsp;<strong><label for=\"selected_{$obj_type}s[]\">";
		if ($target_type !== $obj_type)
			echo ucfirst($target_type) . "s assigned to the following ";
		echo ucfirst($obj_type) . "s</label></strong>\n<br />";
		Mselect_Nacomawidget::make_multi_selection($obj_type, false, 'selected_' . $obj_type . 's', $exclude);
	}

	/**
	 * Simple wrapper around make_multi_obj_selection()
	 * @param $exclude array: Hosts to exclude
	 */
	function make_multi_host_selection($exclude = array())
	{
		$this->make_multi_obj_selection('host', $exclude);
	}

	/**
	 * Simple wrapper around make_multi_obj_selection()
	 * @param $exclude array: Services to exclude
	 */
	function make_multi_service_selection($exclude = array())
	{
		$this->hidden_var('selection_target', 'service');
		$this->make_multi_obj_selection('host', array(), 'service');
		echo "<br />\n";
		$this->make_multi_obj_selection('service', $exclude);
	}

	/**
	 * Prints an array of entries, using $subject as the table
	 * header and then printing one line of text per row.
	 * If $ary is empty, this function does nothing.
	 *
	 * @return The number of entries printed.
	 */
	function print_problem_array($subject, $ary)
	{
		if (empty($ary))
			return 0;

		echo "<table class=\"zebra\"><tr class='header'><td>$subject</td></tr>\n";
		$i = 0;
		foreach ($ary as $problem) {
			$i++;
			echo "<tr><td class='List'><pre>$problem</pre></td></tr>\n";
		}
		echo "</table>\n";

		return $i;
	}

	/**
	 * Print a list of object validation errors
	 * @param $obj A Nagios-object-specific php object
	 * @param $ary The validation errors, if not set in $obj
	 */
	function print_validation_error_table($obj, $ary = false)
	{
		if ($ary === false && empty($obj->validation_error_list)) {
			bug("print_validation_error_table() called, but object has no validation_error_list");
			return;
		}

		$erray = $ary === false ? $obj->validation_error_list : $ary;
		echo "<table style=\"width: 100%;border-bottom: 1px solid #dcdcdc; border-collapse: collapse; border-spacing: 0px\" class=\"zebra\"><tr class='header'><td style='padding-left: 7px;'>Variable</td><td style='padding-left:7px;'>Reason</td></tr>\n";
		foreach ($erray as $v => $reason) {
			echo "<tr>
				<td style='padding: 2px 7px; border-right: 1px solid #dcdcdc'>".html::specialchars($v)."</td>
				<td style='padding: 2px 7px;'>".html::specialchars($reason)."</td>
			</tr>\n";
		}
		echo "</table>\n";
	}

	/**
	 * Given an object type and an array of links, generates the related info div
	 */
	function print_related($obj_type, $link_ary)
	{
		global $Supported_Object_Types;
		$obj_types = $Supported_Object_Types;
		$obj_pages = false;
		// Remove FILE object
		array_pop($obj_types);
		sort($obj_types);
		for ($i=0;$i<sizeof($obj_types);$i++){
			$obj_pages[$obj_types[$i]] = $obj_types[$i];
		}
		$obj_pages[$obj_type] = $obj_type;

		echo '<div id="related">';
		$this->make_drop_down($obj_pages, $obj_type, 'page_selection', 'xlarge');
		echo "<br /><strong>Related items</strong><br />\n";
		echo '<ul class="config">';
		foreach ($link_ary as $row) {
			echo '<li>'.$row.'</li>';
		}
		echo '</ul>';
		echo '</div>'; // #related
	}

	/**
	 * Print links and info related to object type. REWRITE ME PLEASE!!!
	 * @param $obj A Nagios-object-specific php class object
	 */
	function print_related_object($obj)
	{
		global $show_logserver_scan;
		$host 		= false;
		$service 	= false;
		$related_items = array();

		if ($obj->type==='servicedependency' ||  $obj->type==='serviceescalation'){
			$svc = new service_nacoma_Model($obj->master_obj_id);
			$gp_id = $svc->master_obj_id;
			$gp_type = $svc->master_obj_type;
			$gp = $this->get_index_property($svc->master_obj_type, $svc->master_obj_id);
		}

		if (isset($obj->master_obj_candidates['host']) && isset($obj->master_obj_candidates['hostgroup']))
			$parent_icon = $obj->master_obj_type == 'host' ? 'host-configuration.png' : 'host-groups.png';

		switch ($obj->type){
			case 'contact': case 'contact_template':
				// contact
				/* This part is still in nacoma and seemingly
				 * does not have access to ninja classes such
				 * as the URL helper or the LinkProvider,
				 * therefor it is hard-coded until such a time
				 * when it may be moved into ninja */
				$related_items[] = '<img src="images/icons/local-users.png" alt="" /> <a target="_parent" href="/monitor/index.php/users">Local Users</a>';
				$related_items[] = '<img src="images/icons/contact-groups.png" alt="" /> <a href="edit.php?obj_type=contactgroup">Contact Groups</a>';
				$related_items[] = '<img src="images/icons/time-periods.png" alt="" /> <a href="edit.php?obj_type=timeperiod">Timeperiods</a>';
				if (!$obj->is_template) {
					$related_items[] = '<img src="images/icons/templates.png" alt="" /> <a href="edit.php?obj_type=contact_template">Contact Templates</a>';
				}
				break;
			case 'hostdependency':
				if ($obj->master_obj_id)
					$related_items[] = '<img src="images/icons/'.$parent_icon.'" alt="" /> <a href="edit.php?obj_type='.$obj->master_obj_type.'&amp;obj_id='.$obj->master_obj_id.'">'.ucfirst($obj->master_obj_type).' configuration: <b>'.$this->get_index_property($obj->master_obj_type, $obj->master_obj_id).'</b></a>';
				break;
			case 'servicedependency':
				if (isset($gp_id))
					$related_items[] = '<img src="images/icons/services.png" alt="" /> <a href="edit.php?obj_type=service&amp;master_obj_id='.$gp_id.'&amp;master_obj_type='.$gp_type.'">Services for '.$gp_type.': <b>'. $gp .'</b></a>';
				elseif ($gp!='')
					$related_items[] = '<img src="images/icons/services.png" alt="" /> <a href="edit.php?obj_type=service">Services for '.$gp_type.': <b>'. $gp .'</b></a>';
				break;
			case 'serviceescalation':
				if (isset($gp_id))
					$related_items[] = '<img src="images/icons/services.png" alt="" /> <a href="edit.php?obj_type=service&amp;master_obj_id='.$gp_id .'&amp;master_obj_type='.$gp_type.'">Services for '.$gp_type.' <b>'. $gp .'</b></a>';
				$related_items[] = '<img src="images/icons/contact-groups.png" alt="" /> <a href="edit.php?obj_type=contactgroup">Contact Groups</a>';
				$related_items[] = '<img src="images/icons/time-periods.png" alt="" /> <a href="edit.php?obj_type=timeperiod">Time Periods</a>';
				break;
			case 'hostescalation':
				if ($obj->master_obj_id)
					$related_items[] = '<img src="images/icons/'.$parent_icon.'" alt="" /> <a href="edit.php?obj_type='.$obj->master_obj_type.'&amp;obj_id='.$obj->master_obj_id.'">'.ucfirst($obj->master_obj_type).' Configuration: <b>'.$this->get_index_property($obj->master_obj_type, $obj->master_obj_id).'</b></a>';
				$related_items[] = '<img src="images/icons/contact-groups.png" alt="" /> <a href="edit.php?obj_type=contactgroup">Contact Groups</a>';
				$related_items[] = '<img src="images/icons/time-periods.png" alt="" /> <a href="edit.php?obj_type=timeperiod">Time Periods</a>';
				break;
			case 'contactgroup':
				$related_items[] = '<img src="images/icons/contacts.png" alt="" /> <a href="edit.php?obj_type=contact">Contacts</a>';
				break;
			case 'host': case 'host_template':
				if (!$obj->is_template && (int)$obj->id) {
					$related_items[] = '<img src="images/icons/scan-host.png" alt="" /> <a href="host_wizard.php?action=partition_scan&amp;obj_id='.$obj->id.'" class="scan partition">EMCA SCANNER</a>';
					$related_items[] = '<img src="images/icons/scan-host.png" alt="" /> <a href="host_wizard.php?action=scan&amp;obj_id='.$obj->id.'" class="scan network">Scan host for network services</a>';
					$related_items[] = '<img src="images/icons/scan-host.png" alt="" /> <a href="host_wizard.php?action=snmp_scan&amp;obj_id='.$obj->id.'" class="scan snmp">Scan host for SNMP interfaces</a>';
					$related_items[] = '<img src="images/icons/scan-host.png" alt="" /> <a href="host_wizard.php?action=choose_win_check_type&amp;obj_id='.$obj->id.'" class="scan windows">Scan host for Windows Services</a>';
					if ($show_logserver_scan)
						$related_items[] = '<img src="images/icons/scan-host.png" alt="" /> <a href="host_wizard.php?action=check_logserver_filters&amp;obj_id='.$obj->id.'">Scan host for Logserver filters (Logserver 3.x only)</a>';
					$related_items[] = '<img src="images/icons/services.png" alt="" /> <a href="edit.php?obj_type=service&amp;host_id='.$obj->id.'">Services for host <b>' . $this->get_index_property('host', $obj->id) . '</b></a>';
					$related_items[] = '<img src="images/icons/host-configuration.png" alt="" /> <a title="View status information for this host (Opens in new window)" href="/monitor/index.php/extinfo/details/host/'.$this->get_index_property('host', $obj->id).'" target="_blank">Status information</a>';
				}
				if (!$obj->is_template) {
					$related_items[] = '<img src="images/icons/templates.png" alt="" /> <a href="edit.php?obj_type=host_template">Host Templates</a>';
				}
				$related_items[] = '<img src="images/icons/commands.png" alt="" /> <a href="edit.php?obj_type=command">Check Commands</a>';
				$related_items[] = '<img src="images/icons/contact-groups.png" alt="" /> <a href="edit.php?obj_type=contactgroup">Contact Groups</a>';
				$related_items[] = '<img src="images/icons/time-periods.png" alt="" /> <a href="edit.php?obj_type=timeperiod">Time Periods</a>';
				if (!$obj->is_template) {
					$related_items[] = '<img src="images/icons/new-host.png" alt="" /> <a href="host_wizard.php">Add new host</a>';
				}
				break;
			case 'service': case 'service_template':
				if (!$obj->is_template && $obj->master_obj_id) {
					if ($obj->master_obj_type === 'host') {
						$related_items[] = '<img src="images/icons/scan-host.png" alt="" /> <a href="host_wizard.php?action=scan&amp;obj_id='.$obj->master_obj_id.'" class="scan network">Scan host for network services</a>';
						$related_items[] = '<img src="images/icons/scan-host.png" alt="" /> <a href="host_wizard.php?action=snmp_scan&amp;obj_id='.$obj->master_obj_id.'" class="scan snmp">Scan host for SNMP interfaces</a>';
						$related_items[] = '<img src="images/icons/scan-host.png" alt="" /> <a href="host_wizard.php?action=choose_win_check_type&amp;obj_id='.$obj->master_obj_id.'" class="scan windows">Scan host for Windows Services</a>';
						if ($show_logserver_scan)
							$related_items[] = '<img src="images/icons/scan-host.png" alt="" /> <a href="host_wizard.php?action=check_logserver_filters&amp;obj_id='.$obj->master_obj_id.'">Scan host for Logserver filters (Logserver 3.x only)</a>';
					}
					$related_items[] = '<img src="images/icons/'.$parent_icon.'" alt="" /> <a href="edit.php?obj_type='.$obj->master_obj_type.'&amp;obj_id='. $obj->master_obj_id.'">'.ucfirst($obj->master_obj_type).' configuration: <b>'.$this->get_index_property($obj->master_obj_type, $obj->master_obj_id) .'</b></a>';
					if ((int)$obj->id)
						$related_items[] = '<img src="images/icons/host-configuration.png" alt="" /> <a title="View status information for this service (Opens in new window)" href="/monitor/index.php/extinfo/details/service/'.$this->get_index_property($obj->master_obj_type, $obj->master_obj_id).'?service='.urlencode($obj->obj['service_description']).'" target="_blank">Status information</a>';
				}
				if (!$obj->is_template) {
					$related_items[] = '<img src="images/icons/templates.png" alt="" /> <a href="edit.php?obj_type=service_template">Service Templates</a>';
				}
				$related_items[] = '<img src="images/icons/commands.png" alt="" /> <a href="edit.php?obj_type=command">Check Commands</a>';
				$related_items[] = '<img src="images/icons/contact-groups.png" alt="" /> <a href="edit.php?obj_type=contactgroup">Contact Groups</a>';
				$related_items[] = '<img src="images/icons/time-periods.png" alt="" /> <a href="edit.php?obj_type=timeperiod">Time Periods</a>';
				$related_items[] = '<img src="images/icons/service-groups.png" alt="" /> <a href="edit.php?obj_type=servicegroup">Service Groups</a>';
				$related_items[] = '<img src="images/icons/services.png" alt="" /> <a href="edit_special.php/copy_slaves?master_obj_id='.$obj->master_obj_id.'&amp;master_obj_type='.$obj->master_obj_type.'&amp;obj_id='.$obj->id.'&amp;obj_type='.$obj->type.'">Clone Service(s)</a>';
				break;
			case 'command':
				if ( file_exists('/opt/plugins/metadata/'))
					$related_items[] = '<img src="images/icons/check-command-import.png" alt="" /> <a href="metadata.php">Check Command Import</a>';
				break;
				$related_items[] = '<img src="images/icons/graph_templates.png" alt="" /> <a href="edit.php?obj_type=graph_template">Graph Templates</a>';
			case 'hostgroup':
				if ((int)$obj->id) {
					$related_items[] = '<img src="images/icons/services.png" alt="" /> <a href="edit.php?obj_type=service&amp;master_obj_id='.$obj->id.'&amp;master_obj_type=hostgroup">Services for hostgroup <b>' . $obj->get_object_name() . '</b></a>';
				}
				$related_items[] = '<img src="images/icons/management-packs.png" alt="" /> <a title="Package group as a management pack" href="edit.php?obj_type=management_pack">Management packs</a>';
				$related_items[] = '<img src="images/icons/manage-management-packs.png" alt="" /> <a title="Import and export management packs" href="mgmtpackimport.php">Manage Management packs</a>';
				break;
			case 'management_pack':
				if ((int)$obj->id) {
					$related_items[] = '<img src="images/icons/host-groups.png" alt="" /> <a href="edit.php?obj_type=hostgroup&amp;obj_id='.$obj->obj['hostgroup'].'">Hostgroup <b>' . $obj->obj['hostgroup'] . '</b></a>';
				}
				else {
					$related_items[] = '<img src="images/icons/host-groups.png" alt="" /> <a href="edit.php?obj_type=hostgroup">Hostgroups</a>';
				}
				$related_items[] = '<img src="images/icons/management-packs.png" alt="" /> <a title="Import and export management packs" href="mgmtpackimport.php">Manage Management packs</a>';
				break;
		}

		if ($obj->master_obj_type && !$obj->master_obj_id)
		{
			echo '</div></div>';
		}

		if ($obj::can_user_delete())
			$related_items[] = '<img alt="" src="images/icons/delete.png" /><a href="edit_special.php/bulk_delete?obj_type='.$obj->type.'">Bulk delete '.$obj->type.'s</a>';
		$this->print_related($obj->type, $related_items);
		if ($obj->master_obj_type && !$obj->master_obj_id) {
			echo '<div>';
		}
	}

	/**
	 * Create the link "Services for {host,hostgroup}"
	 * @return string: The link, complete with image and all
	 */
	function print_services_link($obj)
	{
		if (($obj->type !== 'host' && $obj->type !== 'hostgroup') || !$obj->id || $obj->id === 'new')
			return;

		return "<img src=\"images/icons/arrow.gif\" alt=\"\" /> &nbsp;<a href='edit.php?obj_type=service&amp;master_obj_id=$obj->id&amp;master_obj_type=$obj->type'>Services for {$obj->type} <b>{$obj->get_object_name()}</b></a>";
	}

	/**
	 * Create drop-down (select) with the option of specifying
	 * classname and onchange action
	 * @param $objects array: Objects to list
	 * @param $dflt string: Default object
	 * @param $selection_name string: Name of the selection
	 * @param $classname string: css class name
	 * @param $onchange javascript gunk to add to form
	 */
	function make_drop_down($objects=false, $dflt='', $selection_name='', $classname='xlarge', $onchange = '')
	{
		$dflt 			= addslashes(trim($dflt));
		$selection_name = addslashes(trim($selection_name));
		$classname 		= addslashes(trim($classname));
		$onchange		= trim($onchange);
		echo "<select name=\"".$selection_name."\" class=\"".$classname."\"";
		echo $onchange!='' ? " onchange=\"".$onchange."\">" : ">";
		foreach($objects as $item){
			echo "<option value=\"".$item."\"";
			if ($dflt==$item){
				echo " selected=\"selected\"";
			}
			echo ">".$item."</option>";
		}
		echo "</select>";
	}

	/**
	 * Get an object name from an id
	 * @param $type string: Nagios-style object type
	 * @param $id int: Id of the object
	 * @return string: The object name
	 */
	function get_index_property($type='', $id=0)
	{
		$id 	= (int)$id;
		$type 	= addslashes( trim($type) );

		if (!$id || $type===''){
			return 'unknown';
		}
		$class = $type . '_nacoma_Model';
		$obj = new $class($id);
		return $obj->get_object_name();
	}

	/**
	 * Print custom variables for one object
	 * @param $obj A Nagios-specific php object class
	 */
	function pretty_print_custom_vars($obj)
	{
		// Warning! These HTML selectors needs to correspond
		// to the similar code in common.js
		echo "<tr class='custom'>
			<td class='HelpList'><a href='#' title='Click to view help on custom_vars' tabindex='-1' data-help='custom_vars'><img src='images/icons/shield-help.png' alt='Help' title='Help' /></a></td>
			<td></td>
			<td>Custom variable:</td>
			<td>".($obj->type=='management_pack'?'Description:':'Value:')."</td>
		</tr>";
		if (!empty($obj->custom_vars)) {
			$i = 0;
			$base_var_name = 'custom_vars[' . $obj->type . '][' . $obj->id . ']';
			$line_start = '<td><input type="text" name="' . $base_var_name;
			foreach ($obj->custom_vars as $k => $v) {
				echo "<tr class=\"custom\">\n";
				$k = htmlspecialchars($k);
				$v = htmlspecialchars($v);
				echo '<td></td>';
				echo '<td>'.$this->print_propagate_checkbox($k).'</td>';
				echo $line_start . "[$i][key]\" value=\"$k\"></td>\n";
				echo $line_start . "[$i][value]\" value=\"$v\">\n";
				echo '<input type="button" class="remove_custom" value="Remove" />';

				$i++;
				echo "</td></tr>\n";
			}
		}
		echo '<tr class="custom"><td></td><td></td><td></td><td><input type="button" id="new_custom" value="Add custom variable" /></td></tr>';
	}

	/**
	 * Returns a checkbox used for selecting variables to propagate
	 * @param $token The name of the variable
	 */
	function print_propagate_checkbox($token)
	{
		if (empty($token))
			return;
		return "<input type='checkbox' class=\"propagate_box\" title=\"Propagate $token\" id=\"prop_vars[$token]\" name='prop_vars[$token]' />\n";
	}

	/**
	 * Return html for drop-down boxes to choose node to test from.
	 *
	 * Warning!
	 *
	 * This is slow. We do not want to call this unless we must
	 */
	private function get_node_html() {
		$nodes = get_merlin_nodes();
		$nodehtml = false;
		if (count($nodes) > 1) {
			$nodehtml = '<span class="extra_test"> on <select id="cmdTestNode" name="cmdTestNode">';
			foreach ($nodes as $node) {
				if ($node === 'This server')
					$nodehtml .= '<option value="" selected="selected">'.$node.'</option>';
				else
					$nodehtml .= '<option value="'.$node.'">'.$node.'</option>';
			}
			$nodehtml .= '</select></span>';
		}
		return $nodehtml;
	}

	/**
	 * Print form for each object
	 * @param $obj Nagios-specific php object class
	 */
	function pretty_print_one($obj)
	{
		// get the object properties
		$special = $obj->properties['special'];
		$obj_index = $obj->properties['var_index'];
		$req_vars = $obj->properties['required'];

		// Create array of objects for required fields and data type
		if (!empty($req_vars)) { ?>
			<script type="text/javascript">
				monitor_required_objects = new Array();
			<?php	$a = 0;
					foreach ($req_vars as $req) {
						if (!array_key_exists($req, $obj_index) || (preg_match('/_options$/', $req, $discard)) && $obj_index[$req]=='checkbox') continue;?>
						monitor_required_objects[<?php echo $a ?>] = new required_vars('<?php echo $req ?>', '<?php echo $obj_index[$req] ?>');
					<?php
						if (isset($obj->properties['req_alt'][$req])) { ?>
						monitor_required_objects[<?php echo $a ?>].req_alt = '<?php echo $obj->properties['req_alt'][$req]; ?>';
						monitor_required_objects[<?php echo $a ?>].alt_type = '<?php echo $obj_index[$obj->properties['req_alt'][$req]]; ?>';
						<?php }
						$a++;
					}?>
			</script><?php
		}

		// Oh IE7, you so silly...
		echo "<div style='float: none; clear:both'></div>";
		// Create the All Surrounding object table
		echo "<div class=\"object-table\">\n" .
		  "<table class=\"ObjTable max zebra\">\n <tr><td colspan=\"4\" style=\"padding: 0; border: 0;\">\n";

		$this->draw_command_bar($obj);
		echo "</td></tr>\n";

		/* Ok. Command bar is completed, so we start printing the
		 * actual variables */
		$this->hidden_var('tmp_obj_id', html::specialchars($obj->id));
		$this->hidden_var('obj_type', $obj->type);
		if (isset($obj->clone_id)) {
			$this->hidden_var('clone_id', $obj->clone_id);
		}

		$denom = $obj->denormalize();
		foreach($obj_index as $token => $v_type) {

			if (!isset($obj->obj[$token]))
				continue;

			if (isset($obj->properties['widget']) && isset($obj->properties['widget'][$token]))
				$v_type = $obj->properties['widget'][$token];

			$this->insert_widget($v_type, $obj, $token, isset($denom[$token]) ? $denom[$token] : false );

		}

		switch ($obj->type) {
		 case 'timeperiod':
			timeperiod_exceptions_print($obj, $this);
			break;
		 case 'host': case 'host_template':
		 case 'service': case 'service_template':
		 case 'contact': case 'contact_template':
		 case 'management_pack':
			$this->pretty_print_custom_vars($obj);
			break;
		}

		// Test-this
		if ($obj->type==='command') {
			$nodehtml = $this->get_node_html();
			echo "<tr>
				<td colspan='4' class='testButton'>
					<input type='button' class='button test-this-command' title='Click to test this command with the values entered above' value='Test this command'>
					<input type='button' class='button hide-test-result' title='Click to hide test results' value='Hide test form' style='display: none;'>
					$nodehtml
					<div id='cmdForm' style='display: none;'>
						<input type='text' class='xlarge' name='cmdString' />
						<input type='button' class='button test-this-check' name='tmpBtn' value='Test this check'>
						<div id='commandTest' style='display:none;'></div>
					</div>
				</td>
			</tr>\n";
		} else if ($obj->type === 'service' && $obj->master_obj_type === 'hostgroup') {
			$hostgroup_service_html = "<input type='button' disabled='disabled' class='button test-this-check' title='Click to test this check with the values entered above' value='Test this check'> Add at least one host to this hostgroup, in order to test this service";
			$hg = new hostgroup_nacoma_Model($obj->master_obj_id);
			if(!empty($hg->obj['members'])) {
				$nodehtml = $this->get_node_html();
				$hostgroup_service_html = "
					<input type='button' class='button test-this-check' title='Click to test this check with the values entered above' value='Test this check'>
					<span class='if-service-on-hostgroup'>
						on host
						<select id='host_id' name='host_id'>";
				$select_host = "";
				if(isset($_GET) && isset($_GET['host'])) {
					$select_host = $_GET['host'];
				}
				foreach($hg->obj['members'] as $host_id) {
					$host = new host_nacoma_Model($host_id);
					$name = $host->obj['host_name'];
					$selected = $name == $select_host ? 'selected="selected"' : "";
					$hostgroup_service_html .= "<option $selected value='$host_id'>$name</option>";
				}
				$hostgroup_service_html .= "
						</select>
					</span>
					<input type='button' class='button hide-test-result' title='Click to hide test results' value='Hide test form' style='display: none;'>
					$nodehtml
					<div id='commandTest' style='display:none;'></div>";
			}
			echo "<tr>
				<td colspan='4' class='testButton'>
					$hostgroup_service_html
				</td>
			</tr>\n";
		} else if ($obj->type==='service' ||$obj->type === 'host') {
			$nodehtml = $this->get_node_html();
			echo "<tr>
				<td colspan='4' class='testButton'>
					<input type='button' class='button test-this-check' title='Click to test this check with the values entered above' value='Test this check'>
					<input type='button' class='button hide-test-result' title='Click to hide test results' value='Hide test form' style='display: none;'>
					$nodehtml
					<div id='commandTest' style='display:none;'></div>
				</td>
			</tr>\n";
		}
		echo "</table>\n";
		echo '</div>';
	}

	/**
	 * Include and render the correct widget for the specified token
	 */
	function insert_widget($name, $obj, $token, $value) {
		// ucfirst = Make sure it's loaded as a library
		$class = ucfirst($name).'_Nacomawidget';
		$widget = new $class();
		$widget->render_row($obj, $token, $value);
	}

	/**
	 * Get variables is shown on page
	 *
	 * @param $obj A Nagios-specific php object class
	 * @return array: key => value indexed array
	 */
	function get_page_vars($obj)
	{
		$variables = array();
		// get the object properties
		$special 	= $obj->properties['special'];
		$v_index 	= $obj->properties['var_index'];
		$req_vars 	= $obj->properties['required'];

		foreach($v_index as $token => $v_type) {
			if(!isset($obj->obj[$token]))
			  continue;
			$value = $obj->obj[$token];
			/* hidden variables are dealt with here, to save us
			 * exception handling in the middle of all if .. elseif .. */
			if($v_type === 'hidden' ||
			   ($token === 'FILE' &&
				$obj->id !== 'new' &&
				$obj->type !== 'new_host' &&
				$obj->type !== 'new_service' &&
				(empty($_REQUEST['action']) ||
				 $_REQUEST['action'] !== 'expand')))
			{
				continue;
			}
			$variables[] = $token;
		}
		return $variables;
	}

	/**
	 * @param $obj object_nacoma_Model
	 * @return string html
	 */
	function help_for_object(object_nacoma_Model $obj)
	{
		$help_nacoma_Model = new help_nacoma_Model;
		try {
			$help = $help_nacoma_Model->get_html_help_for_object_type($obj->type);
		} catch(Exception $e) {
			Op5Log::instance('nacoma')->log('debug', 'Tried to get help for object of type '.$obj->type);
			return null;
		}
		return "<p>".$help['description']."</p>";
	}

	/**
	 * Draws the border and frames of the object listing in the gui.
	 * @param $obj A Nagios-specific php object class.
	 */
	function draw_command_bar($obj)
	{
		/* print the 'command bar' only for non-new objects.
		 * Conditional commandbar items goes first, so ppl recognize
		 * where they are. */
		echo "<table class='max header' style='width: 100%'><tr><td class='CmdBar'>" . html::specialchars($obj->get_object_name()) . " </td>\n";

		$cmd_order = array
			('dependencies', 'escalations', 'pack', 'advanced', 'simple','clone', 'copy', 'propagate', 'delete');
		$cmd_links = $obj->get_cmd_bar_links();
		foreach ($cmd_order as $cmd) {
			if (!isset($cmd_links[$cmd]))
				continue;
			echo "<td class='CmdBarItem'>" .
				$cmd_links[$cmd] . $cmd . "</a></td>\n";
		}

		echo "  </tr></table>\n\n";
	}


	/**
	 * Print out the contents of an array as hidden variables
	 * @param $name Base name of the array
	 * @param $ary The variable array in 'key => value' form
	 */
	function hidden_var_array($name, $ary)
	{
		foreach ($ary as $k => $v) {
			if (is_array($v))
			  $this->hidden_var_array($name . '[' . $k . ']', $v);
			else
			  $this->hidden_var($name . '[' . $k . ']', $v);
		}
	}

	/**
	 * Print message to user
	 */
	function print_message($input=false, $left_margin=false)
	{
		if (!$input){
			return false;
		}

		if (is_string($input)){
			// Wrapper for string messages to user
			if ($left_margin)
				echo "<div style=\"margin-left:{$left_margin}ex;\">$input</div>";
			else
				echo $input;
			return true;
		}
		if (is_array($input)){
			// possibly taking hash arrays as input
			foreach ($input as $key => $value) {
				self::print_message($value, $left_margin === false ? 0 : $left_margin + 2);
			}
		}
		return true;
	}

	/**
	 * Draw the object selector that is on top of most object editing pages
	 */
	function draw_object_selector($obj)
	{
		if ($obj->master_obj_type) {

			$this->form_start('foo_form','get');
			$this->hidden_var('obj_type', $obj->type);

			echo '<table id="parent_selector"><tr><td rowspan="2" valign="bottom">';
			// 3 is how badly I want visible filter thingy
			echo '</td>';
			if (count($obj->master_obj_candidates) > 1)
				foreach ($obj->master_obj_candidates as $obj_type => $_)
					echo "<td class=\"header\">$obj_type</td>";
			else
					echo "<td></td>";
			echo '<td rowspan="2">';
			$this->submit_button('Go', 'action', true);
			echo '</td></tr><tr>';
			$class = $obj->master_obj_type . '_nacoma_Model';
			$master = new $class($obj->master_obj_id);
			foreach ($obj->master_obj_candidates as $obj_type => $_) {
				echo '<td>';
				$select_name = "master_obj[$obj_type]";
				Select_Nacomawidget::make_selection($obj_type, $obj->master_obj_type == $obj_type && $master->id > 0 ? $master->get_object_name() : '', $select_name, 'selector_parent');
				echo '</td>';
			}
			echo '</tr></table>';

			$this->form_end();
			if (!$obj->master_obj_id) {
				$this->print_related_object($obj);
				$this->html_end();
			}

			?>
			<script type="text/javascript">
			$('#parent_selector').on('change', '.selector_parent', function() {
				$(this).parents('table').find('.selector_parent').not(this).val('');
			});
			</script>
			<?php
			echo '<br />';
		}
		$select_obj_type = $obj->type;
		$select_name = 'obj_name';
		$select_default = $obj->id > 0 ? $obj->get_object_name() : '';
		// A master object exists, get slaves for that master
		if (!empty($obj->master_obj_id)) {
			$select_list = $obj->get_fellow_slaves();
			$select_list = array_merge(array("" => ""), $select_list);
		}
		// No master objects exists, get objects the user can see
		else {
			$select_list = $obj->type;
		}

		$this->form_start('bar_form', 'get');
		$this->hidden_var('obj_type', $obj->type);
		if (isset($obj->master_obj_id))
			$this->hidden_var('master_obj_id', $obj->master_obj_id);

		echo $this->make_filtered_selection($select_list, $select_default, $select_name);

		$this->submit_button('Go', 'action', true);
		$this->submit_button('New', 'action', true);

		if ($obj->master_obj_type)
			$this->hidden_var('master_obj_type', $obj->master_obj_type);
		$this->form_end();
	}

	/**
	 * Render a multi-select widget
	 */
	public function make_multi_selection($tokenvalues, $dflt, $select_name = 'selection')
	{
		Mselect_Nacomawidget::make_multi_selection($tokenvalues, $dflt, $select_name);
	}
}
