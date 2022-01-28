<?php
//  /!\ Do not load openswoole for benchmarks otherwise you ram will probably explode /!\
chdir(__dir__);
require_once '../common.php';
ini_set('display_errors', 1);

$log = 0;
$a = [];
$a = $argv;
array_shift($a);
foreach ($a as $t) {
    if (strpos($t, '=')) {
        [$k, $v] = explode('=', $t);
        if ($v) {
			//echo"$k=$v;";
            ${$k} = $v;
        }
    }
}

if(0){
	$r = new \Redis();
	$r->connect('127.0.0.1', 6379);
	$now=date('YmdHis');
	$r->set('g'.$now,1);
	die(''.$r->get('g'.$now));
}

chdir(__dir__);

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
    $p2h=$r->hgetall('p2h');
    foreach($p2h as &$v){if($v<2)$v=null;}unset($v);$p2h=array_filter($p2h);
    $rd = ['totTime' => round($r->get('totTime'), 4), 'TotalTime' => round($r->get('TotalTime'), 4)
        , 'took' => round($r->get('end') - $r->get('start'), 4)
        , 'suc' => $r->get('success'), 'last' => $r->get('last'), 'bg' => $r->get('bg')
        , 'bgp' => $r->get('bgp'), 'bgl' => $r->get('bgl'), 'bgm' => $r->get('bgm'), 'bgn' => $r->get('bgn'), 'db' => json_encode(array_count_values($r->lrange('db',0,-1)))
        , 'p2disk' => $r->get('p2disk')
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
        ,'p2h'=>json_encode($p2h)


    ];
    $rd2 = [];
    foreach ($rd as $k => $v) if ($v) $rd2[] = "$k:$v";
    echo '---'.implode(',', $rd2) . "\n";
    return;
}

if (1) {
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
        if(!$r){echo',';sleep(1);}
    }

    $start = microtime(1);
    $ok = 0;
    while (!$ok) {
        try {
            if (!$r->exists('start')) {
                $r->set('err', 0);
                $r->set('start', $start);
            }
            $ok = 1;
        } catch (\throwable $e) {
            $r = false;
        }
    }
    $r->incr('clients');
    $nb = $r->get('clients');
    $max = $r->get('max');
    if ($nb > $max) $r->set('max', $nb);

    try {
        $aa = microtime(1);
        $error_string = '';
        $connected = 0;
        while (!$connected) {//  php tests/max2.php 2000 1 1
            $sp = websocket_open('127.0.0.1', $argv[1], '', $error_string, 99999);// max 243 connections actives ?
            if (!$sp and 1){$r->incr('cantconnect');echo':';sleep(1);Continue;}// Bloque au 243ème !è
            $x = websocket_read($sp, $error_string);
            //  websocket_write($sp,$data,$final=true,$binary=true)
            if (!$x) {
                if ($log) file_put_contents('8.log', "\nC:" . getMyPid() . "errCode:" . $cli->errCode, 8);//   54:: 2928 connections shitted
                $r->incr('connnect');// Chaque connection bloque les autres ...
                echo';';sleep(1);continue;
            } else {
                $connected = 1;
                $j = json_decode($x, 1);
                $_ENV['fd'] = $j['id'];
                //print_r($j);
            }
        }
        //echo'='.$x;
        $res['welcome'] = $x;
//46ms pour 7 opérations
        $last = '{"keepalive":"1"}';
        //			'{"status":"busy"}','{"get":"time"}','{"iam":' . getMyPid() . '}', '{"queueCount":"' . $pushes . '"}', '{"queueCount":"' . $receives . '"}',
        $discussion = ['{"push":"' . $pushes . '","message":"' . $pushes . 'MessagContains:' . getmyPid() . '-' . uniqid() . '"}', '{"status":"free"}', '{"suscribe":"' . $receives . '"}', $last];//,'{"status":"free"}','{wait:}' => la TX du message peut avoir lieu bien après
        //	{"keepalive":"waits for last transmission"}:in:853.15704345703:
        foreach ($discussion as $q) {
            $waits = 0;
            if ($q == $last){ $r->incr('last');echo'!'; }
            websocket_write($sp, $q);//$x = websocket_read($sp, $error_string);
            $sucess = websocket_read($sp, $error_string);//$cli->recv();//  Last Waits here for something to show up
            if (!$sucess) {
                if ($log) file_put_contents('8.log', "\nC:" . getMyPid() . "errCode:" . $cli->errCode, 8);//8504
                $r->incr('txerr');
                continue;
            } else $x = trim($sucess);
            //$x = $hybi->decode($cli->recv());
            while ($q == $last and !$x) {//Waits for it
                $waits++;
                $r->incr('sleep');
                websocket_write($sp, json_encode(['keepalive' => 1]));
                $x = trim(websocket_read($sp, $error_string));
                //$x = $hybi->decode($cli->recv());
                sleep(1);
            }
            $res[$q] = $x;
            db($q.':'.$x);

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
        if ($nbres != $expected) {
            $r->incr('err');
            if ($log) file_put_contents('7.log', "\nC:" . getMyPid() . "=>xxx=>$waits Boum! no results=>$nbres/$expected in " . (microtime(1) - $aa) . '=>' . implode("\t", $res2), 8);
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
        if($error_string){
            db($error_string);
        }
        //     ps -ax | grep max2 | wc -l
        echo'$';
        die;
        return true;
        $sup = 0;
        while (1) {//            grep MessagC 3.log
            $sup++;
            websocket_write($sp, ['keepalive' => 1]);
            $sucess = websocket_read($sp, $error_string);//  Last Waits here for something to show up
            if ($sucess) {
                rep($sup, $sucess);
                $r->rPush('msg:' . $_ENV['fd'], $sucess);
                $r->rPush('toomuch:' . $_ENV['fd'], $sucess);
                $r->rPush('toomuch', $_ENV['fd']);
                db("TooMuch:" . $receives . ':' . $sucess);
            }
        }
    } catch (\throwable $e) {
        if ($log){ file_put_contents('err.log', "\nC:" . getMyPid() . '=>' . $e->getMessage(), 8);}
        $r->incr('errors');
    }


}

//run(function () use ($argv) { });

function db($x, $fn = '../3.log')
{
    if (!$GLOBALS['log']) return;
    $y = $_ENV['fd'];//c$y=$argv[2];
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
                if ($GLOBALS['log']) file_put_contents('6.log', "\nC:" . getMyPid() . ":Consuming:" . $j['message'], 8);
            } else {
                db("Msg:::" . $j['message']);
            }
        }
    } else {// grep message 4.log
        if ($GLOBALS['log']) file_put_contents('4.log', "\nCm:" . getMyPid() . ',' . substr($x, 0, 1) . substr($x, -1) . ":" . $q . '=>' . $x, 8);// B3
    }
}

//
return;
?>
