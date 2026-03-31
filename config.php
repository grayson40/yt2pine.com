<?php
// Load .env from project root
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        define(trim($key), trim($val));
    }
}
if (!defined('ANTHROPIC_API_KEY')) {
    die(json_encode(['success' => false, 'error' => 'ANTHROPIC_API_KEY not set in .env']));
}
