<?php
/*
 Coroutine http client
// ps -ax|grep max4|grep -v grep|wc -l
pk max6; nb=4; for((i=0;i<$nb;++i)) do php $bf/Shiva/tests/max6.php $spo $i $nb & done;
 */
chdir(__dir__);
require_once '../common.php';
ini_set('display_errors', 1);
$cli = $_ENV['cli'] = 0;

use Swoole\WebSocket\Frame;
use Swoole\Coroutine\Http\Client;
// todo ::: Not coroutine -> lightweighter client ?
use function Swoole\Coroutine\Run;

$pushAndPull = ['ya' => 'yo', 'yi' => 'yu', 'yo' => 'yi', 'yu' => 'ya'];
$pushes1=array_keys($pushAndPull);
$consumes1=array_values($pushAndPull);
$nbTopics = count($pushAndPull);
$heartbeatsEachNSeconds=30;

$to = 60;//read timeout does the whole difference

$pid = getmypid();
$expectedRepliesNb=$errorExitCode = 69;
$okExitCode = 1;
$tentative = $hearbeats = $nb = $total = $fini = $finalOk=$b=$waits=$exit = $e = $tentative = $nbReplies=$cli=0;
$log=[];

if('variables'){
    $host = '127.0.0.1';
    $port = 2000;
    $nb = 3;
    $total = 9;

    if ($argv[1]) $port = $argv[1];
    if (isset($argv[2])) $nb = $argv[2];
    if (isset($argv[3])) $total = $argv[3];
    if (isset($argv[4])) $host = $argv[4];

    $z = $argv;
    array_shift($z);
    foreach ($z as $t) {
        if(strpos($t,'=')){
            [$k, $v] = explode('=', $t);
            if ($v) {
                ${$k} = $v;
            }
        }
    }
    $p1 = $nb % $nbTopics;
    $p2 = ($nb + 1) % $nbTopics;
    $pushes = $pushes1[$p1];
    $consumes = $consumes1[$p2];
    $log = [$pid];//[$pid, $pushes, $consumes];
//echo "\n$pid => $nb :$pushes:$receives:$host:$port";//.implode(' ',$z);
}

try {
    $success = Run(function () {
        global $try, $dialog, $log, $cli, $pid, $pushes, $consumes;

        $cb = function ($a) use ($pid, $dialog, $try) {
            if (strpos($a, 'MessagContains') === false and 'wrap it once again') {
                return false;
            }
            $dialog[] = ['incr' => 'gotMsg'];
            $a = "$pid:read:" . substr($a,0,60);echo "\n\t$a";return $a;
        };

        $dialog = [

            'todisk, second position cuz older message' => ['push' => $pushes, 'message' => '-' . $pushes . ',disk:1,older,order:2,prio1,todisk,MessagContains:' . $pid . '-' . uniqid() . str_repeat('-', 4096)],

            'push' => ['push' => $pushes, 'message' => '-' . $pushes . ',order:3,noprio:1,MessagContains:' . $pid . '-' . uniqid()],

            'prio:3' => ['priority'=>3, 'push' => $pushes, 'message' => '-' . $pushes . ',order:1,prio3:MessagContains:' . $pid . '-' . uniqid()],

            //'consume' => ['data' => ['consume'=>$consumes], 'cb' => $cb],
            'suscribe,free,consume' => [
                'transaction'=>[
                    //
                    //consume=suscribe+free
                    'suscribe' => ['suscribe' => $consumes],'free' => ['free' => 1],// upon deconnection, shall re-suscribe at least ....
                    'wait' => ['data' => ['keepalive' => 1], 'cb' => $cb],//gets prio 3
                    // Le problème:ce sont d'autres process qui peuvent choper cet ordre ..
                        ['free' => 1],
                            ['data' => ['keepalive' => 1], 'cb' => $cb],// gets disk : older than prio 1
                        ['free' => 1],
                            ['data' => ['keepalive' => 1], 'cb' => $cb],// gets prio 1
                    ]
                ]
            // read : {"err":"json not valid"}
            // read'=> function($a){$a="\n\tread:".$a;echo $a;return $a;}// and waits (sleep30) till got something
        ];


        traiter($dialog);



        $cli->close();unset($cli);sleep(10);
        echo "\n".$pid.':'.json_encode($log, JSON_UNESCAPED_SLASHES);
return;
    });
} catch (Swoole\ExitException $e) {//   Fatal error: Uncaught Swoole\ExitException
    e($e->getStatus());
    return $e->getStatus();
} catch (\throwable $e) {
    e($e->getStatus());
    fpc('err.log', "\nC:" . getmypid() . '=>' . $e->getMessage(), 8);
}

