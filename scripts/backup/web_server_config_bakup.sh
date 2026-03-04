#!/bin/bash

. ./server_info.sh

datetime=$(date '+%d-%b-%Y');

current_directory=`pwd`

dirname="/backup/$datetime"

mkdir -p $dirname/webserver

cd $dirname/webserver

tar -cvzf apache.tar.gz /etc/apache2
tar -cvzf nginx.tar.gz /etc/nginx
tar -cvzf php.tar.gz /etc/php
tar -cvzf scripts.tar.gz /root/scripts

cd $current_directory
python3 drivebackup.py --upload_path $server_name/$datetime/webserver-config --local_path $dirname/webserver --local_auth
sleep 3
rm -rf $dirname/webserver