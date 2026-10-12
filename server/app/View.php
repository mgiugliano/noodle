<?php
declare(strict_types=1);

/** HTML rendering. Every dynamic value goes through h(). No inline styles or scripts (strict CSP). */

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function v_day(string $iso, string $tz): string
{
    return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($tz))->format('D j M');
}

function v_time(string $iso, string $tz): string
{
    return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($tz))->format('H:i');
}

function v_range(array $o, string $tz): string
{
    return v_time($o['start'], $tz) . '–' . v_time($o['end'], $tz);
}

function v_icon(string $name): string
{
    return '<svg class="ico" aria-hidden="true" focusable="false"><use href="#i-' . $name . '"/></svg>';
}

function v_sprite(): string
{
    return <<<'SVG'
<svg class="sprite" width="0" height="0" aria-hidden="true" focusable="false"><defs>
<symbol id="i-y" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-m" viewBox="0 0 24 24"><path d="M4 12c2.5-4 5-4 8 0s5.500 4 8 0" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="i-n" viewBox="0 0 24 24"><path d="M6.500 6.500l11 11M17.500 6.500l-11 11" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></symbol>
<symbol id="i-star" viewBox="0 0 24 24"><path d="M12 3.500l2.600 5.400 5.900.8-4.300 4.100 1 5.800L12 16.800 6.800 19.600l1-5.800L3.500 9.700l5.900-.8z" fill="currentColor"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.500" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7.500V12l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
<symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.500" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3.500 12h17M12 3.500c2.500 2.500 3.500 5.500 3.500 8.500s-1 6-3.500 8.500c-2.500-2.500-3.500-5.500-3.500-8.500s1-6 3.500-8.500z" fill="none" stroke="currentColor" stroke-width="1.800"/></symbol>
<symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8.500" r="3.500" fill="none" stroke="currentColor" stroke-width="2"/><path d="M2.500 19c.5-3.500 3-5.500 6.500-5.500s6 2 6.500 5.500M16 5.500a3.500 3.500 0 010 6.500M18 14c2 .6 3.200 2.300 3.500 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
<symbol id="i-logo" viewBox="0 0 32 32"><rect width="32" height="32" rx="9" fill="currentColor"/><path d="M9 17l5 5 9-11" stroke="#fff" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</defs></svg>
SVG;
}

function v_head(string $title): string
{
    $fav = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%234f46e5'/%3E%3Cpath d='M9 17l5 5 9-11' stroke='white' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E";
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light dark"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . h($title) . ' · Noodle</title>'
        . '<link rel="icon" href="' . h($fav) . '">'
        . '<link rel="stylesheet" href="assets/noodle.css">'
        . '<script src="assets/noodle.js" defer></script></head><body>' . v_sprite();
}

function v_brand(): string
{
    return '<div class="brand">' . '<svg class="logo" aria-hidden="true"><use href="#i-logo"/></svg><span>Noodle</span></div>';
}

/** A plain message page (404, errors). */
function render_message(int $code, string $title, string $text): never
{
    http_response_code($code);
    echo v_head($title)
        . '<header class="hero hero-small"><div class="wrap">' . v_brand() . '</div></header>'
        . '<main class="wrap"><section class="card center"><h1 class="msg-title">' . h($title) . '</h1><p class="muted">' . h($text) . '</p></section></main>'
        . '</body></html>';
    exit;
}

/**
 * @param array $ctx keys: now(int), token(string), error(?string), notice(?string),
 *                   form(array{name:string,choices:array}), own(?array vote), action(string)
 */
