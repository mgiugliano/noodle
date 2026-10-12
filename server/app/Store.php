<?php
declare(strict_types=1);

/**
 * Flat-file poll storage.
 *
 * - One file per poll: poll_<slug>.json.php, prefixed with a PHP guard line so that, even if the
 *   web server ignores .htaccess, requesting the file executes it and returns 404 instead of leaking JSON.
 * - One lock file per poll. All writers take LOCK_EX on the lock file, then write a temp file and rename()
 *   it into place. Locking a separate file (not the data file) stays correct across the rename.
 */
final class NotFound extends RuntimeException {}

final class Store
{
    public const GUARD = "<?php http_response_code(404); exit; ?>\n";
    private const SLUG_RE = '/^[a-z2-7]{26}$/D';

    public function __construct(private string $dir)
    {
        $this->dir = rtrim($dir, '/');
        foreach ([$this->dir, $this->dir . '/nonces'] as $d) {
            if (!is_dir($d) && !mkdir($d, 0750, true) && !is_dir($d)) {
                throw new RuntimeException('Cannot create data directory');
            }
        }
        $ht = $this->dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public static function validSlug(mixed $s): bool
    {
        return is_string($s) && preg_match(self::SLUG_RE, $s) === 1;
    }

    public static function newSlug(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $bytes = random_bytes(16);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < 26; $i++) {
            $out .= $alphabet[bindec(str_pad(substr($bits, $i * 5, 5), 5, '0'))];
        }
        return $out;
    }

    private function path(string $slug): string
    {
        if (!self::validSlug($slug)) {
            throw new InvalidArgumentException('Bad slug');
        }
        return $this->dir . '/poll_' . $slug . '.json.php';
    }

    /** @return resource */
    private function lock(string $slug, int $mode)
    {
        $fh = fopen($this->dir . '/poll_' . $slug . '.lock', 'c');
        if ($fh === false || !flock($fh, $mode)) {
            throw new RuntimeException('Cannot lock poll');
        }
        return $fh;
    }

    private function unlock($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private function load(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || !str_starts_with($raw, self::GUARD)) {
            return null;
        }
        $data = json_decode(substr($raw, strlen(self::GUARD)), true);
        return is_array($data) ? $data : null;
    }

    private function save(string $path, array $poll): void
    {
        $json = json_encode($poll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, self::GUARD . $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write poll');
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot commit poll');
        }
    }

    public function create(string $slug, array $poll): void
    {
        $path = $this->path($slug);
        $fh = $this->lock($slug, LOCK_EX);
        try {
            if (file_exists($path)) {
                throw new RuntimeException('Slug collision');
            }
            $this->save($path, $poll);
        } finally {
            $this->unlock($fh);
        }
    }

    public function read(string $slug): ?array
    {
        $path = $this->path($slug);
        if (!is_file($path)) {
            return null;
        }
        $fh = $this->lock($slug, LOCK_SH);
        try {
            return $this->load($path);
        } finally {
            $this->unlock($fh);
        }
    }

    /**
     * Atomic read-modify-write. $fn receives the poll by reference; whatever it returns is returned.
     * Throw from $fn to abort without saving.
     */
    public function mutate(string $slug, callable $fn): mixed
    {
        $path = $this->path($slug);
        if (!is_file($path)) {
            throw new NotFound('No such poll');
        }
        $fh = $this->lock($slug, LOCK_EX);
        try {
            $poll = $this->load($path);
            if ($poll === null) {
                throw new NotFound('No such poll');
            }
            $result = $fn($poll);
            $this->save($path, $poll);
            return $result;
        } finally {
            $this->unlock($fh);
        }
    }

    public function delete(string $slug): bool
    {
        $path = $this->path($slug);
        $fh = $this->lock($slug, LOCK_EX);
        try {
            $ok = is_file($path) && unlink($path);
        } finally {
            $this->unlock($fh);
        }
        @unlink($this->dir . '/poll_' . $slug . '.lock');
        return $ok;
    }

    /** @return string[] slugs */
    public function slugs(): array
    {
        $out = [];
        foreach (glob($this->dir . '/poll_*.json.php') ?: [] as $f) {
            if (preg_match('/poll_([a-z2-7]{26})\.json\.php$/D', $f, $m)) {
                $out[] = $m[1];
            }
        }
        return $out;
    }

    /** Single-use nonce registry for API replay protection. True if the nonce was fresh. */
    public function claimNonce(string $nonce, int $now): bool
    {
        $dir = $this->dir . '/nonces';
        if (random_int(1, 20) === 1) { // opportunistic cleanup
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (@filemtime($f) < $now - 900) {
                    @unlink($f);
                }
            }
        }
        $fh = @fopen($dir . '/' . hash('sha256', $nonce), 'x');
        if ($fh === false) {
            return false;
        }
        fclose($fh);
        return true;
    }
}
