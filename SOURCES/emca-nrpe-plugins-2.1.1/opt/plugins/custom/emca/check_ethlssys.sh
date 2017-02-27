#!/bin/bash

/bin/ls /sys/class/net/|/usr/bin/tr '  ' ';' |/usr/bin/tr '\n' ';'
