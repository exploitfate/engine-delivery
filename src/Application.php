<?php

namespace delivery;

use delivery\model\Command;
use delivery\service\Logger;
use delivery\service\Queue;
use delivery\service\Request;

/**
 * Class Application
 */
class Application
{
    public $name = 'Engine delivery';

    public $version = '1.0';

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Queue
     */
    private $queue;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Command
     */
    private $command;

    /**
     * @var array
     */
    private $configs;

    /**
     * Application constructor.
     * @param array $configs
     */
    public function __construct($configs)
    {
        $this
            ->addConfig($configs)
            ->addLogger(new Logger())
            ->addQueue(new Queue())
            ->addRequest(new Request())
            ->addCommand(new Command());
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return 'app';
    }

    /**
     * @param Logger $logger
     * @return $this
     */
    public function addLogger($logger)
    {
        $alias = $logger->getAlias();
        if (is_array($this->configs) && !empty($this->configs) && array_key_exists($alias, $this->configs)) {
            foreach ($this->configs[$alias] as $property => $value) {
                $logger->$property = $value;
            }
        }
        $this->logger = $logger;
        $this->logger->setApplication($this);

        return $this;
    }

    /**
     * @return Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }


    /**
     * @param Queue $queue
     * @return $this
     */
    public function addQueue($queue)
    {
        $alias = $queue->getAlias();
        if (is_array($this->configs) && !empty($this->configs) && array_key_exists($alias, $this->configs)) {
            foreach ($this->configs[$alias] as $property => $value) {
                $queue->$property = $value;
            }
        }
        $this->queue = $queue;
        $this->queue->setApplication($this);

        return $this;
    }

    /**
     * @return Queue
     */
    public function getQueue()
    {
        return $this->queue;
    }


    /**
     * @param Request $request
     * @return $this
     */
    public function addRequest($request)
    {
        $alias = $request->getAlias();
        if (is_array($this->configs) && !empty($this->configs) && array_key_exists($alias, $this->configs)) {
            foreach ($this->configs[$alias] as $property => $value) {
                $request->$property = $value;
            }
        }
        $this->request = $request;
        $this->request->setApplication($this);

        return $this;
    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    public function addConfig($configs)
    {
        if (!is_array($configs)) {
            $configs = [];
        }
        foreach ($configs as $item => $config) {
            $this->configs[$item] = array_filter($config);
        }

        return $this;
    }

    /**
     * @param Command $command
     * @return $this
     */
    public function addCommand($command)
    {
        $alias = $command->getAlias();
        if (is_array($this->configs) && !empty($this->configs) && array_key_exists($alias, $this->configs)) {
            foreach ($this->configs[$alias] as $property => $value) {
                $command->$property = $value;
            }
        }
        $this->command = $command;
        $this->command->setApplication($this);

        return $this;
    }

    public function run()
    {
        $this->command->execute();
    }
}
