# Changelog

## 1.0.0 (2026-10-12)

First public release.

- Command-line organiser tool for macOS: free-slot finder over Calendar.app calendars, reserved-time rules, review before publishing, tentative holds, booking, release, results, CSV export, vote deletion, background agent
- Web part in plain PHP with JSON file storage: voting page, signed management API, deadlines and vote limits
- Security: HMAC-signed management requests with replay protection, guarded data files, locked atomic writes, signed form tokens, honeypot, rate limiting, strict validation, strict Content-Security-Policy
- 90+ automated server tests and a slot-finder self-test
