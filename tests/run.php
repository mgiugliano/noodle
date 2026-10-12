<?php
declare(strict_types=1);
// Usage: php tests/run.php   (starts a local php -S server and a temp data dir; touches nothing else)

$root = dirname(__DIR__) . '/server';
require $root . '/app/Store.php';
require $root . '/app/Poll.php';
require $root . '/app/Auth.php';
require $root . '/app/Votes.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $info = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $info === '' ? '' : "  -> $info") . "\n";
}

$tmp = sys_get_temp_dir() . '/noodle-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$secret = bin2hex(random_bytes(32));
file_put_contents("$tmp/config.php", "<?php return " . var_export([
    'secret' => $secret, 'data_dir' => "$tmp/data", 'max_body' => 262144, 'vote_rate_limit' => 100000,
], true) . ";");

// ---------- Worker mode: append one vote via Store::mutate ----------
if (($argv[1] ?? '') === 'worker') {
    exit(0);
}

echo "Store: concurrency\n";
$store = new Store("$tmp/data");
$slug = Store::newSlug();
t('slug format', Store::validSlug($slug), $slug);
$store->create($slug, ['slug' => $slug, 'votes' => [], 'n' => 0]);
$tmpPollSlug = $slug;

$workerSrc = <<<'PHP'
<?php
require $argv[1] . '/app/Store.php';
$s = new Store($argv[2]);
for ($i = 0; $i < 5; $i++) {
    $s->mutate($argv[3], function (array &$p) use ($argv) {
        $p['votes'][] = ['id' => bin2hex(random_bytes(8)), 'w' => $argv[4]];
        $p['n']++;
    });
}
PHP;
file_put_contents("$tmp/worker.php", $workerSrc);
$procs = [];
for ($w = 0; $w < 40; $w++) {
    $procs[] = proc_open([PHP_BINARY, "$tmp/worker.php", $root, "$tmp/data", $slug, (string)$w], [], $pipes);
}
foreach ($procs as $p) { proc_close($p); }
$p = $store->read($slug);
t('200 concurrent appends, none lost', count($p['votes']) === 200 && $p['n'] === 200, (string)count($p['votes']));
t('file starts with 404 guard', str_starts_with(file_get_contents("$tmp/data/poll_$slug.json.php"), Store::GUARD));
t('no stray temp files', glob("$tmp/data/*.tmp") === []);

echo "Store: path traversal and slugs\n";
foreach (['../etc/passwd', "abc\0def", str_repeat('a', 26) . '/..', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', '', 'a'] as $bad) {
    t('rejects ' . json_encode($bad), !Store::validSlug($bad));
}
try { $store->read('../../x'); t('read() throws on bad slug', false); }
catch (InvalidArgumentException) { t('read() throws on bad slug', true); }
$seen = [];
for ($i = 0; $i < 2000; $i++) { $seen[Store::newSlug()] = 1; }
t('2000 unique valid slugs', count($seen) === 2000 && count(array_filter(array_keys($seen), [Store::class, 'validSlug'])) === 2000);

echo "Poll validation\n";
$ok = ['title' => 'T', 'timezone' => 'America/New_York', 'slots' => [
    ['start' => '2026-10-20T08:00:00Z', 'end' => '2026-10-20T09:00:00Z'],
    ['start' => '2026-10-19T08:00:00Z', 'end' => '2026-10-19T09:00:00Z'],
    ['start' => '2026-10-19T08:00:00Z', 'end' => '2026-10-19T09:00:00Z'],
], 'settings' => ['max_votes' => 3, 'deadline' => '2026-10-18T12:00:00Z']];
$n = Poll::normalizeNew($ok);
t('sorts, dedupes, assigns ids', count($n['options']) === 2 && $n['options'][0]['id'] === 'o1' && $n['options'][0]['start'] === '2026-10-19T08:00:00Z');
$rej = function (string $name, array $mut) use ($ok) {
    try { Poll::normalizeNew(array_replace_recursive($ok, $mut)); t($name, false); }
    catch (InvalidArgumentException) { t($name, true); }
};
$rej('rejects empty title', ['title' => ' ']);
$rej('rejects control chars', ['title' => "a\x01b"]);
$rej('rejects bad timezone', ['timezone' => '../../etc']);
$rej('rejects end before start', ['slots' => [['start' => '2026-10-20T10:00:00Z', 'end' => '2026-10-20T09:00:00Z']]]);
$rej('rejects non-UTC time', ['slots' => [['start' => '2026-10-20T10:00:00+02:00', 'end' => '2026-10-20T11:00:00Z']]]);
$rej('rejects max_votes 0', ['settings' => ['max_votes' => 0]]);
$rej('rejects long title', ['title' => str_repeat('x', 121)]);
$many = []; for ($i = 0; $i < 61; $i++) { $many[] = ['start' => gmdate('Y-m-d\TH:i:s\Z', 1800000000 + $i * 7200), 'end' => gmdate('Y-m-d\TH:i:s\Z', 1800003600 + $i * 7200)]; }
try { Poll::normalizeNew(['title' => 'x', 'slots' => $many]); t('rejects 61 slots', false); } catch (InvalidArgumentException) { t('rejects 61 slots', true); }

echo "Poll state\n";
$p = $n + ['votes' => []];
t('open before deadline', Poll::state($p, strtotime('2026-10-18T11:59:59Z'))[0] === true);
t('closed at deadline', Poll::state($p, strtotime('2026-10-18T12:00:00Z')) === [false, 'deadline']);
$p['votes'] = [1, 2, 3];
t('closed at max_votes', Poll::state($p, 0) === [false, 'max_votes']);

echo "API over HTTP\n";
$port = random_int(20000, 40000);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp,
    null, ['NOODLE_CONFIG' => "$tmp/config.php", 'PATH' => getenv('PATH'), 'PHP_CLI_SERVER_WORKERS' => '12']);
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

