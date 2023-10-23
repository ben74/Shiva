<?php

use Swoole\Table;
use Swoole\Timer;
use Swoole\Atomic;
use Swoole\Server\Task;
use Swoole\Websocket\Server;
use Swoole\Coroutine\Channel;
/*
 Cant access channel between workers ...
 */
function anomaly()
{
    $xdebugBreackPoint = 1;
}
/*
$channels2start=['ya','yu','ye','yo'];
$capacityMessagesPerChannel = 300;//$_ENV['chanCap'] ?? 90;
$maxSuscribersPerChannel = 300;//$_ENV['subPerChannel'] ?? 200;
*/
$bigMsgToDisk=4096;
$supAdmin = $debug = 0;
$tick = 20000000;// tick each 20 sec :: backup tables to disk
$maxConn = $backlog = 12000;
$swooleTableStringMaxSize = $listTableNbMaxSize = 9000;// participants, suscribers, free, pending: => intercepted to channel on rpush

$listTableNb = 900;// nb max de chaines
$nbPriorities = 3;

$hbIdle = 60000;/*60s*/
$hbCheckEach = 60;// leads to some workers timeouts errors

$pvc = 'messages/';
$salt = '';
$socketSizeKo = 1;
$port = 2000;
$dmi = 'YmdHi';
$timerMs = 1000;
$redisPort = 6379;

$redisIp = '127.0.0.1';
$reaktors = $workers = 1;
$pass = ['bob' => 'pass', 'alice' => 'wolf'];
$maxMemUsageMsgToDisk = 50000 * 1024 * 1024;
$setmem = $log = $del = $memUsage = $action = $needAuth = 0;
$supAdmin='zyx';

$taskWorkers = 1;//$_ENV['taskWorkers'] ?? 2;
$nbAtomics = 0;// non nécessaires ici //$_ENV['nbAtomics'] ?? 100;
$nbChannels = 30;//$_ENV['nbChannels'] ?? 30;

$binSize = strlen(bindec(max($nbChannels, $nbAtomics)));

$_ENV = ['gt' => 0];


if ('break down every a=b commandline arguments into variables') {
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
    $pvc = rtrim($pvc . '/', '/').'/';
    ini_set('display_errors', 1);
}

