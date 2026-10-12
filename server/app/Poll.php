<?php
declare(strict_types=1);

/** Poll model rules: validation of incoming packages and open/closed state. */
final class Poll
{
    public const MAX_OPTIONS = 60;
    public const MAX_VOTES_CAP = 500;

    public static function parseUtc(mixed $s): ?int
    {
        if (!is_string($s) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $s) !== 1) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $s, new DateTimeZone('UTC'));
        return $d === false ? null : $d->getTimestamp();
    }

    public static function fmtUtc(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private static function text(mixed $v, int $max, bool $required): string
    {
        if (!is_string($v)) {
            if ($required) {
                throw new InvalidArgumentException('Missing text field');
            }
            return '';
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1) {
            throw new InvalidArgumentException('Control characters not allowed');
        }
        $v = trim($v);
        if ($required && $v === '') {
            throw new InvalidArgumentException('Missing text field');
        }
        if (mb_strlen($v, 'UTF-8') > $max) {
            throw new InvalidArgumentException('Text too long');
        }
        return $v;
    }

    /** Validate a creation package and return the normalized poll body (without slug/created). */
    public static function normalizeNew(array $in): array
    {
        $title = self::text($in['title'] ?? null, 120, true);
        $description = self::text($in['description'] ?? '', 2000, false);

        $tz = $in['timezone'] ?? 'UTC';
        if (!is_string($tz) || !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Unknown timezone');
        }

        $slots = $in['slots'] ?? null;
        if (!is_array($slots) || !array_is_list($slots) || count($slots) < 1 || count($slots) > self::MAX_OPTIONS) {
            throw new InvalidArgumentException('Between 1 and ' . self::MAX_OPTIONS . ' slots required');
        }
        $byStart = [];
        foreach ($slots as $s) {
            if (!is_array($s)) {
                throw new InvalidArgumentException('Bad slot');
            }
            $a = self::parseUtc($s['start'] ?? null);
            $b = self::parseUtc($s['end'] ?? null);
            if ($a === null || $b === null || $b <= $a || $b - $a > 7 * 86400) {
                throw new InvalidArgumentException('Bad slot times');
            }
            $byStart[$a . '-' . $b] = [$a, $b];
        }
        $list = array_values($byStart);
        usort($list, fn($x, $y) => $x <=> $y);
        $options = [];
        foreach ($list as $i => [$a, $b]) {
            $options[] = ['id' => 'o' . ($i + 1), 'start' => self::fmtUtc($a), 'end' => self::fmtUtc($b)];
        }

        $st = $in['settings'] ?? [];
        if (!is_array($st)) {
            throw new InvalidArgumentException('Bad settings');
        }
        $deadline = null;
        if (isset($st['deadline'])) {
            $d = self::parseUtc($st['deadline']);
            if ($d === null) {
                throw new InvalidArgumentException('Bad deadline');
            }
            $deadline = self::fmtUtc($d);
        }
        $maxVotes = null;
        if (isset($st['max_votes'])) {
            if (!is_int($st['max_votes']) || $st['max_votes'] < 1 || $st['max_votes'] > self::MAX_VOTES_CAP) {
                throw new InvalidArgumentException('Bad max_votes');
            }
            $maxVotes = $st['max_votes'];
        }

        return [
            'title' => $title,
            'description' => $description,
            'timezone' => $tz,
            'options' => $options,
            'settings' => [
                'deadline' => $deadline,
                'max_votes' => $maxVotes,
                'allow_maybe' => ($st['allow_maybe'] ?? true) === true,
                'hide_votes' => ($st['hide_votes'] ?? false) === true,
            ],
            'status' => 'open',
            'closed_reason' => null,
            'closed_at' => null,
            'votes' => [],
        ];
    }

    /** @return array{0: bool, 1: ?string} [isOpen, reason-if-closed] */
    public static function state(array $poll, int $now): array
    {
        if (($poll['status'] ?? 'open') === 'closed') {
            return [false, $poll['closed_reason'] ?? 'manual'];
        }
        $dl = $poll['settings']['deadline'] ?? null;
        if ($dl !== null && ($t = self::parseUtc($dl)) !== null && $now >= $t) {
            return [false, 'deadline'];
        }
        $max = $poll['settings']['max_votes'] ?? null;
        if ($max !== null && count($poll['votes'] ?? []) >= $max) {
            return [false, 'max_votes'];
        }
        return [true, null];
    }

    /** Tallies per option: yes / maybe / no counts and a score (yes=2, maybe=1). */
    public static function tally(array $poll): array
    {
        $t = [];
        foreach ($poll['options'] as $o) {
            $t[$o['id']] = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'score' => 0];
        }
        foreach ($poll['votes'] as $v) {
            foreach ($v['choices'] as $oid => $c) {
                if (!isset($t[$oid])) {
                    continue;
                }
                if ($c === 'y') {
                    $t[$oid]['yes']++;
                    $t[$oid]['score'] += 2;
                } elseif ($c === 'm') {
                    $t[$oid]['maybe']++;
                    $t[$oid]['score'] += 1;
                } else {
                    $t[$oid]['no']++;
                }
            }
        }
        return $t;
    }
}