function call(int $port, string $secret, array $req, array $o = []): array
{
    $body = $o['body'] ?? json_encode($req);
    $ts = $o['ts'] ?? time();
    $nonce = $o['nonce'] ?? bin2hex(random_bytes(12));
    $sig = $o['sig'] ?? Auth::sign($o['secret'] ?? $secret, $ts, $nonce, $body);
    $ch = curl_init("http://127.0.0.1:$port/api.php");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ["X-Noodle-Timestamp: $ts", "X-Noodle-Nonce: $nonce", "X-Noodle-Signature: $sig", 'Content-Type: application/json']]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['code' => $code, 'headers' => substr((string)$raw, 0, $hs), 'json' => json_decode(substr((string)$raw, $hs), true)];
}

$r = call($port, $secret, ['action' => 'ping']);
t('signed ping ok', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true);
t('security headers present', str_contains($r['headers'], 'Content-Security-Policy') && str_contains($r['headers'], 'nosniff') && str_contains($r['headers'], 'no-referrer'));
t('wrong secret rejected', call($port, $secret, ['action' => 'ping'], ['secret' => 'nope'])['code'] === 401);
t('stale timestamp rejected', call($port, $secret, ['action' => 'ping'], ['ts' => time() - 4000])['code'] === 401);
t('tampered body rejected', call($port, $secret, ['action' => 'ping'], ['sig' => str_repeat('0', 64)])['code'] === 401);
$nonce = bin2hex(random_bytes(12));
t('first use of nonce ok', call($port, $secret, ['action' => 'ping'], ['nonce' => $nonce])['code'] === 200);
t('replayed nonce rejected', call($port, $secret, ['action' => 'ping'], ['nonce' => $nonce])['code'] === 401);
$ch = curl_init("http://127.0.0.1:$port/api.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]); curl_exec($ch);
t('GET not allowed', curl_getinfo($ch, CURLINFO_HTTP_CODE) === 405);

$c = call($port, $secret, ['action' => 'create_poll', 'poll' => $ok]);
t('create_poll 201', $c['code'] === 201 && Store::validSlug($c['json']['slug'] ?? null));
$slug2 = $c['json']['slug'] ?? '';
$g = call($port, $secret, ['action' => 'get_poll', 'slug' => $slug2]);
t('get_poll returns 2 options', ($g['json']['state']['options'] ?? 0) === 2);
t('bad package -> 422', call($port, $secret, ['action' => 'create_poll', 'poll' => ['title' => '']])['code'] === 422);
t('traversal slug -> 400', call($port, $secret, ['action' => 'get_poll', 'slug' => '../../config'])['code'] === 400);
t('unknown action -> 400', call($port, $secret, ['action' => 'rm -rf'])['code'] === 400);
t('close_poll ok', call($port, $secret, ['action' => 'close_poll', 'slug' => $slug2])['code'] === 200);
$g = call($port, $secret, ['action' => 'get_poll', 'slug' => $slug2]);
t('poll now closed (manual)', ($g['json']['state']['open'] ?? true) === false && $g['json']['state']['closed_reason'] === 'manual');
$l = call($port, $secret, ['action' => 'list_polls']);
t('list_polls shows it', in_array($slug2, array_column($l['json']['polls'] ?? [], 'slug'), true), json_encode($l['json']));
t('oversized body -> 413', call($port, $secret, [], ['body' => str_repeat('x', 300000)])['code'] === 413);
t('delete_poll ok', call($port, $secret, ['action' => 'delete_poll', 'slug' => $slug2])['code'] === 200);
t('data dir has .htaccess', is_file("$tmp/data/.htaccess"));


echo "Public voting page\n";
function web(int $port, string $method, string $path, array $post = [], ?string $jar = null): array
{
    $ch = curl_init("http://127.0.0.1:$port/$path");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true];
    if ($method === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    if ($jar) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $o);
    $raw = (string)curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}
