# Contributing

Thank you for helping to improve Noodle. A few ground rules keep it small, safe and private.

1. **Open an issue first** for anything bigger than a bug fix, so we can agree on the direction. Do **not** open public issues for security problems: see [SECURITY.md](SECURITY.md).
2. **Keep it dependency-free.** No Composer packages on the server, no Swift packages on the client, no build steps beyond `./build.sh`.
3. **Keep it private.** No analytics, no third-party requests, no stored IP addresses, no personal data in code, tests, fixtures, screenshots or docs. Use fictional names and `example.com`.
4. **Add tests.** Server changes go in `tests/run.php`, slot-logic changes in the `selftest` in `client/Slots.swift`. A security fix needs a test that fails without it.
5. **Run everything before you push:**
   ```sh
   ./build.sh && ./build/noodle selftest
   php tests/run.php
   ```
6. **Update the docs** in the same change when behaviour, options or settings change.
7. **Check your commit** for secrets and personal details (see [docs/development.md](docs/development.md#before-you-publish-a-fork-or-send-a-pull-request)). Never commit `dist/`, `build/`, `data/` or `app/config.php`.

By contributing you agree that your contribution is licensed under the [MIT License](LICENSE).
