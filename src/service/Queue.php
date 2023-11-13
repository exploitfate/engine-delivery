<?php

namespace delivery\service;

use delivery\Application;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * RabbitMQ component for Work Queues (aka: Task Queues) pattern
 * See @link https://www.rabbitmq.com/tutorials/tutorial-two-php.html
 */
class Queue
{
    /**
     * The host that the RabbitMQ server is running on.
     *
     * By default, the host is set to localhost
     *
     * @var string
     */
    public $host = 'localhost';
    /**
     * The RabbitMQ vhost.
     *
     * By default, the host is set to "/"
     *
     * @var string
     */
    public $vhost = '/';

    /**
     * The port that the RabbitMQ server is running on.
     * By default, the port is set to 5672
     *
     * @var int
     */
    public $port = 5672;

    /**
     * The username for logging into the server.
     * By default, the username is set to guest.
     *
     * @var string
     */
    public $user = 'guest';

    /**
     * The password for logging into the server.
     * By default, the password is set to guest.
     *
     * @var string
     */
    public $password = 'guest';

    /**
     * @var AMQPStreamConnection
     */
    protected $connection;

    /**
     * @var AMQPChannel
     */
    protected $channel;

    /**
     * @var Application
     */
    private $application;

    /**
     * @param Application|null $application
     */
    public function setApplication(Application $application = null)
    {
        $this->application = $application;
    }

    /**
     * @return Application
     */
    public function getApplication()
    {
        return $this->application;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return 'queue';
    }

    /**
     * Fetches an AMQPStreamConnection object or create it if that object doesn't already exist.
     *
     * @return AMQPStreamConnection
     */
    protected function getConnection()
    {
        if (empty($this->connection)) {
            $this->connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->password,
                $this->vhost
            );
        }

        return $this->connection;
    }

    /**
     * Fetches an default AMQPChannel object or create that object if it doesn't already exist.
     *
     * @return AMQPChannel
     */
    public function getChannel()
    {
        if (empty($this->channel)) {
            $this->channel = $this->getConnection()->channel();
        }

        return $this->channel;
    }

    /**
     * Create AMQPMessage.
     * By default, delivery mode is set to persistent
     *
     * @param array|mixed $data
     * @param array $options
     *
     * @return AMQPMessage
     */
    protected function createAmpqMessage($data, $options = ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT])
    {
        $data = json_encode($data);

        return new AMQPMessage($data, $options);
    }

    /**
     * Asynchronous publish message to channel queue.
     *
     * Note: Don't forget to close channel and connection after publish to avoid memory leaks.
     *
     * @param string $queueName Queue names may be up to 255 bytes of UTF-8 characters
     * @param array|mixed $data
     * @param string $exchange
     * @param integer $delay Delay time in milliseconds
     */
    public function publish($queueName, $data, $exchange = '', $delay = 0)
    {
        $channel = $this->getChannel();
        /**
         * @param queue string Queue names may be up to 255 bytes of UTF-8 characters
         * @param passive boolean Can use this to check whether an exchange exists without modifying the server state
         * @param durable boolean Make sure that RabbitMQ will never lose our queue if a crash occurs - the queue will
         * survive a broker restart
         * @param exclusive boolean Used by only one connection and the queue will be deleted
         * when that connection closes
         * @param auto_delete boolean Queue is deleted when last consumer unsubscribes
         */
        $channel->queue_declare($queueName, false, true, false, false);
        $message = $this->createAmpqMessage($data);
        if ($delay > 0) {
            $headers = new AMQPTable(["x-delay" => $delay]);
            $message->set('application_headers', $headers);
        }
        $channel->basic_publish($message, $exchange, $queueName);
        $this->getApplication()->getLogger()->log(
            'AMPQ Message '.$message->getBody().' published to "'.$queueName.'" queue',
            Logger::LEVEL_INFO
        );
    }

    /**
     * Asynchronous consume message from channel queue.
     * CallBeck should be \Closure or array [$this, 'method']
     * Note: CallBeck should send a proper acknowledgment from the worker, once we're done with a task:
     *
     * Success (task completed)
     *    `$message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag'], false);`
     *
     * Note: Negative Acknowledgements
     *
     * Fail (task failed)
     *    `$message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, true);`
     *
     * @param string $queueName
     * @param array|\Closure $callback
     */
    public function consume($queueName, $callback)
    {
        $channel = $this->getChannel();
        $channel->queue_declare($queueName, false, true, false, false);

        // Change Round-robin dispatching by Fair dispatching.
        $channel->basic_qos(null, 1, null);
        $channel->basic_consume($queueName, '', false, false, false, false, $callback);
        $this->getApplication()->getLogger()->log(
            'Consumed new AMPQ Message at "'.$queueName.'" queue',
            Logger::LEVEL_INFO
        );
        while (count($channel->callbacks)) {
            $this->getApplication()->getLogger()->log(
                'Consumer waiting for incoming AMPQ Messages at "'.$queueName.'" queue',
                Logger::LEVEL_INFO
            );
            $channel->wait();
        }
    }
}
