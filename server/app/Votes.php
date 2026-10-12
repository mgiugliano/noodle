<?php
declare(strict_types=1);

final class VoteError extends RuntimeException
{
    public function __construct(public string $code_, string $message, public int $http = 422)
    {
        parent::__construct($message);
    }
}

/** Public voting rules: signed form tokens, input validation, atomic vote storage. */
final class Votes
{
    public const NAME_MAX = 60;
    private const MAX_VOTES_HARD = 500;

    // ---- Stateless CSRF / anti-bot token: "<unix-ts>.<hmac>" bound to the poll, valid 2 s .. 7 days ----
    private static function key(string $secret): string
    {
        return hash_hmac('sha256', 'noodle-csrf-v1', $secret);
    }

    public static function formToken(string $secret, string $slug, int $ts): string
    {
        return $ts . '.' . hash_hmac('sha256', "vote|$slug|$ts", self::key($secret));
    }

    public static function formTokenValid(string $secret, string $slug, mixed $tok, int $now): bool
    {
        if (!is_string($tok) || preg_match('/^(\d{9,11})\.[0-9a-f]{64}$/D', $tok, $m) !== 1) {
            return false;
        }
        $ts = (int)$m[1];
        if ($now - $ts < 2 || $now - $ts > 7 * 86400) {
            return false;
        }
        return hash_equals(self::formToken($secret, $slug, $ts), $tok);
    }

    // ---- Input validation ----
    public static function cleanName(mixed $v): string
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            throw new VoteError('name', 'Please enter your name.');
        }
        $v = preg_replace('/\s+/u', ' ', trim($v));
        if ($v === null || $v === '') {
            throw new VoteError('name', 'Please enter your name.');
        }
        if (mb_strlen($v, 'UTF-8') > self::NAME_MAX) {
            throw new VoteError('name', 'That name is too long (max ' . self::NAME_MAX . ' characters).');
        }
        // control characters, bidi overrides, zero-width space / BOM
        if (preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{FEFF}]/u', $v) === 1) {
            throw new VoteError('name', 'That name contains characters that are not allowed.');
        }
        return $v;
    }

    /** @return array<string,string> option id => y|m|n */
    public static function cleanChoices(mixed $raw, array $poll): array
    {
        if ($raw === null) {
            $raw = [];
        }
        if (!is_array($raw)) {
            throw new VoteError('choices', 'Invalid answers.');
        }
        $ids = array_column($poll['options'], 'id');
        foreach (array_keys($raw) as $k) {
            if (!in_array((string)$k, $ids, true)) {
                throw new VoteError('choices', 'Invalid answers.');
            }
        }
        $allowMaybe = ($poll['settings']['allow_maybe'] ?? true) === true;
        $out = [];
        foreach ($ids as $id) {
            $c = $raw[$id] ?? 'n';
            if (!is_string($c) || !in_array($c, ['y', 'm', 'n'], true) || ($c === 'm' && !$allowMaybe)) {
                throw new VoteError('choices', 'Invalid answers.');
            }
            $out[$id] = $c;
        }
        return $out;
    }

    public static function validCookieToken(mixed $t): bool
    {
        return is_string($t) && preg_match('/^[0-9a-f]{32}$/D', $t) === 1;
    }

    /**
     * Add or update a vote under the poll lock.
     * @return array{token: ?string, vote_id: string, updated: bool}  token is set only for brand-new votes
     */
    public static function submit(Store $store, string $slug, array $post, mixed $cookieToken, int $now): array
    {
        $name = self::cleanName($post['name'] ?? null);
        $rawChoices = $post['c'] ?? null;

        return $store->mutate($slug, function (array &$p) use ($name, $rawChoices, $cookieToken, $now) {
            $choices = self::cleanChoices($rawChoices, $p);
            $ownIdx = null;
            if (self::validCookieToken($cookieToken)) {
                $h = hash('sha256', $cookieToken);
                foreach ($p['votes'] as $i => $v) {
                    if (hash_equals((string)($v['edit_hash'] ?? ''), $h)) {
                        $ownIdx = $i;
                        break;
                    }
                }
            }

            [$open, $reason] = Poll::state($p, $now);
            // A full poll still lets existing voters change their answers.
            if (!$open && !($reason === 'max_votes' && $ownIdx !== null)) {
                $msg = match ($reason) {
                    'deadline' => 'Voting has closed: the deadline has passed.',
                    'max_votes' => 'This poll is full: the maximum number of votes has been reached.',
                    default => 'Voting on this poll has closed.',
                };
                throw new VoteError('closed', $msg, 409);
            }

            foreach ($p['votes'] as $i => $v) {
                if ($i !== $ownIdx && mb_strtolower($v['name'], 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                    throw new VoteError('name_taken', 'Someone has already voted with that name. Please use a different name.', 409);
                }
            }

            if ($ownIdx !== null) {
                $p['votes'][$ownIdx]['name'] = $name;
                $p['votes'][$ownIdx]['choices'] = $choices;
                $p['votes'][$ownIdx]['updated'] = Poll::fmtUtc($now);
                return ['token' => null, 'vote_id' => $p['votes'][$ownIdx]['id'], 'updated' => true];
            }
            if (count($p['votes']) >= self::MAX_VOTES_HARD) {
                throw new VoteError('closed', 'This poll is full.', 409);
            }
            $token = bin2hex(random_bytes(16));
            $id = bin2hex(random_bytes(8));
            $p['votes'][] = [
                'id' => $id, 'name' => $name, 'choices' => $choices,
                'created' => Poll::fmtUtc($now), 'updated' => Poll::fmtUtc($now),
                'edit_hash' => hash('sha256', $token),
            ];
            return ['token' => $token, 'vote_id' => $id, 'updated' => false];
        });
    }
}

/** Sliding-window rate limit kept in small flat files (keys are HMACs, never raw IPs). */
final class RateLimit
{
    public static function allow(Store $store, string $key, int $limit, int $window, int $now): bool
    {
        $dir = $store->dir() . '/rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (random_int(1, 50) === 1) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (@filemtime($f) < $now - 3600) {
                    @unlink($f);
                }
            }
        }
        $fh = @fopen($dir . '/' . hash('sha256', $key), 'c+');
        if ($fh === false) {
            return true; // fail open: do not lock voters out because of a filesystem hiccup
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $times = array_values(array_filter(json_decode($raw ?: '[]', true) ?: [], fn($t) => is_int($t) && $t > $now - $window));
            if (count($times) >= $limit) {
                return false;
            }
            $times[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($times));
            fflush($fh);
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
