<?php
/**
 * Aurora - A HTTP Application Server of PHP Script
 *
 * @author  panlatent@gmail.com
 * @link    https://github.com/panlatent/aurora
 * @license https://opensource.org/licenses/MIT
 */

namespace Aurora\Event;

class Dispatcher
{
    const FORWARD_METHOD_NAME = 'forward';

    /**
     * @var \EventBase
     */
    protected $base;

    /**
     * @var \Aurora\Event\Container
     */
    protected $binds;

    /**
     * @var \Aurora\Event\Container
     */
    protected $listeners;

    /**
     * @var array
     */
    protected $declares;

    public function __construct()
    {
        $this->base = new \EventBase();
        $this->binds = new Container();
        $this->listeners = new Container();
    }

    public function __destruct()
    {
        $this->base->free();
    }

    public function getBase()
    {
        return $this->base;
    }

    public function getBinds()
    {
        return $this->binds;
    }

    public function getListeners()
    {
        return $this->listeners;
    }

    public function bind($name, $callback)
    {
        if ( ! is_callable($callback) && ( ! is_object($callback) || ! $callback instanceof EventAcceptable)) {
            throw new Exception('Aurora\\Event\\Dispatcher::bind(): expects parameter 1 to be a valid callback
                                or implements Aurora\\Event\\EventAcceptable, give a ' . gettype($callback));
        }

        if ( ! is_object($callback)) {
            $callback = (object)['callback' => $callback];
        }
        $this->binds->add($name, $callback);
    }

    public function fire($name, $arg = [])
    {
        if ($this->binds->has($name)) {
            foreach ($this->binds->get($name) as $callback) {
                if (is_object($callback)) {
                    if ($callback instanceof EventAcceptable) {
                        $callback->acceptEvent($name, $arg);
                        continue;
                    } elseif ( ! $callback instanceof \Closure) {
                        $callback = $callback->callback;
                    }
                }

                call_user_func_array($callback, $arg);
            }
        }
    }

    public function forward()
    {
        try {
            switch ($num = func_num_args()) {
                case 1:
                    $listener = func_get_arg(0);
                    $this->fire($listener->getName(), [$listener]);
                    break;
                case 2:
                    $signal = func_get_arg(0);
                    $listener = func_get_arg(1);
                    $this->fire($listener->getName(), [$signal, $listener]);
                    break;
                case 3: // Ignore 2nd arg, is what in  $listener
                    $fd = func_get_arg(0);
                    $listener = func_get_arg(2);
                    $this->fire($listener->getName(), [$fd, $listener]);
                    break;
                default:
                    throw new Exception("Aurora\\Event\\Dispatcher::forward(): unable to forward the Libevent event,
                                    it is required to accept 1 to 3 arguments, give $num arguments");
            }
        } catch (\Throwable $ex) {
            echo sprintf('"%s" in "%s:%d"', $ex->getMessage(), $ex->getFile(), $ex->getLine()), "\n";
            echo $ex->getTraceAsString();
            if ( ! $this->getBase()->gotStop()) {
                $this->getBase()->stop();
            }
        }
    }

    public function free($name, Listener $listener = null, $onlyClear = false)
    {
        if (null === $listener) {
            /** @var \Aurora\Event\Listener $listener */
            foreach ($this->listeners->get($name) as $listener) {
                if ( ! $onlyClear) {
                    $listener->delete();
                }
            }
            $this->listeners->remove($name);
        } else {
            if ( ! $onlyClear) {
                $listener->delete();
            }
            $this->listeners->removeSub($name, $listener);
        }
    }

    public function declare($callback, $priority = 0)
    {
        $event = new \Event($this->base, -1, \Event::TIMEOUT, $callback);
        $event->setPriority($priority);
        $event->add(0);

        $this->declares[] = $event;
    }

    public function dispatch()
    {
        $this->base->dispatch();
    }

    public function exit()
    {
        $this->base->exit();
    }

    public function stop()
    {
        $this->base->stop();
    }

    public function reset()
    {
        $this->base->reInit();
        $this->binds = new Container();
        $this->listeners = new Container();
    }

    protected function getNameInfo($name)
    {
        if (false === ($pos = strpos($name, ':'))) {
            return ['name' => $name, 'action' => '*'];
        } else {
            return ['name' => substr($name, 0, $pos), 'action' => substr($name, $pos + 1)];
        }
    }
}