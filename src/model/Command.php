<?php

namespace delivery\model;

use delivery\Application;
use delivery\service\Logger;
use PhpAmqpLib\Message\AMQPMessage;

class Command
{

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
        return 'command';
    }

    public function execute()
    {
        $this->getApplication()->getQueue()->consume('engine', [$this, 'delivery']);
    }

    /**
     * @param AMQPMessage $message
     */
    public function delivery($message)
    {
        $key = 'iteration';
        $limit = 86400; // ETA 1 day
        try {
            $data = json_decode($message->body, true);
            $this->getApplication()->getLogger()->log(
                'Received AMPQ Message data "' . $message->body . '"',
                Logger::LEVEL_INFO
            );
            $post = $data;
            if (isset($post[$key])) {
                unset($post[$key]);
            }
            if ($this->getApplication()->getRequest()->send($post)) {
                $this->getApplication()->getLogger()->log(
                    'AMPQ Message delivered successfully.',
                    Logger::LEVEL_INFO
                );
            } else {
                $this->getApplication()->getLogger()->log(
                    'AMPQ Message delivering failed. Requeue',
                    Logger::LEVEL_INFO
                );
                $iteration = array_key_exists($key, $data) ? (int)$data[$key] : 0;
                $data[$key] = ++$iteration;
                if ($data[$key] <= $limit) {
                    // Requeue failed task up to 3 minutes
                    $this->getApplication()->getQueue()->publish('engine', $data);
                }
            }
            $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
        } catch (\Exception $exception) {
            $this->getApplication()->getLogger()->log(
                'AMPQ Message delivered error.' . PHP_EOL .
                $exception->getMessage() . PHP_EOL .
                $exception->getTraceAsString(),
                Logger::LEVEL_ERROR
            );
            // Fix ASAP. Otherwise queue will stopped
            $message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag']);
        }
    }
}
