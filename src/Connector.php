<?php
/**
 * @author Oliver Lorenz
 * @since 2015-04-28
 * Time: 18:42
 */

namespace oliverlorenz\reactphpmqtt;

use oliverlorenz\reactphpmqtt\packet\ConnectionOptions;
use oliverlorenz\reactphpmqtt\packet\Events\StreamEvent;
use oliverlorenz\reactphpmqtt\packet\MessageHelper;
use oliverlorenz\reactphpmqtt\packet\PublishAck;
use oliverlorenz\reactphpmqtt\packet\PublishComplete;
use oliverlorenz\reactphpmqtt\packet\PublishReceived;
use oliverlorenz\reactphpmqtt\packet\PublishRelease;
use oliverlorenz\reactphpmqtt\packet\Subscribe;
use oliverlorenz\reactphpmqtt\packet\SubscribeAck;
use oliverlorenz\reactphpmqtt\packet\Unsubscribe;
use oliverlorenz\reactphpmqtt\packet\UnsubscribeAck;
use React\Dns\Resolver\Resolver;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use oliverlorenz\reactphpmqtt\packet\Connect;
use oliverlorenz\reactphpmqtt\packet\ConnectionAck;
use oliverlorenz\reactphpmqtt\packet\Disconnect;
use oliverlorenz\reactphpmqtt\packet\Factory;
use oliverlorenz\reactphpmqtt\packet\PingRequest;
use oliverlorenz\reactphpmqtt\packet\PingResponse;
use oliverlorenz\reactphpmqtt\packet\Publish;
use oliverlorenz\reactphpmqtt\protocol\Version;
use React\Promise\Deferred;
use React\Promise\FulfilledPromise;
use React\Promise\PromiseInterface;
use React\SocketClient\ConnectorInterface;
use React\SocketClient\DnsConnector;
use React\SocketClient\TcpConnector;
use React\Stream\Stream;

class Connector implements ConnectorInterface {

    protected $socketConnector;
    protected $version;
    protected $isConnected = false;
    /** @var Stream|null $stream */
    protected $stream;
    protected $onConnected;

    protected $pingedBack = null;
    protected $messageCounter = 1;

    /**
     * Debug mode
     *
     * @var bool
     */
    protected $debug = false;

    /**
     * @var $loop LoopInterface
     */
    protected $loop;

    public function __construct(LoopInterface $loop, Resolver $resolver, Version $version)
    {
        $this->version = $version;
        $this->socketConnector = new DnsConnector(new TcpConnector($loop), $resolver);
        $this->loop = $loop;
    }

    protected $onPublishReceived;

    /**
     * @return Stream
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return !is_null($this->stream);
    }

    public function onConnected(callable $function)
    {
        $this->onConnected = $function;
    }

    public function onPublishReceived(callable $function)
    {
        $this->onPublishReceived = $function;
    }

    public function __destruct()
    {
//        $this->disconnect();
    }

    /**
     * Creates a new connection
     *
     * @param string $host
     * @param integer $port [optional]
     * @param ConnectionOptions $options [optional]
     *
     * @return PromiseInterface Resolves to a \React\Stream\Stream once a connection has been established
     */
    public function create(
        $host,
        $port = 1883,
        ConnectionOptions $options = null
    ) {
        // Set default connection options, if none provided
        if(!isset($options)){
            $options = $this->getDefaultConnectionOptions();
        }

        return $this->socketConnector->create($host, $port)
            ->then(
                function (Stream $stream) use ($options) {
                    return $this->connect($stream, $options);
                }
            )
            ->then(
                function (Stream $stream) {
                    $stream->on('data', function ($rawData) use($stream) {
                        $messages = $this->getSplittedMessage($rawData);
                        foreach ($messages as $data) {
                            try {
                                $message = Factory::getByMessage($this->version, $data);

                                if($this->debug){
                                    $this->output('received:\t' . get_class($message));
                                    $this->output(MessageHelper::getReadableByRawString($data));
                                }

                                if ($message instanceof ConnectionAck) {
                                    $stream->emit(StreamEvent::CONNECTION_ACK, array($message));
                                } elseif ($message instanceof PingResponse) {
                                    $stream->emit(StreamEvent::PING_RESPONSE, array($message));
                                } elseif ($message instanceof Publish) {
                                    $stream->emit(StreamEvent::PUBLISH, array($message));
                                } elseif ($message instanceof PublishReceived) {
                                    $stream->emit(StreamEvent::PUBLISH_RECEIVED, array($message));
                                } elseif ($message instanceof PublishRelease) {
                                    $stream->emit(StreamEvent::PUBLISH_RELEASE, array($message));
                                } elseif ($message instanceof UnsubscribeAck) {
                                    $stream->emit(StreamEvent::UNSUBSCRIBE_ACK, array($message));
                                } elseif ($message instanceof SubscribeAck) {
                                    $stream->emit(StreamEvent::SUBSCRIBE_ACK, array($message));
                                }
                            } catch (\InvalidArgumentException $ex) {

                            }
                        }
                    });

                    $deferred = new Deferred();
                    $stream->on(StreamEvent::CONNECTION_ACK, function($message) use ($stream, $deferred) {
                        $deferred->resolve($stream);
                    });
                    return $deferred->promise();
                }
            )
            ->then(
                function(Stream $stream) {
                    // alive ping
                    $this->getLoop()->addPeriodicTimer(10, function(Timer $timer) use ($stream) {
                        $this->ping($stream);
                    });
                    return new FulfilledPromise($stream);
                }
            );
    }