function render_poll(array $poll, array $ctx): void
{
    $tz = $poll['timezone'];
    $now = $ctx['now'];
    [$open, $reason] = Poll::state($poll, $now);
    $own = $ctx['own'] ?? null;
    $hide = ($poll['settings']['hide_votes'] ?? false) === true;
    $allowMaybe = ($poll['settings']['allow_maybe'] ?? true) === true;
    $max = $poll['settings']['max_votes'] ?? null;
    $votes = $poll['votes'];
    $full = $max !== null && count($votes) >= $max;
    $canVote = $open || ($own !== null && $reason === 'max_votes');
    $tally = Poll::tally($poll);
    $best = [];
    $bestScore = 0;
    foreach ($tally as $t) {
        $bestScore = max($bestScore, $t['score']);
    }
    if ($bestScore > 0) {
        foreach ($tally as $oid => $t) {
            if ($t['score'] === $bestScore) {
                $best[$oid] = true;
            }
        }
    }

    $out = v_head($poll['title']);
    $out .= '<header class="hero"><div class="wrap">' . v_brand();
    $out .= '<h1>' . h($poll['title']) . '</h1>';
    if ($poll['description'] !== '') {
        $out .= '<p class="lead">' . nl2br(h($poll['description']), false) . '</p>';
    }
    $out .= '<ul class="chips">';
    $out .= '<li>' . v_icon('globe') . '<span>Times in ' . h(str_replace('_', ' ', $tz)) . '</span></li>';
    $out .= '<li>' . v_icon('clock') . '<span>' . count($poll['options']) . ' options</span></li>';
    $out .= '<li>' . v_icon('users') . '<span>' . count($votes) . ($max !== null ? ' of ' . $max : '') . ' ' . (count($votes) === 1 ? 'response' : 'responses') . '</span></li>';
    if ($open && ($dl = $poll['settings']['deadline'] ?? null) !== null) {
        $out .= '<li class="chip-warn">' . v_icon('clock') . '<span>Closes ' . h(v_day($dl, $tz) . ', ' . v_time($dl, $tz)) . '</span></li>';
    }
    $out .= '</ul></div></header><main class="wrap">';

    if (!empty($ctx['notice'])) {
        $out .= '<div class="banner banner-ok" role="status">' . h($ctx['notice']) . '</div>';
    }
    if (!empty($ctx['error'])) {
        $out .= '<div class="banner banner-err" role="alert">' . h($ctx['error']) . '</div>';
    }
    if (!$open) {
        $why = match ($reason) {
            'deadline' => 'The deadline has passed.',
            'max_votes' => 'The maximum number of responses has been reached.',
            default => 'The organiser has closed this poll.',
        };
        $out .= '<div class="banner banner-closed" role="status"><strong>Voting is closed.</strong> ' . h($why) . '</div>';
    }

    // ---- Vote form ----
    if ($canVote) {
        $form = $ctx['form'] ?? ['name' => '', 'choices' => []];
        $out .= '<section class="card" aria-labelledby="h-vote"><h2 id="h-vote">' . ($own ? 'Update your availability' : 'Your availability') . '</h2>';
        $out .= '<p class="muted">Choose what works for you for each option, then add your name.</p>';
        $out .= '<form method="post" action="index.php?p=' . h($poll['slug']) . '" autocomplete="off" novalidate>';
        $out .= '<input type="hidden" name="t" value="' . h($ctx['token']) . '">';
        $out .= '<div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
        $out .= '<div class="field"><label for="f-name">Your name</label><input id="f-name" name="name" type="text" maxlength="' . Votes::NAME_MAX . '" required value="' . h((string)($form['name'] ?? '')) . '" placeholder="e.g. Alex Morgan"></div>';
        $out .= '<div class="quick" data-quick hidden><span class="muted">Quick fill:</span> <button type="button" class="btn-ghost" data-fill="y">All yes</button> <button type="button" class="btn-ghost" data-fill="n">Clear all</button></div>';
        $out .= '<ol class="opts">';
        foreach ($poll['options'] as $o) {
            $oid = $o['id'];
            $cur = $form['choices'][$oid] ?? 'n';
            $out .= '<li class="opt' . (isset($best[$oid]) ? ' is-best' : '') . '">';
            $out .= '<div class="when"><span class="day" data-start="' . h($o['start']) . '" data-role="day">' . h(v_day($o['start'], $tz)) . '</span>';
            $out .= '<span class="time" data-start="' . h($o['start']) . '" data-end="' . h($o['end']) . '" data-role="range">' . h(v_range($o, $tz)) . '</span></div>';
            $out .= '<div class="seg" role="radiogroup" aria-label="Availability for ' . h(v_day($o['start'], $tz) . ' ' . v_range($o, $tz)) . '">';
            $choices = ['y' => 'Yes', 'm' => 'If need be', 'n' => 'No'];
            if (!$allowMaybe) {
                unset($choices['m']);
            }
            foreach ($choices as $val => $label) {
                $id = 'c-' . $oid . '-' . $val;
                $out .= '<input type="radio" class="sr-input" id="' . h($id) . '" name="c[' . h($oid) . ']" value="' . $val . '"' . ($cur === $val ? ' checked' : '') . '>';
                $out .= '<label for="' . h($id) . '" class="seg-' . $val . '">' . v_icon($val) . '<span class="seg-lbl">' . h($label) . '</span></label>';
            }
            $out .= '</div></li>';
        }
        $out .= '</ol>';
        $out .= '<div class="actions"><button type="submit" class="btn-primary">' . ($own ? 'Update my vote' : 'Submit my vote') . '</button>';
        if ($own) {
            $out .= '<span class="muted">Your vote is linked to this browser, so you can come back and change it.</span>';
        }
        $out .= '</div></form></section>';
    }

    // ---- Results ----
    $out .= '<section class="card" aria-labelledby="h-res"><div class="card-head"><h2 id="h-res">Responses</h2>';
    $out .= '<button type="button" class="btn-ghost" data-tz-toggle hidden></button></div>';
    if (count($votes) === 0) {
        $out .= '<p class="muted empty">No responses yet. Be the first to vote.</p>';
    } else {
        $out .= '<div class="table-wrap" tabindex="0" role="region" aria-label="Responses table, scrollable"><table class="grid"><thead><tr><th class="who" scope="col"><span class="sr">Participant</span></th>';
        foreach ($poll['options'] as $o) {
            $isBest = isset($best[$o['id']]);
            $out .= '<th scope="col" class="slot' . ($isBest ? ' is-best' : '') . '">';
            if ($isBest) {
                $out .= '<span class="badge">' . v_icon('star') . 'Best</span>';
            }
            $out .= '<span class="day" data-start="' . h($o['start']) . '" data-role="day">' . h(v_day($o['start'], $tz)) . '</span>';
            $out .= '<span class="time" data-start="' . h($o['start']) . '" data-end="' . h($o['end']) . '" data-role="range">' . h(v_range($o, $tz)) . '</span></th>';
        }
        $out .= '</tr></thead><tbody>';
        if (!$hide) {
            foreach ($votes as $v) {
                $mine = $own !== null && $own['id'] === $v['id'];
                $out .= '<tr' . ($mine ? ' class="me"' : '') . '><th scope="row" class="who">' . h($v['name']) . ($mine ? ' <span class="you">you</span>' : '') . '</th>';
                foreach ($poll['options'] as $o) {
                    $c = $v['choices'][$o['id']] ?? 'n';
                    $lbl = ['y' => 'Yes', 'm' => 'If need be', 'n' => 'No'][$c] ?? 'No';
                    $out .= '<td class="cell c-' . $c . (isset($best[$o['id']]) ? ' is-best' : '') . '">' . v_icon($c) . '<span class="sr">' . $lbl . '</span></td>';
                }
                $out .= '</tr>';
            }
        }
        $out .= '</tbody><tfoot><tr><th scope="row" class="who">Total</th>';
        foreach ($poll['options'] as $o) {
            $t = $tally[$o['id']];
            $out .= '<td class="sum' . (isset($best[$o['id']]) ? ' is-best' : '') . '"><span class="n-yes" title="Yes">' . $t['yes'] . '</span>';
            if ($allowMaybe) {
                $out .= '<span class="n-maybe" title="If need be">+' . $t['maybe'] . '</span>';
            }
            $out .= '</td>';
        }
        $out .= '</tr></tfoot></table></div>';
        if ($hide) {
            $out .= '<p class="muted">Individual responses are hidden by the organiser.</p>';
        }
        $out .= '<p class="legend"><span class="lg c-y">' . v_icon('y') . 'Yes</span>' . ($allowMaybe ? '<span class="lg c-m">' . v_icon('m') . 'If need be</span>' : '') . '<span class="lg c-n">' . v_icon('n') . 'No</span></p>';
    }
    $out .= '</section>';
    $out .= '<footer class="foot"><span>Noodle</span> · scheduling that keeps your calendar private</footer>';
    $out .= '</main></body></html>';
    echo $out;
}
