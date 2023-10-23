<?php
//  /!\ Do not load openswoole for benchmarks otherwise you ram will probably explode /!\
$limiter = false;
$maxConnectionReadFails = 1;
$maxClients = 10;
$seuil = 200;//ms -> debug
$timeout = 10;// more concurrence, less timeout to connect please .. as  they stack as 67 completes
// le temps de se connecter mais la lecture de la 1ère frame ( welcome ) prend une méga plombe
// Mais si on laisse plus de temps cela se stacke comme de la merde et n'aboutit à rien du tout
$sleep = 2;//always less than timeout
$alarmTime = 2;
$alarmon = false;
$now = $last = $lastAlarm = now();//2661268792;
ini_set('default_socket_timeout', -1);
//  $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

if ('keepConnectionAlive' and 1) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, 'alarm');
    pcntl_alarm($alarmTime);
}

function alarm($reason = '')
{
    global $alarmTime, $alarmon, $lastAlarm, $log;
    if (!$alarmon) return;
    now($reason . 'baw');// {"timed_out":true,"blocked":true,"eof":false,"stream_type":"tcp_socket\/ssl","mode":"r+","unread_bytes":0,"seekable":false}
    //$log[] = 'k:';
    write('{"keepalive":"hb"}', 'alarm');// Triggers the fail
    $log[] = 'k';
    $lastAlarm = now();
    //echo "\n" . json_encode(stream_get_meta_data($sp));//  timed_out] => 1
    pcntl_alarm($alarmTime);// and repeat it then
}


$host = '127.0.0.1';
//$host = 'shiva.devd339.dev.infomaniak.ch';
$errorExitCode = 69;
$okExitCode = 1;
chdir(__dir__);
require_once '../common.php';

if (in_array($argv[1], ['test'])) {// php max3.php test shiva.devd339.dev.infomaniak.ch 80
    $host = $argv[2];
    $port = $argv[3];
    connect();
    $pushes = 'ya';
    $d = ['{"push":"ye","message":"ye-MessagContains:' . uniqid() . '"}','{"push":"' . $pushes . '","message":"' . $pushes . 'MessagContains:' . uniqid() . '"}', '{"subscribe":"' . $pushes . '"}', '{"status":"free"}'];
    foreach ($d as $q) {
        echo"\n$q:".write($q).'->'.read($es);
    }
    echo"\n".read($es);
    die;
}
if (in_array($argv[1], ['ok'])) {//  pkill -9 -f exitCode; ( exitCode=69; while [ $exitCode == 69 ]; do php -c php.ini max3.php ok & pid=$!;wait $pid;exitCode=$?;echo $pid $exitCode; done; say ok; ) & echo $!
    sleep(1);
    die($okExitCode);
}
if (in_array($argv[1], ['die'])) {// ( php -c php.ini max3.php die & pid=$!;wait $pid;exitCode=$?; [ $exitCode == 69 ] && echo 'ya') &
    sleep(1);//  ( php -c php.ini max3.php die & pid=$!;wait $pid;exitCode=$?; [ $exitCode == 69 ] && echo 'ya') &
    // ( exitCode=69; while [ $exitCode == 69 ]; do php -c php.ini max3.php die & pid=$!;wait $pid;exitCode=$?; done; ) &
    die($errorExitCode);
}
//  pkill -9 -f shiva;pkill -9 -f max3;pkill -9 -f tail;#abort all


ini_set('display_errors', 1);

$pk = $sm = $pid = $expected = 0;
$res = $log = $discussion = $a = [];
register_shutdown_function(function () {
    global $sp,$start, $timeLog, $pid, $res, $expected, $log, $discussion, $o, $alarmon, $pk, $limiter, $sm;
    if($sp)fclose($sp);
    $alarmon = false;
    if ($limiter) {
        now();
        $o[10002] = 'http://127.0.0.1:2001/?decr=clients';
        _cuo($o);
        now('decr');
    }
    if (!$expected) $expected = count($discussion) + 1;
    if (is_file('complete/' . $pid)) {
        echo "\n\nok: $pid";
        return;
        $log[] = "\t\tok:" . count($res);
        $log[] = time();
    }
    touch('failed/' . $pid);
    //$log[] = $sm;
    if (count($res) != $expected) {
        $log[] = "Whut:" . count($res) . '/' . $expected;
    } else {//ok
        $log[] = count($res) . '/' . $expected . stripslashes(json_encode($res));
    }
    //$timeLog = array_filter($timeLog, function ($a, $b = null) {if ($a > 10) return $a;return null;});
    $log += $timeLog;
    $log[] = $pk;
    $log[] = ceil(now() - $start);
    echo "\n\n#" . implode(' ; ', $log) . '--';

    return;
});


