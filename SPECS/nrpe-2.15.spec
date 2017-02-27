%define myname nrpe
%define version 2.15
%define release 19
%define nsuser op5
%define gecos "Monitoring OP5"
%define nsUID 701
%define nsgroup op5
%define nsGID 701
%define nsport 5666
%define debug_package %{nil}

Summary: Host/Service/Network monitoring agent for op5Monitor
URL: http://it.emca.pl
Name: %{myname}
Version: %{version}
Release: %{release}%{dist}
Distribution: %{dist}
License: GPL
Group: Application/System
Source: %{myname}-%{version}.tar.gz
BuildRoot: %{_tmppath}/%{myname}-buildroot
BuildArch: x86_64
Summary: Emca plugins for nrpe agent
# no need to add openssl dependency as we compile the package with --disable-ssl flag
#Requires: openssl >= 0.9.8
AutoReqProv: no
%description
Agent NRPE do not blame with emca plugins

%prep
%setup -q -n %{myname}-%{version}

%build
cd ${RPM_SOURCE_DIR}/%{myname}-%{version}
./configure --enable-command-args --disable-ssl
make all
cp ${RPM_SOURCE_DIR}/%{myname}-%{version}/src/nrpe ${RPM_SOURCE_DIR}/%{myname}-%{version}/src/nrpe-no-ssl

./configure --enable-command-args --enable-ssl
make all

%install
[ "$RPM_BUILD_ROOT" != "/" ] && rm -rf $RPM_BUILD_ROOT
install -d -m 0755 ${RPM_BUILD_ROOT}/etc/init.d
install -d -m 0755 ${RPM_BUILD_ROOT}/usr/sbin

install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/src/nrpe-no-ssl ${RPM_BUILD_ROOT}/usr/sbin/nrpe-no-ssl
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/src/nrpe ${RPM_BUILD_ROOT}/usr/sbin/nrpe
install -m 0755 ${RPM_SOURCE_DIR}/%{myname}-%{version}/etc/init.d/nrpe ${RPM_BUILD_ROOT}/etc/init.d/nrpe
install -m 0644 ${RPM_SOURCE_DIR}/%{myname}-%{version}/etc/nrpe.conf ${RPM_BUILD_ROOT}/etc/nrpe.conf

%pre
if grep ^op5: /etc/group; then
        : # group already exist
else
        /usr/sbin/groupadd -g %{nsGID} %{nsgroup} || %nnmmsg Unexpected error adding group "%{nsgroup}". Aborting install process.
fi
# create op5 user account
if grep ^op5: /etc/passwd; then
        : # user account exist
else
        /usr/sbin/useradd -r -g %{nsgroup} -u %{nsUID} -s /sbin/nologin -c %{gecos} %{nsuser} || %nnmmsg Unexpected error adding user "%{nsuser}". Abort install process.
fi


if grep ^nrpe /etc/services; then
        : # service entry exist
else    # add service entry
        echo -e "nrpe\t5666/tcp\t" >> /etc/services
fi
%post
rpm -q openssl > /dev/null 2>&1
if [ $? -ne 0 ]
then
        cp /usr/sbin/nrpe-no-ssl /usr/sbin/nrpe
fi

/sbin/chkconfig --add nrpe
%preun
if [ $1 -eq 0 ]; then
        /sbin/service nrpe stop >/dev/null 2>&1
        /sbin/chkconfig --del nrpe
        /usr/sbin/userdel %{nsuser} >/dev/null 2>&1
        /bin/sed -i '/nrpe/d' /etc/services >/dev/null 2>&1

fi
%postun
if [ $1 -eq 0 ]; then
        /sbin/service nrpe condrestart >/dev/null 2>&1
fi

if [ $1 -eq 1 ]; then
	
	if grep ^op5: /etc/group; then
        	: # group already exist
	else
        	/usr/sbin/groupadd -g %{nsGID} %{nsgroup} || %nnmmsg Unexpected error adding group "%{nsgroup}". Aborting install process.
	fi
	# create op5 user account
	if grep ^op5: /etc/passwd; then
        	: # user account exist
	else
        	/usr/sbin/useradd -r -g %{nsgroup} -u %{nsUID} -s /sbin/nologin -c %{gecos} %{nsuser} || %nnmmsg Unexpected error adding user "%{nsuser}". Abort install process.
	fi


	if grep ^nrpe /etc/services; then
        	: # service entry exist
	else    # add service entry
        	echo -e "nrpe\t5666/tcp\t" >> /etc/services
	fi
fi

%clean
rm -fr $RPM_BUILD_ROOT

%files
%defattr(0755, root, root)
/etc/init.d/nrpe
/usr/sbin/nrpe
/usr/sbin/nrpe-no-ssl
%defattr(0755, root, root)
%defattr(0644,%{nsuser},%{nsgroup})
%config(noreplace) /etc/nrpe.conf

