<?php
/**
 * Created by PhpStorm.
 * User: matt
 * Date: 8/27/15
 * Time: 3:33 PM
 */

namespace PAMI\Client\Impl;


use PAMI\Client\Closure;
use PAMI\Client\Exception\ClientException;
use PAMI\Client\IClient;
use PAMI\Client\PAMI;
use PAMI\Message\Action\LoginAction;
use PAMI\Message\Event\EventMessage;
use PAMI\Message\Event\Factory\Impl\EventFactoryImpl;
use PAMI\Message\IncomingMessage;
use PAMI\Message\Message;
use PAMI\Message\OutgoingMessage;
use PAMI\Message\Response\ResponseMessage;
use React\Dns\Resolver\Factory as ResolverFactory;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\SocketClient\Connector;
use React\Stream\Stream;

class ReactClientImpl implements IClient
{

    /**
     * @var Action[]
     */
    private $_actions = [];

    /**
     * log4php logger or dummy.
     * @var Logger
     */
    private $_logger;

    /**
     * Hostname
     * @var string
     */
    private $_host;

    /**
     * TCP Port.
     * @var integer
     */
    private $_port;

    /**
     * Username
     * @var string
     */
    private $_user;

    /**
     * Password
     * @var string
     */
    private $_pass;

    /**
     * Connection timeout, in seconds.
     * @var integer
     */
    private $_cTimeout;

    /**
     * Connection scheme, like tcp:// or tls://
     * @var string
     */
    private $_scheme;

    /**
     * Event factory.
     * @var EventFactoryImpl
     */
    private $_eventFactory;

    /**
     * R/W timeout, in milliseconds.
     * @var integer
     */
    private $_rTimeout;

    /**
     * Our stream socket resource.
     * @var Connector
     */
    private $_socket;

    /**
     * Our stream context resource.
     * @var resource
     */
    private $_context;

    /**
     * Our event listeners
     * @var IEventListener[]
     */
    private $_eventListeners;

    /**
     * The send queue
     * @var OutgoingMessage[]
     */
    private $_outgoingQueue;

    /**
     * The receiving queue.
     * @var IncomingMessage[]
     */
    private $_incomingQueue;

    /**
     * Our current received message. May be incomplete, will be completed
     * eventually with an EOM.
     * @var string
     */
    private $_currentProcessingMessage;

    /**
     * This should not happen. Asterisk may send responses without a
     * corresponding ActionId.
     * @var string
     */
    private $_lastActionId;

    /**
     * @var LoopInterface
     */
    private $_loop;

    /**
     * @var Stream
     */
    private $_stream;

    /**
     * @var bool
     */
    private $_loggedIn = false;

    /**
     * @var string
     */
    private $_messageBuffer = '';

    /**
     * Constructor.
     *
     * @param string[] $options Options for ami client.
     *
     * @return void
     */
    public function __construct(array $options)
    {
        if (isset($options['log4php.properties'])) {
            \Logger::configure($options['log4php.properties']);
        }
        $this->_logger = \Logger::getLogger('Pami.ClientImpl');
        $this->_host = $options['host'];
        $this->_port = intval($options['port']);
        $this->_user = $options['username'];
        $this->_pass = $options['secret'];
        $this->_cTimeout = $options['connect_timeout'];
        $this->_rTimeout = $options['read_timeout'];
        $this->_scheme = isset($options['scheme']) ? $options['scheme'] : 'tcp://';
        $this->_eventListeners = array();
        $this->_eventFactory = new EventFactoryImpl();
        $this->_incomingQueue = array();
        $this->_lastActionId = false;
        $this->_loop = $options['loop'];
        $this->_messageBuffer = '';
    }

    /**
     * Opens a tcp connection to ami.
     *
     * @throws ClientException
     * @return void
     */
    public function open()
    {
        if (!($this->_loop instanceof LoopInterface)) {
            throw new ClientException('No loop configured');
        }

        $resolverFactory = new ResolverFactory();
        $dns = $resolverFactory->createCached('8.8.8.8', $this->_loop);

        $this->_socket = new Connector($this->_loop, $dns);

        $this->_socket->create($this->_host, $this->_port)->then(function (Stream $stream) {
            $this->_stream = $stream;

            $signature = '';
            $stream->on('data', function ($data) use (&$signature, $stream) {
                $signature .= $data;
                if (strstr($signature, "\r\n")) {
                    $stream->removeAllListeners('data');
                    if (strstr($signature, "Asterisk Call Manager")) {
                        $stream->on('data', [$this, 'handleData']);

                        $msg = new LoginAction($this->_user, $this->_pass);
                        $loginPromise = $this->send($msg);

                        $loginPromise->then(function (Action $action) {
                            $responseMessage = $action->getResponseMessage();
                            echo "---" . $responseMessage->getMessage() . "\n";

                            if ($responseMessage->isSuccess()) {
                                echo "Logged in\n";
                                $this->_loggedIn = true;
                            }
                        });
                    }




                }
            });



        });
    }