if($exit)die($exit);
die(1);
//die($e->getStatus());

function e($x){
    static $a;if(!$a)echo"\n";
    $a=$x;echo','.$x;
}

function traiter($dialog, $depth = 0){
    global $log;
    foreach ($dialog as $k => $v) {
        $recv = $ok = false;
        while (!$ok) {
            try {
                if (is_callable($v)/*gettype($v) === 'object'*/) {//function'
                    $ok = $read = read2($k);
                    $recv = $v($read);
                    $log[] =$k.':'.$recv;
                } elseif (is_array($v) && isset($v['transaction'])) {// imbriquer des transactions ..
                    $ok = traiter($v['transaction'], $depth++);
                    $a=1;
                } elseif (is_array($v) && isset($v['cb']) && isset($v['data'])) {
                    if (is_array($v['data'])) {$v['data'] = json_encode($v['data']);}
                    $ok = push($v['data']);
                    while (!$recv) {
                        $recv = $v['cb'](read2($k));
                        if(!$recv){
                            sleep(1);
                        }
                    }
                    $log[] = $k . ':' . $recv;
                    $a=1;
                } elseif (in_array(gettype($v), ['string', 'array'])) {
                    if (is_array($v)) {$v = json_encode($v);}
                    $ok = push($v);
                    $recv = read2($k);
                    $log[] =$k.':'.$recv;
                    $a = 1;
                }
            } catch (\Exception $e) {
                echo",".$e->getMessage();
                $ok = false;
            }
            if (!$ok) {
                sleep(1);
            }
        }
    }
    return true;
}

function push($msg){
    global $cli;
    try {
        while (!$cli) {
            read2(0, 0, 0, true);
        }
        return $cli->push($msg);
    }catch(\throwable $e){
        throw $e;
    }
}

/* on exception : renew connection */
function read2($reason = '', $nbRetries = 0, $essai = 0, $connectOnly =false)
{
    global $host, $port, $to, $cli, $heartbeatsEachNSeconds;
    try{
        if (!$cli and 'connect') {
            $_ENV['cli'] = $cli = new Client($host, $port);
            $cli->set(['timeout' => $to, 'connect_timeout' => $to, 'write_timeout' => $to, 'read_timeout' => $to,/*  'open_tcp_nodelay' => true, */]);
            $cli->upgrade('/');
            $x = $cli->recv();
            $j = json_decode($x->data, true);
            $_ENV['fd'] = $j['id'];
            if($heartbeatsEachNSeconds){
                pcntl_signal(SIGALRM, function () use ($heartbeatsEachNSeconds) {
                    push(['keepalive' => 1]);
                    pcntl_alarm($heartbeatsEachNSeconds);
                    return;
                });
            }
            if ($connectOnly) {
                return $x->data;
            }
        }
        $x = $cli->recv();// bloquing :: waits for next transmission
        if ($cli->errCode) {//      websocket handshake failed, cannot push data
            throw new Exception($cli->errCode);
        }
    } catch (\Throwable $e) {
        $cli->close();
        $cli = null;
        throw $e;
    }
    if($x->data){
        if (1 and substr($x->data, 0, 1) == '{' and substr($x->data, -1) == '}') {
            $j = json_decode($x, 1);
            if (strpos($j['message'], ':SIGNAL')) {
                $log[]=':SIGNAL';
            }
        }
    }
    return $x->data;
}


function db($x, $fn = '../3.log')
{
    if (!$GLOBALS['log']) return;
    $y = $_ENV['fd'];//c$y=$argv[2];
    global $argv;
    if (is_array($x)) $x = stripslashes(json_encode($x));
    fpc($fn, "\nC:" . $y . ':' . $x, 8);
}

class redisFaker
{
    function __call($method, $args)
    {
        return 1;
    }
}

function fpc($f, $d, $fla = null)
{
    echo "\n" . trim($d);
}

