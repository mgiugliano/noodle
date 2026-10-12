<?php
declare(strict_types=1);

$app = is_dir(__DIR__ . '/app') ? __DIR__ . '/app' : __DIR__ . '/../app';
require $app . '/bootstrap.php';

send_security_headers(true);
header('Content-Type: text/html; charset=utf-8');

$cfg = noodle_config();
$store = noodle_store();
$now = time();

$slug = $_GET['p'] ?? null;
if (!Store::validSlug($slug)) {
    render_message(404, 'Poll not found', 'This link is not valid. Please check that you copied the whole address.');
}
$poll = $store->read($slug);
if ($poll === null) {
    render_message(404, 'Poll not found', 'This poll does not exist or has been removed.');
}

$cookieName = 'noodle_' . $slug;
$cookieToken = $_COOKIE[$cookieName] ?? null;
$secret = (string)$cfg['secret'];
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

function find_own(array $poll, mixed $token): ?array
{
    if (!Votes::validCookieToken($token)) {
        return null;
    }
    $h = hash('sha256', $token);
    foreach ($poll['votes'] as $v) {
        if (hash_equals((string)($v['edit_hash'] ?? ''), $h)) {
            return $v;
        }
    }
    return null;
}

$ctx = ['now' => $now, 'token' => Votes::formToken($secret, $slug, $now), 'error' => null, 'notice' => null,
    'form' => ['name' => '', 'choices' => []], 'own' => null];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        render_message(413, 'Request too large', 'That submission was too large.');
    }
    // Rate limit by hashed client address (never stored in clear).
    $ipKey = hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''), $secret);
    if (!RateLimit::allow($store, 'vote|' . $ipKey, (int)($cfg['vote_rate_limit'] ?? 12), 600, $now)) {
        header('Retry-After: 600');
        render_message(429, 'Too many attempts', 'Please wait a few minutes before trying again.');
    }
    // Honeypot: bots fill the hidden field. Pretend it worked, store nothing.
    if (($_POST['website'] ?? '') !== '') {
        header('Location: index.php?p=' . $slug . '&saved=1', true, 303);
        exit;
    }
    $own = find_own($poll, $cookieToken);
    $ctx['form'] = ['name' => is_string($_POST['name'] ?? null) ? mb_substr($_POST['name'], 0, Votes::NAME_MAX, 'UTF-8') : '',
        'choices' => is_array($_POST['c'] ?? null) ? $_POST['c'] : []];
    $ctx['own'] = $own;
    $status = 200;
    if (!Votes::formTokenValid($secret, $slug, $_POST['t'] ?? null, $now)) {
        $ctx['error'] = 'The form expired or was sent too quickly. Please check your answers and submit again.';
        $status = 403;
    } else {
        try {
            $res = Votes::submit($store, $slug, $_POST, $cookieToken, $now);
            if ($res['token'] !== null) {
                setcookie($cookieName, $res['token'], [
                    'expires' => $now + 180 * 86400, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
                    'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
                ]);
            }
            header('Location: index.php?p=' . $slug . '&saved=' . ($res['updated'] ? '2' : '1'), true, 303);
            exit;
        } catch (VoteError $e) {
            $ctx['error'] = $e->getMessage();
            $status = $e->http;
        } catch (NotFound) {
            render_message(404, 'Poll not found', 'This poll does not exist or has been removed.');
        }
    }
    http_response_code($status);
    $poll = $store->read($slug) ?? $poll;
} else {
    $ctx['own'] = find_own($poll, $cookieToken);
    if ($ctx['own'] !== null) {
        $choices = $ctx['own']['choices'];
        $ctx['form'] = ['name' => $ctx['own']['name'], 'choices' => $choices];
        $saved = $_GET['saved'] ?? '';
        if ($saved === '1') {
            $ctx['notice'] = 'Thank you! Your vote has been saved. You can return to this page to change it.';
        } elseif ($saved === '2') {
            $ctx['notice'] = 'Your vote has been updated.';
        }
    }
}

if ($ctx['own'] === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ctx['own'] = find_own($poll, $cookieToken);
}
render_poll($poll, $ctx);
