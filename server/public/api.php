<?php
declare(strict_types=1);

// app/ may sit next to this file or one level above it (preferred).
$app = is_dir(__DIR__ . '/app') ? __DIR__ . '/app' : __DIR__ . '/../app';
require $app . '/bootstrap.php';

send_security_headers(false);
header('Content-Type: application/json; charset=utf-8');

function reply(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    reply(405, ['error' => 'method_not_allowed']);
}

$cfg = noodle_config();
$maxBody = (int)($cfg['max_body'] ?? 262144);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
    reply(413, ['error' => 'too_large']);
}
$body = file_get_contents('php://input', false, null, 0, $maxBody + 1);
if ($body === false || strlen($body) > $maxBody) {
    reply(413, ['error' => 'too_large']);
}

$store = noodle_store();
$now = time();
if (!Auth::verify($_SERVER, $body, (string)$cfg['secret'], $store, $now)) {
    usleep(250000); // slow down guessing; real signatures are 256-bit, so this is belt and braces
    reply(401, ['error' => 'unauthorized']);
}

$req = json_decode($body, true);
if (!is_array($req) || !is_string($req['action'] ?? null)) {
    reply(400, ['error' => 'bad_request']);
}

function summary(array $p, int $now): array
{
    [$open, $reason] = Poll::state($p, $now);
    return [
        'slug' => $p['slug'], 'title' => $p['title'], 'created' => $p['created'],
        'open' => $open, 'closed_reason' => $reason,
        'votes' => count($p['votes']), 'options' => count($p['options']),
        'deadline' => $p['settings']['deadline'], 'max_votes' => $p['settings']['max_votes'],
    ];
}

function need_slug(array $req): string
{
    $slug = $req['slug'] ?? null;
    if (!Store::validSlug($slug)) {
        reply(400, ['error' => 'bad_slug']);
    }
    return $slug;
}

try {
    switch ($req['action']) {
        case 'ping':
            reply(200, ['ok' => true, 'time' => Poll::fmtUtc($now), 'version' => 1]);

        case 'create_poll':
            $poll = Poll::normalizeNew($req['poll'] ?? []);
            $slug = Store::newSlug();
            $poll = ['v' => 1, 'slug' => $slug, 'created' => Poll::fmtUtc($now)] + $poll;
            $store->create($slug, $poll);
            reply(201, ['ok' => true, 'slug' => $slug, 'options' => $poll['options']]);

        case 'list_polls':
            $out = [];
            foreach ($store->slugs() as $s) {
                try {
                    if (($p = $store->read($s)) !== null) {
                        $out[] = summary($p, $now);
                    }
                } catch (Throwable $e) {
                    error_log("Noodle: skipping unreadable poll $s: " . $e->getMessage());
                }
            }
            usort($out, fn($a, $b) => strcmp($b['created'], $a['created']));
            reply(200, ['ok' => true, 'polls' => $out]);

        case 'get_poll':
            $slug = need_slug($req);
            $p = $store->read($slug) ?? reply(404, ['error' => 'not_found']);
            foreach ($p['votes'] as &$v) {
                unset($v['edit_hash']); // never leave the server
            }
            unset($v);
            reply(200, ['ok' => true, 'poll' => $p, 'state' => summary($p, $now), 'tally' => Poll::tally($p)]);

        case 'close_poll':
            $slug = need_slug($req);
            $store->mutate($slug, function (array &$p) use ($now) {
                if (($p['status'] ?? 'open') !== 'closed') {
                    $p['status'] = 'closed';
                    $p['closed_reason'] = 'manual';
                    $p['closed_at'] = Poll::fmtUtc($now);
                }
            });
            reply(200, ['ok' => true]);

        case 'delete_vote':
            $slug = need_slug($req);
            $vid = $req['vote_id'] ?? null;
            if (!is_string($vid) || preg_match('/^[a-f0-9]{16}$/D', $vid) !== 1) {
                reply(400, ['error' => 'bad_vote_id']);
            }
            $removed = $store->mutate($slug, function (array &$p) use ($vid) {
                $before = count($p['votes']);
                $p['votes'] = array_values(array_filter($p['votes'], fn($v) => $v['id'] !== $vid));
                return $before - count($p['votes']);
            });
            reply($removed ? 200 : 404, ['ok' => $removed > 0]);

        case 'delete_poll':
            $slug = need_slug($req);
            reply(200, ['ok' => $store->delete($slug)]);

        default:
            reply(400, ['error' => 'unknown_action']);
    }
} catch (NotFound) {
    reply(404, ['error' => 'not_found']);
} catch (InvalidArgumentException $e) {
    reply(422, ['error' => 'invalid', 'detail' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Noodle API error: ' . $e->getMessage());
    reply(500, ['error' => 'server_error']);
}