function token_of(string $html): string { preg_match('/name="t" value="([^"]+)"/', $html, $m); return $m[1] ?? ''; }
function old_token(string $secret, string $slug): string { return Votes::formToken($secret, $slug, time() - 5); }
function votes_in(int $port, string $secret, string $slug): array
{
    return call($port, $secret, ['action' => 'get_poll', 'slug' => $slug])['json']['poll']['votes'] ?? [];
}
function mk_poll(int $port, string $secret, array $over = []): array
{
    $pkg = ['title' => 'Team <b>sync</b>', 'description' => "Line1\nLine2 <i>x</i>", 'timezone' => 'Europe/Berlin', 'slots' => [
        ['start' => '2026-10-20T08:00:00Z', 'end' => '2026-10-20T09:00:00Z'],
        ['start' => '2026-10-21T08:00:00Z', 'end' => '2026-10-21T09:00:00Z'],
    ], 'settings' => []];
    $pkg = array_replace_recursive($pkg, $over);
    $r = call($port, $secret, ['action' => 'create_poll', 'poll' => $pkg]);
    return [$r['json']['slug'] ?? '', array_column($r['json']['options'] ?? [], 'id')];
}
$jarDir = sys_get_temp_dir();
$newJar = fn() => tempnam($jarDir, 'nj');

[$ps, $oids] = mk_poll($port, $secret, ['settings' => ['max_votes' => 3]]);
$page = web($port, 'GET', "index.php?p=$ps");
t('poll page renders', $page['code'] === 200 && str_contains($page['body'], 'Team &lt;b&gt;sync&lt;/b&gt;'));
t('title and description are HTML-escaped', !str_contains($page['body'], '<b>sync') && !str_contains($page['body'], '<i>x</i>'));
t('page sends strict CSP, nosniff, no-referrer', str_contains($page['headers'], "script-src 'self'") && str_contains($page['headers'], 'no-referrer') && str_contains($page['headers'], 'nosniff'));
t('no inline style attributes or inline scripts', !preg_match('/\sstyle\s*=/i', $page['body']) && !preg_match('/<script(?![^>]*\bsrc=)/i', $page['body']) && !preg_match('/\son[a-z]+\s*=/i', $page['body']));
t('no third-party URLs in page', !preg_match('#(src|href)="https?://#', $page['body']));
$tok = token_of($page['body']);
t('form carries signed token + honeypot', $tok !== '' && str_contains($page['body'], 'name="website"'));

$A = $newJar();
$fast = web($port, 'POST', "index.php?p=$ps", ['t' => $tok, 'name' => 'Ann', 'c' => [$oids[0] => 'y']], $A);
t('submitting instantly (<2s) is rejected', $fast['code'] === 403 && count(votes_in($port, $secret, $ps)) === 0);
$good = old_token($secret, $ps);
$r = web($port, 'POST', "index.php?p=$ps", ['t' => $good, 'name' => '  Ann   Lee ', 'c' => [$oids[0] => 'y', $oids[1] => 'm']], $A);
t('valid vote -> 303 redirect', $r['code'] === 303 && str_contains($r['headers'], 'saved=1'));
t('edit cookie is HttpOnly + SameSite=Lax', (bool)preg_match('/Set-Cookie: noodle_[a-z2-7]{26}=[0-9a-f]{32}.*HttpOnly/i', $r['headers']) && stripos($r['headers'], 'SameSite=Lax') !== false);
$vs = votes_in($port, $secret, $ps);
t('vote stored with normalised name', count($vs) === 1 && $vs[0]['name'] === 'Ann Lee' && $vs[0]['choices'] === [$oids[0] => 'y', $oids[1] => 'm']);
t('API never returns edit hashes', !isset($vs[0]['edit_hash']));
$mine = web($port, 'GET', "index.php?p=$ps", [], $A);
t('returning voter sees edit form prefilled', str_contains($mine['body'], 'Update your availability') && str_contains($mine['body'], 'value="Ann Lee"') && str_contains($mine['body'], '>you<'));
$upd = web($port, 'POST', "index.php?p=$ps", ['t' => old_token($secret, $ps), 'name' => 'Ann Lee', 'c' => [$oids[0] => 'n', $oids[1] => 'y']], $A);
$vs = votes_in($port, $secret, $ps);
t('same browser edits its own vote (no duplicate)', $upd['code'] === 303 && str_contains($upd['headers'], 'saved=2') && count($vs) === 1 && $vs[0]['choices'][$oids[0]] === 'n');

