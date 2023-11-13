<?php

namespace delivery\service;

use delivery\Application;
use GuzzleHttp\Client;

/**
 * HTTP Delivery service
 */
class Request
{
    public $schema = 'http';
    public $host = 'hostname';
    public $path = '/query/path';

    private $url;

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
        return 'request';
    }

    /**
     * @return string
     */
    protected function getUrl()
    {
        if ($this->url === null) {
            $schema = rtrim(rtrim($this->schema, '/'), ':');
            $host = rtrim($this->host, '/');
            $path = ltrim($this->path, '/');
            $this->url = $schema . '://' . $host . '/' . $path;
        }

        return $this->url;
    }

    /**
     * @param array $data
     * @return bool
     */
    public function send($data)
    {
        $client = new Client();
        $url = $this->getUrl();
        $this->getApplication()->getLogger()->log(
            'Request target URL: ' . $url,
            Logger::LEVEL_INFO
        );
        try {
            $response = $client->request(
                'POST',
                $this->getUrl(),
                [
                    'form_params' => $data,
                    'headers' => [
                        'User-Agent' => $this->getUserAgent(),
                    ]
                ]
            );
            if ($response->getStatusCode() != 200) {
                $this->getApplication()->getLogger()->log(
                    'Target Url: ' . $url . PHP_EOL .
                    'Form params: ' . PHP_EOL .
                    var_export($data) . PHP_EOL .
                    $response->getReasonPhrase() . ': ' . PHP_EOL .
                    $response->getBody(),
                    Logger::LEVEL_ERROR
                );
            }

            return $response->getStatusCode() == 200;
        } catch (\Exception $e) {
            $this->getApplication()->getLogger()->log(
                'Request error: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL .
                'Target Url: ' . $url . PHP_EOL .
                'Form params: ' . PHP_EOL .
                var_export($data, true) . PHP_EOL,
                Logger::LEVEL_ERROR
            );
        }

        return false;
    }

    /**
     * @return string
     */
    public function getUserAgent()
    {
        return $this->getApplication()->name . DIRECTORY_SEPARATOR . $this->getApplication()->version;
    }
}
