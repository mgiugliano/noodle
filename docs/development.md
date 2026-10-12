# Development

## Layout

```
client/
  main.swift            entry point, config, Keychain, signed API client, init/build/doctor/ping
  Slots.swift           pure slot-finding logic, parsers, selftest
  CalendarAccess.swift  EventKit: reading busy time, creating/confirming/deleting events
  Commands.swift        config, polls, new
  Manage.swift          results, close, delete, book, release, sync, agent
  Info.plist            embedded, supplies the Calendar permission text
server/
  public/               web-visible: index.php, api.php, check.php, assets/, .htaccess
  app/                  Store, Poll, Votes, Auth, View, bootstrap, .htaccess
tests/run.php           server test suite
docs/                   documentation and screenshots
build.sh                compile (+ sign if a code-signing identity exists)
```

There are no third-party dependencies on either side. The client needs only the Swift toolchain that ships with the Xcode Command Line Tools; the server needs only PHP.

## Build and run

```sh
./build.sh                     # produces build/noodle
./build/noodle selftest        # slot-finder tests, no calendar access needed
php tests/run.php              # server tests (needs PHP 8.1+ CLI with the curl extension)
```

To build without `build.sh` (and without signing):

```sh
swiftc -O client/main.swift client/Slots.swift client/CalendarAccess.swift \
  client/Commands.swift client/Manage.swift \
  -Xlinker -sectcreate -Xlinker __TEXT -Xlinker __info_plist -Xlinker client/Info.plist \
  -o build/noodle
```

The embedded `Info.plist` is required: without it macOS cannot show the Calendar permission prompt.

## Trying things safely

Keep experiments away from your real setup:

- `NOODLE_CONFIG_DIR=/some/temp/dir` makes the tool use a separate config folder.
- `noodle init --url http://127.0.0.1:PORT --repo /some/copy` against a local server (`php -S 127.0.0.1:PORT -t dist/noodle`) lets you exercise the whole management side. Clean up afterwards: the Keychain item for that host name (`security delete-generic-password -s noodle-api-secret -a 127.0.0.1`).
- `noodle new … --dry-run` reads your calendar but writes nothing.
- Calendar writes (`new` with holds, `book`, `release`, `sync`, `agent`) are **not** covered by automated tests, because they need real calendars. Test them by hand with a throwaway poll.

PHP's built-in server ignores `.htaccess`, so `noodle doctor` reports the folder-exposure check as failed against it. That is expected.

## The server test suite

`tests/run.php` creates a temporary data directory and config, starts `php -S` with several worker processes on a random port, runs the checks, then stops the server and removes the temporary files. It never touches a real installation. The sections are:

1. Storage: 40 processes × 5 appends to one poll, slug validation, guard line
2. Poll validation and open/closed rules
3. Management API over HTTP: signing, replay, headers, actions, limits
4. Public page: rendering, escaping, CSP, tokens, honeypot, validation, editing, limits, deadlines, hidden votes, URL abuse
5. Concurrency: 30 simultaneous voters for 10 seats
6. Rate limiter

When you add behaviour, add a check next to the related ones. Security-relevant changes should come with a test that **fails without the fix**.

## Conventions

- **PHP**: `declare(strict_types=1)`, no globals except through small helpers, validate at the boundary, escape at output (`h()`), never build a path from user input, never echo exception messages to visitors, errors to the log only.
- **Swift**: one concern per file, `fail()` for fatal errors with an actionable message, no force-unwraps on external data, strip control characters from anything from the network before printing.
- **Both**: no dependencies, no network access beyond the poll server, no telemetry, no personal data in code, tests or docs.
- Keep the client and the server compatible: the signing format and the poll document are a contract (see [architecture](architecture.md)). A breaking change needs a version bump (`"v"` in the poll, `"version"` in `ping`).

## Before you publish a fork or send a pull request

Check that nothing personal or secret is included:

```sh
# a literal 64-character hex string (a secret) must not appear anywhere
grep -rnE "'[0-9a-f]{64}'" .

# nothing generated or private may be tracked (this must print nothing)
git ls-files | grep -E "(^|/)(dist|build|data)/|app/config\.php$|\.log$"

# your own domain, name or e-mail address must not appear anywhere
grep -rniE "your-domain\.example|your-name|you@example\.com" .
```

Replace the placeholders in the last command with your real values.

`dist/`, `build/`, `data/` and `app/config.php` are in `.gitignore` for a reason: `dist/` and `app/config.php` contain the management secret, and a built program carries your code-signing identity. Also check your git author identity (`git config user.email`); GitHub offers a private "noreply" address if you do not want to publish a real one.

## Ideas for the future

Contributions are welcome. Candidate improvements: a Linux or Windows organiser client, optional email notifications, password-protected polls, an iCalendar export of the winning slot, translations of the voting page, more automated tests for the client side (for example behind a calendar abstraction).