    public function handleData($data) {
        $this->_messageBuffer .= $data;

        $msgs = [];
        while (($marker = strpos($this->_messageBuffer, Message::EOM))) {
            $msg = substr($this->_messageBuffer, 0, $marker);
            $this->_messageBuffer = substr(
                $this->_messageBuffer, $marker + strlen(Message::EOM)
            );
            $msgs[] = $msg;
        }

        foreach ($msgs as $aMsg) {
            $resPos = strpos($aMsg, 'Response:');
            $evePos = strpos($aMsg, 'Event:');
            if (($resPos !== false) && (($resPos < $evePos) || $evePos === false)) {
                $response = $this->_messageToResponse($aMsg);

                // find the action that this is in response to
                foreach ($this->_actions as $i => $action) {
                    if ($action->getActionMessage()->getActionID()) {
                        $action->setResponseMessage($response);
                        $action->getDeferred()->resolve($action);
                        unset($this->_actions[$i]);
                    }
                }
            } else if ($evePos !== false) {
                $event = $this->_messageToEvent($aMsg);
                $response = $this->findResponse($event);
                if ($response === false || $response->isComplete()) {
                    $this->dispatch($event);
                } else {
                    $response->addEvent($event);
                }
            } else {
                // broken ami.. sending a response with events without
                // Event and ActionId
                echo "Got message with no action id?";
//                $bMsg = 'Event: ResponseEvent' . "\r\n";
//                $bMsg .= 'ActionId: ' . $this->_lastActionId . "\r\n" . $aMsg;
//                $event = $this->_messageToEvent($bMsg);
//                $response = $this->findResponse($event);
//                $response->addEvent($event);
            }
        }
    }

    /**
     * Main processing loop. Also called from send(), you should call this in
     * your own application in order to continue reading events and responses
     * from ami.
     *
     * @return void
     */
    public function process()
    {
        // This doesn't do anything anymore because react handles everything
    }

    /**
     * Registers the given listener so it can receive events. Returns the generated
     * id for this new listener. You can pass in a an IEventListener, a Closure,
     * and an array containing the object and name of the method to invoke. Can specify
     * an optional predicate to invoke before calling the callback.
     *
     * @param mixed $listener
     * @param Closure|null $predicate
     *
     * @return string
     */
    public function registerEventListener($listener, $predicate = null)
    {
        $id = uniqid('PamiListener');
        $this->_eventListeners[$id] = array($listener, $predicate);
        return $id;
    }

    /**
     * Unregisters an event listener.
     *
     * @param string $id The id returned by registerEventListener.
     *
     * @return void
     */
    public function unregisterEventListener($id)
    {
        if (isset($this->_eventListeners[$id])) {
            unset($this->_eventListeners[$id]);
        }
    }

    /**
     * Closes the connection to ami.
     *
     * @return void
     */
    public function close()
    {
        // TODO: Implement close() method.
    }

    /**
     * Sends a message to ami.
     *
     * @param OutgoingMessage $message Message to send.
     *
     * @see ClientImpl::send()
     * @throws ClientException
     * @return Promise
     */
    public function send(OutgoingMessage $message)
    {
        $action = new Action($message);

        $this->_actions[] = $action;

        $this->_stream->write($message->serialize());

        return $action->getDeferred()->promise();
    }

    /**
     * Returns a ResponseMessage from a raw string that came from asterisk.
     *
     * @param string $msg Raw string.
     *
     * @return ResponseMessage
     */
    private function _messageToResponse($msg)
    {
        $response = new ResponseMessage($msg);
        $actionId = $response->getActionId();
        if ($actionId === null) {
            $response->setActionId($this->_lastActionId);
        }
        return $response;
    }

    /**
     * Returns a EventMessage from a raw string that came from asterisk.
     *
     * @param string $msg Raw string.
     *
     * @return EventMessage
     */
    private function _messageToEvent($msg)
    {
        return $this->_eventFactory->createFromRaw($msg);
    }

    /**
     * Tries to find an associated response for the given message.
     *
     * @param IncomingMessage $message Message sent by asterisk.
     *
     * @return ResponseMessage
     */
    protected function findResponse(IncomingMessage $message)
    {
        $actionId = $message->getActionId();
        if (isset($this->_incomingQueue[$actionId])) {
            return $this->_incomingQueue[$actionId];
        }
        return false;
    }

    /**
     * Dispatchs the incoming message to a handler.
     *
     * @param IncomingMessage $message Message to dispatch.
     *
     * @return void
     */
    protected function dispatch(IncomingMessage $message)
    {
        foreach ($this->_eventListeners as $data) {
            $listener = $data[0];
            $predicate = $data[1];
            if (is_callable($predicate) && !call_user_func($predicate, $message)) {
                continue;
            }
            if ($listener instanceof \Closure) {
                $listener($message);
            } else if (is_array($listener)) {
                $listener[0]->$listener[1]($message);
            } else {
                $listener->handle($message);
            }
        }
    }
}