$B = $newJar();
$dup = web($port, 'POST', "index.php?p=$ps", ['t' => old_token($secret, $ps), 'name' => 'ANN lee', 'c' => []], $B);
t('another browser cannot take/overwrite an existing name', $dup['code'] === 409 && count(votes_in($port, $secret, $ps)) === 1);

$xss = web($port, 'POST', "index.php?p=$ps", ['t' => old_token($secret, $ps), 'name' => '<script>alert(1)</script>"\'', 'c' => []], $B);
$view = web($port, 'GET', "index.php?p=$ps");
t('hostile name is stored but rendered inert', $xss['code'] === 303 && str_contains($view['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($view['body'], '<script>alert'));

$bad = fn(string $why, array $post) => t($why, web($port, 'POST', "index.php?p=$ps", ['t' => old_token($secret, $ps)] + $post, $newJar())['code'] === 422);
$bad('rejects empty name', ['name' => '  ', 'c' => []]);
$bad('rejects control characters in name', ['name' => "Bob\x01", 'c' => []]);
$bad('rejects bidi-override characters in name', ['name' => "Bob\u{202E}evil", 'c' => []]);
$bad('rejects 61-char name', ['name' => str_repeat('x', 61), 'c' => []]);
$bad('rejects invalid answer value', ['name' => 'Eve', 'c' => [$oids[0] => 'x']]);
$bad('rejects answers for unknown options', ['name' => 'Eve', 'c' => ['o999' => 'y']]);
$bad('rejects non-array answers', ['name' => 'Eve', 'c' => 'y']);
t('tampered token rejected', web($port, 'POST', "index.php?p=$ps", ['t' => substr(old_token($secret, $ps), 0, -1) . '0', 'name' => 'Mal', 'c' => []], $newJar())['code'] === 403);
t('token from another poll rejected', web($port, 'POST', "index.php?p=$ps", ['t' => Votes::formToken($secret, str_repeat('a', 26), time() - 5), 'name' => 'Mal', 'c' => []], $newJar())['code'] === 403);
t('token older than 7 days rejected', web($port, 'POST', "index.php?p=$ps", ['t' => Votes::formToken($secret, $ps, time() - 8 * 86400), 'name' => 'Mal', 'c' => []], $newJar())['code'] === 403);
$before = count(votes_in($port, $secret, $ps));
$hp = web($port, 'POST', "index.php?p=$ps", ['t' => old_token($secret, $ps), 'name' => 'Bot', 'website' => 'http://spam', 'c' => []], $newJar());
t('honeypot: looks successful but stores nothing', $hp['code'] === 303 && count(votes_in($port, $secret, $ps)) === $before);

// poll has max_votes 3: Ann, <script> guy = 2 votes. Third fills it.
$C = $newJar();
t('third vote accepted', web($port, 'POST', "index.php?p=$ps", ['t' => old_token($secret, $ps), 'name' => 'Cleo', 'c' => []], $C)['code'] === 303);
$full = web($port, 'POST', "index.php?p=$ps", ['t' => old_token($secret, $ps), 'name' => 'Dan', 'c' => []], $newJar());
t('4th new vote refused when max_votes reached', $full['code'] === 409 && count(votes_in($port, $secret, $ps)) === 3);
t('full poll shows closed banner and no form', str_contains(web($port, 'GET', "index.php?p=$ps")['body'], 'Voting is closed') && !str_contains(web($port, 'GET', "index.php?p=$ps")['body'], 'name="name"'));
$stillEdit = web($port, 'POST', "index.php?p=$ps", ['t' => old_token($secret, $ps), 'name' => 'Cleo', 'c' => [$oids[0] => 'y']], $C);
t('existing voter may still edit on a full poll', $stillEdit['code'] === 303 && votes_in($port, $secret, $ps)[2]['choices'][$oids[0]] === 'y');

// deadline in the past, manual close
[$pd] = mk_poll($port, $secret, ['settings' => ['deadline' => '2020-01-01T00:00:00Z']]);
$r = web($port, 'POST', "index.php?p=$pd", ['t' => old_token($secret, $pd), 'name' => 'Late', 'c' => []], $newJar());
t('vote after deadline refused (server-side)', $r['code'] === 409 && votes_in($port, $secret, $pd) === []);
t('deadline page says closed', str_contains(web($port, 'GET', "index.php?p=$pd")['body'], 'deadline has passed'));
[$pc] = mk_poll($port, $secret);
call($port, $secret, ['action' => 'close_poll', 'slug' => $pc]);
t('vote on manually closed poll refused', web($port, 'POST', "index.php?p=$pc", ['t' => old_token($secret, $pc), 'name' => 'Late', 'c' => []], $newJar())['code'] === 409);

// hidden votes
[$ph, $ho] = mk_poll($port, $secret, ['settings' => ['hide_votes' => true]]);
web($port, 'POST', "index.php?p=$ph", ['t' => old_token($secret, $ph), 'name' => 'Secretive', 'c' => [$ho[0] => 'y']], $newJar());
$hv = web($port, 'GET', "index.php?p=$ph")['body'];
t('hide_votes: names not shown, totals are', !str_contains($hv, 'Secretive') && str_contains($hv, 'hidden by the organiser'));

// URL abuse
foreach (['../../app/config', '..%2f..%2fapp%2fconfig', 'aaaaaaaaaaaaaaaaaaaaaaaaaa%00', 'x', '', str_repeat('a', 300)] as $badSlug) {
    $r = web($port, 'GET', "index.php?p=$badSlug");
    t('bad slug "' . substr($badSlug, 0, 24) . '" -> 404, nothing leaked', $r['code'] === 404 && !str_contains($r['body'], 'secret') && !str_contains($r['body'], 'data_dir'));
}
t('array parameter p[] -> 404', web($port, 'GET', 'index.php?p[]=x')['code'] === 404);
t('valid-looking but unknown slug -> 404', web($port, 'GET', 'index.php?p=' . str_repeat('a', 26))['code'] === 404);
t('oversized POST rejected', web($port, 'POST', "index.php?p=$ps", ['name' => str_repeat('x', 40000)], $newJar())['code'] === 413);

// Concurrency: 30 simultaneous voters, 10 seats
[$pm, $mo] = mk_poll($port, $secret, ['settings' => ['max_votes' => 10]]);
$mh = curl_multi_init(); $hs = [];
for ($i = 0; $i < 30; $i++) {
    $ch = curl_init("http://127.0.0.1:$port/index.php?p=$pm");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['t' => old_token($secret, $pm), 'name' => "Voter$i", 'c' => [$mo[0] => 'y']])]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $act); curl_multi_select($mh, 0.2); } while ($act > 0);
