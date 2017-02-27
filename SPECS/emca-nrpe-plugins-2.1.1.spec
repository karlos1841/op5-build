%define myname emca-nrpe-plugins
%define version 2.1.1
%define release 20
%define nsuser op5
%define nsgroup op5
%define nrpeplugin "opt/plugins"
%define emca_plugins "%{nrpeplugin}/custom/emca"
%define debug_package %{nil}

Summary: Emca plugins form op5Monitor
URL: http://it.emca.pl
Name: %{myname}
Version: %{version}
Release: %{release}%{dist}
License: GPL
Group: Application/System
Source: %{myname}-%{version}.tar.gz
BuildRoot: %{_tmppath}/%{myname}-buildroot
BuildArch: x86_64
Requires: nrpe, sed, wget, sysstat
AutoReqProv: no
%description
Emca plugins for do not blame nrpe agent

%prep 
%setup -q -n %{myname}-%{version}

%pre

%preun

%postun


%post
cp /opt/plugins/nagios-plugins/* /opt/plugins/

if [ -x /etc/init.d/nrpe ]; then
	/etc/init.d/nrpe stop > /dev/null 2>&1
	sleep 2
	/etc/init.d/nrpe start > /dev/null 2>&1
fi

%build
cd ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/nagios-plugins
./configure --prefix=${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/nagios-plugins/nagios
make
make install

%install
[ "$RPM_BUILD_ROOT" != "/" ] && rm -rf $RPM_BUILD_ROOT
install -d -m 0755 ${RPM_BUILD_ROOT}/opt/plugins/custom/emca
install -d -m 0755 ${RPM_BUILD_ROOT}/etc/nrpe.d
install -d -m 0755 ${RPM_BUILD_ROOT}/opt/plugins/nagios-plugins

install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/nagios-plugins/nagios/libexec/* ${RPM_BUILD_ROOT}/opt/plugins/nagios-plugins/

install -m 0644 ${RPM_SOURCE_DIR}/%{myname}-%{version}/etc/nrpe.d/old.cfg ${RPM_BUILD_ROOT}/etc/nrpe.d/old.cfg
install -m 0644 ${RPM_SOURCE_DIR}/%{myname}-%{version}/etc/nrpe.d/0.cfg ${RPM_BUILD_ROOT}/etc/nrpe.d/0.cfg
install -m 0644 ${RPM_SOURCE_DIR}/%{myname}-%{version}/etc/nrpe.d/linux.cfg ${RPM_BUILD_ROOT}/etc/nrpe.d/linux.cfg
install -m 0644 ${RPM_SOURCE_DIR}/%{myname}-%{version}/etc/nrpe.d/op5_commands.cfg ${RPM_BUILD_ROOT}/etc/nrpe.d/op5_commands.cfg

install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_nrpe ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_nrpe

# plugins
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_icmp ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_icmp
#install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_udp ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_udp
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_cpu.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_cpu.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_by_ssh ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_by_ssh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_dummy ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_dummy
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_http ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_http
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ntp_peer ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ntp_peer
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_overcr ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_overcr
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_nagios ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_nagios
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_load ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_load
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_dns ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_dns
#install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_file_age ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_file_age
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ping ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ping
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/utils.pm ${RPM_BUILD_ROOT}/%{nrpeplugin}/utils.pm
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/utils.sh ${RPM_BUILD_ROOT}/%{nrpeplugin}/utils.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_rpc ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_rpc
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_tcp ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_tcp
#install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ifoperstatus ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ifoperstatus
#install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ifstatus ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ifstatus
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_disk ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_disk
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_log ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_log
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_users ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_users
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ntp_time ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ntp_time
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_real ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_real
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_dhcp ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_dhcp
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_time ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_time
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ssh ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ssh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ide_smart ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ide_smart
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ntp ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ntp
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_procs ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_procs
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_swap ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_swap
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_apt ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_apt
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/negate ${RPM_BUILD_ROOT}/%{nrpeplugin}/negate
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_dig ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_dig
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_disk_smb ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_disk_smb
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/urlize ${RPM_BUILD_ROOT}/%{nrpeplugin}/urlize

install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_disk_v2 ${RPM_BUILD_ROOT}/%{emca_plugins}/check_disk_v2
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_mem.pl ${RPM_BUILD_ROOT}/%{emca_plugins}/check_mem.pl
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_users_session ${RPM_BUILD_ROOT}/%{emca_plugins}/check_users_session
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_processes_waiting_time ${RPM_BUILD_ROOT}/%{emca_plugins}/check_processes_waiting_time
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_inode_host.pl ${RPM_BUILD_ROOT}/%{emca_plugins}/check_inode_host.pl
#install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_ldap ${RPM_BUILD_ROOT}/%{emca_plugins}/check_ldap
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/if_statistic_err ${RPM_BUILD_ROOT}/%{emca_plugins}/if_statistic_err
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_linux_stat2 ${RPM_BUILD_ROOT}/%{emca_plugins}/check_linux_stat2
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_ifstats.pl ${RPM_BUILD_ROOT}/%{emca_plugins}/check_ifstats.pl
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_emca_log ${RPM_BUILD_ROOT}/%{emca_plugins}/check_emca_log
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_log3.pl ${RPM_BUILD_ROOT}/%{emca_plugins}/check_log3.pl
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_io_stat ${RPM_BUILD_ROOT}/%{emca_plugins}/check_io_stat
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_paging_usage ${RPM_BUILD_ROOT}/%{emca_plugins}/check_paging_usage
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_memory_usage ${RPM_BUILD_ROOT}/%{emca_plugins}/check_memory_usage
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_zombie_procs ${RPM_BUILD_ROOT}/%{emca_plugins}/check_zombie_procs
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_mount ${RPM_BUILD_ROOT}/%{emca_plugins}/check_mount
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_tcp_states ${RPM_BUILD_ROOT}/%{emca_plugins}/check_tcp_states

install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/awk_arg1.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/awk_arg1.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/awk_arg2.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/awk_arg2.sh


#UPDATER
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/self_updater.pl ${RPM_BUILD_ROOT}/%{emca_plugins}/self_updater.pl


#install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_hpasm ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_hpasm
#install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_osversion.sh ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_osversion.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ntp_time ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ntp_time
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check-multipath.pl ${RPM_BUILD_ROOT}/%{nrpeplugin}/check-multipath.pl
#install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{nrpeplugin}/check_ntpd.pl ${RPM_BUILD_ROOT}/%{nrpeplugin}/check_ntpd.pl


#/custom/emca
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_if_file_exists ${RPM_BUILD_ROOT}/%{emca_plugins}/check_if_file_exists
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_mount ${RPM_BUILD_ROOT}/%{emca_plugins}/check_mount
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_io_stat_summary ${RPM_BUILD_ROOT}/%{emca_plugins}/check_io_stat_summary
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_oracle_alert_log_parser ${RPM_BUILD_ROOT}/%{emca_plugins}/check_oracle_alert_log_parser
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/autoconf.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/autoconf.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_linux_cluster.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_linux_cluster.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_ethls.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_ethls.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_ethlsif.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_ethlsif.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_ethlssys.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_ethlssys.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_rpm_version ${RPM_BUILD_ROOT}/%{emca_plugins}/check_rpm_version

install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_hostname.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_hostname.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_os_version.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_os_version.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_system_updates.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_system_updates.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_mountpoints.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_mountpoints.sh
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/%{emca_plugins}/check_apache2.sh ${RPM_BUILD_ROOT}/%{emca_plugins}/check_apache2.sh

%clean
rm -fr $RPM_BUILD_ROOT

%files
%defattr(0755, %{nsuser}, %{nsgroup})
%dir /etc/nrpe.d
/opt/plugins
/opt/plugins/nagios-plugins
/opt/plugins/custom
/opt/plugins/custom/emca
%defattr(0644,%{nsuser},%{nsgroup})
%config() /etc/nrpe.d/*.cfg
