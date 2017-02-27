#!/bin/bash

/bin/cat /proc/net/dev|/bin/grep -v '|'|/bin/awk -F ':' '{print $1}'|/bin/sed 's/ //g'|/usr/bin/tr '\n' ';'
