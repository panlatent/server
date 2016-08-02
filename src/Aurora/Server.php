<?php
/**
 * Aurora - A HTTP Application Server of PHP Script
 *
 * @author  panlatent@gmail.com
 * @link    https://github.com/panlatent/aurora
 * @license https://opensource.org/licenses/MIT
 */

namespace Aurora;

use Aurora\Event\Dispatcher as EventDispatcher;
use Aurora\Event\EventAccept;
use Aurora\Event\EventAcceptable;
use Aurora\Event\Listener;
use Aurora\Event\SignalAcceptable;
use Aurora\IPC\PipeMessage;

class Server implements EventAcceptable, SignalAcceptable
{
    const EVENT_SOCKET_CONNECT = 'socket:connect';
    const EVENT_SOCKET_ACCEPT = 'socket:accept';
    const EVENT_SOCKET_READ = 'socket:read';
    const EVENT_SIGNAL_ACCEPT = 'signal:accept';

    use EventAccept;

    /**
     * @var resource
     */
    protected $socket;

    /**
     * @var \Aurora\Event\Dispatcher
     */
    protected $event;

    /**
     * @var int
     */
    protected $socketReadBufferSize = 512;

    /**
     * @var \Aurora\Pipeline
     */
    protected $pipeline;

    /**
     * @var bool
     */
    protected $started = false;

    /**
     * @var bool
     */
    protected $worker = false;

    public function __construct()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_nonblock($this->socket);

        $this->event = new EventDispatcher();
        $this->event->bind(static::EVENT_SOCKET_CONNECT, $this);
        $this->event->bind(static::EVENT_SOCKET_ACCEPT, $this);
        $this->event->bind(static::EVENT_SOCKET_READ, $this);
        $this->event->bind(static::EVENT_SIGNAL_ACCEPT, [$this, SignalAcceptable::EVENT_SIGNAL_CALLBACK]);
        $this->addSignalEvents([SIGTERM, SIGUSR1, SIGUSR2]);

        $this->pipeline = new Pipeline();
    }

    public function __destruct()
    {
        unset($this->pipeline);
        unset($this->event);
        socket_close($this->socket);
    }

    public function acceptSignal($signal, $arg)
    {
        switch ($signal) {
            case SIGTERM:
                $this->event->base()->exit();
                break;
            case SIGUSR1: // Workers Shard Memory Message
                break;
            case SIGUSR2: // Daemon Pipeline Message
                $pipeMessage = new PipeMessage('/tmp/' . posix_getpid());
                $request = $pipeMessage->receive();

                if ($request['msg'] == 'status') {
                    $pipeMessage->send(['status' => true]);
                }

                break;
            default:
                return;
        }
    }

    public function bind($address, $port)
    {
        if ( ! @socket_bind($this->socket, $address, $port)) {
            throw new Exception(error_get_last()['message']);
        }
    }

    public function listen($backlog = 0)
    {
        return socket_listen($this->socket, $backlog);
    }

    public function pipe($callback)
    {
        return $this->pipeline->pipe($callback);
    }

    public function pipeline()
    {
        return $this->pipeline;
    }

    public function start()
    {
        if ($this->started) {
            throw new Exception("Server has been marked as the start state");
        }
        $this->started = true;

        $this->event->listen(static::EVENT_SOCKET_CONNECT, new Listener($this->socket, \Event::READ |
            \Event::PERSIST));

        $state = $this->event->base()->dispatch();

        if ($this->worker) {
            exit(0);
        }

        return $state;
    }

    public function stop()
    {
        if ( ! $this->started) {
            throw new Exception("Server is marked as stopped");
        }
        $this->started = false;

        return $this->event->base()->stop();
    }

    public function onConnect($socket)
    {
        $client = socket_accept($socket);
        socket_set_nonblock($client);
        $this->event->listen(static::EVENT_SOCKET_ACCEPT, new Listener($client, \Event::READ | \Event::PERSIST));
    }

    public function onAccept($socket, $what, Listener $listener)
    {
        $this->event->free(static::EVENT_SOCKET_ACCEPT, $listener);
        switch (pcntl_fork()) {
            case 0:
                $this->event->reInit();
                $this->worker = true;
                $client = new Client($socket);

                $this->pipeline->open();
                $this->pipeline->bind('client', $client);
                $this->event->listen(static::EVENT_SOCKET_READ, new Listener($socket, \Event::READ | \Event::PERSIST));
                break;
            case -1:
                socket_close($socket);
                throw new Exception('Failed to create a work process');
            default:
                socket_close($socket);
        }
    }

    public function onRead($socket, $what, Listener $listener)
    {
        $segment = socket_read($socket, $this->socketReadBufferSize);
        $this->pipeline->append($segment);

        if ( ! $segment || $this->socketReadBufferSize != strlen($segment)) {
            $this->pipeline->run();
            $this->pipeline->close();
            socket_close($socket);
            $this->event->base()->stop();
        }
    }

    public function setSocketReadBufferSize($size)
    {
        $this->socketReadBufferSize = $size;
    }

    protected function addSignalEvents(array $signals)
    {
        foreach ($signals as $signal) {
            $this->event->listen('signal:accept', new Listener($signal, \Event::SIGNAL));
        }
    }

}