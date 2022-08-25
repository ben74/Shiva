echo ''>res.log;
tail -f res.log &

php websocket-server.php p=80 reaktors=1 workers=1 backlog=300 > res.log &
exitCode=69; while [ $exitCode == 69 ]; do php stressTest.php connects 127.0.0.1 80;exitCode=$?; done;
echo $exitCode;

#sleep 2;#init
ho='127.0.0.1';po=80;nb=300; pkill -9 -f stressTest.php;   for((i=1;i<$nb;++i)) do ( exitCode=69; while [ $exitCode == 69 ]; do php stressTest.php $po $i $nb $ho >> res.log & pid=$!;wait $pid;exitCode=$?; done;  ) & done;

tail -f /dev/null;
#never ends