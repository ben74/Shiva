<?php
chdir(__dir__);
ini_set('display_errors', 1);
$cli = $_ENV['cli'] = 0;

use Swoole\WebSocket\Frame;
use Swoole\Coroutine\Http\Client;

// todo ::: Not coroutine -> lightweighter client ?
use function Swoole\Coroutine\Run;

chdir(__dir__);
$pid = getmypid();
$errorExitCode = 69;
$okExitCode = 1;
$to = 5;
$exit = $e = $tentative = 0;

try {
    $success = Run(function () use ($argv) {
        global $e, $tentative, $to, $log, $cli, $pid, $exit, $errorExitCode, $okExitCode;
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
        if (in_array($port, ['del','dump','connects', 'test', 'restart'])) {//  php max4.php test shiva.devd339.dev.infomaniak.ch 80
            //echo json_encode($argv);
            $_ENV['cli'] = $cli = new Client($argv[2], $argv[3]);
            $cli->set(['timeout' => 10, 'connect_timeout' => 10, 'write_timeout' => 10, 'read_timeout' => 10,
                //'open_tcp_nodelay' => true,
            ]);
            $cli->upgrade('/');
            $res[] = read('start', $e);
            if ($e) {
                $exit = 69;
                return;
            }
            if ($port == 'connects') {
                $exit = 1;
                return;
            }// User defined signal 2
            if ($port == 'del') {
                $cli->push(json_encode(['del' => 1]));
                $exit = 1;
                return;
            }// User defined signal 2
            if ($port == 'restart') {
                $cli->push(json_encode(['restart' => 1]));
                $exit = 1;
                return;
            }// User defined signal 2

            //$cli->push(json_encode(['queue' => 'ye', 'msg' => 'yeah-men' . time()]));
            $cli->push(json_encode(['dump' => 1]));
            $res = read('dump', $e);
            if ($e) return;
            $log = $res;
            return;
            die($okExitCode);
            throw new ExitException($okExitCode);
            Swoole\Process::exit($okExitCode);
            die();
        }

        if (isset($argv[2])) $nb = $argv[2];
        if (isset($argv[3])) $total = $argv[3];
        if (isset($argv[4])) $host = $argv[4];
        if (isset($argv[5])) $to = $argv[5];

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
                if ($exit) return;
                //echo '.';
                $tentative++;
                if ($cli) {
                    $cli->close();
                    unset($cli);
                }//1st failure
                $_ENV['cli'] = $cli = new Client($host, $port);
                $cli->set(['timeout' => $to, 'connect_timeout' => $to, 'write_timeout' => $to, 'read_timeout' => $to,/*  'open_tcp_nodelay' => true, */]);
                $cli->upgrade('/');
                $log[] = '->conn';
                $try = $x = $e = 0;
                while (!$x) {
                    $x = read('w:' . $try, $e);
                    if ($e) {
                        $x = 1;
                        continue;// Bypasser
                    }
                    $try++;
                    if ($try > 10) {
                        $e = 'too much tries';
                    }
                    sleep(1);
                }
                if ($e) continue;//fail 2 connect

                $res['welcome'] = $x;
                $j = json_decode($x, 1);
                $_ENV['fd'] = $j['id'];

                if ($hearbeats) {
                    pcntl_signal(SIGALRM, function () use ($eachNSeconds, $cli, $fini) {
                        db("Signal fini");//grep "Signal fini" *.log
                        $cli->push(json_encode(['keepalive' => 1]));
                        pcntl_alarm($eachNSeconds);
                        return;
                        $cli->push(json_encode(['hb' => time()]));
                        read('hb', $e);
                        if ($e) return;;//Osef
                        pcntl_alarm($eachNSeconds);// répéter l'interrogation récursivement
                    });
                    pcntl_alarm($eachNSeconds);// KeepAlive
                }

                $finalOk = 0;
                $last = '{"free":"1"}';
                $discussion = ['{"iam":' . $nb . '}', '{"push":"' . $pushes . '","message":"' . $pushes . 'MessagContains:' . $pid . '-' . uniqid() . '"}', '{"status":"free"}', '{"suscribe":"' . $receives . '"}', $last];//,'{"status":"free"}','{wait:}' => la TX du message peut avoir lieu bien après
                foreach ($discussion as $q) {
                    $b = microtime(1);
                    $waits = 0;
                    if ($q == $last) $r->incr('last');
                    $cli->push($q);//$res[$q.((microtime(1)-$a)*1000)]='pushed';
                    $x = read($q, $e);
                    if ($e) break;//  Last Waits here for something to show up, is this blocking ?
                    if (!$x) {
                        fpc('8.log', "\nC:" . $pid . "nosuccess:" . $cli->errCode, 8);//8504
                        continue;
                    }

                    while ($q == $last and !strpos($x, 'MessagContains') and !strpos($x, '"queue":')) {//Waits for the last message
                        $waits++;
                        $cli->push(json_encode(['keepalive' => 1]));//
                        $x = read('free:' . $waits, $e);// Not behaving any better
                        if ($e) {
                            if (1 or $waits > 7) {
                                $x = '-"queue":--MessagContains';//last:{"queue":"ya","message":"yaMessagContains:90484-630883aa07914"}
                                continue;
                            }
                            $e = null;
                        }//2
                        // {"free":"1"}:err:Operation timed out
                        sleep(1);
                    }
                    if ($e) break;


                    if ($waits) $log[] = 'waits:' . $waits;
                    if ($q == $last) {
                        $y = json_decode($x, true);$ack = json_encode(['ack' => $y['ack']]);$cli->push($ack);//

                        //echo "\nack:" . $x . '=>' . $ack;
                        //echo"\nlast:$x";
                        $finalOk = 1;
                        //$log[] = $x;
                    } elseif (strpos($x, 'json pay')) {
                        echo "\n$q->$x";
                    } else {
                        //echo"\nrep:$x";
                    }

                    $res[$q . '::' . round(((microtime(1) - $b) * 1000), 0) . 'ms'] = $x;
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
                    $log[] = 'ok';//                    $finalOk = 1;
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

if ($exit) die($exit);
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

function read($reason = '', &$error = 0)
{
    global $tentative, $log, $pid, $cli, $exit;
    $x = $cli->recv();
    if ($cli->errCode) {//      websocket handshake failed, cannot push data
        $error = $cli->errCode;
        $log[] = $cli->errMsg;
        echo "\n$pid:$tentative:$reason:err:" . $cli->errCode . ',' . $cli->errMsg . '--' . time();
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

return; ?>
pkill -9 -f stressT;pkill -9 -f websocket;pkill -9 -f res.log;# Arret d'Urgence, KillAll !

cd $shiva/demo
tail -f res.log &
echo ''>res.log;pkill -9 -f stressT;pkill -9 -f websocket;phpx websocket-min.php > res.log &
ho='127.0.0.1';po=2001;nb=300;

exitCode=69; while [ $exitCode == 69 ]; do php stressTest.php connects $ho $po;exitCode=$?; done; echo $exitCode;
#sleep 2;#init
pkill -9 -f stressTest.php;   for((i=1;i<$nb;++i)) do ( exitCode=69; while [ $exitCode == 69 ]; do php stressTest.php $po $i $nb $ho >> res.log & pid=$!;wait $pid;exitCode=$?; done;  ) & done;

php stressTest.php test 127.0.0.1 2001 | jq


( exitCode=69; while [ $exitCode == 1 ]; do php php artisan queue:work --queue=delete & pid=$!;wait $pid;exitCode=$?; done;  ) &
