#!/bin/bash

OS=$(uname)
HN=""

if [ "${OS}" = "Linux" ] || [ "${OS}" = "AIX" ]
then
	if [ -n "${HOSTNAME}" ]
	then
		HN="$(echo ${HOSTNAME})"
	fi
fi

if [ "${HN}" != "" ]
then
	echo ${HN}
	exit 0
else
	echo "CRITICAL - Hostname cannot be determined"
	exit 2
fi
