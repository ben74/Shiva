<?php
// Coroutine http client
// ps -ax|grep max4|grep -v grep|wc -l
chdir(__dir__);
require_once '../common.php';
ini_set('display_errors', 1);
$cli = $_ENV['cli'] = 0;

use Swoole\WebSocket\Frame;
use Swoole\Coroutine\Http\Client;

// todo ::: Not coroutine -> lightweighter client ?
use function Swoole\Coroutine\Run;

chdir(__dir__);
$to = 60;//read timeout does the whole difference

$pid = getmypid();
$errorExitCode = 69;
$okExitCode = 1;
$finalOk=$b=$waits=$exit = $e = $tentative = $nbReplies=0;
$log=[];
$expectedRepliesNb=99;

try {
    $success = Run(function () use ($argv) {
        global $e, $tentative, $to, $log, $cli, $pid,$exit, $errorExitCode, $okExitCode, $finalOk;
        $log = [$pid];
        $z = $argv;
        array_shift($z);
        foreach ($z as $t) {
            [$k, $v] = explode('=', $t);
            if ($v) {
                ${$k} = $v;
            }
        }

        $topics = ['yo', 'ya', 'ye'];
        $nbTopics = count($topics);
        $tentative = $hearbeats = $nb = $total = $fini = 0;
        $eachNSeconds = 1;
        $host = '127.0.0.1';
        $port = 2000;
        $nb = 3;
        $total = 9;

        if ($argv[1]) $port = $argv[1];
        if (isset($argv[2])) $nb = $argv[2];
        if (isset($argv[3])) $total = $argv[3];
        if (isset($argv[4])) $host = $argv[4];

        if (0 and 'stacker 1000 consommateurs en wait ... nope... bad idea') {
            $each = $total / $nbTopics;// 300/3
            $push = floor($nb / $each);
            $pushes = $topics[$push];
            $push++;
            if ($push > ($nbTopics - 1)) $push = 0;
            $receives = $topics[$push];
        }
        $p1 = $nb % $nbTopics;
        $p2 = ($nb + 1) % $nbTopics;
        $pushes = $topics[$p1];
        $receives = $topics[$p2];
        //echo "\n$pid => $nb :$pushes:$receives:$host:$port";//.implode(' ',$z);

        $_ENV['r'] = $r = new \RedisFaker();
        $finished = false;
        while (!$finished) {
            try {
                if($exit)return;
                //echo '.';
                $tentative++;
                if ($cli) {$cli->close();unset($cli);sleep(1);}//1st failuremax4.php
$_ENV['cli'] = $cli = new Client($host, $port);$cli->set(['timeout' => $to, 'connect_timeout' => $to, 'write_timeout' => $to, 'read_timeout' => $to,/*  'open_tcp_nodelay' => true, */]);$cli->upgrade('/');
                $try = $x = $e = 0;
                $b = microtime(true);
                $x = read('opened:' . $tentative, $e, 0);// x=opened
// #36668:1:{"push":"ye","message":"-yeMessagContains:36668-653630e60e596"}, err:8504,websocket handshake failed, cannot push data--1698050278
                while (!$x) {
                    continue;// tentative ++;
                }

                if ($e) continue;//fail 2 connect

                $res['welcome'] = $x;
                $j = json_decode($x, 1);
                $_ENV['fd'] = $j['id'];
                $log[] = 'cok:'.$j['id'];

                if ($hearbeats) {
                    pcntl_signal(SIGALRM, function () use ($eachNSeconds, $cli, $fini) {
                        db("Signal fini");//grep "Signal fini" *.log
                        $cli->push(json_encode(['keepalive' => 1]));
                        pcntl_alarm($eachNSeconds);
                        return;
                        $cli->push(json_encode(['hb' => time()]));
                        read('hb', $e);
                        if ($e) return;//Osef
                        pcntl_alarm($eachNSeconds);// répéter l'interrogation récursivement
                    });
                    pcntl_alarm($eachNSeconds);// KeepAlive
                }

                $finalOk = 0;
                $last = '{"free":"1"}';
                $discussion = ['{"push":"' . $pushes . '","message":"-' . $pushes . 'MessagContains:' . $pid . '-' . uniqid() . '"}'/*, '{"status":"free"}' */, '{"suscribe":"' . $receives . '"}', $last];//,'{"status":"free"}','{wait:}' => la TX du message peut avoir lieu bien après
                $nbReplies = 0;
                $expectedRepliesNb = count($discussion);
                foreach ($discussion as $q) {
                    $waits = 0;
                    if ($q == $last) $r->incr('last');
                    try{
                        $cli->push($q);//$res[$q.((microtime(true)-$a)*1000)]='pushed';
                    } catch (\throwable $e) {
                        $log[] = $e->getMessage();
                    }
                    $b = microtime(true);
                    $x = read($q, $e, 4);

                    if($e) {
                        $error = $cli->errCode;
                        if ($error == 60) {
                            $a = ceil(microtime(true) - $b / 1000);
                            echo '::' . $a;
                            fpc('err.log', "\nC:" . getmypid() . '=>' . $a, 8);
                            continue;
                        }
                        if ($e) {// #36668:1:{"push":"ye","message":"-yeMessagContains:36668-653630e60e596"}, err:8504,websocket handshake failed, cannot push data--1698050278
                            fpc('err.log', "\nC:" . getmypid() . '=>' . $cli->errMsg.':'.json_encode($e), 8);
                            echo ',';
                            continue;
                            break;//  Last Waits here for something to show up, is this blocking ?
                        }
                    }
                    if (!$x) {
                        fpc('8.log', "\nC:" . $pid . "nosuccess:" . $cli->errCode, 8);//8504
                        continue;
                    }

                    while ($q == $last and !strpos($x, 'MessagContains')) {// Waits for the last message
                        $waits++;
                        $cli->push(json_encode(['keepalive' => 1]));$b=microtime(true);
                        $x = read('w:' . $waits, $e, 5);// Not behaving any better
                        if ($e) {
                            if (1 or $waits > 7) {
                                $x = '---MessagContains';
                                continue;
                            }
                            $e = null;
                        } elseif ($x) {// 2
                            if ($x != '{"keepalive":1}' && !strpos($x, $receives)) {// keepalive
                                echo"\n unexpected : ".$x;
                                $log[] = 'notExpectedMsg:'.$x;
                            }
                        }
                        echo'.';
                        // {"free":"1"}:err:Operation timed out
                        sleep(1);
                    }

                    if ($e) break;
                    if ($waits) $log[] = 'waits:' . $waits;
                    if ($q == $last) {
                        if (!strpos($x, $receives)) {
                            $log[] = 'notExpectedMsg:'.$x;//                    $finalOk = 1;
                        }
                        $finalOk = 1;
                        //$log[] = $x;
                    }
                    $res[$q . '::' . round(((microtime(true) - $b) * 1000), 0) . 'ms'] = $x;
                }


                if ($e) continue;

                $res = array_filter($res);
                $expected = count($discussion);// - 1 - 2;
                $nbres = count($res);
                $res2 = [];
                foreach ($res as $k => $v) {
                    $res2[] = "$k:$v";
                }
                if (!$finalOk && $nbres != $expected) {
                    $log[] = ';ten:' . $tentative . ';nbres:' . $nbres . '/exp:' . $expected . ';transac:' . implode("\t", $res2);//                    $finalOk = 0
                    echo "\n#" . implode(',', $log);
                    $cli->close();
                    unset($cli);
                    $log = [$pid, $tentative];
                    if (0) {
                        continue;//next loop, refaire tout scénario
                        Swoole\Process::exit($errorExitCode);
                        die($errorExitCode);
                    }
                } else {
                    $cli->push(json_encode(['incr' => 'nbCompleted']));
                    $cli->close();
                    unset($cli);
                    $finalOk=1;
                    $log[] = 'ok,scenarioComplete';//                    $finalOk = 1;
                    return;
                    Swoole\Process::exit($okExitCode);
                }
            } catch (\Exception $e) {//   Fatal error: Uncaught Swoole\ExitException
                if ($e->getMessage() == 60) {
                    $renewConnection = 1;
                }
            } catch (Swoole\ExitException $e) {//   Fatal error: Uncaught Swoole\ExitException
                $log[] = $e;
                return $log;
                return $e->getStatus();
            } catch (\throwable $e) {
                $log[] = $e;
                return $log;
                fpc('err.log', "\nC:" . $pid . '=>' . $e->getMessage(), 8);
            }
        }
    });

    if($finalOk){
        die('+');
    }
    if ($log and is_array($log)) {
        echo "\n" . implode(',', $log);
    } else {
        echo $log;
    }
} catch (Swoole\ExitException $e) {//   Fatal error: Uncaught Swoole\ExitException
    return $e->getStatus();
} catch (\throwable $e) {
    fpc('err.log', "\nC:" . getmypid() . '=>' . $e->getMessage(), 8);
}

if($exit)die($exit);
die(1);
//die($e->getStatus());


function db($x, $fn = '../3.log')
{
    if (!$GLOBALS['log']) return;
    $y = $_ENV['fd'];//c$y=$argv[2];
    global $argv;
    if (is_array($x)) $x = stripslashes(json_encode($x));
    fpc($fn, "\nC:" . $y . ':' . $x, 8);
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
                fpc('6.log', "\nC:" . getMyPid() . ":Consuming:" . $j['message'], 8);
            } else {
                db("Msg:::" . $j['message']);
            }
        }
    } else {// grep message 4.log
        fpc('4.log', "\nCm:" . getMyPid() . ',' . substr($x, 0, 1) . substr($x, -1) . ":" . $q . '=>' . $x, 8);// B3
    }
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
