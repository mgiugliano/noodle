# Security policy

## Reporting a vulnerability

Please report security problems **privately**, not in a public issue or pull request.

Use GitHub's private reporting: open the repository's **Security** tab and choose **Report a vulnerability**. Include:

- what you found and which component is affected (`api.php`, `index.php`, the `noodle` tool...)
- steps or a request that reproduces it
- the impact you think it has
- your version or commit

You will get an acknowledgement as soon as the maintainers can respond, and a fix and credit (if you want it) once the problem is confirmed. Please give a reasonable time to fix the issue before publishing details.

## Scope

In scope: authentication and request signing, path handling, data exposure, injection (XSS and others), concurrency and integrity problems, anything that lets a visitor do more than read a poll and add or change their own vote, anything that exposes a calendar's contents.

Out of scope: weaknesses of the hosting environment itself (a compromised account, an outdated PHP), the fact that anyone holding a poll link can vote (documented behaviour), and denial of service by large volumes of requests against the web host.

See [docs/security.md](docs/security.md) for the design, the protections and the known limitations.

## Supported versions

Only the latest release receives fixes.

## If you run Noodle

Never publish `dist/` or `app/config.php`: they contain the management secret. If it may have leaked, rotate it (`noodle init --url … --force` and upload the new `config.php`).
