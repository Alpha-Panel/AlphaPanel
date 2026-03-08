#!/bin/bash

cd /opt/backup;
. ./server_info.sh

# Phase 1: Create backup archives
sh web_backup.sh;
sh mysql_backup.sh;

# Phase 2: Upload to Google Drive via AlphaPanel
datetime=$(date '+%d-%b-%Y');
dirname="/root/backup/$datetime"

if [ -d "$dirname/websites" ]; then
    docker exec alpha_panel_web php artisan backup:upload-to-drive \
        "$dirname/websites" \
        --upload-path="$server_name/$datetime/websites" \
        --type=web --cleanup
fi

if [ -d "$dirname/mysql" ]; then
    docker exec alpha_panel_web php artisan backup:upload-to-drive \
        "$dirname/mysql" \
        --upload-path="$server_name/$datetime/mysql" \
        --type=mysql --cleanup
fi

# Phase 3: Clean old backups from Drive
docker exec alpha_panel_web php artisan backup:upload-to-drive \
    /dev/null --remove-old-backups

# Clean empty date directory
rmdir "$dirname" 2>/dev/null
