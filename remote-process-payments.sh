#!/bin/bash
set +e

LANFOLDER="lan05"
BASE_DIR=`dirname $0`


echo "Processing payments on $LANFOLDER.kaiserlan.at"
ssh -p 822 headshot_ftp@neu.headshot.at "/$LANFOLDER.kaiserlan.at/auto-process-payments.sh"
