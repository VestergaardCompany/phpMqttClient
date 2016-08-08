<?php

use oliverlorenz\reactphpmqtt\Connector;
use oliverlorenz\reactphpmqtt\packet\ConnectionOptions;
use oliverlorenz\reactphpmqtt\packet\Events\StreamEvent;
use oliverlorenz\reactphpmqtt\packet\Publish;
use oliverlorenz\reactphpmqtt\packet\QoS\Levels;
use oliverlorenz\reactphpmqtt\protocol\Version4;
use React\Dns\Resolver\Resolver;
use React\EventLoop\Factory as EventLoopFactory;
use React\Dns\Resolver\Factory as DNSResolverFactory;
use React\Promise\Deferred;
use React\Stream\Stream;

/**
 * Class SendAndReceiveTest
 *
 * @author Alin Eugen Deac <ade@vestergaardcompany.com>
 */
class SendAndReceiveTest extends PHPUnit_Framework_TestCase
{

    /**
     * Name Server
     *
     * @var string
     */
    protected $nameServer = '8.8.8.8';

    /**
     * Hostname
     *
     * @var string
     */
    protected $hostname = 'iot.eclipse.org';

    /**
     * Port
     *
     * @var int
     */
    protected $port = 1883;

    /**
     * The topic
     *
     * @var string
     */
    protected $topicPrefix = 'testing/';

    /**
     * Event loop
     *
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * DNS Resolver
     *
     * @var Resolver
     */
    protected $resolver;

    /**
     * Protocol Version
     *
     * @var \oliverlorenz\reactphpmqtt\protocol\Version
     */
    protected $version;

    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        // Setup the version
        $this->version = new Version4();

        // Create event loop
        $this->loop = EventLoopFactory::create();

        // DNS Resolver
        $this->resolver = (new DNSResolverFactory())->createCached($this->nameServer, $this->loop);

        // Add loop timeout
        $this->loop->addPeriodicTimer(10, function(){
            $this->loop->stop();
            $this->assertTrue(false, 'Event loop timeout');
        });
    }

    /**
     * {@inheritdoc}
     */
    public function tearDown()
    {

    }

    /*******************************************************
     * Helpers
     ******************************************************/

    public function start()
    {
        $this->loop->run();
    }

    public function stop()
    {
        $this->loop->stop();
    }

    /**
     * Returns a new connector instance
     *
     * @return Connector
     */
    public function makeConnector()
    {
        return new Connector($this->loop, $this->resolver, $this->version);
    }

    /**
     * Returns a new instance of the connection options
     *
     * @param array $options [optional]
     *
     * @return ConnectionOptions
     */
    protected function makeConnectionOptions(array $options = [])
    {
        return new ConnectionOptions($options);
    }

    /**
     * Returns a new topic
     *
     * @return string
     */
    protected function makeTopic()
    {
        return $this->topicPrefix . '/' . uniqid();
    }

    /**
     * Writes to the console
     *
     * @param mixed
     */
    protected function output()
    {
        $message = implode(', ', func_get_args());

        print PHP_EOL . $message;
    }

    /*******************************************************
     * Actual tests
     ******************************************************/

    public function testCanSendAndReceiveAMessage()
    {
        $topic = $this->makeTopic();

        $message = 'Hallo World';

        $options = $this->makeConnectionOptions();

        $connectorA = $this->makeConnector();
        $connectorB = $this->makeConnector();

        // We connect with A, where we subscribe to a topic and listen
        // for messages on that topic.
        $connectorA->create($this->hostname, $this->port, $options)
        ->then(function(Stream $stream) use ($connectorA, &$topic, &$message) {

            // On message received
            $stream->on(StreamEvent::PUBLISH, function(Publish $packet) use (&$message){
                $this->output('Received', $packet->getMessage());

                // Assert and stop the loop
                $this->assertSame($message, $packet->getMessage(), 'Incorrect sent message');
                $this->stop();
            });

            // Subscribe
            $connectorA->subscribe($stream, $topic, Levels::AT_LEAST_ONCE_DELIVERY)
                ->then(function(Stream $stream) use (&$topic){
                    $this->output('Subscribed to', $topic);
                });

            return $stream;
        })

        // Once the first connector has been setup, we create the second connector, which
        // then publishes a message to the same topic as the first connector
        ->then(function(Stream $stream) use($connectorB, $options, &$topic, &$message){

            $connectorB->create($this->hostname, $this->port, $options)
            ->then(function(Stream $stream) use($connectorB, &$topic, &$message){

                // Publish message
               $connectorB->publish($stream, $topic, $message)
               ->then(function(Stream $stream) use(&$message){
                   $this->output('Published', $message);
               });

            });
        });

        $this->start();
    }
}