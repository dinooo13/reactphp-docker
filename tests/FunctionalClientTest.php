<?php

namespace Clue\Tests\React\Docker;

use Clue\React\Block;
use Clue\React\Docker\Client;
use React\EventLoop\Loop;
use React\Promise\Stream;

class FunctionalClientTest extends TestCase
{
    private $client;

    /**
     * @before
     */
    public function setUpClient()
    {
        $this->client = new Client();

        $promise = $this->client->ping();

        try {
            Block\await($promise, Loop::get());
        } catch (\Exception $e) {
            $this->markTestSkipped('Unable to connect to docker ' . $e->getMessage());
        }
    }

    public function testPing()
    {
        $this->expectPromiseResolve($this->client->ping(), 'OK');

        Loop::run();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testImageInspectCheckIfBusyboxExists()
    {
        $promise = $this->client->imageInspect('busybox:latest');

        try {
            Block\await($promise, Loop::get());
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Image "busybox" not downloaded yet');
        }
    }

    /**
     * @depends testImageInspectCheckIfBusyboxExists
     */
    public function testCreateStartAndRemoveContainer()
    {
        $config = array(
            'Image' => 'busybox',
            'Cmd' => array('echo', 'test')
        );

        $promise = $this->client->containerCreate($config);
        $container = Block\await($promise, Loop::get());

        $this->assertNotNull($container['Id']);
        $this->assertEmpty($container['Warnings']);

        $start = microtime(true);

        $promise = $this->client->containerStart($container['Id']);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('', $ret);

        $promise = $this->client->containerLogs($container['Id'], false, true, true);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals("test\n", $ret);

        $promise = $this->client->containerAttach($container['Id'], true, false);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals("test\n", $ret);

        $promise = $this->client->containerRemove($container['Id'], false, true);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('', $ret);

        $end = microtime(true);

        // get all events between starting and removing for this container
        $promise = $this->client->events($start, $end, array('container' => array($container['Id'])));
        $ret = Block\await($promise, Loop::get());

        // expects "start", "attach", "kill", "die", "destroy" events
        $this->assertEquals(5, count($ret));
        $this->assertEquals('start', $ret[0]['status']);
        $this->assertEquals('attach', $ret[1]['status']);
        $this->assertEquals('kill', $ret[2]['status']);
        $this->assertEquals('die', $ret[3]['status']);
        $this->assertEquals('destroy', $ret[4]['status']);
    }

    /**
     * @depends testImageInspectCheckIfBusyboxExists
     */
    public function testStartRunning()
    {
        $config = array(
            'Image' => 'busybox',
            'Tty' => true,
            'Cmd' => array('sleep', '10')
        );

        $promise = $this->client->containerCreate($config);
        $container = Block\await($promise, Loop::get());

        $this->assertNotNull($container['Id']);
        $this->assertEmpty($container['Warnings']);

        $promise = $this->client->containerStart($container['Id']);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('', $ret);

        return $container['Id'];
    }

    /**
     * @depends testStartRunning
     * @param string $container
     * @return string
     */
    public function testExecCreateWhileRunning($container)
    {
        $promise = $this->client->execCreate($container, array('echo', '-n', 'hello', 'world'));
        $exec = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($exec));
        $this->assertTrue(is_string($exec['Id']));

        return $exec['Id'];
    }

