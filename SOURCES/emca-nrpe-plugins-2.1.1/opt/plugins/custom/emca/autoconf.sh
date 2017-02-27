#!/bin/bash
wget --no-check-certificate -q https://10.170.6.162/repo/linux/nrpe.conf -O /etc/nrpe.conf && NRPE_CONF="nrpe.conf fetched"
wget --no-check-certificate -q https://10.170.6.162/repo/linux/newest.tar.gz -O /etc/nrpe.d/newest.tar.gz && NRPE_D="nrpe.d fetched"
cd /etc/nrpe.d/
tar -xzf newest.tar.gz && UNPACK="configuration unpacked"
rm newest.tar.gz
echo $NRPE_CONF, $NRPE_D, $UNPACK, reloading
kill -s SIGHUP `pidof nrpe`