    public function ping(Stream $stream)
    {
        $packet = new PingRequest($this->version);
        $this->sentMessageToStream($stream, $packet);
    }

    public function connect(Stream $stream, ConnectionOptions $options)
    {
        $packet = new Connect($this->version, $options);
        $message = $packet->get();

        if($this->debug){
            $this->output(MessageHelper::getReadableByRawString($message));
        }

        $deferred = new Deferred();
        if ($stream->write($message)) {
            $deferred->resolve($stream);
        } else {
            $deferred->reject();
        }
        return $deferred->promise();
    }

    /**
     * @param Stream $stream
     * @param $message
     * @return bool|void
     */
    protected function sendToStream(Stream $stream, $message)
    {
        return $stream->write($message);
    }

    protected function sentMessageToStream(Stream $stream, $controlPacket)
    {
        //echo "send:\t\t" . get_class($controlPacket) . "\n";
        $message = $controlPacket->get();
        return $this->sendToStream($stream, $message);
    }

    /**
     * @param Stream $stream
     * @param string $topic
     * @param int $qos
     * @return \React\Promise\Promise
     */
    public function subscribe(Stream $stream, $topic, $qos = 0)
    {
        $deferred = new Deferred();
        $packet = new Subscribe($this->version);
        $packet->addSubscription($topic, $qos);
        $this->sentMessageToStream($stream, $packet);

        $deferred = new Deferred();
        $stream->on('SUBSCRIBE_ACK', function($message) use ($stream, $deferred) {
            $deferred->resolve($stream);
        });
        return $deferred->promise();
    }

    /**
     * @param string $topic
     */
    public function unsubscribe(Stream $stream, $topic)
    {
        $packet = new Unsubscribe($this->version);
        $packet->removeSubscription($topic);
        $this->sentMessageToStream($stream, $packet);

        $deferred = new Deferred();
        $stream->on('UNSUBSCRIBE_ACK', function($message) use ($stream, $deferred) {
            $deferred->resolve($stream);
        });
        return $deferred->promise();
    }

    public function disconnect(Stream $stream)
    {
        $packet = new Disconnect($this->version);
        $this->sentMessageToStream($stream, $packet);
        return new FulfilledPromise($stream);
    }

    public function publish(Stream $stream, $topic, $message, $qos = 0, $dup = false)
    {
        $deferred = new Deferred();
        $packet = new Publish($this->version);
        $packet->setTopic($topic);
        $packet->setMessageId($this->messageCounter++);
        $packet->setQos($qos);
        $packet->setDup($dup);
        $packet->addRawToPayLoad($message);
        $success = $this->sentMessageToStream($stream, $packet);
        if ($success) {
            $deferred->resolve($stream);
        } else {
            $deferred->reject();
        }
        return $deferred->promise();
    }

    private function registerSignalHandler()
    {
        /*
        pcntl_signal(SIGTERM, array($this, "processSignal"));
        pcntl_signal(SIGHUP,  array($this, "processSignal"));
        pcntl_signal(SIGINT, array($this, "processSignal"));
        */
    }

    private function getSplittedMessage($data)
    {
        $messages = array();
        while(true) {
            if (isset($data{1})) {
                $length = ord($data{1});
                $messages[] = substr($data, 0, $length + 2);
                $data = substr($data, $length + 2);
            }

            if (empty($data)) {
                break;
            }
        }
        return $messages;
    }

    /**
     * @return LoopInterface
     */
    public function getLoop()
    {
        return $this->loop;
    }

    /**
     * Output a message
     *
     * @param string $message
     */
    protected function output($message)
    {
        echo PHP_EOL . $message;
    }

    /**
     * Set the debug state of this connector
     *
     * @param bool $runInDebugMode [optional] If true, additional debugging information can be viewed in output
     */
    public function debug($runInDebugMode = false)
    {
        $this->debug = $runInDebugMode;
    }

    /**
     * Returns default connection options
     *
     * @return ConnectionOptions
     */
    protected function getDefaultConnectionOptions()
    {
        return new ConnectionOptions();
    }
}