$codes = array_map(fn($c) => curl_getinfo($c, CURLINFO_HTTP_CODE), $hs);
$stored = votes_in($port, $secret, $pm);
t('30 simultaneous voters, 10 seats: exactly 10 stored', count($stored) === 10 && count(array_unique(array_column($stored, 'name'))) === 10, (string)count($stored));
t('exactly 10 accepted and 20 refused', count(array_filter($codes, fn($c) => $c === 303)) === 10 && count(array_filter($codes, fn($c) => $c === 409)) === 20);
t('page and assets are served', web($port, 'GET', 'assets/noodle.css')['code'] === 200 && web($port, 'GET', 'assets/noodle.js')['code'] === 200);

echo "Rate limiter\n";
$rs = new Store("$tmp/data");
$allowed = 0; for ($i = 0; $i < 15; $i++) { if (RateLimit::allow($rs, 'k1', 12, 600, 1000000 + $i)) { $allowed++; } }
t('allows exactly 12 per window, blocks the rest', $allowed === 12);
t('other keys unaffected', RateLimit::allow($rs, 'k2', 12, 600, 1000020));
t('window slides: allowed again after it passes', RateLimit::allow($rs, 'k1', 12, 600, 1000000 + 700));

proc_terminate($srv);
exec('pkill -f ' . escapeshellarg("php -S 127.0.0.1:$port") . ' 2>/dev/null'); // workers outlive the parent
exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
