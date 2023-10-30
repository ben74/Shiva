<?php
$to = 10;//read timeout does the whole difference
/*
Coroutine http client
pecl install swoole
// ps -ax|grep max4|grep -v grep|wc -l
pk test; nb=4; for((i=0;i<$nb;++i)) do php $bf/Shiva/test.php $spo $i $nb & done;
 */
chdir(__dir__);
require_once 'common.php';// todo : use a lightweighter websocket client ?
ini_set('display_errors', 1);
$cli = $_ENV['cli'] = 0;

use Swoole\WebSocket\Frame;
use Swoole\Coroutine\Http\Client;
// todo ::: Not coroutine -> lightweighter client ?
// use function Swoole\Coroutine\Run;
use Swoole\Coroutine as Co;
/*
Co::Run(function(){
    Co::Go(function(){

    })
})
*/
//use OpenSwoole\Coroutine as Co;// but rest of swoole Objects are ok /):
$supAdmin='zyx';
$pushAndPull = ['ya' => 'yo', 'yi' => 'yu', 'yo' => 'yi', 'yu' => 'ya'];// ya and yi queue are stored to memory and disk ..
$pushes1=array_keys($pushAndPull);
$consumes1=array_values($pushAndPull);
$nbTopics = count($pushAndPull);
$heartbeatsEachNSeconds=30;


$pid = getmypid();
$expectedRepliesNb=$errorExitCode = 69;
$okExitCode = 1;
$conerr = $err = $tentative = $hearbeats = $nb = $total = $fini = $finalOk = $b = $waits = $exit = $e = $tentative = $nbReplies = $cli = 0;
$log=[];

if('variables'){
    $host = '127.0.0.1';
    $port = 2000;
    $nb = 3;
    $total = 9;
    extract(argv());
//echo "\n$pid => $nb :$pushes:$receives:$host:$port";//.implode(' ',$z);
}

try{
    if (in_array($argv[1], ['test', 'restart', 'dump', 'reset', 'xdebug'])) {
        $action = $argv[1];
        $port = 2000;
        $success = Co::Run(function () use ($supAdmin, $host, $action, $port, $to, $cli) {
            push(['supAdmin' => $supAdmin, $action => 1]);
            $res = read2($action);
            echo $res."\n";
            return;
            $log = [$res];
            return;
        });
        die;
    }


    $p1 = $nb % $nbTopics;
    $pushes = $pushes1[$p1];
    $consumes = $consumes1[$p1];
    $log = [$pid, $nb,$p1, $pushes, $consumes];//echo"\n".implode(',',$log);
    //$log = [$pid, $nb, $p1, $pushes, $consumes];echo"\n".implode(',',$log);
    ini_set('default_socket_timeout', $to);
    Co::set(['socket_timeout' => $to, 'socket_connect_timeout' => $to, 'socket_read_timeout' => $to, 'socket_write_timeout' => $to,]);


    $cb = function ($a) use ($pid, $dialog, $try) {
        if (strpos($a, 'MessagContains') === false and 'wrap it once again') {
            return false;
        }
        $dialog[] = ['incr' => 'gotMsg'];
        $a = "$pid:read:" . substr($a,0,60);
        //echo "\n\t$a";
        return $a;
    };

    $dialog = [
        'todisk, second position cuz older message' => ['push' => $pushes, 'message' => '-' . $pushes . ',disk:1,older,order:2,prio1,todisk,MessagContains:' . $pid . '-' . uniqid() . str_repeat('-', 4096)],
        'push' => ['push' => $pushes, 'message' => '-' . $pushes . ',order:3,noprio:1,MessagContains:' . $pid . '-' . uniqid()],
        'prio:3' => ['priority'=>3, 'push' => $pushes, 'message' => '-' . $pushes . ',order:1,prio3:MessagContains:' . $pid . '-' . uniqid()],
        ['pushonly'=>['incr'=>'nbPushed1','by'=>3]],// do no wait, pleaz operation timed out, attend un ACK qqpart alors que non nécessaire
    ];

    $dialog2 = [
// gets : prio 3 ; 'consume' => ['data' => ['consume'=>$consumes], 'cb' => $cb],
        ['subscribe'=>'&consume:1','transaction'=>[
                'suscribe:'.$consumes => ['suscribe' => $consumes], 'free' => ['free' => 1],'wait' => ['data' => ['keepalive' => 1], 'cb' => $cb],//gets prio 3
            ]
        ],
// gets disk : older than prio 1
        ['transaction'=>['suscribe:'.$consumes => ['suscribe' => $consumes], ['free' => 1], ['data' => ['keepalive' => 1], 'cb' => $cb],]],
// gets prio 1, what about : operation timed out -> veut relancer toute la transaction depuis le départ
        ['transaction'=>['suscribe:'.$consumes => ['suscribe' => $consumes], ['free' => 1], ['data' => ['keepalive' => 1], 'cb' => $cb],]],
        ['pushonly'=>['incr'=>'nbConsumed1','by'=>3]]

        // read : {"err":"json not valid"}
        // read'=> function($a){$a="\n\tread:".$a;echo $a;return $a;}// and waits (sleep30) till got something
    ];

    $sucess=false;$tries=0;
    while(!$sucess && $tries<3 and 'dialog1:pushes'){
        $s1 = Co::Run(function () {
            global $sucess, $dialog,$err, $cli;//$try, , $log, $cli, $pid, $pushes, $consumes;
            $sucess = false;
            try{
                $sucess = process($dialog);
            }catch(\Exception $e){
                $sucess = false;
                echo',loop1:'.$e->getMessage();
                if($cli){$cli->close();unset($cli);}
            }
            return $sucess;
        });

        if(!$sucess){$err=0;$tries++;echo"\nTry:$tries";}
        else echo '.';
    }//end while no success

    $sucess=false;$tries=0;
    while(!$sucess && $tries<3 and 'dialog2:consumes'){
        $s2 = Co::Run(function () {
            global $sucess,$dialog2,$err, $cli;//$try, , $log, $cli, $pid, $pushes, $consumes;
            $sucess = false;
            try{
                $sucess = process($dialog2);
                if($sucess && $cli){$cli->close();unset($cli);}// then closes it, maybe, later
            }catch(\Exception $e){
                $sucess = false;
                echo',loop2:'.$e->getMessage();
                if($cli){$cli->close();unset($cli);}
            }
            return $sucess;
        });

        if(!$sucess){$err=0;$tries++;echo"\nTry2:$tries,";}
        else echo "\n".$pid.'='.$sucess;
    }//end while no success

//  ps -ax|grep test.php

} catch (Swoole\ExitException $e) {//   Fatal error: Uncaught Swoole\ExitException
    e('swe:'.$e->getStatus());
    return $e->getStatus();
} catch (\throwable $e) {
    e('msg:'.$e->getMessage());
    //e($e->getStatus());
    fpc('err.log', "\nC:" . getmypid() . '=>' . $e->getMessage(), 8);
}


