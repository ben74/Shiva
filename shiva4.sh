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


x=`ps -ax | grep redis-server | grep -v 'grep' | wc -l`;
if [ $x -lt 1 ]; then
    cd $pvc;# for dump.rdb in case of pod evictions
    redis-server > /dev/null  2>&1 &
    cd $pwd
fi;


x=`ps -ax | grep shiva4 | grep -v 'grep' | wc -l`;
if [ $x -lt 1 ]; then
  php -dextension=redis -dextension=openswoole shiva4-nbConnections.php p=$port reaktors=$reaktors workers=$workers needAuth=$needAuth log=$log pass=$pass  > /dev/null  2>&1 &
    #  port=2000 reaktors=1 workers=1 pass={"bob":"pass","alice":"wolf"} dmi=YmdHi salt=plopForToken
fi;

while false; do
    #x=`ps -aux | grep "0.0.0.0:$portweb" | grep -v 'grep' | wc -l`;
    #if [ $x -lt 1 ]; then
      #php -S 0.0.0.0:$portweb > /dev/null  2>&1 &
    #fi;
    # memory totals each 10 sec
    mem=`ps -ax | grep shiva4 | awk '{sum+=$5} END {print sum}'`
    redis=`ps -ax | grep redis | awk '{sum+=$5} END {print sum}'`
    total=`echo $mem $redis | awk '{ sum=$1+$2} END {print sum}'`;
    php shiva.php setmem=$total > /dev/null  2>&1 & # store into redis key
    sleep 1;
done;


# bash shiva4.sh &
# nb=3000;for((i=0;i<$nb;++i)) do php tests/max4.php $i $nb & done;
# nb=3000;for((i=0;i<$nb;++i)) do php tests/redisBlpop.php $i $nb & done;
