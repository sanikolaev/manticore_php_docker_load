# dockerized load_json.php and load_sql.php

## How to use
```
git clone https://github.com/sanikolaev/manticore_php_docker_load.git
cd manticore_php_docker_load/
docker-compose pull
docker-compose up manticoresearch
```
In another tab:
```
mysql -P19306 -h0 -e "drop table user" # may fail, it's ok
docker-compose build load && docker-compose up load
mysql -P19306 -h0 -e "select count(*) from user" # should give 1000000
```
