#!/bin/bash
export PATH=$PATH:/usr/sbin:/sbin
CMD="clustat"

#CMD="cat sowewire1.txt"
if [ "x$1" = "xmember" ]; then

RESULT=`$CMD |grep 'Member Status:'|awk '{print $3}'`
if [ "x$RESULT" = "x" ]; then
echo "UNKNOWN - no output from $CMD"
exit 3;
fi;
    if [ "x$RESULT" = "xQuorate" ]; then
       echo "OK Member Status Quorate";
       exit 0;
    else if [ "x$RESULT" = "xInquorate" ]; then
       echo "CRITICAL Member Status Inquorate";
       exit 2;
    else
       print "Unknown Member Status $RESULT";
       exit 3;
    fi
    fi


fi

if [ "x$1" = "xnodes" ]; then
CLUSTAT=`$CMD`;

if [ "x$CLUSTAT" = "x" ]; then
echo "UNKNOWN - no output from $CMD"
exit 3;
fi
FIRST=`echo "$CLUSTAT"|nl|grep "Member Name"|awk '{print $1}'`;
LAST=`echo "$CLUSTAT"|nl|grep "Service Name"|awk '{print $1}'`;
NUMBER=$(($LAST-$FIRST-3));
LAST=$(($LAST-2));
SERVICES=`echo "$CLUSTAT"|sed '/^$/d'|head -$LAST|tail -$NUMBER`;
OFFLINE=`echo "$SERVICES"|grep Offline`;
INACTIVE=`echo "$SERVICES"|grep Inactive`;
ONLINE=`echo "$SERVICES"|grep Online`;
if [ "$SERVICES" = "$ONLINE" ]; then
   echo "OK All nodes are online
$SERVICES";
   exit 0;
fi
if [ "x$OFFLINE" != "x" ]; then
OFFLINE=`echo "$OFFLINE"|awk '{print $1}'|tr '\n' ','|sed s/,$//`;
echo "CRITICAL nodes: $OFFLINE are offline
$SERVICES";

   exit 2;
fi
if [ "x$INACTIVE" != "x" ]; then
INACTIVE=`echo "$INACTIVE"|awk '{print $1}'|tr '\n' ','|sed s/,$//`;
echo "WARNING nodes: $INACTIVE are inactive
$SERVICES";
   exit 1;
fi
echo "UNHANDLED SITUATION"
exit 3;
fi

if [ "x$1" = "xservices" ]; then

SERVICES=`$CMD|grep "service:"|sed s/'service:'//`;
if [ "x$SERVICES" = "x" ]; then
echo "UNKNOWN - no output from $CMD"
exit 3;
fi
I=0;
NUMBER=0;
STARTED=0;
STARTED_LIST="";
FAILED=0;
FAILED_LIST="";
PENDING=0;
PENDING_LIST="";
DISABLED=0;
DISABLED_LIST="";
STOPPED=0;
STOPPED_LIST="";
for service in $SERVICES; do
I=$(($I+1));
case $(($I%3)) in
1)
SERVICE=$service
;;
2)
;;
0)
NUMBER=$(($NUMBER+1));
  case $service in
  "started")
STARTED=$(($STARTED+1));
STARTED_LIST="$STARTED_LIST $SERVICE";
  ;;
  "failed")
FAILED=$(($FAILED+1));
FAILED_LIST="$FAILED_LIST $SERVICE";
  ;;
  "pending")
PENDING=$(($PENDING+1));
PENDING_LIST="$PENDING_LIST $SERVICE";
  ;;
  "disabled")
DISABLED=$(($DISABLED+1));
DISABLED_LIST="$DISABLED_LIST $SERVICE";
  ;;
  "stopped")
STOPPED=$(($STOPPED+1));
STOPPED_LIST="$STOPPED_LIST $SERVICE";
  ;;
  *)
  echo "Unknown state: $service";
  exit 3;
  ;;
  esac
;;
esac
done

if [ $NUMBER -eq $STARTED ]; then
   echo "OK All services are started - $STARTED_LIST";
   exit 0;
fi
if [ $FAILED -gt 0 ]; then
   echo "CRITICAL $FAILED services are failed - $FAILED_LIST,
pending - $PENDING_LIST, disabled - $DISABLED_LIST, stopped - $STOPPED_LIST";
   exit 2;
fi
WARNING=$(($PENDING+$DISABLED+$STOPPED));
if [ $WARNING -gt 0 ]; then
    echo "WARNING $WARNING problems: pending - $PENDING_LIST, disabled - $DISABLED_LIST, stopped - $STOPPED_LIST";
    exit 1;
fi
echo "UNHANDLED SITUATION";
exit 3;
fi

echo "USAGE: $0 mode
mode in member, nodes, services";
