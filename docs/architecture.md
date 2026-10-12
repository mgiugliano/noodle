# Architecture

## Overview

```
 YOUR MAC                                              YOUR WEB HOST
┌──────────────────────────────────┐   HTTPS POST    ┌──────────────────────────────────────┐
│ noodle (Swift, no dependencies)  │  signed JSON    │ api.php       management API         │
│                                  │ ──────────────► │   Auth  → verify signature + nonce    │
│  main.swift      CLI + config    │                 │   Poll  → validate + state rules      │
│  Slots.swift     free-slot logic │ ◄────────────── │   Store → locked, atomic JSON files   │
│  CalendarAccess  EventKit        │   JSON reply    │                                       │
│  Commands.swift  new, polls      │                 │ index.php     public voting page      │
│  Manage.swift    results, book,  │                 │   Votes → token, validation, rate     │
│                  sync, agent     │                 │   View  → HTML (all output escaped)   │
└──────────────────────────────────┘                 │                                       │
        Keychain: secret                             │ data/poll_<id>.json.php               │
        ~/.config/noodle: settings, hold ids         └──────────────────────────────────────┘
                                                                  ▲
                                          browsers: GET/POST index.php?p=<id>
```

Two trust domains, one shared secret:

- **You** hold the secret. You are the only one who can create, close, read in full or delete polls (through the signed API).
- **Voters** hold only the poll link. A link lets a person read that poll and add or change their own vote. It cannot do anything else.

## Server components

| File | Responsibility |
|---|---|
| `public/api.php` | The management API. Accepts only signed `POST` requests. |
| `public/index.php` | The public page: renders a poll and processes vote submissions. |
| `public/assets/noodle.css`, `noodle.js` | Styling and progressive enhancement (time-zone switch, quick fill). |
| `app/Auth.php` | HMAC request verification. |
| `app/Store.php` | File storage: slugs, locking, atomic writes, nonce registry. |
| `app/Poll.php` | Validation of new polls, open/closed logic, tallies. |
| `app/Votes.php` | Form tokens, vote validation, atomic vote insertion, rate limiter. |
| `app/View.php` | HTML rendering. Every dynamic value passes through `h()`. |
| `app/bootstrap.php` | Error settings, config loading, security headers. |
| `app/config.php` | Generated settings and secret. |
| `data/` | Created at runtime: `poll_<id>.json.php`, `poll_<id>.lock`, `nonces/`, `rate/`. |

## Storage

### One file per poll

`data/poll_<id>.json.php`, where `<id>` is 26 characters of lowercase base32 (`[a-z2-7]`, 128 bits of randomness). The file starts with a guard line, followed by JSON:

```
<?php http_response_code(404); exit; ?>
{"v":1,"slug":"…","title":"Project sync", …}
```

If a web server ignores `.htaccess` and someone requests the file directly, PHP **executes** it, the guard line runs, and the response is a bare 404. The JSON is never sent.

### Safe concurrent writes

Every change to a poll is a read-modify-write:

1. Open `poll_<id>.lock` and take an exclusive `flock()`.
2. Read and decode the poll.
3. Apply the change (for example, append a vote). Business rules such as "is it still open?" and "is the vote limit reached?" are checked **here, inside the lock**.
4. Write the new content to a temporary file, then `rename()` it over the poll file (atomic).
5. Release the lock.

The lock is taken on a separate lock file, not on the data file, because the data file is replaced by `rename()`; locking the data file itself would leave a second writer holding a lock on an orphaned file. Readers take a shared lock.

### Poll document

