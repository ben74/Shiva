<?php
/*
 * Todo: time to ACK message : 10 sec
 * $this->r()->rpush('ack',time().'->'.json_encode(['queue'=>'q','msg'=>'msg']);
 * foreach($this->r()->a['ack'] as $timeAndMessage){ if(time()+10>now())Break;$this->r()->rPush('pending:queue',$msg);}      // dans le ticker
 */
if ('vars -- overridable via arguments') {
    $maxConn = $backlog = 600;
    $p = 2001;
    $reaktors = $workers = 1;

    $tick = 20000000;
    $listTableNb = 900;
    $swooleTableStringMaxSize = $listTableNbMaxSize = 9000;
    $pvc = $salt = '';
    $socketSizeKo = 1;
    $dmi = 'YmdHi';
    $timerMs = 1000;
    $redisPort = 6379;
    $redisIp = '127.0.0.1';
    $pass = ['bob' => 'pass', 'alice' => 'wolf'];
    $maxMemUsageMsgToDisk = 50000 * 1024 * 1024;
    $setmem = $log = $del = $memUsage = $action = $needAuth = 0;
    $taskWorkers = 2;
    $nbChannels = 30;
    $nbAtomics = 0;
    $binSize = strlen(bindec(max($nbChannels, $nbAtomics)));
    $capacityMessagesPerChannel = 90;
    $maxSuscribersPerChannel = 200;
    $_ENV['gt'] = $a = [];

    $z = $argv;
    array_shift($z);
    foreach ($z as $t) {
        if (strpos($t, '=')) {
            [$k, $v] = explode('=', $t);
            if ($v) {
                ${$k} = $v;
            }
        }
    }
    $pvc = rtrim($pvc . '/', '/');
    ini_set('display_errors', 1);
}

if ('options') {
    $tokenHashFun = function ($user, $pass) use ($dmi, $salt) {
        return hash('sha256', $user . date($dmi) . $salt . $pass);
    };

    $rea = ceil($reaktors); //(swoole_cpu_num() * $reaktors);
    $wor = ceil($workers);  //(swoole_cpu_num() * $workers);
    if ($wor < $rea) $wor = $rea;
    echo "\nMaster:" . \getmypid() . ":Rea:$rea,wor:$wor";
    $options = [//  https://www.swoole.co.uk/docs/modules/swoole-server/configuration
        //$socket>setOption(SOL_SOCKET, SO_REUSEPORT, true)
        'reactor_num' => $rea,
        'worker_num' => $wor,
        // LimitNOFILE=100000
        // swoole WARNING	Server::accept_connection(): accept() failed, Error: Too many open files[24]
        //'daemonize' => 1,
        'log_file' => '/dev/null', 'log_level' => 5,//SWOOLE_LOG_INFO,
        'max_request' => 0,
        'dispatch_mode' => 2,//Fixed fd per process : 7 : use idle, 1 : async and non blocking
        'discard_timeout_request' => false,
        'tcp_fastopen' => true,
        'open_tcp_nodelay' => true,
        //'open_tcp_keepalive'=>false,'heartbeat_check_interval'=>60
        'socket_buffer_size' => $socketSizeKo * 1024 * 1024,//    256Ko par transmission
        'backlog' => $backlog,// Max pending connections
        'max_connection' => $maxConn,// Max active connections - limited to ulimit -n : 256
        // 'tcp_defer_accept'=>1,// sec
    ];
    //$options = ['reactor_num' =>1,'worker_num' =>1];// No options : all scenarios ok :)
    echo "\n" . time() . '-' . json_encode($options);
}

chdir(__dir__);

use \Swoole\Websocket\Server;

register_shutdown_function(function () {
    echo "\nDied:" . \getmypid();
});

$sw = new wsServer($p, $options, $needAuth, $tokenHashFun, $pass, $log, $timerMs);

class wsServer
{
    public $log, $timer, $bgProcessing = false, $tokenHashFun, $passworts = [], $needAuth = false, $uuid = 0, $parentPid = 0, $server, $redis, $pid = 0, $frees = [], $pendings = 0, $conn = [], $clients = [], $fdIs = [], $h2iam = [], $pool = [], $fd2sub = [], $auths = [], $ticks = [], $tick = 20000000;

    function db($x, $level = 9, $minLogLevel = 3)
    {
        if ($level < $minLogLevel) return;
        //return;
        if (is_array($x)) $x = json_encode($x);
        echo "\n" . $level . ':' . $x;
        return;
    }


