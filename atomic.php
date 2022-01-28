<?php // php  -dxdebug.start_with_request=1 atomic.php;  #curl 127.0.0.1:9501
// swoole use multiple atomics, channels and tables across workers ( in order not to use redis )
// Si la référence est crée avant alors elle est partagée, sinon elle ne l'est pas !
/*
a: partager une table : références
b:
 */
$nbWorkers = 3;
$capacityMessagesPerChannel = 90;
$nbChannels = 30;
$nbAtomics = 100;
$binSize = strlen(bindec(max($nbChannels, $nbAtomics)));
$nbReferences = $nbAtomics + $nbChannels;

if (1) {
// Installation des gestionnaires de signaux -- Swoole overrides them
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, 'sig');
    pcntl_signal(SIGHUP, 'sig');
    pcntl_signal(SIGUSR1, 'sig');
}
register_shutdown_function(function () {// sigterm on manager process, does not see any of each atomic worker values
    shutdown('shutdown function');
});

$_ENV['atomics'] = ['process:server' => new Swoole\Atomic(), 'process:manager' => new Swoole\Atomic()];
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
$_ENV['rkv']->set('freeAtomicsWorkers', ['v' => json_encode(range(0, $nbWorkers - 1))]);

$_ENV['ref'] = new Swoole\Table($nbReferences);
//$_ENV['ref']->column('e', Swoole\Table::TYPE_STRING, 1);// a:atomic,c:channel
$_ENV['ref']->column('v', Swoole\Table::TYPE_INT, $binSize);// 8:256 values
$_ENV['ref']->create();


// php -dxdebug.start_with_request=1. atomic.php;

$serv = new Swoole\Server('127.0.0.1', '9501');
$serv->set(['worker_num' => $nbWorkers, 'log_file' => '/dev/null']);//worker_num -1

$serv->on('workerstart', function () {
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

$serv->on('workerstop', function () {
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
    $res = [];
    foreach ($_ENV['workerAtomics'] as $wa) {
        $res[] = $wa->get();
    }
    unset($wa);//  ps -ax | grep atomic
    //file_put_contents('atomic.log', "\n" . __line__ . ':' . json_encode($res), 8);
    $a = $_ENV['atomics']['process:manager']->get();
    echo "\nManagerStop:" . getMyPid() . ':' . $a . ':does not see the worker stats:' . json_encode($res);
});

$serv->on('start', function () {//} use ($atomics) {           //once$

    $_ENV['atomics']['process:server']->set(getMyPid());

    if (2) {
        if (!isset($_ENV['atomics'][1])) $_ENV['atomics'][1] = new Swoole\Atomic();
        $_ENV['atomics'][0]->add();
        echo "\nServer started:" . getMyPid() . ':' . $_ENV['atomics'][0]->get();
        //$serv->shutdown();
    }
});

$serv->on('stop', function () {
    shutdown('serveronstop');
});
$serv->on('shutdown', function () {
    $a = $_ENV['atomics']['process:server']->get();
    echo "\nOnShutdown:" . getmypid() . ':' . $a;
    shutdown('serveronshutdown');
});

//$serv->on('connect', function () {shutdown('servonstop');});

$serv->on('receive', function ($serv, $fd, $reactorId) {
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
    $a = $atomic->get();

    $atomicName = 'w:' . getMyPid();
    $atomicId = refg($atomicName);
    $_ENV['workerAtomics'][$atomicId]->add();

    //echo "\n" . __line__ . ':' . getMyPid() . '/' . $a;// Shared Atomic Increment -- 15 ms après :)

    if ($a % 2 == 0) {
        $msg = microtime(true);
        echo "\n" . __line__ . ':' . getMyPid() . ':pushes ' . $channelName . ' ' . $msg;
        $channel->push($msg);
    } else {
        $msg = $channel->pop();
        echo "\n" . __line__ . ':' . getMyPid() . ':consumes ' . $channelName . ' ' . $msg;
    }
    $atomic->add();
    if (is_file(__FILE__ . '.kill')) {
        echo "\n\tstopping server by " . getMyPid();
        $serv->shutdown();
    }
    //echo "\n" . __line__ . ':' . getMyPid() . ':' . count($now);
    //$serv->send($fd,"\n" __line__ . ':' . getMyPid() . ': atomic is ' . $a . ':' . $msg);
    $serv->send($fd, '.');// __line__ . ':' . getMyPid() . ': atomic is ' . $a . ':' . $msg);
    $serv->close($fd);

    /*
      php -dxdebug.start_with_request=1 atomic.php;
      pkill -9 -f atomic;rm atomic.php.kill;echo ''>atomic.log;php atomic.php | tee atomic.log &
        touch atomic.php.kill
        pkill -15 -f atomic;
      while true;do curl 127.0.0.1:9501; done;
    */
    return;


    //echo "\n" . __line__ . ':' . getMyPid() . ':' . json_encode($_ENV['atomics']);
    if (!isset($_ENV['atomics'][1])) {
        echo "\n" . __line__ . ':' . getMyPid() . ':sets';
        $_ENV['atomics'][1] = new Swoole\Atomic();
    }
    $_ENV['atomics'][0]->add(1);
    $_ENV['atomics'][1]->add(1);
    $g = $_ENV['atomics'][1]->get();
    $g2 = $_ENV['atomics'][0]->get();
    echo "\nws:" . __line__ . ':' . getMyPid() . ':' . $g . ':' . $g2;
    $serv->send($fd, "Echo to #{$fd}:" . $g);
    $serv->close($fd);
    //echo "\n" . __line__ . ':' . $atomics[0]->get();
});

$serv->on('close', function () {//$serv, $fd
    //echo "\n".__line__.':'.getMyPid().':'.json_encode($_ENV['atomics']);
    //echo "Connection closed: #{$fd}.\n";
});

$serv->start();
echo "\n" . __line__ . ':' . getmypid() . ':Server Stopped:' . $_ENV['atomics'][0]->get();
die("\n".__line__.'::dies');

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

return; ?>