#!/bin/bash

#deprecated

. ./server_info.sh
datetime=$(date '+%d-%b-%Y');


dirname="/root/backup/$datetime"

#mongodb yedekleri
mkdir -p $dirname/mongodb
#cd $dirname/mongodb
DATABASES=$(mongosh --host $mongodb_host --port $mongodb_port -u $mongodb_username -p $mongodb_password --authenticationDatabase "admin" --quiet --eval "db.adminCommand('listDatabases').databases.map(db => db.name).join(' ')")

# Her bir veritabanını yedekle
for DB in $DATABASES; do
    echo "Yedekleniyor: $DB"
    mongodump --host $mongodb_host --port $mongodb_port -u $mongodb_username -p $mongodb_password --authenticationDatabase "admin" --db $DB --out $dirname/mongodb
    tar -cvzf $dirname/mongodb/$DB.tar.gz $dirname/mongodb/$DB
    rm -rf $dirname/mongodb/$DB
done

python3 drivebackup.py --upload_path $server_name/$datetime/mongodb --local_path $dirname/mongodb --local_auth
sleep 3
rm -rf $dirname/mongodb