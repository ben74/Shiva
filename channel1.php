<?php
// php channel1.php
$starts = microtime(true);
$lock = new Swoole\Atomic;
if (pcntl_fork() > 0) {// Seule utilité : Incrément : Nb Tasks et wakeup while waiting
    echo "master start\n";
    $lock->wait(1.5);
    echo (microtime(1) - $starts) . ":master end\n";
} else {
    echo "child start\n";
    usleep(500000);
    $lock->wakeup();
    echo (microtime(1) - $starts) . ":wakedup\n";
}

if (0) {
    $counter = new Swoole\Atomic(123);
    echo $counter->add(12) . "\n";//135
    echo $counter->sub(11) . "\n";//124
    echo $counter->cmpset(122, 500) . "\n";//si 122 then 999
    echo $counter->cmpset(124, 999) . "\n";//1
    echo $counter->get() . "\n";//999
    echo $counter->wait(1) . "\n";// waits 1 second, exact if another process wakes him up ( bypass this one )
}
//SET, WAIT, WAKEYP
$capacity = 9000;
$chan = new Swoole\Coroutine\Channel($capacity);

Co\run(function () use ($chan, $starts) {

    go(function () use ($chan, $starts) {
        $cid = Swoole\Coroutine::getuid();
        $i = 0;
        while (1) {
            co::sleep(1);
            echo "-----------\n" . (microtime(1) - $starts) . " pushes $i\n";
            $chan->push(['rand' => rand(1000, 9999), 'index' => $i]);
            echo "stats " . json_encode($chan->stats()) . "\n";
            $i++;
        }

    });
// Pour les connections en attente d'un message BLPOP ici
    go(function () use ($chan, $starts) {
        $cid = Swoole\Coroutine::getuid();
        while (1) {
            $data = $chan->pop();
            echo (microtime(1) - $starts) . "[co $cid] pops " . json_encode($data) . "\n";
        }
    });

    go(function () use ($chan, $starts) {
        $cid = Swoole\Coroutine::getuid();
        while (1) {
            $data = $chan->pop();
            echo (microtime(1) - $starts) . "[co $cid] pops " . json_encode($data) . "\n";
        }
    });

    go(function () use ($chan, $starts) {
        $cid = Swoole\Coroutine::getuid();
        usleep(5500000);
        //echo "[co $cid] id ".json_encode($chan->getId())."\n";
        echo (microtime(1) - $starts) . "[co $cid] full " . json_encode($chan->isFull()) . "\n";//false
        echo "[co $cid] empty " . json_encode($chan->isEmpty()) . "\n";//empty:true
        echo "[co $cid] length " . json_encode($chan->length()) . "\n";//leng:0
        echo "[co $cid] stats " . json_encode($chan->stats()) . "\n";
    });
});

return; ?>