    function onMessage($server, $frame)
    {
        global $maxMemUsageMsgToDisk, $pvc;
        if (!$this->r()->exists('firstMessage')) $this->r()->set('firstMessage', time());
        $this->r()->incr('nbMessages');
        $sender = $frame->fd;
        $himself = $this->pid . ',' . $sender;
        $this->db("query from {$sender}: {$frame->data}", 1);
        if (substr($frame->data, 0, 1) == '{' and substr($frame->data, -1) == '}') {
            $s = $a = 0;
            $j = json_decode($frame->data, true);
            if (!$j) {
                $this->send($sender, json_encode(['err' => 'not valid json payload']));
                return;
            }

            if (isset($j['debug'])) {
                $a = $_ENV['__a'];
                $this->send($sender, time());
                return;
            }
            if (isset($j['get'])) {
                if ($j['get'] == 'time') {
                    $this->send($sender, time());
                    return;
                }
            }
            if (isset($j['keepalive'])) {// do nothing : argon, neon, krypton, inerte
                //echo"\nargon";
                return;
            }
            if (isset($j['user']) and isset($j['pass'])) {
                if ($this->checkUserPass($j['user'], $j['pass'])) {
                    $this->auths[$sender] = $j['user'];
                    $this->send($sender, 'ok:' . __line__);
                } else {
                    $this->send($sender, 'ko:' . __line__);
                }
                return;
            }

            if (isset($j['token'])) {
                $posibles = $this->expectedTokens();
                $user = array_keys($posibles, $j['token']);
                if ($user) {
                    $this->auths[$sender] = $user;
                    $this->send($sender, 'ok:' . __line__);
                } else {
                    $this->send($sender, 'ko:' . __line__);
                }
                return;
            }

            if ($this->needAuth) {
                if (!isset($mnb[$sender])) {
                    $mnb[$sender] = 0;
                }
                $mnb[$sender]++;
                if ($mnb[$sender] > 1 && !$this->auths[$sender]) {
                    $this->send($sender, '{"err"=>"auth required"}');
                    return;
                }
            }

            if (isset($j['restart'])) {
                return $this->restart();
            }
            if (isset($j['lpop'])) {
                return $this->send($sender, $this->r()->lpop($j['lpop']));
            }
            if (isset($j['rpop'])) {
                return $this->send($sender, $this->r()->rpop($j['rpop']));
            }
            if (isset($j['get'])) {
                return $this->send($sender, $this->r()->get($j['get']));
            }
            if (isset($j['set']) and isset($j['v'])) {
                return $this->r()->set($j['set'], $j['v']);
            }
            if (isset($j['incr'])) {
                return $this->r()->incr($j['incr']);
            }
            if (isset($j['decr'])) {
                return $this->r()->decr($j['decr']);
            }
            if (isset($j['rpush']) and isset($j['v'])) {
                return $this->r()->rpush($j['rpush'], $j['v']);
            }
            if (isset($j['lpush']) and isset($j['v'])) {
                return $this->r()->lpush($j['lpush'], $j['v']);
            }


            if (isset($j['dump'])) {
                $x = $this->r()->dump();
                foreach ($x as $k => &$v) {
                    if (substr($k, 0, 4) == 'pid2') $v = null;
                }
                unset($v);
                $x = array_filter($x);
                $x['Amem'] = memory_get_usage();
                if (!$x['free']) $x['free'] = [];
                $x['nbFree'] = count($x['free']);
                //$x['time'] = $_ENV['gt'];
                $x['Atime'] = $x['lastSent'] - $x['firstMessage'];
                unset($x['iam'], $x['participants'], $x['p2h'], $x['sentAsapToFd'], $x['messagesSentToFdOnBackground']);//dont care ... no care,
                ksort($x);
                $this->send($sender, json_encode($x));
                return;
            }
            if (isset($j['rk'])) {
                $this->send($sender, $this->rgg($j['rk']));
                return;
            }
            if (isset($j['get'])) {
                if ($j['get'] == 'keys') {
                    $this->send($sender, implode(';;', $this->r()->keys('*')));
                    return;
                } elseif ($j['get'] == 'participants') {
                    $this->send($sender, json_encode($this->r()->lrange('participants', 0, -1)));
                    return;
                } elseif ($j['get'] == 'people') {
                    $this->send($sender, json_encode($this->r()->hGetAll('iam')));
                    return;
                } elseif ($j['get'] == 'queues') {
                    $this->send($sender, json_encode($this->r()->keys('pending:*')));
                    return;
                }
            }

            if (isset($j['getQueue'])) {
                $this->send($sender, json_encode($this->r()->lrange('pending:' . $j['getQueue'], 0, -1)));
                return;
            } // todo: implement
            if (isset($j['clearQueue'])) {
                $this->r()->del('pending:' . $j['clearQueue']);
            } // todo: implement
            if (isset($j['setQueue']) && isset($j['elements'])) {
                foreach ($j['elements'] as $v) {
                    $this->add($j['setQueue'], $v);
                }
            } // todo: implement

            if (isset($j['cmd']) and isset($j['message'])) {#/!\Experimental
                $this->send($j['cmd'], $j['message']);#with crc32 reply later ..
            }
            if (isset($j['ping'])) {
                $this->send($sender, $j['ping']);
                return;
            }
            if (isset($j['queueCount'])) {
                $this->send($sender, $this->r()->lLen('queue:' . $j['queueCount']));
                return;
            }
            if (isset($j['iam'])) {
                if (isset($this->h2iam[$himself])) {//changement d'identité sur le vol
                    $this->send($sender, ['err' => 'cant change name']);
                    return;
                }
                $o = $j['iam'];
                while ($this->r()->Hget('iam', $j['iam'])) {
                    $j['iam'] = $o . uniqid();
                }
                $this->h2iam[$himself] = $j['iam'];
                $this->r()->Hset('iam', $j['iam'], $himself);
                $this->fdIs[$himself] = $j['iam'];
                // L'user recoit ses messages privés
                while ($this->r()->llen('user:' . $j['iam'])) {
                    $msg = $this->r()->lpop('user:' . $j['iam']);
                    $this->send($sender, $msg);
                }
                $this->send($sender, json_encode(['iam' => $j['iam'], 'pid' => $this->pid, 'fd' => $sender]));//Il se bouffe toute la queue de notificationes
                return;
            }
            if (isset($j['broad'])) $j['broadcast'] = $j['broad'];
            if (isset($j['msg'])) $j['message'] = $j['msg'];
            if (isset($j['unsubscribe'])) $j['unsuscribe'] = $j['unsubscribe'];
            if (isset($j['sus'])) $j['suscribe'] = $j['sus'];
            if (isset($j['sub'])) $j['suscribe'] = $j['sub'];
            if (isset($j['subscribe'])) $j['suscribe'] = $j['subscribe'];

            if (isset($j['suscribe'])) {
                $topics = $j['suscribe'];
                if (!is_array($topics)) $topics = [$topics];
                $this->rep($sender, $frame);
                $this->r()->set('lastSub', time());
                foreach ($topics as $topic) {
                    $this->add('suscribers:' . $topic, $this->pid . ',' . $sender);
                    $this->fd2sub[$sender][] = $topic;
                    $this->r()->lPush('pid2sub:' . $this->pid . ',' . $sender, $topic);
                }
                return;
            } elseif (isset($j['unsuscribe'])) {
                $this->rep($sender, $frame);
                $topics = $j['unsuscribe'];
                if (!is_array($topics)) $topics = [$topics];
                foreach ($topics as $topic) {
                    $this->fd2sub[$sender] = array_diff($this->fd2sub[$sender], [$topic]);
                    $this->rem('suscribers:' . $topic, $this->pid . ',' . $sender);
                    $this->rem('pid2sub:' . $this->pid . ',' . $sender, $topic);
                }
                return;
            } elseif (isset($j['dropAll'])) {
                $this->rep($sender, $frame);
                $topics = $this->fd2sub[$sender];
                foreach ($topics as $topic) {
                    $this->rem('suscribers:' . $topic, $this->pid . ',' . $sender);
                    $this->rem('pid2sub:' . $this->pid . ',' . $sender, $topic);
                }
            }

            if (isset($j['message'])) {
                if (isset($j['sendto'])) {//DM
                    $a = $s = 1;
                    $this->db($j['sendto'] . '>' . $j['message']);
                    $to = $this->r()->Hget('iam', $j['sendto']);
                    if (!$to) {
                        $this->r()->lPush('user:' . $j['sendto'], json_encode($j));
                        $this->send($sender, 'ko:unknownRecipient' . __line__);
                        return;
                    }
                    [$pid, $fd] = explode(',', $to);
                    $this->send($fd, ['sendto' => $j['sendto'], 'message' => $j['message']]);
                    $this->send($sender, 'ok:' . __line__);
                } elseif (isset($j['push'])) {
                    $this->r()->incr('nbPushed');
                    $a = 1;
                    $s++;
                    $this->send($sender, hash('crc32c', $j['message']));#aka:ack
                    $sub = $this->r()->lrange('suscribers:' . $j['push'], 0, -1);
                    $free = $this->r()->lrange('free', 0, -1);
                    $libres = array_intersect($sub, $free);
                    $pid = $fd = 0;
                    if ($libres) {
                        $him = reset($libres);
                        [$pid, $fd] = explode(',', $him);
                        $this->db(['him' => $him, 'libres' => $libres], 1);
                    }
                    if ($fd) {
                        $this->busy($him, $fd);
                        $this->r()->incr('nbSentAsap');
                        $this->r()->HINCRBY('sentAsapToFd', $fd, 1);
                        $this->send($fd, json_encode(['queue' => $j['push'], 'message' => $j['message']]));
                        return;
                    } else {
                        $this->r()->incr('nbPending');
                        //echo"\nPending++:".$this->r()->get('nbPending');
                        $this->r()->set('lastPending', time());
                        $this->r()->HINCRBY('pendings', $j['push'], 1);
                        $this->db('new pending msg for ' . $j['push'], 1);// Then todo: en cas de pending upon free event
                        if ($this->r()->get('memUsage') > $maxMemUsageMsgToDisk) {
                            $this->r()->HINCRBY('p2d', $j['push'], 1);
                            $this->r()->incr('nbMessages2disk');
                            if (!is_dir($j['push'])) mkdir($j['push']);
                            file_put_contents($pvc . $j['push'] . '/' . microtime() . '.msg');
                        } else {
                            $this->add('pending:' . $j['push'], $j['message']);// En attendant qu'une libération ..
                        }
                    }
                } elseif (isset($j['broadcast'])) {
                    $sub = $this->r()->lrange('suscribers:' . $j['broadcast'], 0, -1);
                    foreach ($sub as $pid2fd) {
                        [$pid, $fd] = explode(',', $pid2fd);
                        if ($fd != $sender) {
                            $this->send($fd, ['queue' => $j['broadcast'], 'message' => $j['message']]);
                        }
                    }
                    $a = 2;
                } else {
                    $this->send($sender, 'ok:' . __line__ . ':' . $frame->data);
                    return;
                }
            }

            if (isset($j['free'])) {
                $j['status'] = 'free';
            }
            if (isset($j['status'])) {
                $a = 1;
                if ($j['status'] == 'free') {
                    $this->rep($sender, $frame);
                    $sent = $this->shallSend2Free($himself, $sender);// Sends message to first available consumer and maintains him "busy"
                    if (!$sent) {
                        $this->free($this->pid . ',' . $sender, $sender);
                    }
                    return;
                } else {//busy
                    $this->rep($sender, $frame);
                    $this->busy($this->pid . ',' . $sender, $sender);
                    return;
                }
            }

            if (isset($j['consume'])) {//       Set free and returns nothing until new message
                if (!$this->shallSend2Free($himself, $sender)) {
                    $this->free($this->pid . ',' . $sender, $sender);
                }
                return;
            }

            if (!$s) {
                $this->rep($sender, $frame);
            }
            if (!$a) {//auth,list,sub,count,hb,push,broadcast
                $this->send($sender, json_encode(['err' => 'json not valid']));
                $this->r()->incr('sNotUnder');
                $jj = $frame->data;
                if (is_array($j)) $jj = implode(',', array_keys($j));
                $this->db(',' . $this->uuid . ",message not understood :" . $jj);
            }
        } else {
            $this->send($sender, json_encode(['err' => 'not json']));
            $this->r()->incr('sNotJson');
            $this->db(',' . $this->uuid . ",message not json");
        }
        return;
    }

