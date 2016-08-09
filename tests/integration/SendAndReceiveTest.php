<?php

use oliverlorenz\reactphpmqtt\Connector;
use oliverlorenz\reactphpmqtt\packet\ConnectionOptions;
use oliverlorenz\reactphpmqtt\packet\Events\StreamEvent;
use oliverlorenz\reactphpmqtt\packet\Publish;
use oliverlorenz\reactphpmqtt\packet\QoS\Levels;
use oliverlorenz\reactphpmqtt\protocol\Version4;
use oliverlorenz\reactphpmqtt\SecureConnector;
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
     * @see http://iot.eclipse.org/getting-started
     *
     * @var string
     */
    protected $hostname = 'iot.eclipse.org';
    //protected $hostname = 'dev.webdts35.localhost';

    /**
     * Port
     *
     * 1883, unsecured connection
     * 8883, secure connection
     *
     * @var int
     */
    protected $port = 8883;
    //protected $port = 46423;

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
     * Loop timeout, duration in seconds
     *
     * @var int
     */
    protected $loopTimeout = 15;

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
     * State of connector
     *
     * @var bool
     */
    protected $debug = true;

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
        $this->loop->addPeriodicTimer($this->loopTimeout, function(){
            $this->loop->stop();
            $this->assertTrue(false, 'Event loop timeout');
        });
    }

    /**
     * {@inheritdoc}
     */
    public function tearDown()
    {
        unset($this->loop);

        $this->line();
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
        if($this->port == 8883){
            $connector =  new SecureConnector($this->loop, $this->resolver, $this->version);
        } else {
            $connector = new Connector($this->loop, $this->resolver, $this->version);
        }

        $connector->debug($this->debug);

        return $connector;
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
        //$options['username'] = 'vcas';
        //$options['password'] = 'vcas0030';

        return new ConnectionOptions($options);
    }

    /**
     * Returns a new topic
     *
     * @return string
     */
    protected function makeTopic()
    {
        return $this->topicPrefix . uniqid();
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

    /**
     * Outputs a line
     */
    protected function line()
    {
        $this->output(str_repeat('- - ', 25));
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
        ->then(function(Stream $stream) use (&$message){

            $this->output('created...', time());

            // On message received
            $stream->on(StreamEvent::PUBLISH, function(Publish $packet) use (&$message){
                $this->output($packet->getTopic(), 'Received', $packet->getMessage(), time());

                // Assert and stop the loop
                $this->assertSame($message, $packet->getMessage(), 'Incorrect sent message');
                $this->stop();
            });

            return $stream;
        })

        ->then(function(Stream $stream) use ($connectorA, &$topic) {

            // Subscribe
            return $connectorA->subscribe($stream, $topic, Levels::AT_LEAST_ONCE_DELIVERY)
            ->then(function(Stream $stream) use (&$topic){
                $this->output('Connector A', 'Subscribed to', $topic, time());
                return $stream;
            });
        })

        // Once the first connector has been setup, we create the second connector, which
        // then publishes a message to the same topic as the first connector
        ->then(function(Stream $stream) use($connectorB, $options, &$topic, &$message){
            $options->clientId = "connectorB";
            $connectorB->create($this->hostname, $this->port, $options)
            ->then(function(Stream $stream) use($connectorB, &$topic, &$message){

                // Publish message
               $connectorB->publish($stream, $topic, $message)
               ->then(function(Stream $stream) use(&$message, &$topic){
                   $this->output('Connector B', 'Published', $message, $topic, time());
               });

            });
        })
        ->then(null, function($reason) {
            $this->output("Rejected: ", $reason->getMessage());
        });

        $this->start();
    }

    public function testWillMessageIsPublished()
    {
        $topic = $this->makeTopic();
        $willTopic = $this->makeTopic();

        $message = 'Walking on the Moon';
        $willMessage = 'connector offline';

        $options = $this->makeConnectionOptions([
            'willTopic'     => $willTopic,
            'willMessage'   => $willMessage
        ]);

        $connectorA = $this->makeConnector();
        $connectorB = $this->makeConnector();

        // We connect with A, where we subscribe to a topic and listen
        // for messages on that topic.
        $connectorA->create($this->hostname, $this->port, null)
        ->then(function(Stream $stream) use ($options){
            // On message received
            $stream->on(StreamEvent::PUBLISH, function(Publish $packet) use ($options){
                $this->output($packet->getTopic(), 'Received', $packet->getMessage());

                // Assert and stop the loop
                if($packet->getTopic() == $options->willTopic){
                    $this->output('will received');

                    $this->assertSame($options->willMessage, $packet->getMessage(), 'Incorrect will message received');
                    $this->stop();
                }
            });

            return $stream;
        })
        ->then(function(Stream $stream) use ($connectorA, &$topic, $options) {

            // Subscribe
            return $connectorA->subscribe($stream, $topic, Levels::AT_LEAST_ONCE_DELIVERY)
            ->then(function(Stream $stream) use (&$topic){
                $this->output('Connector A', 'Subscribed to', $topic);
                return $stream;
            })
            ->then(function($stream) use($connectorA, $options) {
                return $connectorA->subscribe($stream, $options->willTopic, Levels::AT_LEAST_ONCE_DELIVERY)
                ->then(function (Stream $stream) use ($options) {
                    $this->output('Connector A', 'Subscribed to', $options->willTopic);
                    return $stream;
                });
            });

        })

        // Once the first connector has been setup, we create the second connector, which
        // then jus terminates the stream
        ->then(function(Stream $stream) use($connectorB, $options, &$topic, &$message){

            $connectorB->create($this->hostname, $this->port, $options)
            ->then(function(Stream $stream) use($connectorB, &$topic, &$message) {
                $this->output('Connector B', 'connected', time());

                $this->output('Connector B', 'Publishing', $message);
                // Publish message
                return $connectorB->publish($stream, $topic, $message, Levels::AT_LEAST_ONCE_DELIVERY)
                ->then(function(Stream $stream) use($message, &$topic){
                    $this->output('Connector B', 'Published', $message, $topic, time());
                    return $stream;
                });
            })
            ->then(function(Stream $stream) {
                $this->loop->addTimer(1, function() use($stream) {
                    $stream->close();

                    $this->output('Connector B', 'stream closed', time());
                });
            });

            return $stream;
        })
        ->then(null, function($reason) {
            $this->output("Rejected: ", $reason->getMessage());
        });

        $this->start();
    }
}