```json
{
  "v": 1,
  "slug": "pjrmcyzelcvqct2hvdxgczcyvi",
  "created": "2026-10-12T17:00:00Z",
  "title": "Project sync",
  "description": "",
  "timezone": "Europe/Berlin",
  "options": [
    { "id": "o1", "start": "2026-10-19T09:00:00Z", "end": "2026-10-19T10:00:00Z" }
  ],
  "settings": { "deadline": "2026-10-16T17:00:00Z", "max_votes": 8, "allow_maybe": true, "hide_votes": false },
  "status": "open",
  "closed_reason": null,
  "closed_at": null,
  "votes": [
    { "id": "b1c2d3e4f5a60718", "name": "Alex Morgan", "choices": { "o1": "y" },
      "created": "…", "updated": "…", "edit_hash": "sha256 of the voter's cookie token" }
  ]
}
```

- Times are always UTC (`…Z`). Options have stable ids (`o1`, `o2`, …) assigned by the server in chronological order, so answers never depend on list positions.
- Answers: `y` yes, `m` if need be, `n` no.
- A poll is *effectively closed* when `status` is `closed`, or the deadline has passed, or `max_votes` is reached. This is computed on every request from the stored values, so no timer is needed on the server.
- `edit_hash` stores only a SHA-256 hash of the voter's edit token; it never leaves the server (the management API strips it too).

## The management API

`POST /api.php` with a JSON body `{"action": "...", ...}`. All replies are JSON.

| Action | Parameters | Reply |
|---|---|---|
| `ping` | none | `{ok, time, version}` |
| `create_poll` | `poll`: `{title, description?, timezone, slots:[{start,end}], settings?:{deadline?, max_votes?, allow_maybe?, hide_votes?}}` | `201 {ok, slug, options}` |
| `list_polls` | none | `{ok, polls:[{slug,title,created,open,closed_reason,votes,options,deadline,max_votes}]}` |
| `get_poll` | `slug` | `{ok, poll, state, tally}` (full poll, minus `edit_hash`) |
| `close_poll` | `slug` | `{ok}` |
| `delete_vote` | `slug`, `vote_id` | `{ok}` |
| `delete_poll` | `slug` | `{ok}` |

Status codes: `200`/`201` success, `400` malformed request or id, `401` bad signature, `404` not found, `405` not POST, `413` body too large, `422` invalid poll data (with a `detail` message), `500` server error (details only in the server log).

### Request signing

Every request carries three headers:

| Header | Content |
|---|---|
| `X-Noodle-Timestamp` | Unix time in seconds |
| `X-Noodle-Nonce` | 16–64 random letters/digits, used once |
| `X-Noodle-Signature` | 64 hex characters: `HMAC-SHA256(secret, message)` |

```
message = "noodle-v1\n" + timestamp + "\n" + nonce + "\n" + hex(SHA-256(body))
```

The HMAC key is the secret exactly as it appears in `config.php` (the 64-character hex text, taken as UTF-8 bytes). The server:

1. checks the three headers' formats,
2. rejects timestamps more than 300 seconds from its own clock,
3. recomputes the signature and compares in constant time,
4. only then marks the nonce as used (an exclusive file create); a repeated nonce is rejected,
5. deletes nonce files older than 15 minutes now and then.

A signed request therefore works once, within five minutes, for exactly that body.

Example with `curl` and `openssl` (replace the secret and the address):

```sh
SECRET='your-64-hex-character-secret'
URL='https://example.com/noodle/api.php'
BODY='{"action":"ping"}'
TS=$(date +%s)
NONCE=$(openssl rand -hex 12)
HASH=$(printf '%s' "$BODY" | openssl dgst -sha256 -hex | sed 's/^.* //')
SIG=$(printf 'noodle-v1\n%s\n%s\n%s' "$TS" "$NONCE" "$HASH" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')
curl -s "$URL" -H "X-Noodle-Timestamp: $TS" -H "X-Noodle-Nonce: $NONCE" -H "X-Noodle-Signature: $SIG" \
     -H 'Content-Type: application/json' --data "$BODY"
```

## The public page and voting

`GET index.php?p=<id>` validates the id against `^[a-z2-7]{26}$`; anything else, including arrays and path tricks, returns the same generic 404 page. A valid id that does not exist returns a similar 404.