    function restart()
    {// Todo : doesn't work as expected, the connections remains in timeout

        $this->bgProcessing = time();
        $x = $this->r()->dump();
        unset($x['participants'], $x['p2h'], $x['sentAsapToFd'], $x['messagesSentToFdOnBackground']);//dont care ... no care, just need the pending
        foreach ($x as $k => &$v) {
            if (substr($k, 0, 4) == 'pid2') $v = null;
        }
        unset($v);
        $x = array_filter($x);

        // just need pending ones

        file_put_contents('backup.json', json_encode($x));
        $this->frees = [];
        $this->r()->del('free');
        $this->server->stop(-1);
        $this->server->start();

        //    pr kill -USR1 MASTER_PID


        //$this->server->reload(true);//User defined signal 2
        $this->bgProcessing = false;
        echo "\nrestarted";
        return;
    }

    function processNbPendingInBackground($via = 0)
    {
        global $pvc;
        if ($this->bgProcessing) {
            $this->r()->incr('nbBgpLocks', 1);
            return;
        }

        $this->bgProcessing = $now = time();
        if ($_ENV['timeToBackup'] and $this->r()->get('lastBackup') < ($now - $_ENV['timeToBackup'])) {
            $this->r()->set('lastBackup', $now);
            $x = $this->r()->dump();
            foreach ($x as $k => &$v) {
                if (substr($k, 0, 4) == 'pid2') $v = null;
            }
            unset($v);
            $x = array_filter($x);
            $x['Amem'] = memory_get_usage();
            if (!$x['free']) $x['free'] = [];
            $x['nbFree'] = count($x['free']);
            $x['time'] = $_ENV['gt'];
            unset($x['participants'], $x['p2h'], $x['sentAsapToFd'], $x['messagesSentToFdOnBackground']);//dont care ... no care,
            file_put_contents('backup.json', json_encode($x));
        }

        $this->bgProcessing = false;

        $free = $this->r()->get('free');//frees;
        if (!$free) {
            return;
        }
        if (!$this->r()->get('nbPending')) {
            return;
        }

        $this->bgProcessing = $now;
        $this->r()->incr('nbBgProcessing');

        //  $this->db('pg', 1);
        //  echo "\nBgProcessFree:".count($free).'/pen'.$this->r()->get('nbPending');

        foreach ($free as $fd) {
            if (isset($this->fd2sub[$fd]) and $this->fd2sub[$fd]) {// channel souscrit par chacun des processus libres ...
                foreach ($this->fd2sub[$fd] as $sub) {
                    $m = false;
                    if ($this->r()->exists('pending:' . $sub) and ($this->r()->LLEN('pending:' . $sub)) and ($m = $this->r()->lPop('pending:' . $sub)) and $m) {
                        $a = 'message poped :)';
                    } elseif ($this->r()->hExists('p2d', $sub) and $this->r()->hget('p2d', $sub)) {// Too much memory pressure then ...
                        $this->r()->HINCRBY('p2d', $sub, -1);
                        $x = glob($pvc . $sub . '/*.msg');
                        if ($x) {
                            $x = reset($x);
                            $m = file_get_contents($x);
                            $this->r()->decr('nbMessages2disk');
                            unlink($x);
                            $this->db($x, 1);
                        }
                    }
                    if ($m) {
                        $this->busy($this->pid . ',' . $fd, $fd);
                        $this->r()->HINCRBY('pendings', $sub, -1);
                        $this->send($fd, json_encode(['queue' => $sub, 'message' => $m]), 'async');
                        $this->r()->HINCRBY('messagesSentToFdOnBackground', $fd, 1);
                        $nbSent = $this->r()->hget('messagesSentToFdOnBackground', $fd);
                        if ($nbSent > 1) {
                            $this->db(implode(' ;; ', $this->frees) . ' >> ' . $fd);
                        }
                        $this->r()->incr('nbBgSent');
                        $this->r()->decr('nbPending');
                        $this->db('free: ' . $fd . " $sub receives :" . $m, 1);
                        break;// Cuz not free anymore
                    } else {
                        $this->r()->incr('nbNoMessageToPop');
                    }
                }
            }
        }
        $this->bgProcessing = false;// Todo :: Each individual worker shall care of it's own pending queues
        return;
    }

