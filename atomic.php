<?php // php  -dxdebug.start_with_request=1 atomic.php;  #curl 127.0.0.1:9501
// swoole use multiple atomics, channels and tables across workers ( in order not to use redis )
// Si la référence est crée avant alors elle est partagée, sinon elle ne l'est pas !
/*
a: partager une table : références
b:

Todo ; each consumer has an Atomic Bitwise :
1: free to receive
2: ready to be recycled
4: chan1
8: chan 2 etc
Algo de tri ($val%$channel)===$channel && ($val % 2 ===1 )
 */
try {
    chdir(__DIR__);
    ini_set('display_errors', 1);
    $ip = '0.0.0.0';
    $starts = microtime(true);
    $reakNum = $argv[1];
    $nbWorkers = $argv[2];
    $taskWorkers = 2;
    $capacityMessagesPerChannel = 90;
    $port = 9501;
    $nbChannels = 30;
    $nbAtomics = 100;
    $binSize = strlen(bindec(max($nbChannels, $nbAtomics)));
    $nbReferences = $nbAtomics + $nbChannels;

    if (0) {
// Installation des gestionnaires de signaux -- Swoole overrides them
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, 'sig');
        pcntl_signal(SIGHUP, 'sig');
        pcntl_signal(SIGUSR1, 'sig');
    }
    register_shutdown_function(function () {// called with die
        shutdown('shutdown function');
    });

    $_ENV['atomics'] = ['received' => new Swoole\Atomic(), 'process:server' => new Swoole\Atomic(), 'process:manager' => new Swoole\Atomic()];
    $_ENV['tables'] = $_ENV['channels'] = $_ENV['workerAtomics'] = [];
    $i = 0;
    while ($i < $nbChannels) {
        $_ENV['channels'][$i] = new Swoole\Coroutine\Channel($capacityMessagesPerChannel);
        $i++;
    }
    $i = 0;
    while ($i < $nbAtomics) {
        $_ENV['atomics'][$i] = new Swoole\Atomic();
        $i++;
    }
    $i = 0;
    while ($i < $nbWorkers) {
        $_ENV['workerAtomics'][$i] = new Swoole\Atomic();
        $i++;
    }


    $_ENV['rkv'] = new Swoole\Table($nbReferences);
    $_ENV['rkv']->column('v', Swoole\Table::TYPE_STRING, 9000);
    $_ENV['rkv']->create();
    $_ENV['rkv']->set('freeChannels', ['v' => json_encode(range(0, $nbChannels - 1))]);
    $_ENV['rkv']->set('freeAtomics', ['v' => json_encode(range(2, $nbAtomics - 1))]);
    $fc = json_encode(range(0, $nbWorkers - 1));
    _e("\n" . __line__ . '::' . $fc);
    $_ENV['rkv']->set('freeAtomicsWorkers', ['v' => $fc]);

    $_ENV['ref'] = new Swoole\Table($nbReferences);
//$_ENV['ref']->column('e', Swoole\Table::TYPE_STRING, 1);// a:atomic,c:channel
    $_ENV['ref']->column('v', Swoole\Table::TYPE_INT, $binSize);// 8:256 values
    $_ENV['ref']->create();


// php -dxdebug.start_with_request=1. atomic.php;

    $serv = new Swoole\Server($ip, $port, SWOOLE_BASE);// is not the HTTP one which has on request
    //  $serv = new Swoole\Http\Server($ip, $port); has request but not receive, not shared memory between workers