if($exit)die($exit);
die(1);
//die($e->getStatus());

function e($x){
    static $a;if(!$a)echo"\n";
    $a=$x;echo','.$x;
}

function process($dialog, $depth = 0, $maxTries = 3)
{
    global $log, $err;
    foreach ($dialog as $k => $v) {
        $tries = 0;
        $recv = $ok = false;
        while (!$ok) {
            if ($err == 99) return false;
            if ($tries > $maxTries) {
                $log[] = "stop: $maxTries essais pour $k";
                $err = 99;
                throw new \conexc('e99');//echo $GLOBALS['pid'].':99';
                //echo "\n" . json_encode($log);
                return false;
            }

            try {
                if (is_callable($v)/*gettype($v) === 'object'*/) {//function'
                    $ok = $read = read2($k);
                    $recv = $v($read);
                    $log[] = $k . ':' . $recv;
                } elseif (is_array($v) && isset($v['transaction'])) {// imbriquer des transactions ..
                    $ok = process($v['transaction'], $depth++);
                    $a = 1;
                } elseif (is_array($v) && isset($v['cb']) && isset($v['data'])) {
                    $ok = push($v['data']);
                    while (!$recv) {
                        $recv = $v['cb'](read2($k));
                        if (!$recv) {
                            sleep(1);
                        }
                    }
                    $log[] = $k . ':' . $recv;
                    $a = 1;
                } elseif (is_array($v) && isset($v['pushonly'])) {
                    $ok = push($v['pushonly']);
                } elseif (in_array(gettype($v), ['string', 'array'])) {
                    if (is_array($v)) {
                        $v = json_encode($v);
                    }
                    $ok = push($v);
                    $recv = read2($k);
                    $log[] = $k . ':' . $recv;
                    $a = 1;
                }
            } catch (\conExc $e) {
                throw $e;// to be catched below
            } catch (\Exception $e) {
                $log[] = $e->getMessage();
                echo "\n" . json_encode($log);
                if($e->getMessage()==99){return false;}// cut scenario here
                $ok = false;
            }

            if (!$ok) {
                $tries++;
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
        if(is_array($msg))$msg=json_encode($msg);
        return $cli->push($msg);
    }catch(\throwable $e){
        throw $e;
    }
}

/* on exception : renew connection */
function read2($reason = '', $nbRetries = 0, $essai = 0, $connectOnly =false)
{
    global $conerr, $err, $log, $host, $port, $to, $cli, $heartbeatsEachNSeconds;
    try{
        if (!$cli and 'connect') {
            $_ENV['cli'] = $cli = new Client($host, $port);if($conerr)$log[]='co';
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
        $x = $cli->recv();// false : not connected,bloquing :: waits for next transmission
        if ($cli->errCode) {
            $conerr++;
            $err = 99;
            echo $GLOBALS['pid'].':99,';
            throw new \conExc('a99');
            //throw new Exception('e'.$cli->errCode.':'.$cli->errMsg);//errCode
        }
    } catch (\Throwable $e) {
        if($cli /* && $cli->errCode!=60 */){//  dont kill cli upon 60 error, retry only :)
            $cli->close();
            $cli = null;
        }
        throw $e;
    }

    if($x->data){
        if (1 and substr($x->data, 0, 1) == '{' and substr($x->data, -1) == '}') {
            $j = json_decode($x, 1);
            if (isset($j['err'])) {
                echo"\n".$reason.':'.$j['err'];
                $log[]=$j['err'];
            }
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

class conExc extends \Exception{}

//
return; ?>
