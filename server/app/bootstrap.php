<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Poll.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Votes.php';
require_once __DIR__ . '/View.php';

function noodle_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = getenv('NOODLE_CONFIG') ?: __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Noodle is not configured.');
        }
        $cfg = require $file;
    }
    return $cfg;
}

function noodle_store(): Store
{
    static $s = null;
    return $s ??= new Store(noodle_config()['data_dir']);
}

function send_security_headers(bool $html): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store');
    if ($html) {
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; "
            . "img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; "
            . "base-uri 'none'; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
    } else {
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
