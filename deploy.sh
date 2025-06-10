#!/bin/bash
set +e

# deploy klms
# ./deploy.sh

LANFOLDER="lan03"
BASE_DIR=`dirname $0`

# echo "Build prod assets"
# npm run build

echo "Push data"
rsync -avzh --exclude-from=".deployignore" --delete * -e "ssh -p 822" headshot_ftp@neu.headshot.at:/$LANFOLDER.kaiserlan.at/

echo "Rsynced data, clearing cache"
ssh -p 822 headshot_ftp@neu.headshot.at "rm -rf /$LANFOLDER.kaiserlan.at/var/cache/* && /.phpenv/versions/8.3/bin/php /$LANFOLDER.kaiserlan.at/bin/console doctrine:schema:update --force --complete"

# commands
# bash-4.4$ /.phpenv/versions/8.3/bin/php bin/console cache:clear
# /.phpenv/versions/8.3/bin/php bin/console doctrine:schema:update --force --complete