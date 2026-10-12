# Changelog

## Unreleased

- Voting page: the summary is now one compact row per option (date and time, yes / if need be counts, names as small chips, best options starred), so many slots fit without scrolling sideways. The full grid by participant is available under "Show answers by participant". On phones each row becomes a small card
- Voting page: a button in the header switches between light and dark theme (follows the system by default, remembers the choice in the visitor's browser, no inline script or style, works without JavaScript)
- `noodle new --no-reserved` and `--unreserve "<rule>"`: offer reserved time (for example the lunch break) for a single poll without editing the configuration
- `noodle busy <day> [<last day>]` shows what blocks time on given days and why
- Calendar holds now always block time when proposing slots, even when `tentativeBlocks` is off

## 1.0.0 (2026-10-12)

First public release.

- Command-line organiser tool for macOS: free-slot finder over Calendar.app calendars, reserved-time rules, review before publishing, tentative holds, booking, release, results, CSV export, vote deletion, background agent
- Web part in plain PHP with JSON file storage: voting page, signed management API, deadlines and vote limits
- Security: HMAC-signed management requests with replay protection, guarded data files, locked atomic writes, signed form tokens, honeypot, rate limiting, strict validation, strict Content-Security-Policy
- 90+ automated server tests and a slot-finder self-test
