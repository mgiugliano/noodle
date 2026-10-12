# Security

Noodle is designed to be safe to run on cheap shared hosting. This page explains what it protects against, how, how that is tested, what it does **not** protect against, and what you should do as the operator.

To report a vulnerability, follow [SECURITY.md](../SECURITY.md) and **do not** open a public issue.

## Contents

- [What is protected, and from whom](#what-is-protected-and-from-whom)
- [Controls](#controls)
- [How the controls are tested](#how-the-controls-are-tested)
- [Privacy](#privacy)
- [Limitations and things Noodle does not do](#limitations-and-things-noodle-does-not-do)
- [Operator checklist](#operator-checklist)
- [If something goes wrong](#if-something-goes-wrong)

---

## What is protected, and from whom

| Asset | Who must not get it | How |
|---|---|---|
| The ability to create, read in full, close or delete polls | Everyone except you | Signed management requests; secret in your Keychain; no login form |
| Your calendar contents | The server, voters, anyone on the network | Only the free slots you approve leave your Mac |
| Poll data on the server (names, answers) | People without the poll link; the public web | Unguessable links; guarded data files; protected folders |
| Integrity of votes | Racing voters, bots, malicious input | Locking, validation, server-side rules |
| Other people's votes | Other voters | Per-browser edit token, never shown, stored only as a hash |
| Visitors' browsers | Script injection from stored names/titles | Output escaping and a strict Content-Security-Policy |

The attacker model: an anonymous person on the internet who may know a poll link (a legitimate voter or someone who got the link), may send arbitrary requests, may run bots, and may try to read files on your host by guessing paths. A compromise of your Mac or of your hosting account itself is out of scope (see limitations).

## Controls

### Management access

- **No admin web interface.** There is no password to guess, no session to steal, no login form to attack.
- **HMAC-SHA256 signatures** with a random 256-bit secret over the timestamp, a nonce and the request body.
- **Replay protection.** Timestamps must be within 5 minutes; each nonce is accepted once (nonces are kept in `data/nonces/` for 15 minutes).
- **Constant-time comparison** of signatures; failed requests are delayed by a quarter of a second.
- **The secret is stored in the macOS Keychain** on your Mac and in `app/config.php` on the server (generated with mode 600). It never travels over the network.
- **HTTPS required.** `noodle init` refuses a non-HTTPS address (except `localhost` for testing), and `noodle doctor` flags it.
- All management failures return a generic `401`, revealing nothing about which check failed.

### Files and paths

- **Path traversal is impossible by construction.** A poll id is used in a file name only if it matches `^[a-z2-7]{26}$` (checked with an anchored pattern, `D` modifier so a trailing newline cannot slip through). Anything else, including `../`, encoded variants, null bytes, arrays and over-long input, is rejected before any file access.
- **Unguessable ids.** 128 random bits from `random_bytes()`.
- **Two independent layers keep data private:** `.htaccess` (`Require all denied`, with an Apache 2.2 fallback) in `data/` and `app/`, **and** a guard line at the top of every data file that turns a direct request into a bare 404. Either layer alone is sufficient. For the strongest setup, keep `app/` and `data/` above the web root ([installation guide](installation.md#13-advanced-keep-the-code-and-data-outside-the-web-folder)).
- The public `.htaccess` also denies dotfiles and files ending in `.json`, `.lock`, `.tmp`, `.bak`, `.log`, and disables directory listings.

### Integrity under concurrency

- Every change to a poll takes an exclusive `flock()` on a per-poll lock file, reads, modifies and writes a temporary file, then atomically `rename()`s it into place. A reader never sees a half-written file and two voters never overwrite each other.
- The decisions "is the poll open?", "is it full?" and "is the name free?" are made **inside** the lock, so they cannot be raced. This is tested with 30 simultaneous voters for 10 seats: exactly 10 votes are stored, every time.

### Voting endpoint

- **Signed single-poll form token**, valid between 2 seconds and 7 days. It blocks cross-site form posts (a different site cannot obtain a valid token for the victim's browser) and instant bot submissions, and needs no server-side session.
- **Honeypot field.** A bot that fills it gets a normal-looking response and nothing is stored.
- **Rate limiting** per client address, 12 submissions per 10 minutes by default. The address is only ever used as an HMAC-keyed hash.
- **Strict validation.** Names are trimmed, whitespace-normalised, limited to 60 characters, must be valid UTF-8, and may not contain control characters, bidirectional-override characters, zero-width spaces or a byte-order mark. Answers must be exactly `y`, `m` or `n` for exactly the poll's option ids; unknown ids and wrong types are rejected. Titles, descriptions and slots are validated when the poll is created.
- **Hard limits.** Request body of the voting form 16 KiB, management request 256 KiB, 60 options per poll, 500 votes per poll, slots at most 7 days long.
- **Edit tokens.** A new voter receives a random 128-bit token in an `HttpOnly`, `SameSite=Lax` cookie (`Secure` on HTTPS); only a hash is stored. It authorises changing that one vote and nothing else.
- **Server-authoritative closing.** Deadline and vote limit are checked on the server on every submission.

### Output and browser

- **Every dynamic value is HTML-escaped** with `htmlspecialchars(…, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Titles, descriptions, names and times are never inserted raw.
- **Content-Security-Policy:** `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'`. The generated pages contain **no inline scripts, no inline styles and no event-handler attributes**, and load nothing from other sites, so even a hypothetical injection point could not run script or leak data.
- `Referrer-Policy: no-referrer` (poll links are secrets and must not leak in referrers), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Cross-Origin-Opener-Policy: same-origin`, `X-Robots-Tag: noindex, nofollow, noarchive`, `Cache-Control: no-store`, and `Strict-Transport-Security` over HTTPS.
- PHP errors are never displayed (`display_errors` off); details go to the server log only. API errors are generic.

### On your Mac

- The tool strips control and formatting characters from voters' names before printing them, so a hostile name cannot inject terminal escape sequences.
- CSV export prefixes cells that start with `=`, `+`, `-`, `@`, tab or carriage return with an apostrophe, defusing spreadsheet formula injection.
- Config and state files are written with mode 600.
- The tool deletes calendar events only by identifiers it recorded itself when it created them.

## How the controls are tested

`php tests/run.php` starts a throwaway local web server and runs over 90 checks, including:

- 40 processes writing to one poll at once: no update lost
- slug validation against traversal, null bytes, uppercase, empty, short and very long ids
- signature failures: wrong secret, tampered signature, stale timestamp, replayed nonce, GET instead of POST, oversized body
- form tokens: too fast, tampered, from another poll, expired
- vote validation: empty and over-long names, control and bidi characters, invalid answers, unknown option ids, non-array answers
- stored XSS: a name containing `<script>` is stored and rendered inert; titles and descriptions are escaped
- pages contain no inline style, script or event attributes and no third-party URLs
- security headers on every response
- honeypot, duplicate names, editing only with the right cookie, deadline, manual close, full poll, hidden votes
- URL abuse: traversal and encoded variants, array parameters, unknown ids
- 30 simultaneous voters for 10 seats; the rate limiter's window behaviour

`noodle doctor` repeats the signature, replay, header and folder-exposure checks against your **live** installation.

## Privacy

- **Calendar:** event titles, attendees and notes never leave your Mac. The server receives only the start and end of the slots you approve.
- **Voters:** the server stores the name a voter typed and their answers. It does **not** store IP addresses, user agents or any identifier other than the random per-vote token hash. The rate limiter keeps small files named by an HMAC of the address (it cannot be reversed without your secret) containing only timestamps; files older than an hour are deleted during later requests.
- **Cookies:** the only cookie is the per-poll edit token, needed so a voter can change their own vote. The page also remembers two preferences in the visitor's own browser (`localStorage`: the chosen light/dark theme and the time-zone switch); they are never sent to the server. There are no analytics, advertising or third-party requests of any kind. The pages load nothing from outside your own server, not even fonts.
- **Retention:** polls stay on the server until you delete them. Delete finished polls (`noodle delete <poll>`); names are personal data.
- **Your host** may keep ordinary web-server access logs (with addresses) outside Noodle's control.

This is a description of the software's behaviour, not legal advice. If you collect other people's names, check which privacy rules apply to you.

## Limitations and things Noodle does not do

Being honest about the boundaries matters more than a long feature list.

- **Anyone with a poll link can vote and see the answers** (unless you used `--hide-votes`, which hides who answered what but not the totals). The link *is* the access control, as in most poll tools. Share it only with the intended people.
- **Names are self-declared.** There is no verification of who is voting. A person can vote twice under two names, from two browsers; the honeypot, token timing and rate limit deter casual abuse, not a determined person.
- **Losing the browser cookie means losing the ability to edit** that vote. Use `noodle delete-vote` to remove it.
- **The organiser tool runs on macOS only.** Anyone with your user account and the Keychain access can manage your polls. The Keychain item is protected by macOS from other apps, but it cannot protect you from malware running as you that you approve, or from someone who has your unlocked Mac.
- **A compromised hosting account** (stolen FTP password, hacked server) exposes everything on it, including `app/config.php` and the polls. Noodle cannot defend against its own host. Use a strong, unique hosting password and keep your PHP version supported.
- **Without code above the web root, privacy depends on the web server honouring `.htaccess` or the guard line.** The guard line works everywhere PHP runs, but a host that serves `.php` files as plain text (a severe misconfiguration) would expose data. Keeping `app/` and `data/` above the web root removes that dependency.
- **File locking assumes a normal local disk.** `flock()` can be unreliable on some network file systems. `check.php` tests that it works on your host.
- **Behind a reverse proxy or CDN**, all visitors may appear to come from one address, which makes the per-address rate limit too strict. Raise `vote_rate_limit` in `app/config.php`. Noodle deliberately does not trust `X-Forwarded-For`, because it is trivially forged.
- **No email, accounts, password-protected polls, or audit log.**
- **No formal security audit** has been done. The test suite is thorough but is not a proof. Review the code if the stakes are high.

## Operator checklist

Before you rely on it:

- [ ] The site uses HTTPS and redirects HTTP to HTTPS (your host's setting)
- [ ] `noodle doctor` reports **All checks passed**
- [ ] `check.php` and the `_probe/` folder are **deleted** from the server
- [ ] `dist/` is not shared, synced to a public place or committed (it contains the secret)
- [ ] Your hosting and FTP passwords are strong and unique; FTP is replaced by SFTP/FTPS where available
- [ ] PHP is a supported version (8.1 or newer)
- [ ] Optional: `app/` and `data/` moved above the web root
- [ ] You delete polls after use

Occasionally:

- [ ] Re-run `noodle doctor` after hosting changes
- [ ] Back up `data/` if the polls matter
- [ ] Rotate the secret (`noodle init --force`) if it may have leaked
- [ ] Update Noodle (see [installation](installation.md#10-updating))

## If something goes wrong

- **Secret exposed** (for example `config.php` was uploaded to a public place): run `noodle init --url … --force`, upload the new `app/config.php`. Old signed requests become invalid at once; no poll data is affected.
- **Poll link leaked to the wrong people:** `noodle close <poll>`, optionally `noodle results <poll> --csv` to keep the answers, then `noodle delete <poll>` and create a new poll.
- **Unwanted or abusive votes:** `noodle delete-vote <poll> <name>`, or close the poll.
- **Server files modified or unknown files present:** treat the hosting account as compromised: change its passwords, restore from a clean upload (`noodle build` and re-upload everything), and rotate the secret.