$topics = ['yo', 'ya', 'ye'];
$expected = $sp = 0;

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


if (!$argv[1]) $argv[1] = 2000;
if (isset($argv[2])) $nb = $argv[2];
if (isset($argv[3])) $total = $argv[3];
if (isset($argv[4])) $host = $argv[4];
$port = $argv[1];


chdir(__dir__);
if (!is_dir('complete')) mkdir('complete');
if (!is_dir('failed')) mkdir('failed');

if (!in_array($argv[1], ['debug', 'dump']) and 'Scenario 1 : publishes, then consumes') {

    if ($limiter) {
        now(__line__);
        $o = [10002 => 'http://127.0.0.1:2001/?get=clients', CURLOPT_HTTP09_ALLOWED => true, CURLOPT_RETURNTRANSFER => true];//, 10036 => 'GET', 19913 => 1, 42 => 1, 45 => false, 81 => false, 64 => false, 13 => $to, 78 => $to, 52 => 1, 2 => 1, 41 => 1, 58 => 1,
        $_sent = _cuo($o);//    Received HTTP/0.9 when not allowe
        while ($_sent['error'] or ((int)$_sent['contents']) > $maxClients) {
            //echo $_sent['error'] . ',' . (int)$_sent['contents'];
            sleep($sleep);
            //echo ',';
            //echo "\n".$_sent['contents'];
            now();//sleps
            $_sent = _cuo($o);
            now('curl:clients:1');
        }
        //echo'.';

        now();
        $o[10002] = 'http://127.0.0.1:2001/?incr=clients';
        _cuo($o);
        now('curl:inc:1');
    }

    $_ENV['r'] = $r = new redisFaker();

    $nbTopics = count($topics);
    $ok = $fini = 0;
    $eachNSeconds = 1;

    $pid = $nb . ';';//getMyPid();
    $log[] = $pid;
    //echo "\nù:" . $pid;// 1617

    $each = $total / $nbTopics;
    $push = floor($nb / $each);
    $pushes = $topics[$push];
    $push++;
    if ($push > ($nbTopics - 1)) $push = 0;
    $receives = $topics[$push];
    //echo"\n".$pid.':'.$pushes.','.$receives;
    //$res['pushes'] = $pushes;$res['recv'] = $receives;

    if (0) {
        while (!$r) {
            try {
                $_ENV['r'] = $r = new \Redis();
                $r->connect('127.0.0.1', 6379);
            } catch (\throwable $e) {
                $r = false;
            }
            if (!$r) {
                echo ',';
                sleep(1);
            }
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
    }

    try {
        $start = now(__line__);
        $error_string = '';
        $sp = $x = $connected = 0;
        $discussion = $res = [];
        //echo "\n%:" . $pid;// 1617
        while (!$connected) {//  php tests/max2.php 2000 1 1// max 243 connections actives ?
            now('c1');// 73.185
            connect();
            now('c2');
            $alarmon = true;
            //alarm('asap');//twice, it's bowdel
            $pk = 'readWelcome';
            $x = read($error_string, 1);// si rhf on connection : bad -- met une éternité à se résoudre, pouquoi ??
            //echo "\nc" . $pid;
            $connected = 1;
            $j = json_decode($x, 1);
            $_ENV['fd'] = $j['id'];
            //print_r($j);
        }

        now('c3');
        //echo"\n".$pid.':connected';

        // pcntl_alarm(){global $sp;write('{"keepalive":"hb"}');}

        //echo "\n" . $pid . ':' . $x;
        $res = ['welcome' => $x];
//46ms pour 7 opérations
        $last = '{"keepalive":"waits for the latest response :)"}';
        //			'{"status":"busy"}','{"get":"time"}','{"iam":' . $pid . '}', '{"queueCount":"' . $pushes . '"}', '{"queueCount":"' . $receives . '"}',
        $discussion = ['{"push":"' . $pushes . '","message":"' . $pushes . 'MessagContains:' . $pid . '-' . uniqid() . '"}',
            '{"status":"free"}',    // says he's free
            '{"suscribe":"' . $receives . '"}',  // conversations then subscribes to the message
            $last];//,'{"status":"free"}','{wait:}' => la TX du message peut avoir lieu bien après
        //	{"keepalive":"waits for last transmission"}:in:853.15704345703:
        foreach ($discussion as $q) {
            $x = $waits = 0;
            if ($q === $last) {
                $r->incr('last');
                //echo "\nà" . $pid;
            }
            $written = write($q);//$x = read( $error_string);
            $x = read($error_string);//$cli->recv();//  Last Waits here for something to show up
            //$x = $hybi->decode($cli->recv());
            while ($q == $last and !$x) {//Waits for it
                echo 'w';
                $waits++;
                $r->incr('sleep');
                $written = write(json_encode(['keepalive' => 1]));
                $x = trim(read($error_string));
            }
            $res[$q] = $x;
            db($q . ':' . $x);

            rep($q, $x);
            //$r->rPush('msg:'.$_ENV['fd'],$x);
            // Il peut recevoir un truc entre temps qu'il fait son free
            //echo"\nC:".$pid.':'.$x;
            if ($q === $last) {
                //echo "\n£" . $pid;
                $r->decr('last');
            }
        }    // Receive a PONG
        //$b = microtime(1);$x = $res['waitingForPreviouslyPushedMessage:' . ((microtime(1) - $b) * 1000)] = $cli->recv();
        $res = array_filter($res);
        $expected = count($discussion) + 1;
        $nbres = count($res);
        $res2 = [];
        foreach ($res as $k => $v) {
            $res2[] = "$k:$v";
        }

        if ($nbres != $expected) {
            $log[] = "Nbres:" . $nbres . ',' . $expected;// nbres:10;5,7
            $r->incr('err');
            if ($log) file_put_contents('7.log', "\nC:" . $pid . "=>xxx=>$waits Boum! no results=>$nbres/$expected in " . (microtime(1) - $aa) . '=>' . implode("\t", $res2), 8);
        } else {
            $r->incr('success');
            db("\t\t" . (microtime(1) - $aa) . '=>ok=>' . implode("\t", $res2));
        }
        if ($r->get('success') == $total) {
            $log[] = 'totalsuccess';
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
        if ($error_string) {
            db($error_string);
        }
        //     ps -ax | grep max2 | wc -l
        //echo "\n$" . $pid;

        touch('complete/' . $pid);// Scenario :: perfekt
        die($okExitCode);
        return true;
        $sup = 0;
        while (1) {//            grep MessagC 3.log
            $sup++;
            write(['keepalive' => 1]);
            $sucess = read($error_string);//  Last Waits here for something to show up
            if ($sucess) {
                rep($sup, $sucess);
                $r->rPush('msg:' . $_ENV['fd'], $sucess);
                $r->rPush('toomuch:' . $_ENV['fd'], $sucess);
                $r->rPush('toomuch', $_ENV['fd']);
                db("TooMuch:" . $receives . ':' . $sucess);
            }
        }
    } catch (\throwable $e) {
        touch('failed/' . $pid);
        $log[] = "exception" . ':' . $e->getMessage();
        if ($log) {
            file_put_contents('err.log', "\nC:" . $pid . '=>' . $e->getMessage(), 8);
        }
        $r->incr('errors');
    }


}


if (in_array($argv[1], ['debug', 'dump'])) {
    $port = 2000;
    $_ENV['r'] = $r = new redisFaker();
    connect();
    $x = read($error_string);
    echo '.';//echo $x."\n";//{"pid":45205,"id":303} served by, fileDescriptor
//    write( '{"debug":1}');read( $error_string);
    $ok = write('{"dump":1}');//$x = read( $error_string);
    $sucess = read($error_string);//$cli->recv();//  Last Waits here for something to show up
    echo $sucess;
    die;

    while (!$r) {
        try {
            $_ENV['r'] = $r = new \Redis();
            $r->connect('127.0.0.1', 6379);
        } catch (\throwable $e) {
            $r = false;
        }
        if (!$r) {
            echo ',';
            sleep(1);
        }
    }

    $p2h = $r->hgetall('p2h');
    foreach ($p2h as &$v) {
        if ($v < 2) $v = null;
    }
    unset($v);
    $p2h = array_filter($p2h);
    $rd = ['totTime' => round($r->get('totTime'), 4), 'TotalTime' => round($r->get('TotalTime'), 4)
        , 'took' => round($r->get('end') - $r->get('start'), 4)
        , 'suc' => $r->get('success'), 'last' => $r->get('last'), 'bg' => $r->get('bg')
        , 'bgp' => $r->get('bgp'), 'bgl' => $r->get('bgl'), 'bgm' => $r->get('bgm'), 'bgn' => $r->get('bgn'), 'db' => json_encode(array_count_values($r->lrange('db', 0, -1)))
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
        , 'p2h' => json_encode($p2h)


    ];
    $rd2 = [];
    foreach ($rd as $k => $v) if ($v) $rd2[] = "$k:$v";
    echo "\n\n---" . implode(',', $rd2) . "\n";
    return;
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
    global $pid;
    $r = $_ENV['r'];
    if (1 and substr($x, 0, 1) == '{' and substr($x, -1) == '}') {
        $j = json_decode($x, 1);
        if ($j) $r->incr('json');
        else $r->incr('jsonInvalid');

        if (isset($j['message'])) {
            $r->incr('gotMsg');
            if (strpos($j['message'], ':SIGNAL')) {
                $r->incr('signaled');//grep message *.log
                if ($GLOBALS['log']) file_put_contents('6.log', "\nC:" . $pid . ":Consuming:" . $j['message'], 8);
            } else {
                db("Msg:::" . $j['message']);
            }
        }
    } else {// grep message 4.log
        if ($GLOBALS['log']) file_put_contents('4.log', "\nCm:" . $pid . ',' . substr($x, 0, 1) . substr($x, -1) . ":" . $q . '=>' . $x, 8);// B3
    }
}

class redisFaker
{
    function __call($method, $args)
    {
        return 1;
    }
}

function connect()
{
    global $lastAct, $sp, $sm, $log, $host, $port, $r, $timeout, $errorExitCode, $lastAlarm;
    static $started = 0, $it = 0, $errors = 0, $connTime = 0;
    $it++;
    //if ($sp) fclose($sp);
    while (!$sp) {
        if ($it > 1) {
            $log[] = debug_backtrace(-2);
        }
        $a = now('bc');
        $sp = websocket_open($host, $port, '', $error_string, $timeout, false, false, '/');// Is there a timeout for this functions, even ??
        if ($error_string) {
            $log[] = $error_string;
            die($errorExitCode);
        }
        $started = now('ac');
        $connTime = round($started - $a); // parfois 34 secondes, c'est bcp, bcp trop !
        if (!$sp) {
            $errors++;
            if ($errors > 3) {// connections closed by timeout
                die($errorExitCode);
            }
            $r->incr('cantconnect');
            $log[] = '€;' . $error_string;
            sleep(1);
        }
    }// Bloque au 243ème !è
    $log['recon'] = $errors;
    now('gmd');
    $m = stream_get_meta_data($sp);
    $now = now('gmd:2');
    $c = round($now - $started);
    //$d = round($now - $lastAlarm);
    $d = round($now - $lastAct);

    $suffix = $it . ',' . $errors . '   ' . $connTime . '   ' . $c . '   la:' . $d . '--';
    $sm = json_encode($m) . $suffix;
    $to = $m['timed_out'];
    $bl = $m['blocked'];
    // en millisecondes, ne pas oublier :)

    if ($to and 1) {// Là c'est certain
        $sm = '    to' . $suffix;// drop au bout de 4 secondes en général
        die($errorExitCode);
    }
    if ($bl and 0) {
        $sm = '    bl' . $suffix;
        die($errorExitCode);
    }
    return $sp;
}

function read(&$error_string, $onConnect = 0)
{
    global $lastAct, $errorExitCode, $pid, $log, $maxConnectionReadFails, $pk;
    static $nbErrors = 0;
    now('read:1');
    $c = connect();
    $error_string = '';
    now('read:2');
    $ok = websocket_read($c, $error_string);
    $lastAct = now($pk . 'read:3');
    while ($error_string && $error_string == 'rhf' && $onConnect) {
        now('kar');
        //die($errorExitCode);// connection toute pourrie
        $nbErrors++;
        if ($nbErrors >= $maxConnectionReadFails) {
            die($errorExitCode);// connection initiale moisie
        }
        usleep(1000 * 1000);
    }

    while ($error_string && $error_string == 'rhf' && !$onConnect) {// rhf : nothing to read for the moment
        $error_string = null;
        $ok = websocket_read($c, $error_string);
        usleep(100 * 1000);
    }

    while ($error_string) {
        echo "\n$pid $error_string " . time();
        $nbErrors++;
        if ($nbErrors > 4) {
            $nbErrors = 0;
            die($errorExitCode);
        }
        $log[] = $error_string;
        $error_string = null;
        $ok = websocket_read($c, $error_string);
        usleep(100 * 1000);
    }
    now('read:4');
    return $ok;
}

function write($msg, $origin = '')
{
    global $lastAct, $errorExitCode, $log;
    now($origin . 'write:1');
    $c = connect();
    if (is_array($msg)) $msg = json_encode($msg);
    $ok = websocket_write($c, $msg);
    $lastAct = now($origin . 'write:2');
    if (!$ok) {
        $log[] = "Xwrite";
        die($errorExitCode);
    }
    ///     echo ';written:'.strlen($msg).','.$ok.';';// +data wrappers
    now($origin . 'write:3');
    return $ok;
}


//
return;

function _cuo($opts)
{
    $curl = curl_init();
    curl_setopt_array($curl, $opts);
    $result = \curl_exec($curl);
    $info = \curl_getinfo($curl);
    $error = \curl_error($curl);
    \curl_close($curl);
    //echo $result.'-'.print_r($info,1);
    $header = substr($result, 0, $info['header_size']);
    $contents = trim(substr($result, $info['header_size']));
    return compact('contents', 'info', 'header', 'error');
}

function now($name = null)
{
    global $timeLog, $now, $last, $seuil, $pk;
    if ($now) $last = $now;
    $now = microtime(true) * 1000;
    if ($name) {
        $d = round($now - $last);
        if ($d > $seuil) $timeLog[] = $name . ':' . $d;
        //$pk = $name;
    }
    return $now;
}

?>


grep 'ù:' res.log | wc -l
grep '/:' res.log | wc -l
cd $shiva/tests
redis-server &

nb=300;max=10;    redis-cli set pending 99999;pkill -9 -f pending;pkill -9 -f max3;pkill -9 -f shiva;pkill -9 -f tail;pkill -9 -f counter;  php -r 'echo "shiv3:".time();'>res.log; php $shiva/shiva3-atomics.php >> res.log & php counter.php p=2001 & redis-cli set clients 0;

nb=300;max=10;    redis-cli set pending 99999;pkill -9 -f pending;pkill -9 -f max3;pkill -9 -f shiva;pkill -9 -f tail;pkill -9 -f counter; php -r 'echo "shiv4:".time();'>res.log; php $shiva/shiva4-nbConnections.php >> res.log & php counter.php p=2001 & redis-cli set clients 0;


pkill -9 -f tail;echo ''>done.log; rm -rf failed; rm -rf complete;php -r 'echo"\nStart:".time();'>>res.log;    for((i=1;i<$nb;++i)) do ( pending=90; while [ $pending -gt $max ]; do sleep 1;pending=$(redis-cli get clients);if [ $pending -gt 99998 ]; then exit;fi; done; redis-cli incr clients;      exitCode=69; while [ $exitCode == 69 ]; do php -c php.ini max3.php 2000 $i $nb >> res.log & pid=$!;wait $pid;exitCode=$?; done; redis-cli decr clients; echo $i>>done.log;  ) & done;

ho=shiva.devd339.dev.infomaniak.ch;po=80;max=20;
redis-cli set pending 99999;pkill -9 -f tail;echo ''>done.log; rm -rf failed; rm -rf complete;php -r 'echo"\nStart:".time();'>res.log;    for((i=1;i<$nb;++i)) do ( pending=90; while [ $pending -gt $max ]; do sleep 1;pending=$(redis-cli get clients);if [ $pending -gt 99998 ]; then exit;fi; done; redis-cli incr clients;      exitCode=69; while [ $exitCode == 69 ]; do php -c php.ini max3.php $po $i $nb $ho >> res.log & pid=$!;wait $pid;exitCode=$?; done; redis-cli decr clients; echo $i>>done.log;  ) & done;

ho=shiva.devd339.dev.infomaniak.ch;po=80;max=20;nb=300;max=10;      php -r 'echo"\nStart:".time();'>monolog.log; tail -f res.log &   for((i=1;i<$nb;++i)) do ( exitCode=69; while [ $exitCode == 69 ]; do php -c php.ini max3.php $po $i $nb $ho >> monolog.log & pid=$!;wait $pid;exitCode=$?; done;   ) & done;


watch -n 1 "ps -ax -eo %cpu,args | grep php | grep -v grep | sort -rn" &

php max3.php debug

mac:
sudo launchctl limit maxfiles 1048576 1048600
ulimit -S -n 1048576
ulimit -S -n

http://talks.deminy.in/v4/csp-programming-in-php.html