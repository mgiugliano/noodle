<?php
declare(strict_types=1);
// Phase 0: temporary hosting check. Upload, open in a browser, read the results, then DELETE this file.
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok, $detail];
}

check('PHP 8.1 or newer', PHP_VERSION_ID >= 80100, PHP_VERSION);
foreach (['json', 'mbstring', 'hash', 'openssl'] as $ext) {
    check("Extension $ext", extension_loaded($ext));
}
check('random_bytes works', strlen(random_bytes(16)) === 16);
check('HTTPS in use', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'Open this page via https://');

$tmp = __DIR__ . '/_check_' . bin2hex(random_bytes(4)) . '.lock';
$fh = @fopen($tmp, 'c');
$locked = $fh && flock($fh, LOCK_EX);
check('Can write here and flock() works', (bool)$locked, $locked ? '' : 'Locking failed: JSON storage would be unsafe');
if ($fh) { flock($fh, LOCK_UN); fclose($fh); @unlink($tmp); }

$parent = dirname(__DIR__);
check('Can write ABOVE this folder (ideal for data/ and app/)', is_writable($parent), $parent);

$probeDir = __DIR__ . '/_probe';
if (!is_dir($probeDir)) { @mkdir($probeDir, 0755); }
@file_put_contents($probeDir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
@file_put_contents($probeDir . '/secret.txt', "If you can read this in a browser, .htaccess is NOT honoured here.\n");
$probeUrl = 'secret.txt';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Noodle hosting check</title>
<style>
body{font:16px/1.5 system-ui,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;color:#1b1f3b}
h1{margin-bottom:.25rem} li{margin:.35rem 0;list-style:none} .ok{color:#0a7d4f} .bad{color:#c62828}
small{color:#667} code{background:#f0f2fa;padding:.1rem .35rem;border-radius:4px}
</style></head><body>
<h1>Noodle hosting check</h1>
<p><small>Delete this file when you are done.</small></p>
<ul>
<?php foreach ($results as [$n, $ok, $d]): ?>
  <li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '&#10003;' : '&#10007;' ?> <?= htmlspecialchars($n, ENT_QUOTES, 'UTF-8') ?>
  <?php if ($d !== ''): ?><small>(<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>)</small><?php endif; ?></li>
<?php endforeach; ?>
</ul>
<h2>Manual test: is .htaccess honoured?</h2>
<p>Open <a href="_probe/<?= $probeUrl ?>" target="_blank" rel="noopener"><code><?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/_probe/secret.txt</code></a>.<br>
<strong>403 or 404 = good.</strong> If you can read the text, tell me: Noodle will then keep its data above the web root.</p>
</body></html>
