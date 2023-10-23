<?php
/*
 * php ../shiva4-nbConnections.php responseAsap=1 &
 * php --ri openswoole | grep version
 * ps -ax|grep shiva|grep -v grep|wc -l
 */
$maxConn = 300;//10240; -> opens more processes
$nbChannels = 30;
$reaktors = $workers = 1;
$maxConn = $backlog = 900;
$tick = 20000000;// tick each 20 sec .. only on first Process .. Evaluates pending Messages towards newly (Delayed) Connected Consumers


$listTableNb = 900;
$swooleTableStringMaxSize = $listTableNbMaxSize = 9000;// participants, suscribers, free, pending: => intercepted to channel o


$pvc = $salt = '';
$socketSizeKo = 1;
$p = 2000;
$dmi = 'YmdHi';
$timerMs = 1000;
$redisPort = 6379;

$redisIp = '127.0.0.1';

$pass = ['bob' => 'pass', 'alice' => 'wolf'];
$maxMemUsageMsgToDisk = 50000 * 1024 * 1024;
$setmem = $log = $del = $memUsage = $action = $needAuth = 0;

$taskWorkers = 1;//$_ENV['taskWorkers'] ?? 2;
//$_ENV['nbChannels'] ?? 30;
$nbAtomics = 0;// non nécessaires ici //$_ENV['nbAtomics'] ?? 100;
$binSize = strlen(bindec(max($nbChannels, $nbAtomics)));
$capacityMessagesPerChannel = 90;//$_ENV['chanCap'] ?? 90;
$maxSuscribersPerChannel = 200;//$_ENV['subPerChannel'] ?? 200;
$_ENV['gt'] = [];


if ('default overridable configuration') {//        //
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
    ini_set('display_errors', 1);
}

if(0){//    http://talks.deminy.in/v4/csp-programming-in-php.html   -> https://github.com/hyperf/hyperf
    $channel = new Swoole\Coroutine\Channel(2);
    $channel->push('pop');
    $channel->pop();
}

if (1) {
    $tokenHashFun = function ($user, $pass) use ($dmi, $salt) {
        return hash('sha256', $user . date($dmi) . $salt . $pass);
    };

    $rea = ceil($reaktors);#(swoole_cpu_num() * $reaktors);
    $nbWorkers = ceil($workers);#(swoole_cpu_num() * $workers);
    if ($nbWorkers < $rea) $nbWorkers = $rea;
    echo "\nMaster:" . \getmypid() . ":Rea:$rea,wor:$nbWorkers";

    $options = [//  https://www.swoole.co.uk/docs/modules/swoole-server/configuration
        'heartbeat_idle_time' => 600000,
        'heartbeat_check_interval' => 60,// leads to some workers timeouts errors

        'reactor_num' => $rea,
        'worker_num' => $nbWorkers,
        'max_request' => 0,
        'discard_timeout_request' => true,
        'input_buffer_size' => 2097152,
        'open_tcp_nodelay' => true,
        //'open_tcp_keepalive'=>false,'heartbeat_check_interval'=>60
        'buffer_output_size' => $socketSizeKo * 1024 * 1024, // byte in unit
        'socket_buffer_size' => $socketSizeKo * 1024 * 1024,//    256Ko par transmission
        'backlog' => $backlog,// Max pending connections

        // see:https://openswoole.com/docs/modules/swoole-server/configuration
        'tcp_fastopen' => true,
        // The value of max_conn should be less than ulimit -n on Linux
        //The value of max_conn should be more than (server->worker_num + SwooleG.task_worker_num) * 2 + 32
        'max_conn' => $maxConn,// Max active connections - limited to ulimit -n : 256
        'max_connection' => $maxConn,// Max active connections - limited to ulimit -n : 256
        'enable_coroutine' => true,
        'open_cpu_affinity' => true,
        'max_request_execution_time' => 1000, // in milliseconds
        'dispatch_mode' => 2,// 2: Fixed fd per process : 7 : use idle, 1 : async and non blocking, 3 : worker only taks actual possible connections
        //'tcp_defer_accept' => 1,// wait foreach connections
        'max_request_grace' => 0,
        'socket_timeout'=>20,
        'socket_connect_timeout' => 2,
        'socket_read_timeout' => 5,
        'socket_write_timeout' => 5,
        //'daemonize' => 1,
        'log_file' => '/dev/null',//sw.log - output with daemonize
        'log_level' => 0,//SWOOLE_LOG_INFO,
        //'ping_timeout' => 6000000,
        /*
        'request_slowlog_file' => 'slow.log',
        'request_slowlog_timeout' => 2,
        'trace_event_worker' => true,
        */
    ];

    //$options = [];// No options : all scenarios ok :)
}
echo "\n" . json_encode($options);

