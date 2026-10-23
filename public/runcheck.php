<?php

header('Content-Type: text/plain');

echo 'PHP: ' . PHP_VERSION . PHP_EOL;
echo 'SAPI: ' . PHP_SAPI . PHP_EOL;
echo 'Phalcon loaded: ' .
    (extension_loaded('phalcon') ? 'yes' : 'no') . PHP_EOL;
echo 'Phalcon version: ' .
    (phpversion('phalcon') ?: 'not loaded') . PHP_EOL;