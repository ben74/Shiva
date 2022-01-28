<?php
$_ENV['nolog']=0;
chdir(__dir__);
require_once '../swooleCommons.php';
ini_set('display_errors', 1);

use Swoole\WebSocket\Frame;
use Swoole\Coroutine\Http\Client;
use function Swoole\Coroutine\run;

chdir(__dir__);
// 2000 bob pass bob alice helloFromBob token
// 2000 alice pass2 alice bob helloFromAlice
db($argv);
run(function () use ($argv) {// Coroutine launch several instances, nop ?
    $start = microtime(1);
    try {
        $eachNSeconds = 20000;
        $fini = false;
        $aa = microtime(1);
        $cli = new Client('127.0.0.1', $argv[1]);
        $cli->set(['timeout' => 9999, 'connect_timeout' => 9999, 'write_timeout' => 9999, 'read_timeout' => 9999,]);
        $cli->upgrade('/');//10248:nibards
        $x = $cli->recv();
        $res['welcome'] = $x->data;
        db('welcom' . $x->data);
if(0){
        pcntl_signal(SIGALRM, function () use ($eachNSeconds, $cli, $fini) {
            db("Signal fini");//grep "Signal fini" *.log
            $cli->push(json_encode(['keepalive' => 1]));
            pcntl_alarm($eachNSeconds);
            return;
            $cli->push(json_encode(['hb' => time()]));
            $cli->recv();//Osef
            pcntl_alarm($eachNSeconds);// répéter l'interrogation récursivement
        });
        pcntl_alarm($eachNSeconds);// KeepAlive
}
        if ($argv[6] == 'token') {
            $tok = hash("sha256", $argv[2] . date("YmdHi") . $argv[3]);
            $login = '{"token":"' . $tok . '"}';
        } else {
            $login = '{"user":"' . $argv[2] . '","pass":"' . $argv[3] . '"}';
        }
//46ms pour 7 opérations
        $last=$argv[7];
        $discussion = array_filter(['{"get":"time"}', $login /** Gêle ici  */, '{"iam":"' . $argv[2] . '"}', '{"sendto":"' . $argv[4] . '","message":"' . $argv[5] . '"}',$argv[7],$argv[8] ]);//,'{"status":"free"}','{wait:}' => la TX du message peut avoir lieu bien après
        //	{"keepalive":"waits for last transmission"}:in:853.15704345703:
        foreach ($discussion as $q) {
            $waits = 0;
            //$a = microtime(1);
            $cli->push($q);//$res[$q.((microtime(1)-$a)*1000)]='pushed';
            //$b = microtime(1);#$res[$b]='pushed';
            // trims the reception, nope
            $sucess = $cli->recv();//  Last Waits here for something to show up
            $x = trim($sucess->data);
            //$x = $hybi->decode($cli->recv());
            while ($last and $q == $last and !$x) {//Waits for it
                db('waits');
                $waits++;
                $cli->push(json_encode(['keepalive' => 1]));//
                $x = trim($cli->recv()->data);
                //$x = $hybi->decode($cli->recv());
                sleep(10);
            }
            //db('q::'.$q.' reply ==>'.$x);
            $res[$q] = $x;//:in:' . ((microtime(1) - $b) * 1000)

            if (1 and substr($x, 0, 1) == '{' and substr($x, -1) == '}') {
                $j = json_decode($x, 1);
                if (isset($j['message'])) {
                    if (strpos($j['message'], ':SIGNAL')) {
                        db("Consuming:Sign:" . $j['message']);
                    } else {
                        db("Consuming:" . $j['message']);
                    }
                }
            } else {// grep message 4.log
                db(":notMsg:" . substr($x, 0, 1) . substr($x, -1) . "\t" . $q . ' => ' . $x);
            }
        }#end discussion

        $res = array_filter($res);
        $expected = count($discussion) + 1;
        $nbres = count($res);
        $res2 = [];
        foreach ($res as $k => $v) {
            $res2[] = "$k  == > $v";
        }
        if ($nbres != $expected) {
            db("=>xxx=>$waits Boum! no results=>$nbres/$expected in " . (microtime(1) - $aa) . '=>' . implode("\t", $res2));
        } else {
            db("Ok=>" . (microtime(1) - $aa) . '=>' . implode("\t", $res2));
        }
        //echo"\nC:".getmypid();//.':'.json_encode($res);
        //print_r($res);
    } catch (\throwable $e) {
        db("err" . $e->getMessage());
        //file_put_contents('err.log', "\nC:" . getMyPid() . '=>' . $e->getMessage(), 8);
        print_r($e);
    }
    $fini = true;
});
function db($x)
{
    global $argv;
    if(is_array($x))$x=stripslashes(json_encode($x));
    file_put_contents('../3.log', "\nC:".getmypid().':'.$argv[2].':' . $x, 8);
}


//
return; ?>