    public function __construct($p, $options, $needAuth = false, $tokenHashFun = null, $passwords = [], $log = false, $timer = 2000)
    {
        global $tick;
        $this->tick = $tick;
        //$_ENV['gt']['started'] = microtime(1);
        $this->log = $log;
        $this->timer = $timer;
        $this->needAuth = $needAuth;
        $this->passworts = $passwords;
        $this->tokenHashFun = $tokenHashFun;
        $this->parentPid = \getmypid();
        if (is_array($p)) {
            foreach ($p as $k => $v) $this->{$k} = $v;
        }
        $this->run($p, $options);
    }

    function getLock($x)
    {
        foreach (range(1, 1) as $nb) {
            if ($this->r()->exists('lock:' . $x . ':' . $nb)) {
                return;
            }
            if (!$this->r()->setNX('lock:' . $x . ':' . $nb, 'P:' . \getmypid())) {
                return;
            }
        }
        return true;
    }

    function rmLock($x)
    {
        foreach (range(1, 1) as $nb) {
            $this->r()->del('lock:' . $x . ':' . $nb);
        }
    }

    function busy($him, $fd)
    {
        $this->frees = array_diff($this->frees, [$fd]);//Sur lui même, si c'est le cas
        $this->rem('free', $him);
        $this->r()->incr('nbBusied', 1);
    }

