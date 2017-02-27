#!/usr/bin/perl -I/opt/plugins/custom/emca/perl5_lib
use Nagios::Plugin;
use File::Basename;
use File::stat;
use POSIX qw(strftime);

use constant REMOTE_HOST => "10.125.13.10";

use constant LOG_DIR => "/opt/plugins/custom/emca";
use constant LOG_FILE => "self_updater.log";

use constant VERSION => "1.0";
use constant PROGNAME => basename($0);
use constant URL => "http://it.emca.pl";
use constant PROGNAME2 => "";
use constant NRPE_PORT => 5666;

use constant LOCAL_CONFIG_DIR => "/etc/nrpe.d/";
use constant LOCAL_PLUGINS_DIR => "/opt/plugins/custom/emca/";

use constant REMOTE_CONFIG_DIR => sprintf("https://%s/repo/updates/nrpe.d", REMOTE_HOST);
use constant REMOTE_PLUGINS_DIR => sprintf("https://%s/repo/updates/plugins", REMOTE_HOST);

our %msg;


my $nagios = Nagios::Plugin->new(
	shortname => uc(PROGNAME),
	plugin    => PROGNAME,
	url	  => URL,
	version	  => VERSION,
	blurb 	  => PROGNAME2,
	usage 	  => "Usage: %s -T <type> -f <filename>",
	extra	  => "\n\nCopyright (c) 2014 EM&CA S.A."
	);

$nagios->add_arg(
	spec => "type|T=s",
	help => "Possible commands: config_version|get_config_list|remove_configs|remove_config|download_plugin|download_config|agent_version",
	required => 1
	);

$nagios->add_arg(
	spec => "filename|f=s",
	help => "Plugin or config filename",
	required => 0
	);

$nagios->getopts;

if($nagios->opts->type =~ /^config_version$/) 		{ __display_config_version($nagios->opts->filename); }
elsif($nagios->opts->type =~ /^list_config$/) 		{ __display_config_list(($nagios->opts->filename eq "all" ? "all" : $nagios->opts->filename)); }
elsif($nagios->opts->type =~ /^list_plugin$/) 		{ __display_plugins(($nagios->opts->filename eq "all" ? "all" : $nagios->opts->filename)); }
elsif($nagios->opts->type =~ /^remove_config$/)   	{ __remove_config(($nagios->opts->filename)); }
elsif($nagios->opts->type =~ /^remove_plugin$/)   	{ __remove_plugin($nagios->opts->filename); }
elsif($nagios->opts->type =~ /^download_plugin$/) 	{ __download_file(LOCAL_PLUGINS_DIR, REMOTE_PLUGINS_DIR, $nagios->opts->filename, 0755); }
elsif($nagios->opts->type =~ /^download_config$/) 	{ __download_file(LOCAL_CONFIG_DIR, REMOTE_CONFIG_DIR, $nagios->opts->filename, 0644); }
elsif($nagios->opts->type =~ /^agent_version$/) 	{ __display_agent_version(); }
elsif($nagios->opts->type =~ /^agent_restart$/)		{ __restart_nrpe_agent(); }



$nagios->nagios_exit($msg{'code'}, $msg{'message'});

sub __display_config_list() {
	local $filename = $_[0];

	opendir(DIR_HANDLE, LOCAL_CONFIG_DIR) || $nagios->nagios_die(sprintf("Can not read directory: %s", LOCAL_CONFIG_DIR));

	while(my $file = readdir(DIR_HANDLE)) {
		next if($file =~ m/^\./);

		local $mode = stat(sprintf("%s/%s", LOCAL_CONFIG_DIR,$file));
		if($filename eq "all") {
			$msg{'message'} = sprintf("%s%s%s", $msg{'message'}, $file, "; ");
		}
		elsif($file eq $filename) {
			$msg{'message'} = sprintf("%s ( perm: %04o, size: %s, owner: %s:%s )", $file, $mode->mode & 07777, $mode->size, $mode->uid, $mode->gid);
		}
	}

	$msg{'code'} = "OK";
	closedir(DIR_HANDLE);
	
}

sub __display_config_version {
	local $file = $_[0];

	$msg{'code'} = "OK";

	open(FILE, sprintf("<%s/%s", LOCAL_CONFIG_DIR, $file)) || $nagios->nagios_die(spritnf("Can not open file: %s", $file));

	local @lines = <FILE>;
	chomp @lines;

	foreach(@lines) {
		if( $_ =~ /# VERSION:/) { 
			$msg{'message'} = sprintf("Version of %s file: %s", $file, $_);
			return;
		}
	}
	close(FILE);
}


sub __remove_config {
	local $filename = $_[0];

	#if($filename =~ /all/) {
	#	opendir(DIR_HANDLE, LOCAL_CONFIG_DIR) || $nagios->nagios_die(sprintf("Can not read directory: %s", LOCAL_CONFIG_DIR));
	#		
	#
	#	while(my $file = readdir(DIR_HANDLE)) {
	#		next if($file =~ m/^\./);
	#		next if($file =~ m/^0.cfg$/);
	#	
	#		local $f = sprintf("%s/%s", LOCAL_CONFIG_DIR, $file);
	#		local $unlinked = `rm $f`;
	#
	#		$msg{'message'} = sprintf ("%sFile %s was %s.\n", $msg{'message'}, $file, ($unlinked == 0 ? "removed" : "not removed"));
	#		__logger(LOG_DIR, LOG_FILE, sprintf("File %s was %s", $file, ($unlinked == 0 ? "removed" : "not removed")));
	#		$msg{'code'} = "OK";
	#	}
	#
	#	closedir(DIR_HANDLE);
	#} else {

		local $f = sprintf("%s/%s", LOCAL_CONFIG_DIR, $filename);
		if(-e $f) {
			local $unlinked = `rm $f`;
			if($unlinked == 0) {
				$msg{'code'} = "OK";
				$msg{'message'} = sprintf("File %s was deleted", $filename);
				__logger(LOG_DIR, LOG_FILE, sprintf("File %s was deleted", $filename));
			} else {
				$msg{'code'} = "CRITICAL";
				$msg{'message'} = sprintf("File %s can not be removed", $filename);
				__logger(LOG_DIR, LOG_FILE, sprintf("File %s can not be removed", $filename));
			}
		} else {	
			__logger(LOG_DIR, LOG_FILE, sprintf("File %s not exist.", $filename));
			$msg{'code'} = "OK";
			$msg{'message'} = sprintf("File %s not exist.", $filename);
			}
	#}
}

