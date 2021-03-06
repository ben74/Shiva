<?php
$pvc = $salt = '';
$p = 2000;
$dmi = 'YmdHi';
$timerMs = 1000;
$redisPort = 6379;
$redisIp = '127.0.0.1';
$reaktors = $workers = 1;
$pass = ['bob' => 'pass', 'alice' => 'wolf'];
$maxMemUsageMsgToDisk = 50000 * 1024 * 1024;
$setmem = $log = $del = $memUsage = $action = $needAuth = 0;

if (1 or 'default overridable configuration') {//        // p=2000 reaktors=1 workers=1 pass={"bob":"pass","alice":"wolf"}
    $a = [];
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

    if ($setmem) {
        $r = new \Redis();
        $r->connect($redisIp, $redisPort);
        $r->set('memUsage', $setmem);// If above threshold, put new pending data to disk : RSS vs VSZ
        die('memset:' . $setmem);
    }

    if ($del and 'FLUSHES THE DB KEYS') {
        $r = new \Redis();
        $r->connect($redisIp, $redisPort);
        $k = $r->keys('*');
        foreach ($k as $key) {
            $r->del($key);
        }
        print_r($k);
        die;
    }
}

if (1) {
    $tokenHashFun = function ($user, $pass) {
        return hash('sha256', $user . date($dmi) . $salt . $pass);
    };

    $rea = ceil($reaktors);#(swoole_cpu_num() * $reaktors);
    $wor = ceil($workers);#(swoole_cpu_num() * $workers);
    if ($wor < $rea) $wor = $rea;
    echo "\nMaster:" . getMyPid() . ":Rea:$rea,wor:$wor";
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
        'socket_buffer_size' => 256 * 1024 * 1024,//256Ko
        'backlog' => 4000,// Max pending connections
        'max_connection'=> 4000,// Max active connections - limited to ulimit -n : 256
        // 'tcp_defer_accept'=>1,// sec
    ];
}

chdir(__dir__);
require_once 'common.php';

use \Swoole\Websocket\Server;

register_shutdown_function(function () {
    echo "\nDied:" . getMyPid();
});

$sw = new wsServer($p, $options, $needAuth, $tokenHashFun, $pass, $log, $timerMs);

class wsServer
{
    public $log, $timer, $bgProcessing = false, $tokenHashFun, $passworts = [], $needAuth = false, $uuid = 0, $parentPid = 0, $server, $redis, $pid = 0, $frees = [], $pendings = 0, $conn = [], $clients = [], $fdIs = [], $h2iam = [], $pool = [], $fd2sub = [], $auths = [], $ticks = [], $tick = 20000000;// 20 sec in microseconds here for heartbeat:: keep connection alive

    function db($x, $level = 9, $minLogLevel = 1)
    {
        if ($level > 98) ;
        elseif (!$this->log) return;
        elseif ($level < $minLogLevel) return;
        if (is_array($x)) $x = json_encode($x);
        file_put_contents('3.log', "\nS:" . getmyPid() . ':' . $x, 8);
    }

    function processPendingInBackground($via = 0)
    {
        if ($this->bgProcessing) {
            $this->r()->incr('bgp', 1);
            return;
        }
        $free = $this->frees;
        if (!$free) {
            return;
        }

        ////$this->db('pg', 1);
        $this->r()->incr('bg', 1);
        $this->bgProcessing = true;

        foreach ($free as $fd) {
            if (isset($this->fd2sub[$fd]) and $this->fd2sub[$fd]) {
                foreach ($this->fd2sub[$fd] as $sub) {
                    //$this->r()->rpush('db', __line__);
                    $m = false;
                    if ($this->r()->exists('pending:' . $sub) and ($this->r()->LLEN('pending:' . $sub)) and ($m = $this->r()->lPop('pending:' . $sub)) and $m) {
                        //$this->r()->rpush('db', __line__);
                    } elseif ($this->r()->hExists('p2d', $sub) and $this->r()->hget('p2d', $sub)) {// Too much memory pressure then ...
                        //$this->r()->rpush('db', __line__);
                        $this->r()->HINCRBY('p2d', $sub, -1);
                        $x = glob($pvc . $sub . '/*.msg');
                        if ($x) {
                            $x = reset($x);
                            $m = file_get_contents($x);
                            $this->r()->decr('p2disk');
                            unlink($x);
                            $this->db($x, 1);
                        }
                    }
                    if ($m) {
                        //$this->r()->rpush('db', __line__);
                        $this->busy($this->pid . ',' . $fd, $fd);
                        $this->r()->HINCRBY('pendings', $sub, -1);
                        $this->send($fd, json_encode(['queue' => $sub, 'message' => $m]), 'async');
                        $this->r()->HINCRBY('p2h', $fd, 1);
                        $nbSent = $this->r()->hget('p2h', $fd);
                        if ($nbSent > 1) {
                            $this->db(implode(' ;; ', $this->frees) . ' >> ' . $fd);
                        }
                        $this->r()->incr('sAsyncOk');
                        $this->r()->decr('sPending');
                        $this->db('free: ' . $fd . " $sub receives :" . $m, 1);
                        break;// Cuz not free anymore
                    }
                }
            }
        }
        $this->bgProcessing = false;
        return;
    }

