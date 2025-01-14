<?php

namespace Imi\Server\ConnectContext\StoreHandler;

use Imi\App;
use Imi\Bean\Annotation\Bean;
use Imi\Lock\Lock;
use Imi\Log\Log;
use Imi\Redis\Redis as ImiRedis;
use Imi\Redis\RedisHandler;
use Imi\ServerManage;
use Imi\Util\AtomicManager;
use Imi\Util\Swoole;
use Imi\Worker;

/**
 * 连接上下文存储处理器-Redis.
 *
 * @Bean("ConnectContextRedis")
 */
class Redis implements IHandler
{
    /**
     * Redis 连接池名称.
     *
     * @var string
     */
    protected $redisPool;

    /**
     * redis中第几个库.
     *
     * @var int
     */
    protected $redisDb = 0;

    /**
     * 键.
     *
     * @var string
     */
    protected $key;

    /**
     * 心跳时间，单位：秒.
     *
     * @var int
     */
    protected $heartbeatTimespan = 5;

    /**
     * 心跳数据过期时间，单位：秒.
     *
     * @var int
     */
    protected $heartbeatTtl = 8;

    /**
     * 数据写入前编码回调.
     *
     * @var callable|null
     */
    protected $dataEncode = null;

    /**
     * 数据读出后处理回调.
     *
     * @var callable|null
     */
    protected $dataDecode = null;

    /**
     * 锁 ID.
     *
     * @var string|null
     */
    protected $lockId = null;

    /**
     * 心跳Timer的ID.
     *
     * @var int|null
     */
    private $timerID;

    /**
     * 主进程 ID.
     *
     * @var int
     */
    private $masterPID;

    /**
     * @return void
     */
    public function __init()
    {
        if (null === $this->key)
        {
            $this->key = 'imi:' . App::getNamespace() . ':connect_context';
        }
        if (null === $this->redisPool)
        {
            return;
        }
        if (!$this->lockId)
        {
            throw new \RuntimeException('ConnectContextRedis lockId must be set');
        }
        $workerId = Worker::getWorkerID();
        $this->masterPID = $masterPID = Swoole::getMasterPID();
        $masterPidKey = $this->key . ':master_pid';
        if (0 === $workerId)
        {
            $this->useRedis(function (RedisHandler $redis) use ($masterPID, $masterPidKey) {
                // 判断master进程pid
                $storeMasterPID = $redis->get($masterPidKey);
                if (!$storeMasterPID)
                {
                    // 没有存储master进程pid
                    $this->initRedis($redis, $storeMasterPID);
                }
                elseif ($masterPID != $storeMasterPID)
                {
                    $hasPing = $this->hasPing($redis);
                    if ($hasPing)
                    {
                        Log::warning('ConnectContextRedis key has been used, waiting...');
                        sleep($this->heartbeatTtl);
                        $hasPing = $this->hasPing($redis);
                    }
                    if ($hasPing)
                    {
                        // 与master进程ID不等
                        Log::emergency('ConnectContextRedis key has been used');
                        ServerManage::getServer('main')->getSwooleServer()->shutdown();
                    }
                    else
                    {
                        $this->initRedis($redis, $storeMasterPID);
                        Log::info('ConnectContextRedis key init');
                    }
                }
                $this->startPing($redis);
                AtomicManager::wakeup('imi.ConnectContextRedisLock', Worker::getWorkerNum());
            });
        }
        elseif ($workerId > 0)
        {
            if (!$this->useRedis(function (RedisHandler $redis) use ($masterPID, $masterPidKey) {
                return $masterPID == $redis->get($masterPidKey);
            }))
            {
                AtomicManager::wait('imi.ConnectContextRedisLock');
            }
        }
    }

    /**
     * 初始化redis数据.
     *
     * @param RedisHandler $redis
     * @param int          $storeMasterPID
     *
     * @return void
     */
    private function initRedis($redis, $storeMasterPID = null)
    {
        if (null !== $storeMasterPID)
        {
            $redis->del($this->key . ':master_pid');
        }
        if ($redis->setnx($this->key . ':master_pid', $this->masterPID))
        {
            // 清空存储列表
            $redis->del($this->getStoreKey());
        }
    }

