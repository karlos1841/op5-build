#!/bin/bash
set -e

NACOMA_PATH=/opt/monitor/op5/nacoma

cp -pf patches/op5_gui_emca.patch1 ${NACOMA_PATH}
cp -pf patches/op5_gui_emca.patch2 ${NACOMA_PATH}/classes
cp -pf nacoma/host_wizard_emca.php ${NACOMA_PATH}
cd ${NACOMA_PATH};patch -b -V numbered < op5_gui_emca.patch1
cd ${NACOMA_PATH}/classes;patch -b -V numbered < op5_gui_emca.patch2

echo "Files have been patched"
exit 0
