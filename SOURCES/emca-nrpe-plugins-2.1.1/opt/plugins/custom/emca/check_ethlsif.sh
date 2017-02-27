#!/bin/bash



result=$(ifconfig|grep 'Link encap'|awk '{print $1}'|tr '\n' ';')
echo $result