    /**
     * @depends testExecCreateWhileRunning
     * @param string $exec
     */
    public function testExecInspectBeforeRunning($exec)
    {
        $promise = $this->client->execInspect($exec);
        $info = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($info));
        $this->assertFalse($info['Running']);
        $this->assertEquals(null, $info['ExitCode']);
    }

    /**
     * @depends testExecCreateWhileRunning
     * @param string $exec
     */
    public function testExecStartWhileRunning($exec)
    {
        $promise = $this->client->execStart($exec);
        $output = Block\await($promise, Loop::get());

        $this->assertEquals('hello world', $output);
    }

    /**
     * @depends testExecCreateWhileRunning
     * @param string $exec
     */
    public function testExecInspectAfterRunning($exec)
    {
        $promise = $this->client->execInspect($exec);
        $info = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($info));
        $this->assertFalse($info['Running']);
        $this->assertEquals(0, $info['ExitCode']);
    }

    /**
     * @depends testStartRunning
     * @param string $container
     */
    public function testExecStringCommandWithOutputWhileRunning($container)
    {
        $promise = $this->client->execCreate($container, 'echo -n hello world');
        $exec = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($exec));
        $this->assertTrue(is_string($exec['Id']));

        $promise = $this->client->execStart($exec['Id']);
        $output = Block\await($promise, Loop::get());

        $this->assertEquals('hello world', $output);
    }

    /**
     * @depends testStartRunning
     * @param string $container
     */
    public function testExecStreamOutputInMultipleChunksWhileRunning($container)
    {
        $promise = $this->client->execCreate($container, 'echo -n hello && sleep 0.2 && echo -n world');
        $exec = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($exec));
        $this->assertTrue(is_string($exec['Id']));

        $stream = $this->client->execStartStream($exec['Id']);
        $stream->once('data', $this->expectCallableOnceWith('hello'));
        $stream->on('end', $this->expectCallableOnce());

        $output = Block\await(Stream\buffer($stream), Loop::get());

        $this->assertEquals('helloworld', $output);
    }

    /**
     * @depends testStartRunning
     * @param string $container
     */
    public function testExecUserSpecificCommandWithOutputWhileRunning($container)
    {
        $promise = $this->client->execCreate($container, 'whoami', false, false, true, true, 'nobody');
        $exec = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($exec));
        $this->assertTrue(is_string($exec['Id']));

        $promise = $this->client->execStart($exec['Id']);
        $output = Block\await($promise, Loop::get());

        $this->assertEquals('nobody', rtrim($output));
    }

    /**
     * @depends testStartRunning
     * @param string $container
     */
    public function testExecStringCommandWithStderrOutputWhileRunning($container)
    {
        $promise = $this->client->execCreate($container, 'echo -n hello world >&2');
        $exec = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($exec));
        $this->assertTrue(is_string($exec['Id']));

        $promise = $this->client->execStart($exec['Id']);
        $output = Block\await($promise, Loop::get());

        $this->assertEquals('hello world', $output);
    }

    /**
     * @depends testStartRunning
     * @param string $container
     */
    public function testExecStreamCommandWithTtyAndStderrOutputWhileRunning($container)
    {
        $promise = $this->client->execCreate($container, 'echo -n hello world >&2', true);
        $exec = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($exec));
        $this->assertTrue(is_string($exec['Id']));

        $stream = $this->client->execStartStream($exec['Id'], true);
        $stream->once('data', $this->expectCallableOnce('hello world'));
        $stream->on('end', $this->expectCallableOnce());

        $output = Block\await(Stream\buffer($stream), Loop::get());

        $this->assertEquals('hello world', $output);
    }

    /**
     * @depends testStartRunning
     * @param string $container
     */
    public function testExecStreamStderrCustomEventWhileRunning($container)
    {
        $promise = $this->client->execCreate($container, 'echo -n hello world >&2');
        $exec = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($exec));
        $this->assertTrue(is_string($exec['Id']));

        $stream = $this->client->execStartStream($exec['Id'], false, 'err');
        $stream->on('err', $this->expectCallableOnceWith('hello world'));
        $stream->on('data', $this->expectCallableNever());
        $stream->on('error', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableOnce());

        $output = Block\await(Stream\buffer($stream), Loop::get());

        $this->assertEquals('', $output);
    }

    /**
     * @depends testStartRunning
     * @param string $container
     */
    public function testExecStreamEmptyOutputWhileRunning($container)
    {
        $promise = $this->client->execCreate($container, array('true'));
        $exec = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($exec));
        $this->assertTrue(is_string($exec['Id']));

        $stream = $this->client->execStartStream($exec['Id'], true);
        $stream->on('end', $this->expectCallableOnce());

        $output = Block\await(Stream\buffer($stream), Loop::get());

        $this->assertEquals('', $output);
    }

    /**
     * @depends testStartRunning
     * @param string $container
     */
    public function testExecDetachedWhileRunning($container)
    {
        $promise = $this->client->execCreate($container, array('sleep', '10'));
        $exec = Block\await($promise, Loop::get());

        $this->assertTrue(is_array($exec));
        $this->assertTrue(is_string($exec['Id']));

        $promise = $this->client->execStartDetached($exec['Id'], true);
        $output = Block\await($promise, Loop::get());

        $this->assertEquals('', $output);
    }

    /**
     * @depends testStartRunning
     * @param string $container
     */
    public function testRemoveRunning($container)
    {
        $promise = $this->client->containerRemove($container, true, true);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('', $ret);
    }

    public function testContainerRemoveInvalid()
    {
        $promise = $this->client->containerRemove('invalid123');
        $this->setExpectedException('RuntimeException');
        Block\await($promise, Loop::get());
    }

    public function testImageSearch()
    {
        $promise = $this->client->imageSearch('clue');
        $ret = Block\await($promise, Loop::get());

        $this->assertGreaterThan(9, count($ret));
    }

    /**
     * @depends testImageInspectCheckIfBusyboxExists
     * @doesNotPerformAssertions
     */
    public function testImageTag()
    {
        // create new tag "bb:now" on "busybox:latest"
        $promise = $this->client->imageTag('busybox', 'bb', 'now');
        Block\await($promise, Loop::get());

        // delete tag "bb:now" again
        $promise = $this->client->imageRemove('bb:now');
        Block\await($promise, Loop::get());
    }

    public function testImageCreateStreamMissingWillEmitJsonError()
    {
        $promise = $this->client->version();
        $version = Block\await($promise, Loop::get());

        // old API reports a progress with error message, newer API just returns 404 right away
        // https://docs.docker.com/engine/api/version-history/
        $old = $version['ApiVersion'] < '1.22';

        $stream = $this->client->imageCreateStream('clue/does-not-exist');

        // one "progress" event, but no "data" events
        $old && $stream->on('progress', $this->expectCallableOnce());
        $old || $stream->on('progress', $this->expectCallableNever());
        $stream->on('data', $this->expectCallableNever());

        // will emit "error" with RuntimeException and close
        $old && $stream->on('error', $this->expectCallableOnceParameter('RuntimeException'));
        $old || $stream->on('error', $this->expectCallableOnceParameter('React\Http\Message\ResponseException'));
        $stream->on('close', $this->expectCallableOnce());

        Loop::run();
    }

    public function testInfo()
    {
        $this->expectPromiseResolve($this->client->info());

        Loop::run();
    }

    public function testVersion()
    {
        $this->expectPromiseResolve($this->client->version());

        Loop::run();
    }

    public function testContainerList()
    {
        $this->expectPromiseResolve($this->client->containerList());

        Loop::run();
    }

    /**
     * @depends testImageInspectCheckIfBusyboxExists
     */
    public function testCreateConnectDisconnectAndRemoveNetwork()
    {
        $containerConfig = array(
            'Image' => 'busybox',
            'Cmd' => array('echo', 'test')
        );
        $networkName = uniqid('reactphp-docker', true);

        $promise = $this->client->containerCreate($containerConfig);
        $container = Block\await($promise, Loop::get());

        $start = microtime(true);

        $promise = $this->client->networkCreate($networkName);
        $network = Block\await($promise, Loop::get());

        $this->assertNotNull($network['Id']);
        $this->assertEquals('', $network['Warning']);

        $promise = $this->client->networkConnect($network['Id'], $container['Id']);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('', $ret);

        $promise = $this->client->networkDisconnect($network['Id'], $container['Id'], false);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('', $ret);

        $promise = $this->client->networkRemove($network['Id']);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('', $ret);

        $end = microtime(true);

        $promise = $this->client->containerRemove($container['Id']);
        $ret = Block\await($promise, Loop::get());

        // get all events between starting and removing for this container
        $promise = $this->client->events($start, $end, array('network' => array($network['Id'])));
        $ret = Block\await($promise, Loop::get());

        // expects "create", "disconnect", "destroy" events ("connect" will be skipped because we don't start the container)
        $this->assertCount(3, $ret);
        $this->assertEquals('create', $ret[0]['Action']);
        $this->assertEquals('disconnect', $ret[1]['Action']);
        $this->assertEquals('destroy', $ret[2]['Action']);
    }

    /**
     * @depends testImageInspectCheckIfBusyboxExists
     */
    public function testCreateAndCommitContainer()
    {
        $config = array(
            'Image' => 'busybox',
            'Cmd' => array('echo', 'test')
        );

        $promise = $this->client->containerCreate($config);
        $container = Block\await($promise, Loop::get());

        $this->assertNotNull($container['Id']);
        $this->assertEmpty($container['Warnings']);

        $promise = $this->client->containerCommit($container['Id']);
        $image = Block\await($promise, Loop::get());

        $this->assertNotNull($image['Id']);
        $this->assertArrayNotHasKey('message', $image);

        $promise = $this->client->containerRemove($container['Id'], false, true);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('', $ret);

        $config = array(
            'Image' => $image['Id'],
            'Cmd' => array('echo', 'test')
        );

        $promise = $this->client->containerCreate($config);
        $container = Block\await($promise, Loop::get());

        $this->assertNotNull($container['Id']);
        $this->assertEmpty($container['Warnings']);

        $promise = $this->client->containerRemove($container['Id'], false, true);
        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('', $ret);
    }
}
