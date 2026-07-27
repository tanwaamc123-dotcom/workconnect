<?php

declare(strict_types=1);

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$relative = ltrim($path, '/');
$blocked = preg_match(
    '#(^|/)\.|^(?:includes|pages|scripts|tests|storage|tmp|database)(?:/|$)|^(?:README\.md|Dockerfile|render\.yaml|dev-router\.php)$#i',
    $relative
);
if ($blocked) {
    http_response_code(404);
    exit;
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
