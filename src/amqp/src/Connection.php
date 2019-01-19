<?php
declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://hyperf.org
 * @document https://wiki.hyperf.org
 * @contact  group@hyperf.org
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Amqp;

use Hyperf\Amqp\Connection\AMQPSwooleConnection;
use Hyperf\Amqp\Pool\AmqpPool;
use Hyperf\Contract\ConnectionInterface;
use Hyperf\Pool\Connection as BaseConnection;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Coroutine;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Container\ContainerInterface;

class Connection extends BaseConnection implements ConnectionInterface
{
    /**
     * @var AmqpPool
     */
    protected $pool;

    /**
     * @var AbstractConnection
     */
    protected $connection;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var Params
     */
    protected $params;

    /**
     * @var float
     */
    protected $lastHeartbeatTime = 0.0;

    protected $transaction = false;

    public function __construct(ContainerInterface $container, AmqpPool $pool, array $config)
    {
        parent::__construct($container, $pool);
        $this->config = $config;
        $this->context = $container->get(Context::class);
        $this->params = new Params(Arr::get($config, 'params', []));
        $this->connection = $this->initConnection();
    }

    public function __call($name, $arguments)
    {
        return $this->connection->$name(...$arguments);
    }

    public function getConnection(): AbstractConnection
    {
        if ($this->check()) {
            return $this->connection;
        }

        $this->reconnect();

        return $this;
    }

    public function reconnect(): bool
    {
        $this->connection->reconnect();
        return true;
    }

    public function check(): bool
    {
        return isset($this->connection)
            && $this->connection instanceof AbstractConnection
            && $this->connection->isConnected()
            && !$this->isHeartbeatTimeout();
    }

    public function close(): bool
    {
        $this->connection->close();
        return true;
    }

    public function release(): void
    {
        parent::release();
    }

    protected function initConnection(): AbstractConnection
    {
        $class = AMQPStreamConnection::class;
        if (Coroutine::id() > 0) {
            $class = AMQPSwooleConnection::class;
        }

        return new $class(
            $this->config['host'] ?? 'localhost',
            $this->config['port'] ?? 5672,
            $this->config['user'] ?? 'guext',
            $this->config['password'] ?? 'guest',
            $this->config['vhost'] ?? '/',
            $this->params->isInsist(),
            $this->params->getLoginMethod(),
            $this->params->getLoginResponse(),
            $this->params->getLocale(),
            $this->params->getConnectionTimeout(),
            $this->params->getReadWriteTimeout(),
            $this->params->getContext(),
            $this->params->isKeepalive(),
            $this->params->getHeartbeat()
        );
    }

    protected function isHeartbeatTimeout(): bool
    {
        if ($this->params->getHeartbeat() === 0) {
            return false;
        }

        $lastHeartbeatTime = $this->lastHeartbeatTime;
        $currentTime = microtime(true);
        $this->lastHeartbeatTime = $currentTime;

        if ($lastHeartbeatTime && $lastHeartbeatTime > 0) {
            if ($currentTime - $lastHeartbeatTime > $this->params->getHeartbeat()) {
                return true;
            }
        }

        return false;
    }
}