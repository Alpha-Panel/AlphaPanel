#!/bin/bash

. ./server_info.sh

datetime=$(date '+%d-%b-%Y');

dirname="/root/backup/$datetime"

current_directory=`pwd`

mkdir -p $dirname/mysql

cd $dirname/mysql

echo "show databases;" | mysql --host $mysql_host -u $mysql_username -p"$mysql_password" |egrep -vi $mysql_excepts > databaseslist.txt
while read line
do
    mysqldump --host $mysql_host -u $mysql_username -p"$mysql_password" $line > $line.sql
		tar -cvzf $line.tar.gz $line.sql
		rm -f $line.sql
done <databaseslist.txt
rm -f databaseslist.txt

cd $current_directory
# Upload handled separately by alpha_panel_web artisan command