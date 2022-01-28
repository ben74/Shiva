<?php
if ('default overridable configuration') {//        // p=2000 reaktors=1 workers=1 pass={"bob":"pass","alice":"wolf"}
    $salt = '';
    $p = 2000;
    $dmi = 'YmdHi';
    $redisPort = 6379;
    $redisIp = '127.0.0.1';
    $reaktors = $workers = 1;
    $setmem = $log = $del = $memUsage = $action = $needAuth = 0;

    $pass = ['bob' => 'pass', 'alice' => 'wolf'];

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
    $rea = ceil($reaktors);//swoole_cpu_num
    $wor = ceil($workers);
    if ($wor < $rea) $wor = $rea;
    echo "\n" . getMyPid() . ":Rea:$rea,wor:$wor";
    $options = [//https://www.swoole.co.uk/docs/modules/swoole-server/configuration
        'log_file' => '/dev/null',
        'log_level' => SWOOLE_LOG_INFO,
        'reactor_num' => $rea,
        'worker_num' => $wor,
    ];
}

chdir(__dir__);
//require_once '../vendor/autoload.php';
require_once 'common.php';

//require_once '../fun.php';
use \Swoole\Websocket\Server;

//use Swoole\Table as mem;

register_shutdown_function(function () {
    echo "\nDied:" . getMyPid();
});

$sw = new wsServer($p, $options, $needAuth, $tokenHashFun, $pass, $log);

class wsServer
{
    public $tokenHashFun, $passworts = [], $eedAuth = false, $uuid = 0, $isChild = 0, $isParent = 0, $parentPid = 0, $server, $redis, $pid = 0, $free = [], $pendings = 0, $mnb = [], $conn = [], $clients = [], $fdIs = [], $h2iam = [], $pool = [], $auths = [], $ticks = [], $tick = 20000000;// 20 sec in microseconds here for heartbeat:: keep connection alive

