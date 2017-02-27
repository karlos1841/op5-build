#!/bin/bash

NRPE_VERSION="3.0.1"
LOCAL_DIR_PATH="${HOME}/rpmbuild"
REMOTE_DIR_PATH="/root/rpmbuild"
CONFIG="${REMOTE_DIR_PATH}/SOURCES/nrpe-${NRPE_VERSION}/etc/nrpe.conf"
OUTPUT_DIR="."
EXIT=0

if [ "$(hostname)" != "aligator" ]
then
	echo "ERROR: The script must be executed inside aligator"
	exit 1
fi

if [ ! -d ${LOCAL_DIR_PATH} ]
then
	echo "ERROR: Build directory cannot be found."
	echo "It should reside here: ${LOCAL_DIR_PATH}"
	exit 1
fi

if [ $# -eq 0 ]
then
        echo -e "Skladnia: ${0} REMOTE_HOST1 REMOTE_HOST2 ...\n\
Skrypt generuje paczki na wymienionych hostach i kopiuje je do katalogu \"${OUTPUT_DIR}\".\n"
	exit 1
fi

if [ -f $CONFIG ]
then
	while true
	do
		echo "Wybierz plik nrpe.conf: "
		echo "
		1) ENEA
		2) VIG
		3) Tauron
		4) UFG
		5) GPW
		6) VW
		7) Custom config file
		"
		echo -n ": "
		read choice
		case $choice in
		"1")
			echo "ENEA"
			cp ${CONFIG}.enea ${CONFIG}
			break
			;;
		"2")
			echo "VIG"
			cp ${CONFIG}.vig ${CONFIG}
			break
			;;
		"3")
			echo "Tauron"
			cp ${CONFIG}.tauron ${CONFIG}
			break
			;;
		"4")
			echo "UFG"
			cp ${CONFIG}.ufg ${CONFIG}
			break
			;;
		"5")
			echo "GPW"
			cp ${CONFIG}.gpw ${CONFIG}
			break
			;;
		"6")
			echo "VW"
			cp ${CONFIG}.vw ${CONFIG}
			break
			;;
		"7")
			echo "Custom config file"
			cp ${CONFIG}.custom ${CONFIG}
			break
			;;
		*)
			;;
		esac
	done
else
	echo "${CONFIG} does not exist!"
	exit 1
fi

echo -n "Podbic wersje? <y, N>: "
read choice
if [ "${choice}" = "y" ] || [ "${choice}" = "Y" ]
then
	line=$(grep ${LOCAL_DIR_PATH}/SPECS/emca-nrpe-plugins-2.1.1.spec -e "^%define release")
	release=$(echo ${line} | awk '{print $NF}')
	num=$(expr ${release} + 1)
	sed -i "s/${line}/%define release ${num}/g" ${LOCAL_DIR_PATH}/SPECS/emca-nrpe-plugins-2.1.1.spec
	sed -i "s/${line}/%define release ${num}/g" ${LOCAL_DIR_PATH}/SPECS/nrpe-${NRPE_VERSION}.spec

	sed -i "s/command\[version\]=echo 2.1.1.${release}/command\[version\]=echo 2.1.1.${num}/g" ${LOCAL_DIR_PATH}/SOURCES/emca-nrpe-plugins-2.1.1/etc/nrpe.d/linux.cfg
	echo "Podbito wersje do ${num}"
fi