    /**
     * 开始ping.
     *
     * @param RedisHandler $redis
     *
     * @return void
     */
    private function startPing($redis)
    {
        if ($this->ping($redis))
        {
            // 心跳定时器
            $this->timerID = \Swoole\Timer::tick($this->heartbeatTimespan * 1000, [$this, 'pingTimer']);
        }
    }

    /**
     * ping定时器执行操作.
     *
     * @return void
     */
    public function pingTimer()
    {
        $this->useRedis(function ($redis) {
            $this->ping($redis);
        });
    }

    /**
     * 获取redis中存储ping的key.
     *
     * @return string
     */
    private function getPingKey()
    {
        return $this->key . ':ping';
    }

    /**
     * ping操作.
     *
     * @param RedisHandler $redis
     *
     * @return bool
     */
    private function ping($redis)
    {
        $key = $this->getPingKey();
        $redis->multi();
        $redis->set($key, '');
        $redis->expire($key, $this->heartbeatTtl);
        $result = $redis->exec();
        if (!$result)
        {
            return false;
        }
        foreach ($result as $value)
        {
            if (!$value)
            {
                return false;
            }
        }

        return true;
    }

    /**
     * 是否有ping.
     *
     * @param RedisHandler $redis
     *
     * @return bool
     */
    private function hasPing($redis)
    {
        $key = $this->getPingKey();

        return 1 == $redis->exists($key);
    }

    public function __destruct()
    {
        if (null !== $this->timerID)
        {
            \Swoole\Timer::clear($this->timerID);
        }
    }

    /**
     * 读取数据.
     *
     * @param string $key
     *
     * @return array
     */
    public function read(string $key): array
    {
        return $this->useRedis(function ($redis) use ($key) {
            $result = $redis->hget($this->getStoreKey(), $key);
            if ($result)
            {
                if ($this->dataDecode)
                {
                    return ($this->dataDecode)($result);
                }
                else
                {
                    return $result;
                }
            }
            else
            {
                return [];
            }
        });
    }

    /**
     * 保存数据.
     *
     * @param string $key
     * @param array  $data
     *
     * @return void
     */
    public function save(string $key, array $data)
    {
        $this->useRedis(function ($redis) use ($key, $data) {
            if ($this->dataEncode)
            {
                $data = ($this->dataEncode)($data);
            }
            $redis->hSet($this->getStoreKey(), $key, $data);
        });
    }

    /**
     * 销毁数据.
     *
     * @param string $key
     *
     * @return void
     */
    public function destroy(string $key)
    {
        $this->useRedis(function ($redis) use ($key) {
            $redis->hdel($this->getStoreKey(), $key);
        });
    }

    /**
     * 延迟销毁数据.
     *
     * @param string $key
     * @param int    $ttl
     *
     * @return void
     */
    public function delayDestroy(string $key, int $ttl)
    {
        $this->useRedis(function (RedisHandler $redis) use ($ttl) {
            $redis->expire($this->getStoreKey(), $ttl);
        });
    }

    /**
     * 数据是否存在.
     *
     * @param string $key
     *
     * @return bool
     */
    public function exists(string $key)
    {
        return $this->useRedis(function (RedisHandler $redis) use ($key) {
            return $redis->hExists($this->getStoreKey(), $key);
        });
    }

    /**
     * 获取存储hash键名.
     *
     * @return string
     */
    private function getStoreKey(): string
    {
        return $this->key . ':store';
    }

    /**
     * 使用redis.
     *
     * @param callable $callback
     *
     * @return mixed
     */
    private function useRedis($callback)
    {
        return ImiRedis::use(function ($redis) use ($callback) {
            $redis->select($this->redisDb);

            return $callback($redis);
        }, $this->redisPool, true);
    }

    /**
     * 加锁
     *
     * @param string   $key
     * @param callable $callable
     *
     * @return bool
     */
    public function lock(string $key, $callable = null)
    {
        if (null === $this->lockId)
        {
            $callable();

            return true;
        }
        else
        {
            return Lock::getInstance($this->lockId, $key)->lock($callable);
        }
    }

    /**
     * 解锁
     *
     * @return bool
     */
    public function unlock()
    {
        if (null === $this->lockId)
        {
            return true;
        }
        else
        {
            return Lock::unlock($this->lockId);
        }
    }
}