//https://openswoole.com/docs/modules/swoole-server/configuration
    $serv->set(['reactor_num' => $reakNum, 'worker_num' => $nbWorkers, 'log_file' => '/dev/null']);//worker_num -1   'daemonize' => 1, , 'task_worker_num' => $taskWorkers

    //$port2 = $serv->listen('127.0.0.1', 9502, SWOOLE_SOCK_TCP);$port2->on('receive',function($s,$fd,$rea,$data){});

    $serv->on('task', function ($server, $task_id, $reactorId, $data) {
        _e("\n" . __line__ . ':');
    });
    $serv->on('finish', function ($server, $task_id, $reactorId, $data) {
        _e("\n" . __line__ . ':');
    });
    $serv->on('workerstart', function ($server, $workerId) {
        _e("\nWS:" . __line__ . ':' . $workerId . ':' . getMyPid());
        $atomicName = 'w:' . getMyPid();
        if (!$_ENV['ref']->exists($atomicName)) {
            $fc = rkg('freeAtomicsWorkers');
            $free = array_shift($fc);
            REFS($atomicName, $free);
            RKS('freeAtomicsWorkers', $fc);
            echo "\n" . __line__ . ':StartingWorker::' . getMyPid() . '=>' . $free;
            //$now = rkg('freeAtomics');
        }

        if (!isset($_ENV['atomics'][1])) {
            echo "\nws:" . __line__ . ':' . getMyPid() . ':sets';
            $_ENV['atomics'][1] = new Swoole\Atomic();
        }
        $_ENV['atomics'][0]->add(1);
        $_ENV['atomics'][1]->add(1);
        $g = $_ENV['atomics'][1]->get();
        $g2 = $_ENV['atomics'][0]->get();
        echo ':inc:' . $g . ':' . $g2;
    });

    $serv->on('workerstop', function ($server, $workerId) {
        _e("\n" . __line__ . ':');
        $atomicName = 'w:' . getMyPid();
        $fc[] = $atomicId = refg($atomicName);
        $_ENV['ref']->del($atomicName);
        $v = $_ENV['workerAtomics'][$atomicId]->get();
        $_ENV['workerAtomics'][$atomicId]->set(0);//reset
        RKS('freeAtomicsWorkers', $fc);//Remettre libre
        //ws:97:50673 had nb jobs : 1464
        echo "\nws:" . __line__ . ':' . getMyPid() . ':arrayRef:' . $atomicId . ' had nb jobs : ';
        echo $v;
    });

    $serv->on('ManagerStart', function () {//once
        //_e("\nMs:" . __line__ . ':');
        $_ENV['atomics']['process:manager']->set(getMyPid());
        if (1) {
            $_ENV['atomics'][0]->add();
            //$g = $_ENV['atomics'][1]->get();//is null here boy ( only created on worker )
            $g2 = $_ENV['atomics'][0]->get();
            //if (!isset($_ENV['atomics'][1])) $_ENV['atomics'][1] = new Swoole\Atomic();
            echo "\nmanagerstart:" . getMyPid() . ':' . $g2;
        }
    });

    $serv->on('ManagerStop', function () {
        _e("\n" . __line__ . ':');
        $res = [];
        foreach ($_ENV['workerAtomics'] as $wa) {
            $res[] = $wa->get();
        }
        unset($wa);//  ps -ax | grep atomic
        //file_put_contents('atomic.log', "\n" . __line__ . ':' . json_encode($res), 8);
        $a = $_ENV['atomics']['process:manager']->get();
        echo "\nManagerStop:" . getMyPid() . ':' . $a . ':does not see the worker stats:' . json_encode($res);
    });

    $serv->on('start', function ($server) {//   $workerId } use ($atomics) {           //once$
        //_e("\nStarted:" . __line__ . ':');
        $_ENV['atomics']['process:server']->set(getMyPid());

        if (2) {
            if (!isset($_ENV['atomics'][1])) $_ENV['atomics'][1] = new Swoole\Atomic();
            $_ENV['atomics'][0]->add();
            echo "\nServer started:" . getMyPid() . ':' . $_ENV['atomics'][0]->get();
            //$serv->shutdown();
        }
    });

    $serv->on('stop', function () {
        _e("\n" . __line__ . ':');
        shutdown('serveronstop');
    });
    $serv->on('shutdown', function ($server, $workerId) {
        _e("\n" . __line__ . ':');
        $a = $_ENV['atomics']['process:server']->get();
        echo "\nOnShutdown:" . getmypid() . ':' . $a;
        shutdown('serveronshutdown');
    });
// memory not shared here
//$serv->on('connect', function () {shutdown('servonstop');});

    ///* Problem : hangs forever
    //$serv->on('Request', function (\Swoole\Server\Request $request, \Swoole\Server\Response $response) {
    $serv->on('request', function ($request, $response) use ($starts) {
        //_e("\n" . __line__);
        $a = $request->get['a'];
        //_e("\nRequest:has:" . __line__ . ':' . $a);

        $response->header('Content-Type', 'text/plain');
        //$response->end('.' . __line__ . ":$a\n");
        if (1) {
            //return;


            $atomicName = 'a:oddEven';
            if (!$_ENV['ref']->exists($atomicName)) {
                $fc = rkg('freeAtomics');
                REFS($atomicName, array_shift($fc));
                RKS('freeAtomics', $fc);
                echo "\n" . __line__ . ':' . getMyPid() . ':setAtomic';
                //$now = rkg('freeAtomics');
            }
            $channelName = 'c:cooking';
            if (!$_ENV['ref']->exists($channelName)) {
                $fc = rkg('freeChannels');
                REFS($channelName, array_shift($fc));
                RKS('freeChannels', $fc);
                echo "\n" . __line__ . ':' . getMyPid() . ':setChannel';
                //$now = rkg('freeChannels');
            }
            $atomicId = refg($atomicName);
            $atomic = $_ENV['atomics'][$atomicId];
            $channelId = refg($channelName);
            $channel = $_ENV['channels'][$channelId];
            $oddEven = $atomic->get();//Odd or even
            $atomic->add();

            $atomicName = 'w:' . getMyPid();
            $atomicId = refg($atomicName);
            $_ENV['workerAtomics'][$atomicId]->add();

            //echo "\n" . __line__ . ':' . getMyPid() . '/' . $a;// Shared Atomic Increment -- 15 ms après :)
            //if ($a % 2 == 0) {

            if ($a == 'total') {
                $x = "\n" . $_ENV['atomics']['received']->get() . ' messages in ' . microtime(true) - $starts;
                _e($x);
                $response->end($x);
            } elseif ($a == 'p') {
                $msg = microtime(true);
                _e("\n" . __line__ . ':' . getMyPid() . ':pushes ' . $channelName . ' ' . $msg);
                $channel->push($msg);
                $response->end('.' . __line__);
                //$serv->send($fd, '.' . __line__);
            } elseif ($a == 'c') {
                $msg = $channel->pop();
                _e("\n" . __line__ . ':' . getMyPid() . ':consumes ' . $channelName . ' ' . $msg);
                $response->end('.' . __line__);
            } else {
                _e("\n" . __line__ . ':' . getMyPid() . ':rien ');
                $response->end('.' . __line__);
            }
        }
    });
    //*/