while [ $# -gt 0 ]
do
	echo "Connecting to ${1}"
	if [ ! -f ${HOME}/.ssh/id_rsa ]
	then
		ssh-keygen -t rsa
	fi

	ssh-copy-id -i ${HOME}/.ssh/id_rsa.pub root@${1} 1> /dev/null
	if [ ${?} -ne 0 ]
        then
                echo "ERROR connecting to ${1}"
		exit 1
	else
		ssh root@${1} uname | grep -q "Linux"
		if [ ${?} -ne 0 ]
		then
			echo "Only Linux is supported"
			EXIT=1
			break
		fi

		ssh root@${1} uname -m | grep -q "i686"
		if [ ${?} -eq 0 ]
		then
			new_arch="i686"
		else
			ssh root@${1} uname -m | grep -q "x86_64"
			if [ ${?} -eq 0 ]
			then
				new_arch="x86_64"
			fi
		fi
		echo "Building for ${new_arch} architecture"
		line2=$(grep /root/rpmbuild/SPECS/emca-nrpe-plugins-2.1.1.spec -e "^BuildArch:")
		arch=$(echo ${line2} | awk '{print $NF}')
		sed -i "s/${line2}/BuildArch: ${new_arch}/g" ${LOCAL_DIR_PATH}/SPECS/emca-nrpe-plugins-2.1.1.spec
		sed -i "s/${line2}/BuildArch: ${new_arch}/g" ${LOCAL_DIR_PATH}/SPECS/nrpe-${NRPE_VERSION}.spec

		cd ${LOCAL_DIR_PATH}/SOURCES
		tar -cvf emca-nrpe-plugins-2.1.1.tar emca-nrpe-plugins-2.1.1 1> /dev/null
		cd ${LOCAL_DIR_PATH}
		ssh root@${1} rm -rf ${REMOTE_DIR_PATH}
		scp -r ${LOCAL_DIR_PATH} root@${1}: 1> /dev/null
        fi

	echo "Generating rpm package"
	DIST=$(ssh root@${1} grep '^ID=' /etc/os-release 2> /dev/null | cut -d '=' -f 2)
	VERSION=$(ssh root@${1} grep '^VERSION_ID=' /etc/os-release 2> /dev/null | cut -d '=' -f 2)
	if [[ $DIST =~ "sles" ]] && [[ $VERSION =~ "11" ]]
	then
		echo -e "%packager EM&CA S.A. <op5@emca.pl>\n\
                %dist .sles11\n\
                %_topdir ${REMOTE_DIR_PATH}\n\
                %_tmppath %{_topdir}/tmp\n\
                %_signature gpg\n\
                %_gpgpath /root/.gnupg\n\
                %_gpg_name emcabuild <op5@emca.pl>\n\
                %_gpgbin /usr/bin/gpg" > .rpmmacros

		scp .rpmmacros root@${1}:
		ssh root@${1} rpmbuild -ba --clean ${REMOTE_DIR_PATH}/SPECS/emca-nrpe-plugins-2.1.1.spec
		ssh root@${1} rpmbuild -ba --clean ${REMOTE_DIR_PATH}/SPECS/nrpe-${NRPE_VERSION}.spec
	elif [[ $DIST =~ "sles" ]] && [[ $VERSION =~ "12" ]]
	then
		echo -e "%packager EM&CA S.A. <op5@emca.pl>\n\
		%dist .sles12\n\
		%_topdir ${REMOTE_DIR_PATH}\n\
		%_tmppath %{_topdir}/tmp\n\
		%_signature gpg\n\
		%_gpgpath /root/.gnupg\n\
		%_gpg_name emcabuild <op5@emca.pl>\n\
		%_gpgbin /usr/bin/gpg" > .rpmmacros

		scp .rpmmacros root@${1}:
		ssh root@${1} rpmbuild -ba --clean ${REMOTE_DIR_PATH}/SPECS/emca-nrpe-plugins-2.1.1.spec
		ssh root@${1} rpmbuild -ba --clean ${REMOTE_DIR_PATH}/SPECS/nrpe-${NRPE_VERSION}.spec
	else
		echo -e "%packager EM&CA S.A. <op5@emca.pl>\n\
               	%_topdir ${REMOTE_DIR_PATH}\n\
               	%_tmppath %{_topdir}/tmp\n\
               	%_signature gpg\n\
               	%_gpgpath /root/.gnupg\n\
               	%_gpg_name emcabuild <op5@emca.pl>\n\
               	%_gpgbin /usr/bin/gpg" > .rpmmacros

		scp .rpmmacros root@${1}:
		ssh root@${1} rpmbuild -ba --clean ${REMOTE_DIR_PATH}/SPECS/emca-nrpe-plugins-2.1.1.spec
		ssh root@${1} rpmbuild -ba --clean ${REMOTE_DIR_PATH}/SPECS/nrpe-${NRPE_VERSION}.spec
	fi

	if [ ${?} -ne 0 ]
	then
		echo "An ERROR occurred"
		exit 1
	else
		scp -r root@${1}:${REMOTE_DIR_PATH}/RPMS ${OUTPUT_DIR} 1> /dev/null
		echo "The package has been successfully generated and placed in ${OUTPUT_DIR}"
        fi
shift
done


#ssh root@${1} uname | grep -q "AIX"
#if [ ${?} -eq 0 ]
#then
#	echo "Generating package for AIX"
#	ssh root@${1} tar cvf /nrpe_aix.tauron_v1.4.tar /opt/plugins/ /etc/nrpe.d/ /etc/nrpe.conf /usr/sbin/nrpe /etc/rc.d/init.d/nrpe.sh /etc/rc.d/rc2.d/Snrpe
#	scp root@${1}:/nrpe_aix.tauron_v1.4.tar ${OUTPUT_DIR}
#fi