if (1) {
    $tokenHashFun = function ($user, $pass) use ($dmi, $salt) {
        return hash('sha256', $user . date($dmi) . $salt . $pass);
    };

    $rea = ceil($reaktors); //(swoole_cpu_num() * $reaktors);
    $nbWorkers = ceil($workers);  //(swoole_cpu_num() * $workers);
    if ($nbWorkers < $rea) $nbWorkers = $rea;
    echo "\nMaster:" . \getmypid() . ":Rea:$rea,wor:$nbWorkers";
    $options = [//  https://www.swoole.co.uk/docs/modules/swoole-server/configuration
        'open_cpu_affinity' => true, 'max_request' => 0,// before restarting workers, or backup data in between ( it also drops the connections ... )
        'heartbeat_idle_time' => $hbIdle,/*10 min*/
        'heartbeat_check_interval' => $hbCheckEach,// leads to some workers timeouts errors
        //$socket>setOption(SOL_SOCKET, SO_REUSEPORT, true)
        'reactor_num' => (int)$rea,
        'worker_num' => (int)$nbWorkers,
        // LimitNOFILE=100000
        // swoole WARNING	Server::accept_connection(): accept() failed, Error: Too many open files[24]
        //'daemonize' => 1,
        'log_file' => '/dev/null', 'log_level' => 5,//SWOOLE_LOG_INFO,
        'dispatch_mode' => 2,// Fixed fd per process : 7 : use idle, 1 : async and non blocking
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
    echo "\nStarted:" . time() . '-' . json_encode($options);
}

chdir(__dir__);
require_once 'common.php';// never manager to run the tests.php with a "lightweight websocket client"


register_shutdown_function(function () {
    echo "\nDied:" . \getmypid();
});


class singleChannel extends singleThreaded {

    function llen($k)
    {
        return $_ENV['channels'][$k]->length;
    }
    function firstOf($k){// will get the last one
        return $this->lpop($k, false);
    }

    function lpop($k, $consume = true){// lshift
        return $this->rpop();
    }

    function rpop($k, $consume = true)
    {
        $m = $_ENV['channels'][$k]->pop();
        if (!$consume) {
            $_ENV['channels'][$k]->push($m);
        }
        return $m;
    }

    function lpush($k, $v)
    {
        return $this->rpush($k,$v);
    }

    function rpush($k, $v)
    {
        $a=$_ENV['channels'][$k];
        return $a->push($v);
    }
}

class singleThreaded
{// relies on simplest php data array ever
    public $a = ['nbConnectionsActives' => 0, 'nbOpened' => 0, 'nbClosed' => 0];//,'lastSent'=>time()

    function reset()// todo:devOnly
    {
        $this->a = ['nbConnectionsActives' => 0, 'nbOpened' => 0, 'nbClosed' => 0];
        return;
        foreach ($this->a as $k => &$v) {
            if (is_array($v)) {
                $v = [];
            } elseif (in_array(gettype($v), ['integer', 'float', 'double'])) {
                $v = 0;
            } elseif (in_array(gettype($v), ['string'])) {
                $v = '';
            } else {
                anomaly();
            }
        }
        unset($v);
    }

    function setIfNull($k, $v)
    {
        if (!$this->exists($k)) {
            return $this->set($k, $v);
        }
    }

    function setNX($k, $v)
    {

    }

    function dump()
    {
        $this->a['testtime'] = $this->get('lastSent') - $this->get('firstPush');
        return $this->a;
    }

    function llen($k)
    {
        if (!is_array($this->a[$k]) or !$this->a[$k]) return 0;
        return count($this->a[$k]);
    }

    function firstOf($k)
    {
        return $this->lpop($k, false);
    }

    function lpop($k, $consume = true)// lshift
    {
        if (!is_array($this->a[$k]) or !$this->a[$k]) {
            return null;
        }
        if (!$consume) {
            return reset($this->a[$k]);
        }
        return array_shift($this->a[$k]);
    }

    function lpush($k, $v)
    {
        if (!is_array($this->a[$k])) $this->a[$k] = [];
        return array_unshift($this->a[$k], $v);
    }

    function rpop($k, $consume = true)
    {
        if (!is_array($this->a[$k]) or !$this->a[$k]) {
            return null;
        }
        if (!$consume) {
            return end($this->a[$k]);
        }
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
        //
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

/** replace redis by Atomic and Tables */
class AtomicRedis
{ // when num workers > 1
    function reset()
    {
        anomaly();
    }

    function firstOf($chan){
        return $this->lpop($chan, false);
    }

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

    function lpop($chan, $consume=true)
    {
        if (strpos($chan, 'pending:') === 0) {
            $c = $this->getChannelId($chan);
            return $_ENV['channels'][$c]->pop();
        }
        // Un explode sur un caractère devrait suffire, non ?
        $x = json_decode($_ENV['listTable'][$chan]['v']);
        $v = array_shift($x);
        if($consume)$_ENV['listTable'][$chan] = ['v' => json_encode($x)];
        return $v;
    }

    function rpop($chan, $consume=true)
    {
        if (strpos($chan, 'pending:') === 0) {
            $c = $this->getChannelId($chan);
            return $_ENV['channels'][$c]->pop();
        }
        $x = json_decode($_ENV['listTable'][$chan]['v']);
        $v = array_pop($x);
        if($consume)$_ENV['listTable'][$chan] = ['v' => json_encode($x)];
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
        anomaly();
    }

    /*
     type,smembers,zRange, lrem($k, $v);lrange('pid2sub:' . $himself, 0, -1);
    */
    function __call($method, $arguments)
    {
        anomaly();
    }
}

//compact('taskWorkers,nbChannels,nbAtomics,capacityMessagesPerChannel,maxSuscribersPerChannel')+['port'=>$port]
$sw = new wsServer($port, $options, $needAuth, $tokenHashFun, $pass, $log, $timerMs);

class wsServer
{
    public $memlimit, $log, $timer, $tokenHashFun, $server, $ato, $redis, $bgProcessing = false, $needAuth = false, $uuid = 0, $parentPid = 0, $pid = 0, $pendings = 0, $port = 0, $tick = 20000000, $options = [], $passworts = [], $frees = [], $conn = [], $clients = [], $fdIs = [], $h2iam = [], $pool = [], $fd2sub = [], $auths = [], $ticks = [],;// 20 sec in microseconds here for heartbeat:: keep connection alive

    public function shallRestart()
    {// return false;
        $m = memory_get_usage();
        if ($m > ($this->memlimit - (2 * pow(1024, 2))/* Seuil Allocation Memoire */)) {
            if (1 or !$this->r()->get('nbPending') or !$this->r()->get('nbConnectionsActives')) {//  Plus de consommateurs en attente ou plus aucun message en attente
                echo "\nMemory Limit Exceeded .. auto restarting ..";
                $this->r()->a['nbBgProcessing'] = __line__;
                foreach ($this->r()->a as $k => &$v) {
                    if (substr($k, 0, 8) === 'pending:' or substr($k, 0, 11) === 'suscribers:' or substr($k, 0, 8) === 'pid2sub:') {// Les fd et connections vont tous pêter ...
                        $v = null;
                    }
                }
                unset($v, $this->ato()->a['free'], $this->ato()->a['participants'], $this->r()->a['nbFree'], $this->r()->a['nbBgProcessing']);
                $this->restart(true);
            }
        }
    }

    function db($x, $level = 9, $minLogLevel = 1)
    {
        return;
        if ($level > 98) ;
        elseif (!$this->log) return;
        elseif ($level < $minLogLevel) return;
        if (is_array($x)) $x = json_encode($x);
        file_put_contents('3.log', "\nS:" . \getmypid() . ':' . $x, 8);
    }

    function restart($dump = false)
    {// Todo : doesn't work as expected, the connections remains in timeout
        $this->bgProcessing = time();
        // todo: cycle and drop each connection
        if ($dump) {
            $x = $this->r()->dump();//dont care ... no care, just need to export the pending Messages ....
            unset($x['participants'], $x['p2h'], $x['sentAsapToFd'], $x['messagesSentToFdOnBackground']);
            foreach ($x as $k => &$v) {
                if (substr($k, 0, 4) == 'pid2') $v = null;
            }
            unset($v);
            $x = array_filter($x);
            // just need pending ones btw
            echo "\nServer Restarting :" . $this->pid . ':' . memory_get_usage() . ',' . $this->r()->get('nbPending') . " pending messages," . $this->r()->get('nbConnectionsActives') . ' clients connected';
            //$x['nbConnectionsActives'] = 0;// Clients are killed :)
            file_put_contents('backup.json', json_encode($x));// nécessaire car le nouveau worker débute avant le stop du précédent
        }

        $this->timer = null;
        $this->frees = $this->conn = $this->clients = $this->fdIs = $this->h2iam = $this->pool = $this->fd2sub = $this->auths = $this->ticks = [];
        $this->server->reload();// kill -USR1 MASTER_PID
        // $this->server->stop();$this->server=null;$this->run($this->port, $this->options);//unset($this->server);
        //$this->server->stop(-1);$this->server->start();
        //    pr kill -USR1 MASTER_PID
        //$this->server->reload(true);//User defined signal 2
        $this->bgProcessing = false;
        gc_collect_cycles();// where sould be that memLeak be located at???
        gc_mem_caches();/* todo :: pas terrible  : parfois il clean sa mémoire lui même sans ordre de restart .... */
        echo "\nrestarted:" . memory_get_usage();// _ENV['_a']
        return;
    }

    /* async function */
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

        $this->shallRestart();


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
                    if ($this->shallSendMessageToClient($fd, $sub, 'AsyncPendingMessagesSentToFdOnBackground')) {
                        break;// stop cycling subject, the client is busy consuming ressource
                    }
                }
            }
        }
        $this->bgProcessing = false;// Todo :: Each individual worker shall care of it's own pending queues
        return;
    }

    /** todo : implement at other places, especially for requeiing */
    function shallSendMessageToClient($fd, $sub, $reason = '')
    {
        global $pvc, $nbPriorities;
        $m = false;
        $priorities = range($nbPriorities, 1);// from 3 to 1
        foreach ($priorities as $priority) {

            $a = $this->ato()->firstOf('firstOf:' . $sub . ':' . $priority);
            $b = $this->ato()->firstOf('firstOfDisk:' . $sub . ':' . $priority);

            if ($b && (!$a or $b < $a) && 'disk message is older') {
                $time = $b;
                if($this->r()->hExists('p2d', $sub) and $this->r()->hget('p2d', $sub)){
                    $this->r()->HINCRBY('p2d', $sub, -1);
                    $x = glob($pvc . $sub . '/' . $priority . '/*.msg');// time.checksum.msg
                    if ($x) {
                        $x = array_shift($x);
                        $time = $this->ato()->lpop('firstOfDisk:' . $sub . ':' . $priority);
                        $m = file_get_contents($x);
                        if(!$m){
                            echo"\nNothing to read from:".$x;
                        }
                        $this->r()->decr('nbMessages2disk');
                        unlink($x);
                        $this->db($x, 1);
                    }
                }
            } elseif (!$m or ($a && 'read from ram')) {
                $time = $this->ato()->lpop('firstOf:' . $sub . ':' . $priority);
                if ($this->r()->exists('pending:' . $sub . ':' . $priority) and ($this->r()->llen('pending:' . $sub . ':' . $priority))){
                    $m = $this->r()->lpop('pending:' . $sub . ':' . $priority);
                }
            } elseif ('nothing to read then ..') {
$a=1;
            }

            if ($m) {
                return $this->sendOrRequeue($fd, $sub, $m, $reason, $time);
            } elseif ('cela se peut être consomée entre temps ... passer à une autre') {
                $this->r()->incr('nb:queuevide:' . $sub);
            }
        }
    }

    function sendOrRequeue($fd, $sub, $m, $reason, $time)
    {
        $this->busy($this->pid . ',' . $fd, $fd);
        $this->r()->HINCRBY('pendings', $sub, -1);
        if (!$this->sendOrDisconnect($fd, ['queue' => $sub, 'message' => $m], 1, 'async')) {
            $this->r()->lPush('pending:' . $sub, $m);// requeue, but wrapper ceci avec un last time ... éhéh
            $this->ato()->lpush('firstOf:' . $sub, $time);
        } else {
            $this->r()->HINCRBY($reason, $fd, 1);
            $nbSent = $this->r()->hget($reason, $fd);
            if ($nbSent > 1) {
                $this->db(implode(' ;; ', $this->frees) . ' >> ' . $fd);
            }
            $this->r()->incr('nbSent:' . $reason);
            $this->db('free: ' . $fd . " $sub receives :" . $m, 1);
        }
        $this->r()->decr('nbPending');
        return true;
    }

    function sendOrDisconnect($fd, $message, $tries = 1, $plus = '', $tagAsSent = true)
    {
        if (is_array($message)) {
            $message = json_encode($message);
        }
        $try = 0;
        while ($try < $tries) {
            $ok = $this->send($fd, $message, $plus, $tagAsSent);
            if ($ok) {
                return $ok;
            }
            if ($try >= $tries) {
                return false;
            }
            $tries++;
            sleep(pow(2, $tries));
        }
        $deco = $this->server->disconnect($fd);
        if ($GLOBALS['debug']) echo "\ndeco=$deco;$fd";
        $this->onClose(null, $fd);
    }

    public function __construct($port, $options, $needAuth = false, $tokenHashFun = null, $passwords = [], $log = false, $timer = 2000)
    {
        global $tick;
        $this->tick = $tick;
        //$_ENV['gt']['started'] = microtime(1);
        $this->log = $log;
        $this->port = $port;
        $this->timer = $timer;
        $this->options = $options;
        $this->needAuth = $needAuth;
        $this->passworts = $passwords;
        $memlimit = ini_get('memory_limit');
        if (preg_match('/^(\d+)(.)$/', $memlimit, $matches)) {
            if ($matches[2] == 'G') {
                $memlimit = $matches[1] * 1024 * 1024 * 1024; // nnnM -> nnn MB
            } elseif ($matches[2] == 'M') {
                $memlimit = $matches[1] * 1024 * 1024; // nnnM -> nnn MB
            } else if ($matches[2] == 'K') {
                $memlimit = $matches[1] * 1024; // nnnK -> nnn KB
            }
        }
        $this->memlimit = $memlimit;
        $this->tokenHashFun = $tokenHashFun;
        $this->parentPid = \getmypid();
        if (is_array($port)) {
            foreach ($port as $k => $v) $this->{$k} = $v;
        }
        $this->run($port, $options);
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

    function send($fd, $mess, $plus = '', $tagAsSent = true)
    {
        if (!$fd) {
            $this->db(['wtr?', debug_backtrace(-2)], 99);
            return;
        }
        if (is_array($mess)) $mess = json_encode($mess);
        $success = 0;
        while (!$success && $tries < 4) {// Caution : never get this stuck in a kind of loop or timeout ..
            $success = $this->server->push($fd, $mess);// or send
            if (!$success) {
                $this->db('nosuccess sending ' . $mess . ' to ' . $fd . ' -- disconnected ?');
                $tries++;
                sleep(1);
            }
        }
        if (!$success) {
            $this->r()->incr('nbRetriedSendFailures', $tries);
            $deco = $this->server->disconnect($fd);
            if ($GLOBALS['debug']) echo "\ndeco=$deco;$fd";
            $this->onClose(null, $fd);
            return;//$this->busy($this->pid . ',' . $fd, $fd);$this->rem('participants', $fd);return;
        }
        $this->db('sentto:' . $fd);
        if ($tagAsSent) {
            $this->r()->set('lastSent', time());
            $this->r()->incr('nbSend');
        }
        return true;
    }

    function rep($sender, $frame)
    {
        $this->sendOrDisconnect($sender, $frame->data . ':' . hash('crc32c', $frame->data), 1, 'rep');
    }

    function handshake($server, $frame)
    {

    }

    function onClose($server, $fd)
    {
        //$server->on('close', function ($server, $fd) {
        $this->r()->decr('nbConnectionsActives');
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
        unset($this->fd2sub[$fd]);
        $suscribtions = $this->r()->lrange('pid2sub:' . $himself, 0, -1);
        foreach ($suscribtions as $sub) {
            $this->rem('suscribers:' . $sub, $himself);
        }
        $this->r()->del('pid2sub:' . $himself);
        $this->busy($himself, $fd);
        $this->rem('participants', $himself);
        $this->db("connection close: {$fd}", 2);
    }

    function onMessage($server, $frame)
    {
        global $maxMemUsageMsgToDisk, $pvc, $nbPriorities, $bigMsgToDisk,$supAdmin;
        $this->r()->setIfNull('firstMessage', time());
        $this->r()->incr('nbMessages');
        $sender = $frame->fd;
        $himself = $this->pid . ',' . $sender;
        $this->db("query from {$sender}: {$frame->data}", 1);

        if (substr($frame->data, 0, 1) == '{' and substr($frame->data, -1) == '}') {
            $s = $a = 0;
            $j = json_decode($frame->data, true);
            if (!$j) {
                $this->sendOrDisconnect($sender, json_encode(['err' => 'not valid json payload']), 1, 'onmsg');
                return;
            }

            if (isset($j['debug'])) {
                $a = $_ENV['__a'];
                $this->sendOrDisconnect($sender, time());
                return;
            }
            if (isset($j['get'])) {
                if ($j['get'] == 'time') {
                    $this->sendOrDisconnect($sender, time());
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
                    $this->sendOrDisconnect($sender, 'ok:' . __line__);
                } else {
                    $this->sendOrDisconnect($sender, 'ko:' . __line__);
                }
                return;
            }

            if (isset($j['token'])) {
                $posibles = $this->expectedTokens();
                $user = array_keys($posibles, $j['token']);
                if ($user) {
                    $this->auths[$sender] = $user;
                    $this->sendOrDisconnect($sender, 'ok:' . __line__);
                } else {
                    $this->sendOrDisconnect($sender, 'ko:' . __line__);
                }
                return;
            }

            if ($this->needAuth) {
                if (!isset($mnb[$sender])) {
                    $mnb[$sender] = 0;
                }
                $mnb[$sender]++;
                if ($mnb[$sender] > 1 && !$this->auths[$sender]) {
                    $this->sendOrDisconnect($sender, ['err' => 'auth required']);
                    return;
                }
            }


            if (isset($j['lpop'])) {// todo wrap about multiple priorities and disk queues as well
                $sub = $j['lpop'];
                $m = $this->r()->lpop($sub);
                if (!$m) {
                    return $this->sendOrDisconnect($sender, ['nothing' => 1], 1, 'lpop');
                }
                $time = $this->ato()->lpop('firstOf:' . $sub);
                return $this->sendOrRequeue($sender, $sub, $m, 'lpop', $time);
            }

            if (isset($j['rpop'])) {
                return $this->sendOrDisconnect($sender, $this->r()->rpop($j['rpop']), 1, 'rpop');
            }

            if (isset($j['rpush']) and isset($j['v'])) {
                return $this->r()->rpush($j['rpush'], $j['v']);
            }
            if (isset($j['lpush']) and isset($j['v'])) {
                return $this->r()->lpush($j['lpush'], $j['v']);
            }

            if (isset($j['get'])) {
                return $this->sendOrDisconnect($sender, $this->r()->get($j['get'], 1, 'get'));
            }
            if (isset($j['set']) and isset($j['v'])) {
                return $this->r()->set($j['set'], $j['v']);
            }

            if (isset($j['decr']) && isset($j['by'])) {
                return $this->r()->incr($j['incr'], $j['by']);
            } elseif (isset($j['decr'])) {
                return $this->r()->decr($j['decr'], $j['by']);
            }

            if (isset($j['incr']) && isset($j['by'])) {
                return $this->r()->incr($j['incr'], $j['by']);
            } elseif (isset($j['incr'])) {
                return $this->r()->decr($j['incr']);
            }

            if (isset($j['supAdmin']) && $j['supAdmin'] == $supAdmin) {
                if (isset($j['xdebug'])) {
                    anomaly();
                    $this->sendOrDisconnect($sender, ['Amem' => memory_get_usage()], '', false);
                }
                if (isset($j['reset'])) {
                    $a = memory_get_usage();
                    $this->r()->reset();
                    $this->r()->a['beforemem'] = $a;
                    file_put_contents('backup.json', json_encode([]));
                    $this->restart(false);
                    $j['dump'] = 1;
                }

                if (isset($j['restart'])) {
                    $this->restart(true);
                    $this->sendOrDisconnect($sender, ['restarted' => 1, 'Amem' => memory_get_usage()], '', false);
                }

                if (isset($j['dump'])) {
                    $x = $this->r()->dump();
                    foreach ($x as $k => &$v) {
                        if (substr($k, 0, 4) == 'pid2') $v = null;
                    }
                    unset($v);
                    $x = array_filter($x);
                    $x['Amem'] = memory_get_usage();
                    if (isset($x['beforemem'])) {
                        $x['freed'] = $x['beforemem'] - $x['Amem'];
                    }
                    if (!$x['free']) $x['free'] = [];
                    $x['nbFree'] = count($x['free']);
                    $x['time'] = $_ENV['gt'];
                    $x['Atime'] = $x['lastSent'] - $x['firstMessage'];
                    unset($x['participants'], $x['p2h'], $x['sentAsapToFd'], $x['messagesSentToFdOnBackground']);//dont care ... no care,
                    $this->sendOrDisconnect($sender, $x, 1,'dump',false);
                    return;
                }
            }

            if (isset($j['rk'])) {
                $this->sendOrDisconnect($sender, $this->rgg($j['rk']));
                return;
            }

            if (isset($j['get'])) {
                if ($j['get'] == 'keys') {
                    $this->sendOrDisconnect($sender, implode(';;', $this->r()->keys('*')));
                    return;
                } elseif ($j['get'] == 'participants') {
                    $this->sendOrDisconnect($sender, json_encode($this->ato()->lrange('participants', 0, -1)));
                    return;
                } elseif ($j['get'] == 'people') {
                    $this->sendOrDisconnect($sender, json_encode($this->r()->hGetAll('iam')));
                    return;
                } elseif ($j['get'] == 'queues') {
                    $this->sendOrDisconnect($sender, json_encode($this->r()->keys('pending:*')));
                    return;
                }
            }

            if (isset($j['getQueue'])) {// todo: remove :: The whole stuff, whaow !
                $this->sendOrDisconnect($sender, json_encode($this->r()->lrange('pending:' . $j['getQueue'], 0, -1)));
                return;
            } // todo: implement
            if (isset($j['clearQueue'])) {// todo : admin only
                $this->r()->del('pending:' . $j['clearQueue']);
            } // todo: implement

            if (isset($j['setQueue']) && isset($j['elements'])) {
                foreach ($j['elements'] as $v) {
                    $this->add($j['setQueue'], $v);
                }
            } // todo: implement

            if (isset($j['cmd']) and isset($j['message'])) {// send to fd /!\Experimental
                $this->sendOrDisconnect($j['cmd'], $j['message']);#with crc32 reply later ..
            }
            if (isset($j['ping'])) {
                $this->sendOrDisconnect($sender, $j['ping']);
                return;
            }
            if (isset($j['queueCount'])) {// todo foreach nbPriorities
                $this->sendOrDisconnect($sender, $this->r()->llen('queue:' . $j['queueCount']));
                return;
            }
            if (isset($j['iam'])) {
                if (isset($this->h2iam[$himself])) {//changement d'identité sur le vol
                    $this->sendOrDisconnect($sender, ['err' => 'cant change name']);
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
                    $this->sendOrDisconnect($sender, $msg);
                }
                $this->sendOrDisconnect($sender, json_encode(['iam' => $j['iam'], 'pid' => $this->pid, 'fd' => $sender]));//Il se bouffe toute la queue de notificationes
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
                if (isset($j['sendto'])) {// directMessage : bob to Alice
                    $a = $s = 1;
                    $this->db($j['sendto'] . '>' . $j['message']);
                    $to = $this->r()->Hget('iam', $j['sendto']);
                    if (!$to) {
                        $this->r()->lPush('user:' . $j['sendto'], json_encode($j));
                        $this->sendOrDisconnect($sender, 'ko:unknownRecipient' . __line__);
                        return;
                    }
                    [$pid, $fd] = explode(',', $to);
                    $this->sendOrDisconnect($fd, ['sendto' => $j['sendto'], 'message' => $j['message']]);
                    $this->sendOrDisconnect($sender, 'ok:' . __line__);
/* the main priority storage logic here */
                } elseif (isset($j['push'])) {
                    $this->r()->setIfNull('firstPush', time());
                    $this->r()->incr('nbPushed');
                    $a = 1;
                    $s++;
                    $ack = hash('crc32c', $j['message']);
                    $this->sendOrDisconnect($sender, $ack);#aka:ack

if('croiser les libres et ceux qui sont abonnés'){
                    $sub = $this->r()->lrange('suscribers:' . $j['push'], 0, -1);
                    $free = $this->r()->lrange('free', 0, -1);
                    $libres = array_intersect($sub, $free);

                    $pid = $fd = 0;
                    if ($libres) {// le premier des libres
                        $him = array_unshift($libres);
                        [$pid, $fd] = explode(',', $him);
                        $this->db(['him' => $him, 'libres' => $libres]);
                    }

                    if ($fd) {
                        $this->busy($him, $fd);
                        $this->r()->incr('nbSentAsap');
                        $this->r()->HINCRBY('sentAsapToFd', $fd, 1);
                        $this->sendOrDisconnect($fd, json_encode(['queue' => $j['push'], 'message' => $j['message']]));
                        return;

} elseif('aucun consommateur disponible on va stocker ce message qqpart') {
                        $priority = 1;
                        if (isset($j['priority']) && is_numeric($j['priority']) && $j['priority'] <= $nbPriorities) {
                            $priority = (int)$j['priority'];
                        }
// has priority && priority> or priority not a number, otherwise default is 1 .. todisk if too big nbPriorities
                        $this->r()->incr('nbPending');
                        //echo"\nPending++:".$this->r()->get('nbPending');
                        $this->r()->set('lastPending', time());
                        $this->r()->HINCRBY('pendings', $j['push'], 1);
                        $this->db('new pending msg for ' . $j['push'], 1);// Then todo: en cas de pending upon free event
                        $mt=microtime(true);
                        if ($this->r()->get('memUsage') > $maxMemUsageMsgToDisk or strlen($j['message']) > $bigMsgToDisk) {
                            $this->r()->HINCRBY('p2d', $j['push'], 1);
                            $this->r()->incr('nbMessages2disk');
                            if (!is_dir($pvc . $j['push'] . '/' . $priority)) {
                                mkdir($pvc . $j['push'] . '/' . $priority, 0777, true);
                            }
                            file_put_contents($pvc . $j['push'] . '/' . $priority . '/' . str_replace('.','',$mt) . '-' . $ack . '.msg',$j['message']);
                            $this->ato()->rpush('firstOfDisk:' .  $j['push'] . ':' . $priority, $mt);
                        } else {
                            $this->ato()->rpush('firstOf:' . $j['push'] . ':' . $priority, $mt);
                            $this->add('pending:' . $j['push'] . ':' . $priority, $j['message']);// En attendant qu'une libération ..
                        }
                    }
}
                } elseif (isset($j['broadcast'])) {// pas de mémorisation ni de pendins
                    $sub = $this->r()->lrange('suscribers:' . $j['broadcast'], 0, -1);
                    foreach ($sub as $pid2fd) {
                        [$pid, $fd] = explode(',', $pid2fd);
                        if ($fd != $sender) {
                            $this->sendOrDisconnect($fd, ['queue' => $j['broadcast'], 'message' => $j['message']]);
                        }
                    }
                    $a = 2;
                } else {
                    $this->sendOrDisconnect($sender, 'ok:' . __line__ . ':' . $frame->data);
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

            if (isset($j['consume'])) {//      consume:1 s'il n'y a rien, retournera rien, le marquera juste en libre : aka : same as free
                if (!$this->shallSend2Free($himself, $sender)) {
                    $this->free($this->pid . ',' . $sender, $sender);
                }
                return;
            }

            if (!$s) {
                $this->rep($sender, $frame);
            }
            if (!$a) {//auth,list,sub,count,hb,push,broadcast
                $this->sendOrDisconnect($sender, json_encode(['err' => 'json not valid']));
                $this->r()->incr('sNotUnder');
                $jj = $frame->data;
                if (is_array($j)) $jj = implode(',', array_keys($j));
                $this->db(',' . $this->uuid . ",message not understood :" . $jj);
            }
        } else {
            $this->sendOrDisconnect($sender, json_encode(['err' => 'not json']));
            $this->r()->incr('sNotJson');
            $this->db(',' . $this->uuid . ",message not json");
        }
        return;
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
                    if ($this->shallSendMessageToClient($sender, $topic, 'free')) {
                        return true;// stop cycling subject, the client is busy consuming ressource
                    }
                }
            }
        }
        return false;
    }

    function ato(){
        if ($this->redis instanceOf singleThreaded) return $this->redis;
        if ($this->ato) return $this->ato;
        $_ENV['__ato'] = $this->ato = new \singleThreaded();
        return $this->ato;
    }

    function r()
    {
        global $nbWorkers;
        if ($this->redis) return $this->redis;
        if ($nbWorkers == 1) {
            echo "\nPhpArray:1 worker";
            //$_ENV['__a'] = $this->redis = new \SingleChannel();
            $_ENV['__a'] = $this->redis = new singleThreaded();
        } else {
            echo "\nAtoRedis";
            $_ENV['__a'] = $this->redis = new \AtomicRedis();
        }
        return $this->redis;
    }

    function add($k, $v)
    {
        $this->r()->rPush($k, $v);
    }

    function rem($k, $v)
    {
        $this->ato()->lrem($k, $v);
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

    function run($port, $options)
    {
        try {
            // see https://openswoole.com/docs/modules/swoole-server-doc
            //$m=SWOOLE_PROCESS;if($options['worker_num']==1)$m=SWOOLE_BASE;
            $this->server = $server = new Server('0.0.0.0', $port);
            //  $this->server = $server = new swoole_websocket_server('0.0.0.0', $port);
            //$this->server = $server = new Server('0.0.0.0', $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
            //$server->set(array('ssl_cert_file' => __DIR__.'/ssl.cert','ssl_key_file' => __DIR__.'/ssl.key',));


            $server->set($options);
            echo "\nServer:" . getMyPid();
            ///*
            $server->on('workerstart', function (Server $server) use ($port) {
                $this->pid = \getmypid();
                echo "\nworker Start: " . $this->pid;
                global $channels2start,$nbPriorities,$capacityMessagesPerChannel;
                $a=1;
if(0 and !isset($_ENV['channels'])){
    foreach($channels2start as $i) {
        $j = 1;
        while ($j <= $nbPriorities) {
            try{
                $_ENV['channels'][$i . ':' . $j] = new Channel($capacityMessagesPerChannel);
            }catch(\throwable $e){
                echo"\n".$e->getMessage();
            }
        }
}
                $a=1;
}
                $_SERVER['_'] = 'worker';
                $f = 'backup.json';
                if (is_file($f)) {
                    echo "\nRestoringBackup.." . filesize($f);
                    $this->r()->a = json_decode(file_get_contents($f), true);
                }

                //$this->childProcess('workerstart');
                //if ($this->pid) return;
                echo ':' . memory_get_usage() . ',' . $this->r()->a['nbPending'] . ' pending messages,' . $this->r()->a['nbConnectionsActives'] . ' clients connected';
                Timer::tick($this->timer, [$this, 'processNbPendingInBackground'], ['timer']);
                return;


                register_shutdown_function(function () {
                    global $nbWorkers;
                    echo "\nShutdown";
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


                    foreach ($this->pool as $fd) {
                        $himself = $this->pid . ',' . $fd;
                        $this->busy($himself, $fd);
                        $this->rem('participants', $himself);
                        $suscribtions = $this->r()->lrange('pid2sub:' . $himself, 0, -1);

                        foreach ($suscribtions as $sub) {
                            $this->rem('suscribers:' . $sub, $himself);
                        }

                        $this->r()->del('pid2sub:' . $himself);
                    }
                    $this->db('killed');
                });
            });

            $server->on('workerstop', function (Server $server) use ($port) {
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
                        $this->rem('suscribers:' . $sub, $himself);
                    }
                    $this->r()->del('pid2sub:' . $himself);

                    $this->busy($himself, $fd);
                    $this->rem('participants', $himself);
                }
                foreach ($this->fdIs as $hm => $iam) {
                    $this->r()->Hdel('iam', $iam);
                }

                //$f='backup.json';file_put_contents($f,json_encode($this->r()->a));
            });

            $server->on('workererror', function (Server $server, int $workerId, int $workerPid, int $exitCode, int $signal) use ($port) {
                echo "\nworkerError" . $exitCode . ':' . getMyPid();
                $this->db(['workererror', $port], 99);
            });

            $server->on('managerstart', function (Server $server) use ($port) {
                $_SERVER['_']='manager';echo "\nManager Start:" . getmypid();
                $this->db('managerstart');
            });

            $server->on('managerstop', function (Server $server) use ($port) {
                echo "\nmStop" . getmypid();
                $this->db('managerstop');
            });

            if (1) {
                $server->on('timer', function (Server $server) use ($port) {
                    $this->db('timer');
                });
                $server->on('connect', function (Server $server, $fd) use ($port) {
                    //echo"\nConnect:".$fd.':'.time();
                    $this->db('connect');
                });
                $server->on('shutdown', function (Server $server, $fd) use ($port) {
                    echo "\nShutdown:" . time();
                });
                $server->on('receive', function (Server $server) use ($port) {
                    $this->db('receive');
                });
                $server->on('packet', function (Server $server) use ($port) {
                    $this->db('packet');
                });
                $server->on('task', function (Server $server) use ($port) {
                    $this->db('task');
                });
                $server->on('finish', function (Server $server) use ($port) {
                    echo "\nfinish" . getmypid();
                    $this->db('finish');
                });
                $server->on('pipemessage', function (Server $server) use ($port) {
                    $this->db('pipemessage');
                });

                $server->on('beforereload', function (Server $server) use ($port) {
                    //echo"\nBeforeReload:".memory_get_usage();
                    $this->db('beforereload');
                });
                $server->on('afterreload', function (Server $server) use ($port) {
                    //echo"\nAfterreload:".memory_get_usage();
                    $this->db('afterreload');
                });

                $server->on('Task', function ($serv, Task $task) {
                    echo "\ntask" . getmypid();

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
            $server->on('close', [$this, 'onClose']);
            $server->on('open', function ($server, $req) {
                if ($GLOBALS['debug']) {
                    $a = microtime(true) * 1000;
                }
                //$this->childProcess('open');
                $this->r()->incr('nbConnectionsActives');//nbOpened,nbClosed,serverError,sMessage
                $this->r()->incr('nbOpened');//nbOpened,nbClosed,serverError,sMessage
                $this->ato()->rpush('participants', $this->pid . ',' . $req->fd);
                $this->pool[] = $req->fd;
                $this->db("connection open: {$req->fd}", 2);
                if ($GLOBALS['debug']) {
                    $a = round((microtime(true) * 1000) - $a);
                    if ($a > 100) echo "\n" . $a;
                }// todo: connection took too much fucking time
                //echo "\n" . '{"pid":' . $this->pid . ',"id":' . $req->fd . '}';
                $this->sendOrDisconnect($req->fd, ['pid'=>$this->pid,'id'=>$req->fd], 1,'pid');//so he knows who he actually is
            });


            $server->on('start', function (Server $server) use ($port) { // Le serveur uniquement, pas les workersn, espace pour caller les globales vit fait
                echo "\nss:" . getmypid();

                global $channels2start, $nbPriorities, $swooleTableStringMaxSize, $nbReferences, $nbChannels, $nbAtomics, $nbWorkers, $capacityMessagesPerChannel, $binSize, $nbWorkers, $listTableNb, $listTableNbMaxSize;
                //$this->r()->incr('sStart');//nbOpened,nbClosed,serverError,sMessage,sStart
                $this->db("\t\t\t" . \getmypid() . '/' . $this->uuid . '::started::the parent process');// Doit en avoir un seul

                if ($nbWorkers > 1 and 'useAtomic,Table,Channels to run the whole') {
                    $_ENV['atomics'] = [
                        'received' => new Atomic(),
                        'occupiedChannels' => new Atomic(),
                        'connectedConsumers' => new Atomic(),
                    ];
                    // 'process:server' => new Atomic(), 'process:manager' => new Atomic()];
                    $_ENV['tables'] = $_ENV['channels'] = $_ENV['workerAtomics'] = [];

                    $_ENV['listTable'] = new Table($listTableNb);
                    $_ENV['listTable']->column('v', Table::TYPE_STRING, $listTableNbMaxSize);

                    $_ENV['rkv'] = new Table($nbReferences);
                    $_ENV['rkv']->column('v', Table::TYPE_STRING, $swooleTableStringMaxSize);
                    $_ENV['rkv']->create();
                    if (1) {
                        // is an array
                        $_ENV['rkv']->set('freeChannels', ['v' => json_encode(range(0, $nbChannels - 1))]);
                        if ($nbAtomics) {
                            $i = 0;
                            while ($i < $nbAtomics) {
                                $_ENV['atomics'][$i] = new Atomic();
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
                                $_ENV['workerAtomics'][$i] = new Atomic();
                                $i++;
                            }
                        }
                    }

                    $_ENV['ref'] = new Table($nbReferences);
                    //$_ENV['ref']->column('e', Table::TYPE_STRING, 1);// a:atomic,c:channel
                    $_ENV['ref']->column('v', Table::TYPE_INT, $binSize);// 8:256 values
                    $_ENV['ref']->create();
                }

if(0) {
    foreach ($channels2start as $channel) {
        $i = 1;
        while ($i <= $nbPriorities) {
            $_ENV['channels'][$channel . ':' . $i] = new Channel($capacityMessagesPerChannel);
            $i++;
        }
    }
    $a = 1;
}
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
        anomaly();
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