    function free($him, $fd)
    {
        $this->frees[] = $fd;
        $this->add('free', $him);
        $this->r()->incr('nbFreed', 1);
    }

    function checkUserPass($user, $pass)
    {
        if (!isset($this->passworts[$user])) {
            _db(['nouser' => $this->passworts]);
            return;
        }
        if ($this->passworts[$user] != $pass) {
            _db(['nopass' => $this->passworts[$user] . '!=' . $pass]);
            return;
        }
        return true;
    }

    function expectedTokens()
    {
        if (!$this->tokenHashFun) return [];
        $ret = [];
        foreach ($this->passworts as $user => $pass) {
            $ret[] = \call_user_func($this->tokenHashFun, $user, $pass);// todo un peu lent tout cela, ou base64 '{"user","pass","token"}'
        }
        return $ret;
    }

    function send($fd, $mess, $plus = '')
    {
        if (!$fd) {
            $this->db(['wtr?', debug_backtrace(-2)], 99);
            return;
        }
        if (is_array($mess)) $mess = json_encode($mess);
        if (strpos($mess, '"queue":')) $this->r()->incr('nbPushedConsumed');
        $success = 0;
        while (!$success) {
            $success = $this->server->push($fd, $mess);// or send
            if (!$success) {
/*
-- https://openswoole.com/docs/modules/swoole-server-getLastError : 1004 connection closed
1001: The connection has been closed by the server.
1002: The connection has been closed by the client side.
1003: The connection is closing.
1004: The connection is closed.
1005: The connection does not exist or incorrect $fd was given.
1007: Received data after connection has been closed already. Data will be discarded.
1008: The send buffer is full, cannot perform any further send operations as buffer is full.
1202: The data sent exceeds the buffer_output_size configuration option.
9007: Only for dispatch_mode 3, indicates that the process is not currently available.
*/
                $this->busy($fd, $fd);// remove connection from freed, so no more bottlenecks :)
                $this->db('err:'.$this->server->getLastError().'->'.$fd, 99);
                //$this->db('nosuccess sending ' . $mess . ' to ' . $fd . ' -- disconnected ?' . $plus->finish, 99);
                if ($plus->finish) return;// Tries returning ack for Free
                return;
                sleep(1);
            }
            $this->db('sentto:' . $fd, 1);
        }
        $this->r()->set('lastSent', time());
        $this->r()->incr('nbSend');
        return;
    }

    function rep($sender, $frame)
    {
        $this->send($sender, $frame->data . ':' . hash('crc32c', $frame->data), $frame);
    }

    function handshake($server, $frame)
    {

    }


    function shallSend2Free($himself, $sender)
    {
        global $pvc;
        $this->db('Pid2Sub4:' . $himself . '==>' . $this->r()->exists('pid2sub:' . $himself), 0);
        if ($this->r()->exists('pid2sub:' . $himself)) {
            $suscribedTo = $this->r()->lrange('pid2sub:' . $himself, 0, -1);
            if ($suscribedTo) {
                $this->db('free: ' . $himself . ' is suscribedTo:' . implode(',', $suscribedTo), 0);
                foreach ($suscribedTo as $topic) {
                    $this->db('free: ' . $himself . " $topic has n elements : " . $this->r()->llen('pending:' . $topic), 0);
                    $m = false;
                    if ($this->r()->exists('pending:' . $topic) and $this->r()->llen('pending:' . $topic)) {
                        $m = $this->r()->lPop('pending:' . $topic);
                    } elseif ($this->r()->hExists('p2d', $topic) and $this->r()->hget('p2d', $topic)) {// Too much memory pressure then ...
                        $this->r()->HINCRBY('p2d', $topic, -1);
                        $this->r()->decr('nbMessages2disk');
                        $x = glob($pvc . $topic . '/*.msg');
                        $x = reset($x);
                        $m = file_get_contents($x);
                        \unlink($x);
                    }
                    if ($m) {

                        $this->r()->incr('nbMsgSentOnFree');
                        $this->r()->decr('nbPending');
                        //echo"\nPending--:".$this->r()->get('nbPending');
                        $this->r()->HINCRBY('pendings', $topic, -1);
                        $this->db('free: ' . $himself . " $topic $sender receives :" . $m, 1);
                        $this->send($sender, json_encode(['queue' => $topic, 'message' => $m]));
                        $this->busy($this->pid . ',' . $sender, $sender);
                        return true;
                    }
                }
            }
        }
        return false;
    }