A form carries a **signed token** `"<timestamp>.<HMAC>"`. The HMAC key is derived from the secret (`HMAC(secret, "noodle-csrf-v1")`) and the signed text is `"vote|<poll id>|<timestamp>"`. A token is accepted only if it matches **this poll** and is between **2 seconds and 7 days** old. This is stateless (no session files), stops cross-site form posts, and stops bots that submit instantly.

`POST index.php?p=<id>` runs these checks in order:

1. request size ≤ 16 KiB
2. rate limit per hashed client address (12 per 10 minutes by default)
3. honeypot field empty (if filled, the bot is answered with a normal-looking redirect and nothing is stored)
4. form token valid
5. inside the poll lock: the name is normalised and validated; answers are checked against the poll's option ids; the poll is still open (or the voter is editing on a poll that is merely full); the name is not taken; the vote limit is not reached
6. the vote is stored; a new voter receives a 128-bit random edit token as an `HttpOnly`, `SameSite=Lax` (and `Secure` over HTTPS) cookie valid for 180 days; only its hash is stored
7. the browser is redirected (HTTP 303) to the poll page, so a reload never re-submits

Editing: if the cookie token's hash matches an existing vote, the submission updates that vote instead of adding one.

## The command-line tool

### Free-slot computation (`Slots.swift`)

A pure function from *busy intervals* + *parameters* to *slots*, so it can be tested without a calendar (`noodle selftest`). It iterates days in the configured time zone, generates candidate starts inside the working hours, discards candidates that violate the minimum notice, come within the buffer of a busy interval, or overlap reserved time, then thins the result with the per-day and overall caps.

### Calendar access (`CalendarAccess.swift`)

Uses Apple's EventKit (`requestFullAccessToEvents`). The tool needs the Calendar permission once; the embedded `Info.plist` supplies the explanation text for the macOS prompt. It reads events of the chosen calendars through a date predicate (recurring events are expanded by EventKit), ignores events marked *Free*, cancelled, or declined, and maps tentative/all-day events according to your settings.

### Hold lifecycle

```
new      ──► poll created on server ──► one tentative HOLD event per slot  (ids saved in polls.json)
              │
              ▼  (people vote)
closed   ──► deadline / max votes / `noodle close`
              │
              ▼
sync or book/release:
   book     winner → confirmed busy event (named, with attendees in the notes); all other holds deleted; poll closed
   release  all holds deleted
   sync     closed? notify once; then act per `onClose` (notify | auto_book_best | release_all)
            poll gone from the server? holds released
            hold in the past? deleted
```

### Background agent

`noodle agent install` copies the executable to `~/Library/Application Support/Noodle/noodle` and registers a launchd user agent that runs `noodle sync` at load and every 900 seconds. `sync` makes network requests only for polls that are recorded as unsettled in `polls.json`, so with no active polls it does nothing.

### Local state

`~/.config/noodle/polls.json` maps a poll id to its title, its hold identifiers (`optionId`, `eventId`, `start`, `end`), and flags (`notified`, `settled`, `bookedOption`). It contains no secrets.

## Design decisions

| Decision | Reason |
|---|---|
| Flat JSON files, not a database | Works on any host with PHP and FTP; trivially inspectable and backed up. |
| No admin login page; signed API instead | Removes the biggest attack surface (a password form), and the secret never travels over the network. |
| A PHP guard line in every data file | Protection that does not depend on web-server configuration. |
| Separate lock file + atomic rename | Correct locking across file replacement; readers never see a half-written file. |
| Stateless form tokens | No sessions on shared hosting; nothing to clean up. |
| Server-computed open/closed state under the lock | Deadlines and limits cannot be raced or bypassed by clients. |
| Free slots only leave the Mac | Calendar privacy by construction. |
| Swift, one file per concern, no packages | Builds with only the Command Line Tools; nothing to audit but the source. |
