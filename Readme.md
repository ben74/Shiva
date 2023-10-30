<img href='#' src='http://1.x24.fr/a/shiva1.webp?a#bird.png' style='max-width:5vw;margin:0 1rem 0.1rem 0' align='left'/> Shiva : Fast Php Message Queue Server ( as fast as Nats ? )
---
<hr>

Monitor the console for running 4000 successive clients pushing 3 messages, consuming 3 messages ( + 1 disk based ), 12000 messages then ..

> docker run -p 2000:2000 alptech/yuzu:shiva2

Then run the tests using simple php ( can also set a concurrent task limiter here ... some connections might break or retry depending on the server usage, so thats why the rate limiter is a good idea here .. )

> nb=4001;
echo '' > test.log; pk test.php;  
for((i=1;i<$nb;++i)) do php  -dextension= openswoole.so test.php host=$dockerHost port=2000 nb=$i total=$nb to=$to & done;

Using rate limiter ( better results, if it crashes or hangs, reduce 21 and 19 to lower values )


> rl=/Volumes/RAMDisk/pids/; # your ramdisk location in order to rate limit processes .. 
>
>nb=4001;  echo '' > test.log; pk test.php;find $rl -type f -delete;
>
>for((i=1;i<$nb;++i)) do
> 
>  m=$(($i % 21)); if [ $m -gt 19 ]; then n=`ls $rl*.pid 2>/dev/null | wc -l | trim`; while [ $n -gt 19 ]; do sleep 2; n=`ls $rl*.pid 2>/dev/null | wc -l | trim`;done;fi;
> 
> sleep 0.3; php  -dextension= openswoole.so test.php rl=$rl host=$dockerHost port=2000 nb=$i total=$nb to=$to &
>
> done;


envs: supAdmin=zyx, reaktors=1, workers=1, port=2000, log=0,needAuth=0,pass=0






1) <pre>docker run --name shiva --rm -p 82:82 -p 2000:2000 -e portweb=82 -e port=2000 -e reaktors=1 -e workers=1 -e pass='{"b":"c"}' -e needAuth=0 -e log=0 -e pvc=/pvc -v `pwd`:/pvc -ti docker.io/alptech/yuzu:shiva</pre>

2) Go http://192.168.99.100:82 ( your docker host ip on previously specified port and play with the websockets )

3) Please note this is a demonstrator with lots of pendings updates and optimizations
4) Go shiva2.php for server logic and tests/max2.php for the client php logic
5) If you haven't enough connections available, consider raising your file descriptor limit ;) <pre>ulimit -n 60000</pre>

<hr>

- Postulate: nothing could be as fast and memory efficient as redis with blpop or brpop for completing a simple MQ scheme
With the motivation on serving a MQ with websockets, especially for distant hosts or browser js based ones, we will never get as fast a redis, for reference ..
- Motivation: original idea came out in 2013 on a "lego" project build brick per brick without any communication between contractors, where the "Technical Expert" was billed 12000$ for 3 days setting up a ActiveMQ in a project .. with quick & dirty pretty bad configuration .. huge messages and too much connections constantly crashes the MQ, being not reliable then
- At this time I've replaced ActiveMQ with a combo of Redis and Php in order to handle messages, redis for the tiny ones, php especially for the large ones ( payloads around 100Mo ), and the Idea came back on my mind while visiting a temple in India, when a ceremony occured ..
<hr>
- Run redis performance tests

<pre>pkill -9 -f redisBlpop;pkill -9 -f redis-server;redis-server &
php tests/redisBlpop.php del
nb=3000;for((i=0;i<$nb;++i)) do php tests/redisBlpop.php $i $nb & done;#26sec
php tests/redisBlpop.php get</pre>
`
<hr>

- In order to achieve this goal, we'll need : sockets, pcntl_fork, communication between master and child processes, and especially handling the pending queues with consummers waiting for messages the right way ... tried via ratchet and swoole with the event loop then ( 300 clients is okay on a macbook pro, but further tuning is required in order to have more than 380 clients connected at the same time ), featuring

+ authentication
+ broadcasting
+ private messaging

<hr>

- Perform gine tuning using `shiva2.php reaktors=1 workers=1;`# more reactors and more workers can handle more connections, but will also be slower ..

<hr>
Dependencies : swoole, redis, listed within the dockerfile
<hr>
Runnin the tests

 - /!\ Caution when running tests with at least 3000 producers / consumers as loading the swoole extension results in significantly higher ram usage +1.2M

<pre>php -c ex/php.ini -r 'echo memory_get_usage();';# 389640
php -c ex/php.ini -dextension=swoole -dextension_dir=/usr/local/Cellar/php/8.0.12/pecl/20200930/ -r 'echo memory_get_usage();'#1564800`
</pre><hr>

<pre>pkill -9 -f 2000;pkill -9 -f 2001;pkill -9 -f redis-server;rm dump.rdb;redis-server 2>&1 >/dev/null & sleep 2 && php $x shiva2.php del;php $x shiva2.php 2000 reaktors=1 workers=1 &
rm *.log;   pkill -9 -f 3.log;echo ''>3.log;echo ''>2.log;tail -f 2.log & tail -f 3.log & php $x test/max2.php reset;
nb=300;for((i=0;i<$nb;++i)) do php test/max2.php 2000 $i $nb & done;
php test/max2.php get; #    2.669 seconds</pre>




<hr>
&copy; 2021 <a href='//alptech.dev'>Alptech</a>

<center>📫 <a href='//www.linkedin.com/in/benjaminfontaine1/#https://alpow.fr/#contact' target=a>Questions ? Just click here :)<br><img src='http://1.x24.fr/a/stardust-ban.jpg'></a></center>

![visitors](https://visitor-badge.glitch.me/badge?page_id=gh:shiva)
