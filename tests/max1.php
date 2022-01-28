<?php

chdir(__dir__);
require_once '../common.php';
ini_set('display_errors', 1);

use Swoole\WebSocket\Frame;
use Swoole\Coroutine\Http\Client;

// todo ::: Not coroutine
use function Swoole\Coroutine\run;

$log = 0;
$a = [];
$z = $argv;
array_shift($z);
foreach ($z as $t) {
    [$k, $v] = explode('=', $t);
    if ($v) {
        ${$k} = $v;
    }
}

chdir(__dir__);
run(function () use ($argv) {
//if(1){
    //$hybi=new hybi;
    if ($argv[1] == 'reset' or $argv[1] == 'del') {
        $r = new \Redis();
        $r->connect('127.0.0.1', 6379);
        $k = $r->keys('*');
        foreach ($k as $key) {
            $r->del($key);
        }
        return;
    }
    if ($argv[1] == 'get') {
        $r = new \Redis();
        $r->connect('127.0.0.1', 6379);
        //timeTaken
        if (0) {
            $k = $r->keys('pending:*');//yo:193
            foreach ($k as $key) {
                $res[$key] = $r->llen($key);
            }
        }
        if (0) {
            $k = $r->keys('*');
            foreach ($k as $key) {
                $res[$key] = $r->get($key);
            }
        }
        print_r($res);// Last:193 hanged Up
//  totTime:1.1063,took:2.6321,clients:149,max:159,last:149,suc:151,sClose:151,sPending:100,sMessage:2700,sSignalSentMsg:50,sPviaMessage:50,sPMessage:150
// Restent 100 messages non consommés, et 59 d'entre eux qui , bon bah on été consommés on ne sait pas quand, vraiment ..
        if (0) {
            $k = $r->keys('toomuch:*');
            foreach ($k as $v) {
                echo "\n$v:   " . implode('  ;;  ', $r->lrange($v, 0, -1));
            }
            $k = $r->keys('msg:*');
            foreach ($k as $v) {
                echo "\n$v:   " . implode('  ;;  ', $r->lrange($v, 0, -1));
            }
        }
        echo "\n\n";
        $rd = ['totTime' => round($r->get('totTime'), 4), 'TotalTime' => round($r->get('TotalTime'), 4)
            , 'took' => round($r->get('end') - $r->get('start'), 4)
            , 'suc' => $r->get('success'), 'last' => $r->get('last'), 'bg' => $r->get('bg')
            , 'sOpen' => $r->get('sOpen')
            , 'clients' => $r->get('clients'), 'max' => $r->get('max'), 'err' => $r->get('errors')
            , 'ConnectErrors' => $r->get('connnect'), 'txErr' => $r->get('txerr')
            , 'sClose' => $r->get('sClose')

            , 'sleep' => $r->get('sleep')
            , 'serverError' => $r->get('serverError')
            , 'sStart' => $r->get('sStart')
            , 'sSignalToOther' => $r->get('sSignalToOther')
            , 'sSignalNothingFree' => $r->get('sSignalNothingFree')

            , 'sSend' => $r->get('sSend')
            , 'sPending' => $r->get('sPending')

            , 'SonMessage' => $r->get('SonMessage')
            , 'sSentAsap' => $r->get('sSentAsap')

            , 'sMsgSentOnAsync' => $r->get('sMsgSentOnAsync')
            , 'sPMessageSelfReady' => $r->get('sPMessageSelfReady')

            , 'sNotUnder' => $r->get('sNotUnder')
            , 'sNotJson' => $r->get('sNotJson')


            , 'sPvia' => $r->get('sPvia')

            , 'sSignalSentMsg' => $r->get('sSignalSentMsg')
            , 'signaled' => $r->get('signaled')
            , 'sMsgSentOnFree' => $r->get('sMsgSentOnFree')

            , 'sPushed' => $r->get('sPushed')
            , 'json' => $r->get('json')
            , 'jsonInvalid' => $r->get('jsonInvalid')
            , 'gotMsg' => $r->get('gotMsg')
            , 'noLpop' => $r->get('noLpop')


            , 'sAsyncOk' => $r->get('sAsyncOk')
            , 'sErrAsync' => $r->get('sErrAsync')

            , 'sEc' => $r->get('sEc')
            , 'free' => $r->get('free')

            , 'noFree' => $r->get('noFree')
            , 'noLibre' => $r->get('noLibre')
            , 'nbLoop' => $r->get('nbLoop')
            //,'relaunch'=>json_encode($r->hgetall('relaunch'))


            // ,'lastPending'=>$r->get('lastPending'),'lastBgProcess'=>$r->get('lastBgProcess')
            , 'bgLock' => $r->get('bgLock')
            , 'pendings' => json_encode(array_filter($r->hgetAll('pendings')))


            , 'busy' => $r->get('busy')
            , 'freed' => $r->get('freed')
            , 'still' => ($r->get('lastPending') > $r->get('lastBgProcess'))
            , 'toomuch' => implode(',', $r->lrange('toomuch', 0, -1))
            //,'p2h'=>json_encode($r->hgetall('p2h'))


        ];
        $rd2 = [];
        foreach ($rd as $k => $v) if ($v) $rd2[] = "$k:$v";
        echo "\n" . implode(',', $rd2) . "\n";

        //echo"\n".$r->get('TotalTime').' --- '.($r->get('end')-$r->get('start')).' --- with:'.$r->get('clients').' clients, '.$r->get('errors').' errors,'.$r->get('connnect').' ctx, '.$r->get('txerr').' txerr,'.$r->get('err').' err, '.$r->get('success').' success, '.$r->get('max').' max, '.$r->get('sleep')." sleep\n\n";return;//Pending... ?

        //  1635530031.4406 dernière frame trouvée
        //  1635530441.6748 --- 0
        return;
    }
    $nbTopics = 3;
    $topics = ['yo', 'ya', 'ye'];
    $nb = $total = $fini = 0;
    $eachNSeconds = 1;

    if (!$argv[1]) $argv[1] = 2000;
    if (isset($argv[2])) $nb = $argv[2];
    if (isset($argv[3])) $total = $argv[3];

    $each = $total / $nbTopics;
    $push = floor($nb / $each);
    $pushes = $topics[$push];
    $push++;
    if ($push > ($nbTopics - 1)) $push = 0;
    $receives = $topics[$push];
    $res['pushes'] = $pushes;
    $res['recv'] = $receives;

    while (!$r) {
        try {
            $_ENV['r'] = $r = new \Redis();
            $r->connect('127.0.0.1', 6379);
        } catch (\throwable $e) {
            $r = false;
        }
        sleep(1);
    }
    $start = microtime(1);
    if (!$r->exists('start')) {
        $r->set('err', 0);
        $r->set('start', $start);
    }
    $r->incr('clients');
    $nb = $r->get('clients');
    $max = $r->get('max');
    if ($nb > $max) $r->set('max', $nb);

    try {
        $aa = microtime(1);
        $cli = new Client('127.0.0.1', $GLOBALS['argv'][1]);
        $cli->set(['timeout' => 9999, 'connect_timeout' => 9999, 'write_timeout' => 9999, 'read_timeout' => 9999,
            //'open_tcp_nodelay' => true,
            ]);
        $cli->upgrade('/');

        $x = $cli->recv();
        if (!$x) {
            file_put_contents('8.log', "\nC:" . getMyPid() . "errCode:" . $cli->errCode, 8);//   54:: 2928 connections shitted
            $r->incr('connnect');// Chaque connection bloque les autres ...
            return;
        } else {
            $j = json_decode($x->data, 1);
            $_ENV['fd'] = $j['id'];
            //print_r($j);
        }
        $res['welcome'] = $x->data;


        pcntl_signal(SIGALRM, function () use ($eachNSeconds, $cli, $fini) {
            db("Signal fini");//grep "Signal fini" *.log
            $cli->push(json_encode(['keepalive' => 1]));
            pcntl_alarm($eachNSeconds);
            return;
            $cli->push(json_encode(['hb' => time()]));
            $cli->recv();//Osef
            pcntl_alarm($eachNSeconds);// répéter l'interrogation récursivement
        });
        pcntl_alarm($eachNSeconds);// KeepAlive

        //file_put_contents('2.log', "\nC:" . getMyPid() . ':welcome:'.$x, 8);#echo"\n$x";
        if (0) {
            $pingFrame = '{"fd":0,"data":"","opcode":9,"flags":1,"finish":null}';
            $pingFrame = new Frame;
            $pingFrame->opcode = WEBSOCKET_OPCODE_PING;//
            // Send a PING
            $cli->push(json_encode(['ping' => $pingFrame]));
            $res['ping'] = $pongFrame = $cli->recv();
            $res['pongframe'] = ($pongFrame->opcode === WEBSOCKET_OPCODE_PONG);
        }

//46ms pour 7 opérations
        $last = '{"keepalive":"1"}';
        //			'{"status":"busy"}','{"get":"time"}','{"iam":' . getMyPid() . '}', '{"queueCount":"' . $pushes . '"}', '{"queueCount":"' . $receives . '"}',
        $discussion = ['{"push":"' . $pushes . '","message":"' . $pushes . 'MessagContains:' . getmyPid() . '-' . uniqid() . '"}', '{"status":"free"}', '{"suscribe":"' . $receives . '"}', $last];//,'{"status":"free"}','{wait:}' => la TX du message peut avoir lieu bien après
        //	{"keepalive":"waits for last transmission"}:in:853.15704345703:
        foreach ($discussion as $q) {
            $waits = 0;
            if ($q == $last) $r->incr('last');
            $a = microtime(1);
            $cli->push($q);//$res[$q.((microtime(1)-$a)*1000)]='pushed';
            $b = microtime(1);#$res[$b]='pushed';
            // trims the reception, nope
            $sucess = $cli->recv();//  Last Waits here for something to show up
            if (!$sucess) {
                file_put_contents('8.log', "\nC:" . getMyPid() . "errCode:" . $cli->errCode, 8);//8504
                $r->incr('txerr');
                continue;
            } else $x = trim($sucess->data);
            //$x = $hybi->decode($cli->recv());
            while ($q == $last and !$x) {//Waits for it
                $waits++;
                $r->incr('sleep');
                $cli->push(json_encode(['keepalive' => 1]));//
                $x = trim($cli->recv()->data);
                //$x = $hybi->decode($cli->recv());
                sleep(10);
            }
            $res[$q . '::' . round(((microtime(1) - $b) * 1000), 4) . 'ms'] = $x;
            //db($q.':'.$x);

            rep($q, $x);
            //$r->rPush('msg:'.$_ENV['fd'],$x);
            // Il peut recevoir un truc entre temps qu'il fait son free
            //echo"\nC:".getmypid().':'.$x;
            if ($q == $last) $r->decr('last');
        }    // Receive a PONG
        //$b = microtime(1);$x = $res['waitingForPreviouslyPushedMessage:' . ((microtime(1) - $b) * 1000)] = $cli->recv();
        $res = array_filter($res);
        $expected = count($discussion) + 3;
        $nbres = count($res);
        $res2 = [];
        foreach ($res as $k => $v) {
            $res2[] = "$k:$v";
        }
        //$cli->push('{"status":"busy"}');//$cli->close();
        if ($nbres != $expected) {
            $r->incr('err');
            file_put_contents('7.log', "\nC:" . getMyPid() . "=>xxx=>$waits Boum! no results=>$nbres/$expected in " . (microtime(1) - $aa) . '=>' . implode("\t", $res2), 8);
        } else {
            $r->incr('success');
            db("\t\t" . (microtime(1) - $aa) . '=>ok=>' . implode("\t", $res2));
        }
        if ($r->get('success') == $total) {
            $now = microtime(true);
            $r->set('end', $now);
            $r->set('totTime', ($now - $start));
        }
        $r->decr('clients');
        $now = microtime(1);
        $r->set('end', $now);
        $r->set('totTime', ($now - $start));
        if (!$r->get('clients')) {
            $r->set('timeTaken', ($now - $r->get('start')));
            db("===>" . ($now - $r->get('start')));
        }
        $fini = true;
        return true;
        $sup = 0;
        while (1) {//            grep MessagC 3.log
            $sup++;
            $cli->push('{"keepalive":"1"}');
            $sucess = $cli->recv();//  Last Waits here for something to show up
            if ($sucess) {
                rep($sup, $sucess->data);
                $r->rPush('msg:' . $_ENV['fd'], $sucess->data);
                $r->rPush('toomuch:' . $_ENV['fd'], $sucess->data);
                $r->rPush('toomuch', $_ENV['fd']);
                db("TooMuch:" . $receives . ':' . $sucess->data);
            }
        }
    } catch (\throwable $e) {
        file_put_contents('err.log', "\nC:" . getMyPid() . '=>' . $e->getMessage(), 8);
        print_r($e);
        $r->incr('errors');
    }
});

function db($x, $fn = '../3.log')
{
    if (!$GLOBALS['log']) return;
    $y = $_ENV['fd'];//c$y=$argv[2];
    global $argv;
    if (is_array($x)) $x = stripslashes(json_encode($x));
    file_put_contents($fn, "\nC:" . $y . ':' . $x, 8);
}

function rep($q, $x)
{
    $r = $_ENV['r'];
    if (1 and substr($x, 0, 1) == '{' and substr($x, -1) == '}') {
        $j = json_decode($x, 1);
        if ($j) $r->incr('json');
        else $r->incr('jsonInvalid');
        if (isset($j['message'])) {
            $r->incr('gotMsg');
            if (strpos($j['message'], ':SIGNAL')) {
                $r->incr('signaled');//grep message *.log
                file_put_contents('6.log', "\nC:" . getMyPid() . ":Consuming:" . $j['message'], 8);
            } else {
                db("Msg:::" . $j['message']);
            }
        }
    } else {// grep message 4.log
        file_put_contents('4.log', "\nCm:" . getMyPid() . ',' . substr($x, 0, 1) . substr($x, -1) . ":" . $q . '=>' . $x, 8);// B3
    }
}

//
return; ?>