    public function __construct($p, $options, $needAuth = false, $tokenHashFun = null, $passwords = [], $log = false)
    {
        $this->log = $log;
        //$this->db(['C' => $passwords]);
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

    function processPendingInBackground($via = 0)
    {
        if (!$this->getLock('bg')) {
            return;
        }
        $this->r()->set('lastBgProcess', microtime(true));

        $pendings = $this->r()->hgetAll('pendings');
        if (!$pendings) {
            $this->rmLock('bg');
            return;
        }
        $free = $this->r()->lrange('free', 0, -1);
        if (!$free) {
            $this->rmLock('bg');
            return;
        }

        foreach ($pendings as $topic => $nb) {
            $sub = 0;
            while ($nb) {/* tant que l'on a des messages à envoyer */
                if (!$sub) {
                    $sub = $this->r()->lrange('suscribers:' . $topic, 0, -1);
                    if (!$sub) {
                        $nb = 0;
                        break;
                    }
                }
                if (!$free) {// Force Refresh Ready list
                    $free = $this->r()->lrange('free', 0, -1);
                    if (!$free) {// Nothing todo
                        $this->rmLock('bg');
                        $this->r()->incr('noFree');
                        return;
                        $nb = 0;
                        break 2;
                    }
                }
                $libres = array_intersect($sub, $free);
                $free = 0;
                if (!$libres) {
                    $nb = 0;
                    $this->r()->incr('noLibre');
                    _db("noOneFor:" . $topic);
                    continue 2;#next topic please
                }
                [$pid, $fd] = explode(',', reset($libres));
                $him = $pid . ',' . $fd;

                //echo"\nLibres:".implode(';;',$sub).' <> '.implode(';;',$libres).' => '.$him;
                $m = $this->r()->lPop('pending:' . $topic);
                if (!$m) {
                    $nb = 0;
                    continue 2;
                    $this->r()->incr('noLpop');// nothing left to consume, handled by another process
                } else {

                    $success = $this->send($fd, json_encode(['queue' => $topic, 'message' => $m]), 'async');// <============
                    //$success = $this->send($fd, json_encode(['queue' => $topic, 'message' => 'A:' . $this->pid . ' -- ' . microtime(true)]), 'async');// <============
                    if (0 and !$success) {// ne peut on pas savoir, non, quand ce n'est pas le même process qui se charge d'envoyer la chose !
                        $this->r()->rPush('sEc', json_encode($success));
                        $this->r()->rPush('pending:' . $topic, $m);
                        $this->r()->incr('sErrAsync');// Mais du coup... Quid du signal pour déboucher le tout, non ?
                    } else {
                        $this->busy($him, $fd);
                        $this->r()->HINCRBY('p2h', $fd, 1);
                        $nbSent = $this->r()->hget('p2h', $fd);
                        if ($nbSent > 1) {
                            $this->db(implode(' ;; ', $this->r()->lrange('free', 0, -1)) . ' >> ' . $him);
                            $a = 2;
                        }
                        if (1) {
                            $free = $this->r()->lrange('free', 0, -1);
                            if (in_array($him, $free)) {
                                $this->db("WTF::" . implode(',', $free) . '<>' . $him);
                            }
                        }
                        $this->pendings--;
                        $this->r()->incr('sAsyncOk');
                        $this->r()->decr('sPending');
                        $this->r()->HINCRBY('pendings', $topic, -1);
                        $this->db('free: ' . $fd . " $topic receives :" . $m, 1);
                    }
                    $nb = $this->r()->hget('pendings', $topic);
                }

                if ($nb) $this->r()->incr('nbLoop');
                // $himself = $this->pid . ',' . $fd;
            }
            $free = 0;
            //if (!$free)break;
        }
        $this->rmLock('bg');
        if ($this->r()->get('lastPending') > $this->r()->get('lastBgProcess') or $this->r()->get('lastSub') > $this->r()->get('lastBgProcess')) {// Si de nouveaux process entre temps ??
            //echo"\nRelaunch";
            $this->r()->hset('relaunch', $this->pid, 1);
            $this->processPendingInBackground('selfRelaunch');
        }
    }

    function busy($him, $fd)
    {
        $this->rem('free', $him);
        $this->r()->incr('busy', 1);
        $this->free = array_diff($this->free, [$fd]);//Sur lui même, si c'est le cas
    }

    function free($him, $fd)
    {
        $this->r()->rPush('free', $him);
        $this->r()->incr('freed', 1);
        $this->free[] = $fd;
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

    function db($x, $level = 9, $minLogLevel = 1)
    {
        if (!$this->log) return;
        if ($level < $minLogLevel) return;
        if (is_array($x)) $x = json_encode($x);
        file_put_contents('3.log', "\nS:" . getmyPid() . ':' . $x, 8);
    }


    function childProcess($x)
    {// between start and on open ..
        //$this->processPendingInBackground('viachild');
        if ($this->pid) return;

        $this->db('child of :' . $this->parentPid, 1);
        $this->isChild = 1;
        $this->isParent = 0;
        $this->uuid = uniqid();
        $this->pid = getMyPid();

        register_shutdown_function(function () {
            foreach ($this->pool as $fd) {
                $himself = $this->pid . ',' . $fd;
                $this->busy($himself, $fd);
                //$this->rem('free', $himself);
                $this->rem('participants', $himself);
                $suscribtions = $this->r()->lrange('pid2sub:' . $himself, 0, -1);
                foreach ($suscribtions as $sub) {
                    $this->r()->lrem('suscribers:' . $sub, $himself);
                }
                $this->r()->del('pid2sub:' . $himself);
            }
            $this->db('killed');
        });
    }

    function send($fd, $mess, $plus = '')
    {
        if (!$fd) {
            _db(['wtr?', debug_backtrace(-2)]);
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
        //echo'\n$plus sentto $fd";
        $this->r()->incr('sSend');//sOpen,sClose,serverError,sMessage,sStart,sMessage,sSend
        return;
    }

    function onMessage($server, $frame)
    {
        $this->processPendingInBackground('onMessage');
        //$this->childProcess('onmessage');
        $this->r()->incr('SonMessage');//sOpen,sClose,serverError,sMessage,sStart,sMessage,sSend
        //Default is 0 for any child
        $sender = $frame->fd;
        $himself = $this->pid . ',' . $sender;
        // Fd increments
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
                //file_put_contents('3.log', "\n" . $j['token'] . '><' . implode(',', $posibles), 8);
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
                //file_put_contents('3.log', "\n" . $j['cmd'] . '>' . $j['message'], 8);
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
            if (isset($j['sus'])) $j['suscribe'] = $j['sus'];
            if (isset($j['subscribe'])) $j['suscribe'] = $j['subscribe'];
            if (isset($j['unsubscribe'])) $j['unsuscribe'] = $j['unsubscribe'];

            if (isset($j['suscribe'])) {
                //$this->db($sender . " subscribed to " . $j['suscribe'], 1);
                $this->send($sender, $frame->data . ':' . hash('crc32c', $frame->data));
                $this->r()->set('lastSub', microtime(true));
                $this->add('suscribers:' . $j['suscribe'], $this->pid . ',' . $sender);
                $this->r()->lPush('pid2sub:' . $this->pid . ',' . $sender, $j['suscribe']);
                $this->processPendingInBackground('onSuscribe');//
                return;
                //$this->add($server->stats, 'suscribers', $this->pid.','.$frame->fd);
            } elseif (isset($j['unsuscribe'])) {
                $this->send($sender, $frame->data . ':' . hash('crc32c', $frame->data));
                $a = 1;
                $this->rem('suscribers:' . $j['unsuscribe'], $this->pid . ',' . $sender);
                $this->r()->lRem('pid2sub:' . $this->pid . ',' . $sender, $j['unsuscribe']);
                return;
                //$this->rem($server->stats, 'suscribers', $this->pid.','.$frame->fd);
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
                    if (1) {
                        $this->send($fd, ['sendto' => $j['sendto'], 'message' => $j['message']]);
                    } else {
                        if ($pid == $this->pid) {//lucky us
                            $this->send($fd, ['sendto' => $j['sendto'], 'message' => $j['message']]);
                        } else {
                            $this->r()->rPush('send:' . $pid, json_encode([$fd => ['sendto' => $j['sendto'], 'message' => $j['message']]]));
                            //posix_kill((int)$pid, 2);//has new message to handle :)
                        }
                    }
                    $this->send($sender, 'ok:' . __line__);
                } elseif (isset($j['push'])) {
                    $this->r()->incr('sPushed');
                    //file_put_contents('3.log', "\nPushed:" . $j['push'] . '>' . $j['message'], 8);
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
                        //$this->send($fd, json_encode(['queue' => $j['push'], 'message' => 'Msg:' . $this->pid . ' ' . microtime(true)]));
                        $this->send($fd, json_encode(['queue' => $j['push'], 'message' => $j['message']]));
                        return;
                    } else {
                        //ile_put_contents('3.log', "\nPending>" . $j['message'], 8);
                        $this->pendings++;
                        $this->r()->incr('sPending');
                        $this->r()->set('lastPending', microtime(true));
                        $this->r()->HINCRBY('pendings', $j['push'], 1);
                        $this->db('new pending msg for ' . $j['push'], 1);// Then todo: en cas de pending upon free event
                        $this->r()->rPush('pending:' . $j['push'], $j['message']);// En attendant qu'une libération ..
                    }

                    if (0) {
                        if ($ready) {// si libre on self connection :: cool =)
                            $this->r()->incr('sPMessageSelfReady');
                            $this->db($this->uuid . "--sends himself", 12);
                            $this->send($ready, json_encode(['queue' => $j['push'], 'message' => $j['message']]));
                        } else {// aucun de libre --> ajout à pending et message vers serveur qui le gère
                            if ($libres) {
                                [$pid, $fd] = explode(',', $libres[0]);
                                if (!$pid) {
                                    $this->db('#error:no pid', 99);
                                }
                                $this->db(',' . $this->uuid . "--sends via $pid:" . $j['message'], 20);
                                $this->r()->incr('sPvia');
                                $this->r()->rPush('send:' . $pid, json_encode([$fd => ['queue' => $j['push'], 'message' => $j['message']]]));
                                //posix_kill((int)$pid, 2);//has new message to handle :)
                                //posix_kill((int)$pid, 1);// start of tx
                                //posix_kill((int)$pid, 31);// end of transmission
                            } else {
                                $this->pendings++;
                                $this->r()->incr('sPending');
                                $this->r()->set('lastPending', microtime(true));
                                $this->r()->HINCRBY('pendings', $j['push'], 1);
                                $this->db('new pending msg for ' . $j['push'], 20);// Then todo: en cas de pending upon free event
                                $this->r()->rPush('pending:' . $j['push'], $j['message']);// En attendant qu'une libération ..
                            }
                            return;
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
                //$this->add($server->stats, 'free', $this->pid.','.$frame->fd);
            }
            if (isset($j['consume'])) {//       Set free and returns nothing until new message
                if (!$this->shallSend2Free($himself, $sender)) {
                    $this->free($this->pid . ',' . $sender, $sender);
                }
                return;
            }

            if (!$s) {
                //$this->send($sender,hash('crc32', $frame->data));
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
                    if ($this->r()->exists('pending:' . $topic) and $this->r()->llen('pending:' . $topic)) {
                        $m = $this->r()->lPop('pending:' . $topic);
                        $this->r()->incr('sMsgSentOnFree');
                        $this->r()->decr('sPending');
                        $this->r()->HINCRBY('pendings', $topic, -1);
                        $this->db('free: ' . $himself . " $topic $sender receives :" . $m, 1);
                        //[$pid,$fd]=explode(',',$himself);
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
            }
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

// Redis even memcached are better at lists !!!!
    function add($k, $v)
    {
        $this->r()->rPush($k, $v);
        //rPush($k,$v);
    }

    function rem($k, $v)
    {
        $this->r()->lrem($k, $v);
        //rlrem($k,$v);
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
                $this->childProcess('workerstart');
            });
            $server->on('workerstop', function (Server $server) use ($p) {
                $this->db('workerstop');// Cleans the pool, participants who shall reconnect to another instance

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
                $this->db('workererror');
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
                //$this->childProcess('close');
                $this->r()->incr('sClose');//sOpen,sClose,serverError,sMessage
                //Default is 0 for any child
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
                $this->isParent = 1;
                $this->uuid = uniqid();
                $this->db("\t\t\t" . getMyPid() . '/' . $this->uuid . '::started::the parent process');// Doit en avoir un seul
                // Les childs sont spawnés à ce moment .. overrider class server
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