<?php

// this example executes some commands within the given running container and
// displays the streaming output as it happens.

use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

if (DIRECTORY_SEPARATOR === '\\') {
    exit('File I/O not supported on Windows' . PHP_EOL);
}

$container = 'asd';
//$cmd = array('echo', 'hello world');
//$cmd = array('sleep', '2');
$cmd = array('sh', '-c', 'echo -n hello && sleep 1 && echo world && sleep 1 && env');
//$cmd = array('cat', 'invalid-path');

if (isset($argv[1])) {
    $container = $argv[1];
    $cmd = array_slice($argv, 2);
}

$client = new Clue\React\Docker\Client();

$out = new React\Stream\WritableResourceStream(STDOUT);
$stderr = new React\Stream\WritableResourceStream(STDERR);

// unkown exit code by default
$exit = 1;

$client->execCreate($container, $cmd)->then(function ($info) use ($client, $out, $stderr, &$exit) {
    $stream = $client->execStartStream($info['Id'], false, 'stderr');
    $stream->pipe($out);

    // forward custom stderr event to STDERR stream
    $stream->on('stderr', function ($data) use ($stderr, $stream) {
        if ($stderr->write($data) === false) {
            $stream->pause();
            $stderr->once('drain', function () use ($stream) {
                $stream->resume();
            });
        }
    });

    $stream->on('error', function (Exception $e) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
    });

    // remember exit code of executed command once it closes
    $stream->on('close', function () use ($client, $info, &$exit) {
        $client->execInspect($info['Id'])->then(function ($info) use (&$exit) {
            $exit = $info['ExitCode'];
        }, function (Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });
    });
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

Loop::run();

exit($exit);