/*
a=auto;pk shiva;pk max;ps -ax|grep php; php -dmemory_limit=4M $bf/Shiva/shiva3-atomics.php port=$spo reaktors=1 workers=1 needAuth=0 log=0 pass=0 supAdmin=1 > /dev/null 2>&1 & sleep 2; ps -ax|grep php

pk max4; nb=4; for((i=0;i<$nb;++i)) do phpx $bf/Shiva/tests/max4.php $spo $i $nb & done;

*/


function read($reason = '', &$error = 0, $nbRetries = 0, $essai = 0)
{
    global $tentative, $log, $pid, $cli, $exit, $expectedRepliesNb, $nbReplies, $waits, $b;
    if ($essai > $nbRetries) {
        echo "\n#$pid:essai:$essai;".implode(',',$log);
        $cli->close();unset($cli);die;
    }
    $x = $cli->recv();
    $nbReplies++;
    $a = ceil(microtime(true) - $b);
    if (($nbReplies + $waits ) > $expectedRepliesNb) {
        echo "\n$pid:$nbReplies > $expectedRepliesNb,$tentative:$reason:err:". $a." :: ".trim($x->data);
    }// $x->data == 'opened'
    if ($cli->errCode) {//      websocket handshake failed, cannot push data
        throw new Exception($cli->errCode);
        $error = $cli->errCode;// err=60
        if (strpos($reason, 'opened:') === 0) {
            $log[] = 'cnt:' . $a;
        } else {
            $log[] = $reason.','.$error.','. $cli->errMsg.','.$a;
        }

        if ($nbRetries) {
            sleep(1);
            $b=microtime(true);
            return read($reason, $error, $nbRetries, $essai + 1);
        }

        if ($error == 8504) {
            $log[]=$error;return null;
        }

        $cli->close();
        $exit = 69;
        return null;
        if ($cli->errCode == 60 or $cli->errMsg == 'Operation timed out') {//Operation timed out
            $cli->close();
            $error = 60;//throw new \Exception(60);
        }
        return null;
    }
    return trim($x->data);
}


//
return; ?>
Detect the thing is failing and trigger a backup+worker restart

Jusqu'à 283 ok au démarrage, puis timed out

# Prend plus de mémoire à charger swoole dans les extensions ??

ho=shiva.devd339.dev.infomaniak.ch;po=80;max=10;nb=300;

php max4.php test $ho 80 | jq .nbCompleted
php max4.php restart $ho 80

redis-cli set clients 129999;pkill -9 -f tail;pkill -9 -f max;pkill -9 -f stern;            echo ''>done.log; rm -rf failed; rm -rf complete;php -r 'echo"\nStart:".time();'>res.log;               stern -nvod2 shiva -s1s & k delete -f $bf/shiva8.yml;  k apply -f $bf/shiva8.yml

ho=shiva.devd339.dev.infomaniak.ch;po=80;max=10;nb=300;     cd $shiva/tests; redis-cli set clients 0; for((i=1;i<$nb;++i)) do ( exitCode=69; while [ $exitCode == 69 ]; do php  max4.php $po $i $nb $ho >> res.log & pid=$!;wait $pid;exitCode=$?; done; ) & done;


ho=shiva.devd339.dev.infomaniak.ch;po=80;max=10;nb=300;     cd $shiva/tests; redis-cli set clients 0; for((i=1;i<$nb;++i)) do ( pending=90; while [ $pending -gt $max ]; do sleep 1;pending=$(redis-cli get clients);if [ $pending -gt 99998 ]; then exitCode=1;exit;fi; done; redis-cli incr clients;      exitCode=69; while [ $exitCode == 69 ]; do php  max4.php $po $i $nb $ho >> res.log & pid=$!;wait $pid;exitCode=$?; done; redis-cli decr clients; echo $i>>done.log;  ) & done;


ho='127.0.0.1';po=80;max=10;nb=300;pkill -9 -f max;pkill -9 -f res.log;echo ''>res.log;tail -f res.log & for((i=1;i<$nb;++i)) do ( php  max4.php $po $i $nb $ho >> res.log & ) & done;# inner Test

- chamonix spa
- roussette jean vulien
- processo crème marrons jus pomme


cat backup.json|jq .Amem

lastSent-firstMessage
8504,websocket handshake failed

1661361442-1661361423 : 19 seconds