//Swoole\Server::start(): require onReceive callback
    $serv->on('receive', function ($serv, $fd, $reactorId, $data) use ($starts) {// Pour un server standard parser
        $_ENV['atomics']['received']->add();
        _e("\nreceived:$fd;$reactorId:" . $data);
        $x = explode("\n", trim($data));
        preg_match('~/\?a=([^& ]+)~', $x[0], $m);
        if ($m[1]) {
            $a = $m[1];
            if (0) {
                $atomicName = 'a:oddEven';
                if (!$_ENV['ref']->exists($atomicName)) {
                    $fc = rkg('freeAtomics');
                    REFS($atomicName, array_shift($fc));
                    RKS('freeAtomics', $fc);
                    echo "\n" . __line__ . ':' . getMyPid() . ':setAtomic';
                    //$now = rkg('freeAtomics');
                }
                $atomicId = refg($atomicName);
                $atomic = $_ENV['atomics'][$atomicId];
                $oddEven = $atomic->get();//Odd or even
                $atomic->add();
            }

            $channelName = 'c:cooking';
            if (!$_ENV['ref']->exists($channelName)) {
                $fc = rkg('freeChannels');
                REFS($channelName, array_shift($fc));
                RKS('freeChannels', $fc);
                echo "\n" . __line__ . ':' . getMyPid() . ':setChannel';
                //$now = rkg('freeChannels');
            }

            $channelId = refg($channelName);
            $channel = $_ENV['channels'][$channelId];
            $atomicName = 'w:' . getMyPid();
            $atomicId = refg($atomicName);
            $_ENV['workerAtomics'][$atomicId]->add();

            //echo "\n" . __line__ . ':' . getMyPid() . '/' . $a;// Shared Atomic Increment -- 15 ms après :)
            //if ($a % 2 == 0) {

            if ($a == 'total') {
                $x = "\n" . $_ENV['atomics']['received']->get() . ' messages in ' . microtime(true) - $starts;
                _e($x);
                $serv->send($fd, $x);
            } elseif ($a == 'p') {
                $msg = microtime(true);
                _e("\n" . __line__ . ':' . getMyPid() . ':pushes ' . $channelName . ' ' . $msg);
                $channel->push($msg);
                $serv->send($fd, '.' . __line__);
                //$serv->send($fd, '.' . __line__);
            } elseif ($a == 'c') {
                $msg = $channel->pop();
                _e("\n" . __line__ . ':' . getMyPid() . ':consumes ' . $channelName . ' ' . $msg);
                $serv->send($fd, '.' . __line__);
            } else {
                _e("\n" . __line__ . ':' . getMyPid() . ':rien ');
                $serv->send($fd, '.rien:' . json_encode($m) . '/' . $x[0] . ':' . __line__);
            }
        } else {
            _e("\n" . __line__ . ':' . getMyPid() . ':rien ');
            $serv->send($fd, '.' . __line__);
        }


        if (is_file(__FILE__ . '.kill')) {
            echo "\n\tstopping server by " . getMyPid();
            $serv->shutdown();
        }

        $serv->close($fd);

    });
//*/
    $serv->on('close', function () {//$serv, $fd
        //echo "\n".__line__.':'.getMyPid().':'.json_encode($_ENV['atomics']);
        //echo "Connection closed: #{$fd}.\n";
    });

    $serv->start();
    echo "\n" . __line__ . ':' . getmypid() . ':Server Stopped:' . $_ENV['atomics'][0]->get();
    echo "\n" . $_ENV['atomics']['received']->get() . ' messages in ' . microtime(true) - $starts;
    die("\n" . __line__ . '::dies');
} catch (\throwable $____e) {
    _e("\n" . $____e->getMessage());
    echo $____e->getMessage();
}