    function r()
    {
        global $wor;
        if ($this->redis) return $this->redis;
        if ($wor == 1) {
            echo "\nstorage:purePhp";
            $_ENV['__a'] = $this->redis = new r1();
        } elseif (1) {
            echo "\nmultiple workers:SwooleAtomic";
            $_ENV['__a'] = $this->redis = new AtomicRedis();
        } else {
            echo "\nmultiple workers:using redis backend";
            //$_ENV['__a'] = $this->redis = new r2();
        }
        return $this->redis;
    }

    function add($k, $v)
    {
        $this->r()->rPush($k, $v);
    }

    function rem($k, $v)
    {
        $this->r()->lrem($k, $v);
    }

    function add2($mem, $k, $v, $col = 'v')
    {
        $x = json_decode($mem->get($k, $col), true);
        $x[] = $v;
        $mem->set($k, [$col => json_encode($x)]);
    }

    function rem2($mem, $k, $v, $col = 'v')
    {
        $x = json_decode($mem->get($k, $col), true);
        $x = array_diff($x, [$v]);
        $mem->set($k, [$col => json_encode($x)]);
    }

    function run($p, $options)
    {
        try {
            // see https://openswoole.com/docs/modules/swoole-server-doc
            //$m=SWOOLE_PROCESS;if($options['worker_num']==1)$m=SWOOLE_BASE;
            $this->server = $server = new Server('0.0.0.0', $p);
            //  $this->server = $server = new swoole_websocket_server('0.0.0.0', $p);
            //$this->server = $server = new Server('0.0.0.0', $p, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
            //$server->set(array('ssl_cert_file' => __DIR__.'/ssl.cert','ssl_key_file' => __DIR__.'/ssl.key',));


            $server->set($options);
            echo "\nServer:" . getMyPid();
            ///*
            $server->on('workerstart', function (Server $server) use ($p) {
                $f = 'backup.json';
                if (is_file($f)) {
                    echo "\nRestoringBackup..";
                    $x = json_decode(file_get_contents($f), true);
                    $this->r()->a = $x;
                }

                //$this->childProcess('workerstart');
                //if ($this->pid) return;
                $this->pid = \getmypid();
                echo "\nwstart" . $this->pid;
                \Swoole\Timer::tick($this->timer, [$this, 'processNbPendingInBackground'], ['timer']);
                return;
                register_shutdown_function(function () {
                });// backup and cleanup pool
            });

            $server->on('workerstop', function (Server $server) use ($p) {
                echo "\nwstop" . getmypid();
                $this->db('workerstop', 99);
                return;
            });

            $server->on('workererror', function (Server $server, int $workerId, int $workerPid, int $exitCode, int $signal) use ($p) {
                echo "\nworkerError" . $exitCode . ':' . getMyPid();
                $this->db(['workererror', $p], 99);
            });

            $server->on('managerstart', function (Server $server) use ($p) {
                echo "\nmStart" . getmypid();
                $this->db('managerstart');
            });

            $server->on('managerstop', function (Server $server) use ($p) {
                echo "\nmStop" . getmypid();
                $this->db('managerstop');
            });

            if (1) {
                $server->on('timer', function (Server $server) use ($p) {
                    $this->db('timer');
                });
                $server->on('connect', function (Server $server, $fd) use ($p) {
                    $this->r()->incr('nbTotConnected');
                    //echo "\nConnect:" . $fd . ':' . time();
                    //$this->db('connect');
                });
                $server->on('disconnect', function (Server $server, $fd) use ($p) {
                    return;
                    $this->r()->decr('connected');
                    $this->db('disconnected',9);
                    //echo "\nConnect:" . $fd . ':' . time();
                });
                $server->on('shutdown', function (Server $server, $fd) use ($p) {
                    echo "\nShutdown:" . time();
                });
                $server->on('receive', function (Server $server) use ($p) {
                    $this->db('receive');
                });
                $server->on('packet', function (Server $server) use ($p) {
                    $this->db('packet');
                });
                $server->on('task', function (Server $server) use ($p) {
                    $this->db('task');
                });
                $server->on('finish', function (Server $server) use ($p) {
                    echo "\nfinish" . getmypid();
                    $this->db('finish');
                });
                $server->on('pipemessage', function (Server $server) use ($p) {
                    $this->db('pipemessage');
                });

                $server->on('beforereload', function (Server $server) use ($p) {
                    $this->db('beforereload');
                });
                $server->on('afterreload', function (Server $server) use ($p) {
                    $this->db('afterreload');
                });

                $server->on('Task', function ($serv, Swoole\Server\Task $task) {
                    echo "\ntask" . getmypid();
                });
            }
            //*/
            //$server->on('handshake', [$this, 'handshake']);
            $server->on('message', [$this, 'onMessage']);
            $server->on('open', function ($server, $req) {
                $a = microtime(true) * 1000;
                //$this->childProcess('open');
                $this->r()->incr('nbConnectionsNonFermees');//nbOpened,nbClosed,serverError,sMessage
                $this->r()->incr('nbOpened');//nbOpened,nbClosed,serverError,sMessage
                $this->add('participants', $this->pid . ',' . $req->fd);
                $this->pool[] = $req->fd;
                $this->db("Connection open: {$req->fd}", 2);
                $a = round((microtime(true) * 1000) - $a);
                if ($a > 100) echo "\nLong opening:" . $req->fd . ":" . $a . " ms";
                //echo "\n" . '{"pid":' . $this->pid . ',"id":' . $req->fd . '}';
                $this->send($req->fd, '{"pid":' . $this->pid . ',"id":' . $req->fd . '}');//so he knows who he actually is
            });

            $server->on('close', function ($server, $fd) {
                $this->r()->decr('nbConnectionsNonFermees');
                $this->r()->incr('nbClosed');//nbOpened,nbClosed,serverError,sMessage
                $this->pool = array_diff($this->pool, [$fd]);
                $himself = $this->pid . ',' . $fd;
                if (isset($this->h2iam[$himself])) {
                    $iam = $this->h2iam[$himself];
                    //echo"\nClosedI:".$iam;
                    $this->r()->Hdel('iam', $iam);
                    unset($this->fdIs[$himself]);
                } else {
                    //echo"\nClosed:".$fd.','.time();
                }
                $suscribtions = $this->r()->lrange('pid2sub:' . $himself, 0, -1);
                foreach ($suscribtions as $sub) {
                    $this->rem('suscribers:' . $sub, $himself);
                }
                $this->r()->del('pid2sub:' . $himself);
                $this->busy($himself, $fd);
                $this->rem('participants', $himself);
                $this->db("connection close: {$fd}", 2);
            });

            $server->on('start', function (Server $server) use ($p) { // Le serveur uniquement, pas les workers
                echo "\nss:" . getmypid();
                // Les childs sont spawnés à ce moment .. overrider class server
            });
            $server->start();
            echo "\nss1:" . getmypid();
            echo ',' . __line__;
        } catch (\Throwable $e) {
            $this->r()->incr('serverError');
            echo "\n" . getmypid() . '--' . $e->getMessage();
            echo "\n" . json_encode($e);
            echo ',' . __line__;
            _db($e);
        }
    }

