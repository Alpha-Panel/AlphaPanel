#!/bin/bash

. ./server_info.sh

datetime=$(date '+%d-%b-%Y');

current_directory=`pwd`

dirname="/root/backup/$datetime"

mkdir -p $dirname/websites

cd $dirname/websites

ls $websites_path | egrep -vi 'virtfs|ubuntu|.cpan|.cpcpan|0_README_BEFORE_DELETING_VIRTFS' > websites.txt

while read wline
do
		tar -cvzf $wline.tar.gz $websites_path/$wline
done <websites.txt
rm -f websites.txt

cd $current_directory
# Upload handled separately by alpha_panel_web artisan command