sub __remove_plugin {
	$filename = $_[0];

	local $f = sprintf("%s/%s", LOCAL_PLUGINS_DIR, $filename);
	if(-e $f) {
		local $unlinked = `rm $f`;
		if($unlinked == 0) {
			$msg{'code'} = "OK";
			$msg{'message'} = sprintf("File %s was deleted.", $filename);
			__logger(LOG_DIR, LOG_FILE, sprintf("File %s was deleted", $filename));
		}
		else {
			$msg{'code'} = "CRITICAL";
			$msg{'message'} = sprintf("File %s can not be deleted.", $filename);
			__logger(LOG_DIR, LOG_FILE, sprintf("File %s can not be deleted", $filename));
		}
	} else {	
		__logger(LOG_DIR, LOG_FILE, sprintf("File %s not exist", $filename));
		$msg{'code'} = "OK";
		$msg{'message'} = sprintf("File %s not exist.", $filename);
	}
}

sub __display_plugins {
	local $filename = $_[0];

	opendir(DIR_HANDLE, LOCAL_PLUGINS_DIR) || $nagios->nagios_die(sprintf("Can not read directory: %s", LOCAL_PLUGINS_DIR));
	local @plugins = <DIR_HANDLE>;

	while(my $file = readdir(DIR_HANDLE)) {
                 next if($file =~ m/^\./);
 
                 local $mode = stat(sprintf("%s/%s", LOCAL_PLUGINS_DIR,$file));
		 if($filename eq "all") {
                 	$msg{'message'} = sprintf("%s%s%s", $msg{'message'}, $file, "; ");
		 }
		elsif($filename eq $file) {
			$msg{'message'} = sprintf("%s ( perm: %04o, size: %s, owner: %s:%s )", , $file, $mode->mode & 07777, $mode->size, $mode->uid, $mode->gid);
		}
         }
 
        $msg{'code'} = "OK";
	closedir(DIR_HANDLE);
}

sub __download_file {
	local ($localDir, $remoteDir, $remoteFile, $perm) = @_;

	local $filename = sprintf("%s/%s", $remoteDir, $remoteFile);
	local $localFile = sprintf("%s/%s", $localDir, $remoteFile);

	local $cmd = sprintf("wget --no-check-certificate -q %s -O %s ", $filename, $localFile);
	$r = `$cmd`;

	if(-e sprintf("%s/%s", $localDir, $remoteFile)) {
		__logger(LOG_DIR, LOG_FILE, sprintf("File %s was succesfull uploaded.", $remoteFile));

		chmod $perm, sprintf("%s/%s", $localDir, $remoteFile);
		chown 701,701, sprintf("%s/%s", $localDir, $remoteFile);

		local $mode = stat(sprintf("%s/%s", $localDir, $remoteFile));
		$msg{'code'} = "OK";
		$msg{'message'} = sprintf("Uploaded file: %s ( perm: %04o, size: %s, owner: %s:%s )", $remoteFile, $mode->mode & 07777, $mode->size, $mode->uid, $mode->gid);

		__logger(LOG_DIR, LOG_FILE, sprintf("Uploaded file: %s ( perm: %04o, size: %s, owner: %s:%s )", $remoteFile, $mode->mode & 07777, $mode-size, $mode->uid, $mode->gid));
	} else {
		__logger(LOG_DIR, LOG_FILE, sprintf("File %s was not uploaded", $remoteFile));
		$msg{'code'} = "CRITICAL";
		$msg{'message'} = sprintf("File %s was not uploaded.", $remoteFile);
	}
}

sub __display_agent_version {

	$cmd = "/opt/plugins/check_nrpe -H 127.0.0.1";
	$result = `$cmd`;
	
	$msg{'code'} = "OK";
	$msg{'message'} = sprintf("NRPE Agent Version: %s", $result);
}

sub __restart_nrpe_agent {
	
	local $pid = `pidof nrpe`;

	exec("kill -HUP $pid");

	if($? != 0) {
		__logger(LOG_DIR, LOG_FILE, "NRPE Agent restart unsuccessull");
	}
	__logger(LOG_DIR, LOG_FILE, "NRPE Agent restart successull");
		
	$msg{'code'} = "OK";
	$msg{'message'} = "NRPE Agent restart successfull.";
}

sub __logger {
	local ($dir, $file, $msg) = @_;

	open(LOG_HANDLE, sprintf(">>%s/%s", $dir, $file)) || $nagios->nagios_die(sprintf("Can not open log file: %s/%s", $dir, $file));
	printf LOG_HANDLE "%s [ NRPE_UPDATER ] %s\n", strftime(q{%F %T}, localtime), $msg;
	close LOG_HANDLE;
}