chdir(__dir__);
require_once 'common.php';

use \Swoole\Websocket\Server;

register_shutdown_function(function () {
    echo "\nDied:" . \getmypid();
});

//compact('taskWorkers,nbChannels,nbAtomics,capacityMessagesPerChannel,maxSuscribersPerChannel')+['p'=>$p]
$sw = new wsServer($p, $options, $needAuth, $tokenHashFun, $pass, $log, $timerMs);

class wsServer
{
    public $log, $timer, $bgProcessing = false, $tokenHashFun, $passworts = [], $needAuth = false, $uuid = 0, $parentPid = 0, $server, $redis, $pid = 0, $frees = [], $pendings = 0, $conn = [], $clients = [], $fdIs = [], $h2iam = [], $pool = [], $fd2sub = [], $auths = [], $ticks = [];//, $tick = 20000000;

    function onMessage($server, $frame)// simple reflexion
    {
        $this->send($frame->fd, $frame->data);
    }

    function db($x, $level = 9, $minLogLevel = 1)
    {
        return;
    }

    function processPendingInBackground($via = 0)
    {
        echo "\nBg";
        return;
    }

    public function __construct($p, $options, $needAuth = false, $tokenHashFun = null, $passwords = [], $log = false, $timer = 10000)
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


    function send($fd, $mess, $plus = '')
    {
        if (!$fd) {
            $this->db(['wtr?', debug_backtrace(-2)], 99);
            return;
        }
        if (is_array($mess)) {
            $mess = json_encode($mess);
        }
        $success = 0;
        while (!$success) {
            $success = $this->server->push($fd, $mess);// or send
            if (!$success) {
                $this->db('nosuccess sending ' . $mess . ' to ' . $fd . ' -- disconnected ?');
                sleep(1);
            }
            $this->db('sentto:' . $fd);
        }
        return;
        $this->r()->incr('sSend');
        return;
    }

    function rep($sender, $frame)
    {
        $this->send($sender, $frame->data . ':' . hash('crc32c', $frame->data));
    }


