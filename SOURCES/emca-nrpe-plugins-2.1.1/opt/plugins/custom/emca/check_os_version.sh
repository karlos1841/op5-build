#!/bin/bash

OS=$(uname)
MARCH=$(uname -m)
DIST=""

if [ "${OS}" = "AIX" ]
then
	DIST="${OS} $(oslevel) ($(oslevel -r))"
elif [ "${OS}" = "Linux" ]
then
	hash lsb_release > /dev/null 2>&1
	if [ $? -eq 0 ]
	then
		DIST="$($(hash -t lsb_release) -sd | tr -d "\"") ${MARCH}"
	else
		if [ -f /etc/redhat-release ]
		then
			DIST="$(cat /etc/redhat-release) ${MARCH}"
		elif [ -f /etc/SuSE-release ]
		then
			DIST="$(cat /etc/SuSE-release) ${MARCH}"
		fi
	fi
fi

if [ "${DIST}" != "" ]
then
	echo ${DIST}
	exit 0
else
	echo "CRITICAL - OS version cannot be determined"
	exit 2
fi
