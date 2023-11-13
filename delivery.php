<?php

use delivery\Application;

require_once __DIR__ . '/vendor/autoload.php';

$config = array_merge(
    require(__DIR__ . '/config/config.php'),
    require(__DIR__ . '/config/config-local.php')
);

$app = new Application($config);
$app->run();