function sig($signo)
{
    echo $signo;
    if (0) {
        switch ($signo) {
            case SIGTERM:
                break;
            case SIGHUP:
                // gestion du redémarrage
                break;
            case SIGUSR1:
                echo "Reçu le signe SIGUSR1...\n";
            default:
                // gestion des autres signaux
        }
    }
    return \shutdown('sig:' . $signo);
}

function shutdown($x = '')
{
    if ($x) {
        $x = "\nShutdownFun::" . $x . ':' . getmypid();
        echo $x;
        file_put_contents('atomic.log', $x, 8);
    }
    $res = [];
    foreach ($_ENV['workerAtomics'] as $wa) {//Multiple shudowns
        $res[] = $wa->get();
    }
    echo json_encode($res);
    return;
}

// rkv
function rkg($k, $table = 'rkv')
{
    $x = trim($_ENV[$table]->get($k)['v']);
    if (in_array(substr($x, 0, 1), ['{', '[']) && in_array(substr($x, -1), ['}', ']']) && $y = json_decode($x, true)) {
        return $y;
    }
    return $x;
}

function RKS($k, $v, $table = 'rkv')
{
    if (is_object($v) or is_array($v)) $v = json_encode($v);
    return $_ENV[$table]->set($k, ['v' => $v]);
}

function refg($k)
{
    return rkg($k, 'ref');
}

function REFS($k, $v)
{
    return rks($k, $v, 'ref');
}

function _e($x)
{
    return;
    echo $x;
    file_put_contents('atomic.log', $x, 8);
}

return; ?>

1117 messages in 11 seconds
2857 messages in 30.921313047409

swoole reactor_num and multiple workers


php -dxdebug.start_with_request=1 atomic.php;
( i=0;while [ $i -lt 25 ];do let "i=i+1"; echo "ya:$i" & let "i=i-1" & echo "yp:$i"; done; ) & pid0=$!

#decorellation :: https://stackoverflow.com/questions/31884052/how-to-increment-a-variable-in-parallelized-bash-script
# SALVE de 25 puis test et sleep


touch atomic.php.kill;kill -9 $pid1;kill -9 $pid2;pkill -9 -f curl;         pkill -9 -f atomic;  rm atomic.php.kill;echo ''>atomic.log;      php -dxdebug.mode=off atomic.php 1 1 &
( i=0;while true;do while [ $i -gt 25 ]; do i=$(ps -ax | grep curlpush | grep -v grep | wc -l ); if  [ $i -gt 25 ]; then sleep 1;fi; done; let "i=i+1";curl -ks 'http://127.0.0.1:9501/?a=p&curlpushatomic' >/dev/null &  done; ) & pid1=$!
( i=0;while true;do while [ $i -gt 25 ]; do  i=$(ps -ax | grep curlpush | grep -v grep | wc -l ); if  [ $i -gt 25 ]; then sleep 1;fi; done; let "i=i+1";curl -ks 'http://127.0.0.1:9501/?a=c&curlconsatomic' >/dev/null &  done; ) & pid2=$!
tail -f atomic.log


curl 'http://127.0.0.1:9501/?a=total'


set pid1 $pid1;set pid2 $pid2;echo "$pid1 $pid2";


while true;do curl -ks '127.0.0.1:9501/?a=p&curlpushatomic'; curl -ks '127.0.0.1:9501/?a=p&curlconsatomic'; done; #simple getalternative


curl -k http://127.0.0.1:9501/?a=p
curl -k http://127.0.0.1:9501/?a=c


f59f6783


curl 'http://127.0.0.1:9501/?a=total'
2 reaktors, 2 workers
424 messages in 25.77
983 messages in 65

1r1w
1045 messages in 57
1757 messages in 100 then freezed
2083 messages in 205

redis:nginx:php-fpm
3251,3249 :: I/Os in :70s

1r1w huge common stress
( 67 curl )
313 messages in 2.34
1981 messages in 10
4059 messages in 20 s
5883 messages in 33 sec
7712 messages in 37.29
10431 messages in 48.55
15008 messages in 77
16803 messages in 100s

vs nginx/fpm/opcache tests ( mettre plus de workers php-fpm ? ) : 3779,3675 :: I/Os in :60sec


touch atomic.php.kill;kill -9 $pid1;kill -9 $pid2;pkill -9 -f curl;         pkill -9 -f atomic;  rm atomic.php.kill;echo ''>atomic.log;#Arret d'urgence