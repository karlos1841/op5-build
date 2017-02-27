#!/bin/bash

OS=$(uname)
NUM=""

if [ "${OS}" = "Linux" ]
then
	hash zypper > /dev/null 2>&1
	if [ $? -eq 0 ]
	then
		NUM="$(zypper --no-refresh pa | grep -Ec 'openSUSE|Updates')"
		if [ $? -ne 0 ]
		then
			NUM=""
		fi
	else
		hash yum > /dev/null 2>&1
		if [ $? -eq 0 ]
		then
			NUM="$(yum list updates -q | grep -cv "Updated Packages")"
			if [ $? -ne 0 ]
			then
				NUM=""
			fi
		fi
	fi
fi

if [ "${NUM}" != "" ]
then
	if [ ${NUM} -eq 0 ]
	then
		echo "There are no updates available"
		exit 0
	else
		echo "Number of available updates: ${NUM}"
		exit 1
	fi
else
	echo "CRITICAL - Number of available updates cannot be retrieved"
	exit 2
fi
