/usr/bin/sudo systemctl stop redis
/usr/bin/sudo cp /home/isucon/webapp/php/redis_data/dump.rdb /var/lib/redis/dump.rdb
/usr/bin/sudo systemctl start redis