    public function __construct($p, $options, $needAuth = false, $tokenHashFun = null, $passwords = [], $log = false, $timer = 1000)
    {
        $this->log = $log;
        $this->timer = $timer;
        $this->needAuth = $needAuth;
        $this->passworts = $passwords;
        $this->tokenHashFun = $tokenHashFun;
        $this->parentPid = getMyPid();
        $this->run($p, $options);
    }

    function getLock($x)
    {
        foreach (range(1, 1) as $nb) {
            if ($this->r()->exists('lock:' . $x . ':' . $nb)) {
                return;
            }
            if (!$this->r()->setNX('lock:' . $x . ':' . $nb, 'P:' . getmypid())) {
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
        $this->frees = array_diff($this->frees, [$fd]);//Sur lui m??me, si c'est le cas
        $this->rem('free', $him);
        $this->r()->incr('busy', 1);
    }

    function free($him, $fd)
    {
        $this->frees[] = $fd;
        $this->r()->rPush('free', $him);
        $this->r()->incr('freed', 1);
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
        $success = 0;
        while (!$success) {
            $success = $this->server->push($fd, $mess);// or send
            if (!$success) {
                $this->db('nosuccess sending ' . $mess . ' to ' . $fd . ' -- disconnected ?');
                sleep(1);
            }
            $this->db('sentto:' . $fd);
        }
        $this->r()->incr('sSend');
        return;
    }

    function onMessage($server, $frame)
    {
        $this->r()->incr('SonMessage');
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
            if (isset($j['get'])) {
                if ($j['get'] == 'time') {
                    $this->send($sender, time());
                    return;
                }
            }
            if (isset($j['keepalive'])) {// do nothing : argon, neon, krypton, inerte
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
                    $this->r()->rPush($j['setQueue'], $v);
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
                if (isset($this->h2iam[$himself])) {//changement d'identit?? sur le vol
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
                // L'user recoit ses messages priv??s
                while ($this->r()->llen('user:' . $j['iam'])) {
                    $msg = $this->r()->lpop('user:' . $j['iam']);
                    $this->send($sender, $msg);
                }
                $this->send($sender, json_encode(['iam' => $j['iam'], 'pid' => $this->pid, 'fd' => $sender]));//Il se bouffe toute la queue de notificationes
                return;
            }
            if (isset($j['broad'])) $j['broadcast'] = $j['broad'];
            if (isset($j['msg'])) $j['message'] = $j['msg'];
            if (isset($j['sus'])) $j['suscribe'] = $j['sus'];
            if (isset($j['subscribe'])) $j['suscribe'] = $j['subscribe'];
            if (isset($j['unsubscribe'])) $j['unsuscribe'] = $j['unsubscribe'];

            if (isset($j['suscribe'])) {
                $this->send($sender, $frame->data . ':' . hash('crc32c', $frame->data));
                $this->r()->set('lastSub', microtime(true));
                $this->add('suscribers:' . $j['suscribe'], $this->pid . ',' . $sender);
                $this->fd2sub[$sender][] = $j['suscribe'];
                $this->r()->lPush('pid2sub:' . $this->pid . ',' . $sender, $j['suscribe']);
                return;
            } elseif (isset($j['unsuscribe'])) {
                $this->send($sender, $frame->data . ':' . hash('crc32c', $frame->data));
                $this->fd2sub[$sender] = array_diff($this->fd2sub[$sender], [$j['suscribe']]);
                $this->rem('suscribers:' . $j['unsuscribe'], $this->pid . ',' . $sender);
                $this->r()->lRem('pid2sub:' . $this->pid . ',' . $sender, $j['unsuscribe']);
                return;
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
                    $this->r()->incr('sPushed');
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
                        $this->db(['him' => $him, 'libres' => $libres]);
                    }
                    if ($fd) {
                        $this->busy($him, $fd);
                        $this->r()->incr('sSentAsap');
                        $this->r()->HINCRBY('p2h', $fd, 1);
                        $this->send($fd, json_encode(['queue' => $j['push'], 'message' => $j['message']]));
                        return;
                    } else {
                        $this->r()->incr('sPending');
                        $this->r()->set('lastPending', microtime(true));
                        $this->r()->HINCRBY('pendings', $j['push'], 1);
                        $this->db('new pending msg for ' . $j['push'], 1);// Then todo: en cas de pending upon free event
                        if ($this->r()->get('memUsage') > $maxMemUsageMsgToDisk) {
                            $this->r()->HINCRBY('p2d', $j['push'], 1);
                            $this->r()->incr('p2disk');
                            if (!is_dir($j['push'])) mkdir($j['push']);
                            file_put_contents($pvc . $j['push'] . '/' . microtime() . '.msg');
                        } else {
                            $this->r()->rPush('pending:' . $j['push'], $j['message']);// En attendant qu'une lib??ration ..
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

            if (isset($j['status'])) {
                $a = 1;
                if ($j['status'] == 'free') {
                    $this->send($sender, $frame->data . ':' . hash('crc32c', $frame->data));
                    $sent = $this->shallSend2Free($himself, $sender);
                    if (!$sent) {
                        $this->free($this->pid . ',' . $sender, $sender);
                    }
                    return;
                } else {//busy
                    $this->send($sender, $frame->data . ':' . hash('crc32c', $frame->data));
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
                $this->send($sender, $frame->data . ':' . hash('crc32c', $frame->data));
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

    function shallSend2Free($himself, $sender)
    {
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
                        $x = glob($pvc . $sub . '/*.msg');
                        $x = reset($x);
                        $m = file_get_contents($x);
                        unlink($x);
                    }
                    if ($m) {
                        $this->r()->decr('p2disk');
                        $this->r()->incr('sMsgSentOnFree');
                        $this->r()->decr('sPending');
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
        if ($this->redis) {
            if ($this->redis->ping()) {   //check if is alive, otherwise re-create
                return $this->redis;
            }// Sinon l'on recr??e une connexion
        }
        $this->redis = new \Redis();
        try {
            $this->redis->connect('127.0.0.1', 6379);
            if (!$this->redis) die('noconnect');
        } catch (\exception $e) {
            print_r($e);
            die;
        }
        if (!$this->redis) {
            die("\nRedis dead");
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
            $this->server = $server = new Server('0.0.0.0', $p);
            $server->set($options);
            ///*
            $server->on('workerstart', function (Server $server) use ($p) {
                //$this->childProcess('workerstart');
                if ($this->pid) return;
                $this->pid = getMyPid();
                \Swoole\Timer::tick($this->timer, [$this, 'processPendingInBackground'], ['timer']);

                register_shutdown_function(function () {
                    foreach ($this->pool as $fd) {
                        $himself = $this->pid . ',' . $fd;
                        $this->busy($himself, $fd);
                        $this->rem('participants', $himself);
                        $suscribtions = $this->r()->lrange('pid2sub:' . $himself, 0, -1);
                        foreach ($suscribtions as $sub) {
                            $this->r()->lrem('suscribers:' . $sub, $himself);
                        }
                        $this->r()->del('pid2sub:' . $himself);
                    }
                    $this->db('killed');
                });
            });
            $server->on('workerstop', function (Server $server) use ($p) {
                $this->db('workerstop', 99);// Cleans the pool, participants who shall reconnect to another instance

                foreach ($this->pool as $fd) {// Pour le pool des connections actives
                    $himself = $this->pid . ',' . $fd;
                    if (isset($this->h2iam[$himself])) {
                        $iam = $this->h2iam[$himself];
                        $this->r()->Hdel('iam', $iam);
                        unset($this->fdIs[$himself]);
                    }

                    $suscribtions = $this->r()->lrange('pid2sub:' . $himself, 0, -1);
                    foreach ($suscribtions as $sub) {
                        $this->r()->lrem('suscribers:' . $sub, $himself);
                    }
                    $this->r()->del('pid2sub:' . $himself);

                    $this->busy($himself, $fd);
                    $this->rem('participants', $himself);
                }
                foreach ($this->fdIs as $hm => $iam) {
                    $this->r()->Hdel('iam', $iam);
                }
            });
            $server->on('workererror', function (Server $server) use ($p) {
                $this->db(['workererror', $p], 99);
            });
            $server->on('managerstart', function (Server $server) use ($p) {
                $this->db('managerstart');
            });
            $server->on('managerstop', function (Server $server) use ($p) {
                $this->db('managerstop');
            });
            if (1) {
                $server->on('timer', function (Server $server) use ($p) {
                    $this->db('timer');
                });
                $server->on('connect', function (Server $server) use ($p) {
                    $this->db('connect');
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
                    return;
                    $this->db(['ontask' => $serv]);
                    $task->worker_id;
                    $task->id;
                    $task->flags;
                    $task->data;
                    $task->dispatch_time;
                    co::sleep(0.2);
                    $task->finish([123, 'hello']);
                });
            }
            //*/
            $server->on('message', [$this, 'onMessage']);
            $server->on('open', function ($server, $req) {
                //$this->childProcess('open');
                $this->r()->incr('sOpen');//sOpen,sClose,serverError,sMessage
                $this->add('participants', $this->pid . ',' . $req->fd);
                $this->pool[] = $req->fd;
                $this->db("connection open: {$req->fd}", 2);
                $this->send($req->fd, '{"pid":' . $this->pid . ',"id":' . $req->fd . '}');//so he knows who he actually is

                $this->ticks[$req->fd] = $server->tick($this->tick, function () use ($server, $req) {//Heartbeats, keep connection open
                    $this->db('tick hb pool :' . implode(',', $this->pool));
                    if (!in_array($req->fd, $this->pool)) {
                        $server->clearTimer($this->ticks[$req->fd]);
                        return;
                    }
                    $this->send($req->fd, 'hb:' . microtime(1));
                });
            });

            $server->on('close', function ($server, $fd) {
                $this->r()->incr('sClose');//sOpen,sClose,serverError,sMessage
                $this->pool = array_diff($this->pool, [$fd]);
                $himself = $this->pid . ',' . $fd;
                if (isset($this->h2iam[$himself])) {
                    $iam = $this->h2iam[$himself];
                    $this->r()->Hdel('iam', $iam);
                    unset($this->fdIs[$himself]);
                }
                $suscribtions = $this->r()->lrange('pid2sub:' . $himself, 0, -1);
                foreach ($suscribtions as $sub) {
                    $this->r()->lrem('suscribers:' . $sub, $himself);
                }
                $this->r()->del('pid2sub:' . $himself);
                $this->busy($himself, $hd);
                $this->rem('participants', $himself);
                $this->db("connection close: {$fd}", 2);
            });

            $server->on('start', function (Server $server) use ($p) {
                $this->r()->incr('sStart');//sOpen,sClose,serverError,sMessage,sStart
                $this->db("\t\t\t" . getMyPid() . '/' . $this->uuid . '::started::the parent process');// Doit en avoir un seul
                // Les childs sont spawn??s ?? ce moment .. overrider class server
            });
            $server->start();
            echo ',' . __line__;
        } catch (\Throwable $e) {
            $this->r()->incr('serverError');
            print_r($e);
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
}

function _db($x, $fn = '3.log', $lv = 20)
{
    if (!$GLOBALS['log']) return;
    if ($lv < 20) return;
    if (is_array($x)) $x = stripslashes(json_encode($x));
    file_put_contents($fn, "\nS:" . getmypid() . ':' . $x, 8);
}

return;
?>
