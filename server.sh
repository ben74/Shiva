#!/bin/bash

#pwd=`pwd`;
port="${port:-2000}"
log="${log:-0}"
pass="${pass:-0}"
reaktors="${reaktors:-1}"
workers="${workers:-1}"
needAuth="${needAuth:-0}"
supAdmin="${supAdmin:-zyx}"

#PHP_INI_SCAN_DIR="/dev/null" nice --20 php -c null.ini  -dextension=/usr/local/Cellar/php/8.2.12/pecl/20220829/openswoole.so
php -dmemory_limit=-1 server.php port=$port reaktors=$reaktors workers=$workers needAuth=$needAuth log=$log pass=$pass supAdmin=$supAdmin;#


