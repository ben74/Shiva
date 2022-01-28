#!/bin/bash

pwd=`pwd`;
pvc="${pvc:-$pwd}"
log="${log:-0}"
port="${port:-2000}"
portweb="${portweb:-82}"

workers="${workers:-1}"
reaktors="${reaktors:-1}"
needAuth="${needAuth:-0}"

#pvc=$_ENV['pvc']
#keep services on line even if a crash ocrrus
while true; do

    x=`ps -aux | grep redis-server | grep -v 'grep' | wc -l`;
    if [ $x -lt 1 ]; then
        cd $pvc;# for dump.rdb in case of pod evictions
        redis-server > /dev/null  2>&1 &
        cd $pwd
    fi;

    x=`ps -aux | grep shiva2.php | grep -v 'grep' | wc -l`;
    if [ $x -lt 1 ]; then
        #  port=2000 reaktors=1 workers=1 pass={"bob":"pass","alice":"wolf"} dmi=YmdHi salt=plopForToken
        php -dextension=redis -dextension=openswoole shiva2.php p=$port reaktors=$reaktors workers=$workers needAuth=$needAuth log=$log pass=$pass  > /dev/null  2>&1 &
    fi;

    x=`ps -aux | grep "0.0.0.0:$portweb" | grep -v 'grep' | wc -l`;
    if [ $x -lt 1 ]; then
      php -S 0.0.0.0:$portweb > /dev/null  2>&1 &
    fi;


    # memory totals
    mem=`ps -aux | grep shiva2 | awk '{sum+=$5} END {print sum}'`
    redis=`ps -aux | grep redis | awk '{sum+=$5} END {print sum}'`
    total=`echo $mem $redis | awk '{ sum=$1+$2} END {print sum}'`;
    php shiva.php setmem=$total > /dev/null  2>&1 & # store into redis key

    sleep 10;
done;
