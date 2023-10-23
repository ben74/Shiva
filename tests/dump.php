<?php
// Coroutine http client
// php $bf/Shiva/dump.php 2000 1 1
chdir(__dir__);
require_once '../common.php';
ini_set('display_errors', 1);
$cli = $_ENV['cli'] = 0;

use Swoole\WebSocket\Frame;
use Swoole\Coroutine\Http\Client;

// todo ::: Not coroutine -> lightweighter client ?
use function Swoole\Coroutine\Run;

chdir(__dir__);
$to = 360;//read timeout does the whole difference

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

        $tentative = $hearbeats = $nb = $total = $fini = 0;
        $eachNSeconds = 1;
        $host = '127.0.0.1';
        $port = 2000;$nb = $total = 1;

        if ($argv[1]) $port = $argv[1];
        if (isset($argv[2])) $nb = $argv[2];
        if (isset($argv[3])) $total = $argv[3];
       if (isset($argv[4])) $host = $argv[4];

        if (in_array($nb, ['test', 'restart', 'dump', 'reset', 'xdebug'])) {//  php max4.php test shiva.devd339.dev.infomaniak.ch 80
            $cli=$x=null;//echo json_encode($argv);
            while(!$x){
				if($cli){$cli->close();unset($cli);sleep(1);}
				$_ENV['cli'] = $cli = new Client($host, $port);
				$cli->set(['timeout' => $to, 'connect_timeout' => $to, 'write_timeout' => $to, 'read_timeout' => $to]);$cli->upgrade('/');
				$res[] = $x=read('start', $e);
			}
            if ($e) return;
            $cli->push(json_encode([$nb => 1]));
            $res = read($nb, $e);
            echo"\n".$res;
            $cli->close();unset($cli);
            return;
            $log = [$res];
            return;
            die($okExitCode);
            throw new ExitException($okExitCode);
            Swoole\Process::exit($okExitCode);
            die();
        }
        echo $nb;
		return;Swoole\Process::exit($exit);
    });

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
        echo "\n#$pid:$essai;".implode(',',$log);
        $cli->close();die;
    }
    $x = $cli->recv();
    $nbReplies++;
    if (($nbReplies + $waits ) > $expectedRepliesNb) {
        echo "\n$pid:$nbReplies > $expectedRepliesNb,$tentative:$reason:err:". time()." :: ".trim($x->data);
    }// $x->data == 'opened'
    if ($cli->errCode) {//      websocket handshake failed, cannot push data
        $error = $cli->errCode;// err=60
        $a = ceil(microtime(true) - $b);
        if (strpos($reason, 'opened:') === 0) {
            $log[] = 'cnt:' . $a;
        }else{
            $log[] = $reason.','.$cli->errCode.','. $cli->errMsg.','.$a;
        }

        if ($nbRetries) {
            $b=microtime(true);
            return read($reason, $error, $nbRetries, $essai + 1);
        }

        if($error==8504){
            $a=1;
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