    function r()
    {
        global $nbWorkers;
        if ($this->redis) return $this->redis;
        if ($nbWorkers == 1 or 1152) {
            $_ENV['__a'] = $this->redis = new r1();
        } else {
            $_ENV['__a'] = $this->redis = new AtomicRedis();
        }
        return $this->redis;
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
                $this->pid = \getmypid();
                //  \Swoole\Timer::tick($this->timer, [$this, 'processPendingInBackground'], ['timer']);

                register_shutdown_function(function () {
                    global $nbWorkers;
                    if ($nbWorkers == 1) {
                        file_put_contents('swoole.1worker.dump.json', json_encode($this->r()->dump()));
                    }

                    if (0) {
                        $a = json_encode($_ENV['ref']);
                        $b = [];
                        foreach ($_ENV['ref'] as $k => $v) {
                            $b[$k] = $v;
                        }
                        file_put_contents('swoole.a.dump.json', json_encode($a));
                        file_put_contents('swoole.b.dump.json', json_encode($b));
                    }
                    $this->db('killed');
                });
            });

            $server->on('workerstop', function (Server $server) use ($p) {
                $this->db('workerstop', 99);// Cleans the pool, participants who shall reconnect to another instance
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
                    echo "\ntimer";
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
            //$server->on('handshake', [$this, 'handshake']);
            $server->on('message', [$this, 'onMessage']);
            $server->on('open', function ($server, $req) {
                $this->send($req->fd, 'opened');
                //$server->tick(1000, function() use ($server, $req) {$server->push($req->fd, json_encode(['hb', time()]));});
                //echo"\nopen";
                return;
                $a = microtime(true) * 1000;
                //$this->childProcess('open');
                $this->r()->incr('enCours');//sOpen,sClose,serverError,sMessage
                $this->r()->incr('sOpen');//sOpen,sClose,serverError,sMessage
                $this->add('participants', $this->pid . ',' . $req->fd);
                $this->pool[] = $req->fd;
                $this->db("connection open: {$req->fd}", 2);
                $a = round((microtime(true) * 1000) - $a);
                if ($a > 100) echo "\n" . $a;
                //echo "\n" . '{"pid":' . $this->pid . ',"id":' . $req->fd . '}';
                $this->send($req->fd, '{"pid":' . $this->pid . ',"id":' . $req->fd . '}');//so he knows who he actually is

                if (0) {
                    $this->ticks[$req->fd] = $server->tick($this->tick, function () use ($server, $req) {//Heartbeats, keep connection open
                        $this->db('tick hb pool :' . implode(',', $this->pool));
                        if (!in_array($req->fd, $this->pool)) {
                            $server->clearTimer($this->ticks[$req->fd]);
                            return;
                        }
                        echo '\nhb:' . $req->fd;
                        $this->send($req->fd, 'hb:' . microtime(1));
                    });
                }

            });

            $server->on('disconnect', function ($server, $fd) {
                echo"\ndisconnected";
            });
            $server->on('close', function ($server, $fd) {
                return;
                echo "\nClosed";
                return;
                $this->r()->decr('enCours');
                $this->r()->incr('sClose');//sOpen,sClose,serverError,sMessage
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

            $server->on('start', function (Server $server) use ($p) {
                echo "\nStart";
                global $swooleTableStringMaxSize, $nbReferences, $nbChannels, $nbAtomics, $nbWorkers, $capacityMessagesPerChannel, $binSize, $nbWorkers, $listTableNb, $listTableNbMaxSize;
                $this->r()->incr('sStart');//sOpen,sClose,serverError,sMessage,sStart
                $this->db("\t\t\t" . \getmypid() . '/' . $this->uuid . '::started::the parent process');// Doit en avoir un seul

                if ($nbWorkers > 1 and 'onServerStart if more than 1 worker, rely on Atomic and Tables') {// so each worker reference the same atomic space in order to keep the right numbers
                    $_ENV['atomics'] = [
                        'received' => new Swoole\Atomic(),
                        'occupiedChannels' => new Swoole\Atomic(),
                        'connectedConsumers' => new Swoole\Atomic(),
                    ];
                    // 'process:server' => new Swoole\Atomic(), 'process:manager' => new Swoole\Atomic()];
                    $_ENV['tables'] = $_ENV['channels'] = $_ENV['workerAtomics'] = [];

                    $_ENV['listTable'] = new Swoole\Table($listTableNb);// todo : l'ajout d'une table ( listTable[] )permettrait t-elle .. ??
                    $_ENV['listTable']->column('v', Swoole\Table::TYPE_STRING, $listTableNbMaxSize);// rows

                    $_ENV['rkv'] = new Swoole\Table($nbReferences);
                    $_ENV['rkv']->column('v', Swoole\Table::TYPE_STRING, $swooleTableStringMaxSize);
                    $_ENV['rkv']->create();

                    if ('Affecter 30 channels libres -> un nom vers un identifiant afin de savoir rapidement le nb de message dedans') {
                        // is an array
                        $_ENV['rkv']->set('freeChannels', ['v' => json_encode(range(0, $nbChannels - 1))]);
                        if ($nbAtomics) {
                            $i = 0;
                            while ($i < $nbAtomics) {
                                $_ENV['atomics'][$i] = new Swoole\Atomic();
                                $i++;
                            }
                            $_ENV['rkv']->set('freeAtomics', ['v' => json_encode(range(2, $nbAtomics - 1))]);// Afin de faire référence à des channels dont les noms n'ont pas encore été crées
                        }
                        if (0 and 'aquoicasert ?') {
                            $fc = json_encode(range(0, $nbWorkers - 1));
                            _e("\n" . __line__ . '::' . $fc);
                            $_ENV['rkv']->set('freeAtomicsWorkers', ['v' => $fc]);
                            $i = 0;
                            while ($i < $nbWorkers) {
                                $_ENV['workerAtomics'][$i] = new Swoole\Atomic();
                                $i++;
                            }
                        }

                        $i = 0;
                        while ($i < $nbChannels) {
                            $_ENV['channels'][$i] = new Swoole\Coroutine\Channel($capacityMessagesPerChannel);
                            $_ENV['rkv']->set('subscribersPerChannel:' . $i, ['v' => '[]']);
                            //$_ENV['subcriberPerChannel'][$i] = new Swoole\Coroutine\Channel($maxSuscribersPerChannel); // push && pop only ...
                            $i++;
                        }

                    }
if('afin de permettre le mapping'){
    $_ENV['ref'] = new Swoole\Table($nbReferences);
//$_ENV['ref']->column('e', Swoole\Table::TYPE_STRING, 1);// a:atomic,c:channel
    $_ENV['ref']->column('v', Swoole\Table::TYPE_INT, $binSize);// 8:256 values
    $_ENV['ref']->create();
}
                }


                // Les childs sont spawnés à ce moment .. overrider class server
            });
            $server->start();
            echo ',' . __line__;
        } catch (\Throwable $e) {
            $this->r()->incr('serverError');
            print_r($e);
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

class r2 extends \Redis
{// simple passthrough
}

class r1
{// single process only :: all data is stored on the server process : relies on simplest php data array
    public $a = [];

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

class AtomicRedis // when more than single worker
{
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
