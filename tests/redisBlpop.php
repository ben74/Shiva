<?php
// blpop
$r=false;
while(!$r){
    try {
        $_ENV['r'] = $r = new \Redis();
        $r->connect('127.0.0.1', 6379);
    } catch (\throwable $e) {
        $r = false;
    }
    if(!$r)sleep(1);
}

if($argv[1]=='get'){
    echo "\t\t\tTook:".$r->get('end')-$r->get('starts')." with ".$r->get('clients')." clients===\t\t";die;
}

if($argv[1]=='del'){// 2.8 secondes pour compléter le Scénario 300 clients
    echo "\t\t\tTook:".$r->get('end')-$r->get('starts')." with ".$r->get('clients')." clients===\t\t";
    $k=$r->keys('*');foreach($k as $key){$r->del($key);}die("delete\n");return;
}

$nb=$argv[1];
$total=$argv[2];

$topics = ['yo', 'ya', 'ye'];
$nbTopics=count($topics);
$each=$total/$nbTopics;
$push=floor($nb/$each);
$pushes=$topics[$push];
$push++;
if($push>($nbTopics-1))$push=0;
$receives=$topics[$push];

if(!$r->exists('starts'))$r->set('starts',microtime(true));
$r->incr('clients');
$r->rpush('pending:'.$pushes,time());
echo"\n".getMyPid().':'.json_encode($r->blpop('pending:'.$receives,99999999));//['key,content]
$r->decr('clients');
$r->set('end',microtime(true));

return;?>
cd $m/hermes;pkill -9 -f redis-server;redis-server &
php test-redisBlpop.php del
nb=300;for((i=0;i<$nb;++i)) do php test-redisBlpop.php $i $nb & done;# 2.8 sec
nb=1200;for((i=0;i<$nb;++i)) do php test-redisBlpop.php $i $nb & done;# 11sec
nb=9000;for((i=0;i<$nb;++i)) do php test-redisBlpop.php $i $nb & done;# 95 sec
php test-redisBlpop.php del

ps -ax | grep redisBl
