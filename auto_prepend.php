<?php
$baseDir = __DIR__;
$loadingFile = $baseDir . DIRECTORY_SEPARATOR . 'loading.php';

if (!defined('SECURITY_AUTO_PREPEND_EXECUTED')) {
    define('SECURITY_AUTO_PREPEND_EXECUTED', true);
}

if (is_file($loadingFile)) {
    require_once $loadingFile;
} else {
    trigger_error(sprintf('Security auto-prepend: missing loading.php at %s', $loadingFile), E_USER_WARNING);
}