    function rgg($k)
    {
        $r = $this->r();
        $type = $r->type($k);
        $x = null;
        switch ($type) {
            case 1;
                $x = $r->get($k);
                break;
            case 5;
                $x = $r->hGetAll($k);
                break;
            case 3;
                $x = $r->lrange($k, 0, -1);
                break;
            case 2;
                $x = $r->sMembers($k);
                break;//set
            case 4;
                $x = $r->zRange($k, 0, -1);
                break;//zset
            default:
                return ['err' => 'uktype'];
                $err = 3;
                break;
        }
        return $x;
    }

    function __call($method, $arguments)
    {
        $a = 1;
    }
}

//class r2 extends \redis{}
class r1
{// relies on simplest php data array ever
    public $a = [];

    function setNX($k, $v)
    {
        return $this->set($k, $v);
    }

    function dump()
    {
        return $this->a;
    }

    function llen($k)
    {
        if (!is_array($this->a[$k]) or !$this->a[$k]) return 0;
        return count($this->a[$k]);
    }

    function lpop($k)
    {
        if (!is_array($this->a[$k]) or !$this->a[$k]) return null;
        return array_shift($this->a[$k]);
    }

    function lpush($k, $v)
    {
        if (!is_array($this->a[$k])) $this->a[$k] = [];
        return array_unshift($this->a[$k], $v);
    }

    function rpop($k)
    {
        return array_pop($this->a[$k]);
    }

    function rpush($k, $v)
    {
        //echo"\nPush:$k:$v";
        if (!is_array($this->a[$k])) $this->a[$k] = [];
        return array_push($this->a[$k], $v);
    }

    function hGetAll($k)
    {
        return $this->a[$k];
    }

    function hGet($k, $k2)
    {
        return $this->a[$k][$k2];
    }

    function hSet($k, $k2, $v)
    {
        $this->a[$k][$k2] = $v;
    }

    function hExists($k, $k2)
    {
        return isset($this->a[$k][$k2]);
    }

    function hDel($k, $k2)
    {
        unset($this->a[$k][$k2]);
    }

    function hIncrBy($k, $k2, $v)
    {
        $this->a[$k][$k2] += $v;
    }

    function exists($k)
    {
        return isset($this->a[$k]);
    }

    function del($k)
    {
        unset($this->a[$k]);
    }

    function get($k)
    {
        return $this->a[$k];
    }

    function set($k, $v)
    {
        return $this->a[$k] = $v;
    }

    function incr($k, $v = 1)
    {
        return $this->a[$k] += $v;
    }

    function decr($k, $v = 1)
    {
        return $this->a[$k] -= $v;
    }

    function keys()
    {
        return array_keys($this->a);
    }

    function lrem($k, $v)
    {
        if (!$this->a[$k]) $this->a[$k] = [];
        $this->a[$k] = array_diff($this->a[$k], [0 => $v]);
        $a = 1;
    }

    function lRange($k, $from = 0, $to = -1)
    {
        if (!isset($this->a[$k])) $this->a[$k] = [];
        return $this->a[$k];
    }

    /* pour les dumps uniquement après type */
    function type($k)
    {
        $t = gettype($k);
        switch ($t) {
            case'array':
                $ak = array_keys($k);
                if (is_numeric(reset($ak))) return 5;
                return 3;
                break;#hashmap
            case'int':
            case'string':
                return 1;
                break;
        }
        return 1;
    }// via rgg

    function smembers($k)
    {
        return $this->a[$k];
    }

    function zRange($k, $from = 0, $to = -1)
    {
        return $this->a[$k];
    }


}

class AtomicRedis
{ // when num workers > 1
    function getChannelId($channelName)
    {// Casts channel name to slot
        if (!$_ENV['ref']->exists($channelName)) {
            // refs('c:'.$chanName,$_ENV['atomics']['occupiedChannels']);$_ENV['atomics']['occupiedChannels']++;//connectedConsumers++// mess per channel= $_ENV['channels'][$i]->length()
            $fc = rkg('freeChannels');
            REFS($channelName, array_shift($fc));// Attribution à un Atomic
            RKS('freeChannels', $fc);
            echo "\n" . __line__ . ':' . \getmypid() . ':setChannel as List';
            //$now = rkg('freeChannels');
        }
        $channelId = refg($channelName);
        return $_ENV['channels'][$channelId];
    }


    function llen($chan)
    {
        $c = $this->getChannelId($chan);
        return $_ENV['channels'][$c]->length();
    }

    function lpop($chan)
    {
        if (strpos($chan, 'pending:') === 0) {
            $c = $this->getChannelId($chan);
            return $_ENV['channels'][$c]->pop();
        }
        $x = json_decode($_ENV['listTable'][$chan]['v']);
        $v = array_shift($x);
        $_ENV['listTable'][$chan] = ['v' => json_encode($x)];
        return $v;
    }

    function rpop($chan)
    {
        if (strpos($chan, 'pending:') === 0) {
            $c = $this->getChannelId($chan);
            return $_ENV['channels'][$c]->pop();
        }
        $x = json_decode($_ENV['listTable'][$chan]['v']);
        $v = array_pop($x);
        $_ENV['listTable'][$chan] = ['v' => json_encode($x)];
        return $v;
    }

    function lpush($chan, $msg)
    {
        if (strpos($chan, 'pending:') === 0) {
            $c = $this->getChannelId($chan);
            return $_ENV['channels'][$c]->push($msg);
        }
        if (!$_ENV['listTable'][$chan]['v']) $_ENV['listTable'][$chan]['v'] = '[]';
        $x = json_decode($_ENV['listTable'][$chan]['v']);
        array_unshift($x, $msg);
        $_ENV['listTable'][$chan] = ['v' => json_encode($x)];
    }

    function rpush($chan, $msg)
    {
        if (strpos($chan, 'pending:') === 0) {
            $c = $this->getChannelId($chan);
            return $_ENV['channels'][$c]->push($msg);
        }
        if (!$_ENV['listTable'][$chan]['v']) $_ENV['listTable'][$chan]['v'] = '[]';
        $x = json_decode($_ENV['listTable'][$chan]['v']);
        $x[] = $msg;
        $_ENV['listTable'][$chan] = ['v' => json_encode($x)];
    }


    function hGetAll($k)
    {
        $res = [];
        $keys = array_keys($_ENV['rkv']);
        foreach ($keys as $key => $v) {
            if (strpos($key, $k) === 0) {
                $res[$key] = $v;
            }
        }
        return $res;
    }

    function hGet($k, $k2)
    {
        return $_ENV['rkv']->get($k . $k2)['v'];
    }

    function Hset($k, $k2, $v)
    {
        return $_ENV['rkv']->set($k . $k2, ['v' => $v]);
    }

    function hexists($k, $k2)
    {
        return $_ENV['rkv']->exists($k . $k2);
    }

    function hDel($k, $k2)
    {
        return $_ENV['rkv']->del($k . $k2);
    }

    function hIncrBy($k, $k2, $v)
    {
        return $_ENV['rkv']->incr($k . $k2, 'v', $v);
    }

    //hexists('p2d', $sub) -> is an atomic of channel length pendingToDisk
    function exists($k)
    {
        if ($_ENV['rkv']->exists($k)) return true;//
    }

    function del($k)
    {
        return $_ENV['rkv']->del($k);
    }

    function get($k)
    {
        return $_ENV['rkv']->get($k)['v'];
    }

    function set($k, $v)
    {
        return $_ENV['rkv']->set($k, ['v' => $v]);
    }

    function incr($k, $v = 1)
    {
        return $_ENV['rkv']->incr($k, 'v', $v);
    }

    function decr($k, $v = 1)
    {
        return $_ENV['rkv']->decr($k, 'v', $v);
    }

    function type($k)
    {
        return $k;// via rgg
    }

    function keys($k)
    {
        if ($k == 'pending:*') return array_keys($_ENV['ref']);
        $a = 1;
    }

    /*
     type,smembers,zRange, lrem($k, $v);lrange('pid2sub:' . $himself, 0, -1);
    */
    function __call($method, $arguments)
    {
        $a = 1;
    }
}


function _db($x, $fn = '3.log', $lv = 20)
{
    if (!$GLOBALS['log']) return;
    if ($lv < 20) return;
    if (is_array($x)) $x = stripslashes(json_encode($x));
    file_put_contents($fn, "\nS:" . \getmypid() . ':' . $x, 8);
}

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

return;
?>

pkill -9 -f stressT;pkill -9 -f websocket;phpx websocket-min.php &
ho='127.0.0.1';po=2001;nb=300;

exitCode=69; while [ $exitCode == 69 ]; do php stressTest.php connects $ho $po;exitCode=$?; done; echo $exitCode;
#sleep 2;#init
pkill -9 -f stressTest.php;   for((i=1;i<$nb;++i)) do ( exitCode=69; while [ $exitCode == 69 ]; do php stressTest.php $po $i $nb $ho >> res.log & pid=$!;wait $pid;exitCode=$?; done;  ) & done;

php stressTest.php test 127.0.0.1 2001 | jq
