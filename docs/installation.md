# Installation

This guide takes you from nothing to a working poll. Plan for about 20 minutes. You do not need shell access to your web host.

Throughout, `https://example.com/noodle` stands for the address where **you** will publish Noodle. Replace it with your own.

## Contents

1. [What you need](#1-what-you-need)
2. [Build the command-line tool](#2-build-the-command-line-tool)
3. [Check your web host (optional but recommended)](#3-check-your-web-host-optional-but-recommended)
4. [Create your secret and the upload folder](#4-create-your-secret-and-the-upload-folder)
5. [Upload to your web host](#5-upload-to-your-web-host)
6. [Verify the installation](#6-verify-the-installation)
7. [Configure your calendars](#7-configure-your-calendars)
8. [Create your first poll](#8-create-your-first-poll)
9. [Install the background agent (optional)](#9-install-the-background-agent-optional)
10. [Updating](#10-updating)
11. [Rotating the secret](#11-rotating-the-secret)
12. [Uninstalling](#12-uninstalling)
13. [Advanced: keep the code and data outside the web folder](#13-advanced-keep-the-code-and-data-outside-the-web-folder)
14. [Advanced: nginx](#14-advanced-nginx)

---

## 1. What you need

**On your Mac**

- macOS 14 (Sonoma) or newer
- The Xcode Command Line Tools. Check with `swift --version`. If it is missing, run `xcode-select --install`.
- Calendar.app with the calendars you want to use (iCloud, Google, Exchange and others all work as long as they appear in Calendar.app)

**On your web host**

- PHP 8.1 or newer with the `json` and `mbstring` extensions (both are almost always enabled)
- HTTPS (the tool refuses to talk to a non-HTTPS address, except `localhost` for testing)
- A way to upload files: FTP, SFTP or the host's web file manager
- Apache with `.htaccess` support is assumed. On other servers see [section 14](#14-advanced-nginx).
- A folder on your site where Noodle will live, for example `noodle/` in the web root, giving you `https://example.com/noodle`

You do **not** need: a database, SSH, cron, Composer, Node.js or an email server.

## 2. Build the command-line tool

Open Terminal in the project folder and run:

```sh
./build.sh
```

This compiles `build/noodle`. If you have an "Apple Development" code-signing certificate in your Keychain, the script signs the program with it automatically. That gives the program a stable identity, so macOS remembers your "Always Allow" choices for the Keychain and Calendar after each rebuild. Without a certificate the program is signed ad hoc, which works but means macOS may ask again after every rebuild.

> The bundle identifier in `client/Info.plist`, `build.sh` and `client/Manage.swift` is `com.example.noodle`. You can change it to your own reverse-domain name (for example `com.yourname.noodle`) in all three places before building. It is only an identifier and is never sent anywhere.

Test the build:

```sh
./build/noodle selftest
```

You should see "All self-tests passed". This tests the slot finder on synthetic data and does not touch your calendar.

For convenience you can add an alias to `~/.zshrc`:

```sh
alias noodle="/full/path/to/this/project/build/noodle"
```

The rest of this guide writes `./build/noodle`.

## 3. Check your web host (optional but recommended)

`server/public/check.php` is a one-page diagnostic. It tells you whether your host meets the requirements.

1. Create the `noodle` folder on your host and upload `server/public/check.php` into it.
2. Open `https://example.com/noodle/check.php` in a browser.
3. Every line should show a green tick:
   - PHP 8.1 or newer, the required extensions, working random numbers
   - HTTPS in use
   - Writing files and `flock()` file locking work (essential for safe voting)
4. Follow the link at the bottom of the page (`.../_probe/secret.txt`). You must see **403 Forbidden or 404**. If you can read the text, your host ignores `.htaccess`. Noodle still protects its data with the PHP guard line, but read [section 13](#13-advanced-keep-the-code-and-data-outside-the-web-folder).
5. **Delete `check.php` and the `_probe` folder from the server** when you are done.

The "can write above this folder" line tells you whether the folder *containing* `noodle/` is writable. On many hosts the folder above is the web root itself, which is fine.

## 4. Create your secret and the upload folder

```sh
./build/noodle init --url https://example.com/noodle
```

This does three things:

1. Generates a random 256-bit secret and stores it in your **macOS Keychain** (service `noodle-api-secret`, account = your host name). You never see it or type it.
2. Writes `~/.config/noodle/config.json` with default settings.
3. Builds the folder `dist/noodle/`, containing everything to upload, including a generated `app/config.php` that holds the same secret.

> **`dist/` contains your secret.** Never publish it, never commit it. The included `.gitignore` already excludes it.

The address must start with `https://` and must be the exact public address of the folder you will upload to, without a trailing slash.

## 5. Upload to your web host

Upload the **contents** of `dist/noodle/` into the `noodle` folder of your site, so that your host ends up with:

```
noodle/
├── .htaccess
├── index.php
├── api.php
├── assets/
│   ├── noodle.css
│   └── noodle.js
└── app/
    ├── .htaccess
    ├── config.php
    ├── Auth.php
    ├── Poll.php
    ├── Store.php
    ├── Votes.php
    ├── View.php
    └── bootstrap.php
```

Things to get right:

- **Hidden files.** `.htaccess` files start with a dot, and Finder hides them. Press `Cmd+Shift+.` in Finder to show them. Make sure your FTP program uploads them (most have a "show hidden files" option). Without them, protection layer one is missing.
- **Transfer mode.** Use binary or automatic mode. The files are plain text and fine either way.
- **Permissions.** Folders `755`, files `644` is normal. `app/config.php` may be `600` or `640` if your host allows PHP to still read it.
- **Do not create `data/`.** Noodle creates `noodle/data/` itself on first use, with its own `.htaccess`.
- **Do not upload** `tests/`, `docs/`, `client/` or `check.php`. They are not needed on the server.

## 6. Verify the installation

```sh
./build/noodle doctor
```

`doctor` is a self-check against the **live** site. It verifies that:

- the address uses HTTPS
- a correctly signed request is accepted
- a request signed with a wrong secret is rejected
- a replayed request is rejected
- a request with an old timestamp is rejected
- security headers (CSP, `nosniff`, `no-referrer`) are present
- `api.php` refuses plain GET requests
- `app/` and `data/` cannot be downloaded

You want to see **All checks passed**. If something fails, see [troubleshooting](troubleshooting.md).

## 7. Configure your calendars

List the calendars Noodle can see (macOS will ask for Calendar access the first time; allow it):

```sh
./build/noodle calendars
```

Then open the settings:

```sh
./build/noodle config        # prints the path and every setting, filling in defaults
open -t ~/.config/noodle/config.json
```

The two settings that matter most:

```json
"busyCalendars": ["Work", "Personal"],
"reserved": ["Mon-Fri 12:00-13:30", "Fri 14:00-18:00"]
```

- `busyCalendars` is the list of calendar names (exactly as `noodle calendars` prints them) whose events count as busy. If you leave it empty, **every** calendar except Birthdays counts, including holiday and subscribed calendars, which usually block more than you want.
- `reserved` lists time you never want offered, without having to create calendar events.

Make sure the file stays valid JSON (straight double quotes, commas between items, none after the last item). `./build/noodle config` will refuse to run if it is broken. All settings are explained in [configuration.md](configuration.md).

Preview what Noodle would propose, without publishing anything or touching your calendar:

```sh
./build/noodle new --title "Test" --dry-run
```

## 8. Create your first poll

Use a throwaway poll first, because creating holds writes to your real calendar:

```sh
./build/noodle new --title "Test poll" --max-votes 2
```

1. The numbered slot list appears. Press **Enter** to publish, type numbers to drop slots (for example `2,5-7`), or `q` to cancel.
2. Noodle publishes the poll, creates tentative `HOLD:` events in a calendar called **Noodle Holds** (it creates the calendar if it does not exist), and copies the link to your clipboard.
3. Paste the link into a browser or your phone and vote.
4. Look at the answers: `./build/noodle results latest`
5. Book a winner: `./build/noodle book latest best`. This confirms that slot in your calendar and deletes the other holds. Or remove everything with `./build/noodle release latest` and `./build/noodle delete latest`.

## 9. Install the background agent (optional)

The agent runs `noodle sync` every 15 minutes. When a poll closes (deadline reached, maximum votes reached, or `noodle close`), it shows a macOS notification with the best slot, and depending on the `onClose` setting, keeps, books or releases the holds. It also removes holds whose time has already passed.

```sh
./build/noodle agent install
./build/noodle agent status
```

`agent install` copies the program to `~/Library/Application Support/Noodle/noodle` (so it does not run from a synced or removable folder) and registers a launchd job. macOS may ask once for Calendar access for that copy; allow it. The log is at `~/Library/Logs/noodle-sync.log`.

After you rebuild the program, run `./build/noodle agent install` again to refresh the copy. To see what would be installed without installing it: `./build/noodle agent print`.

## 10. Updating

After pulling new code:

```sh
./build.sh                       # rebuild the tool
./build/noodle build             # rebuild dist/noodle with your EXISTING secret
# upload the contents of dist/noodle/ again, overwriting existing files
./build/noodle doctor
./build/noodle agent install     # only if you use the agent
```

`build` keeps your secret and your settings. (Use `build --no-config` if you edited the server's `app/config.php` by hand, see section 13.) Your polls live in `noodle/data/` on the server, which is never overwritten because it is not part of `dist/`.

## 11. Rotating the secret

If you think the secret leaked (for example `dist/noodle/app/config.php` was shared):

```sh
./build/noodle init --url https://example.com/noodle --force
```

Then upload the new `dist/noodle/app/config.php`. Your settings are kept. Existing polls keep working, because votes are not tied to the management secret. Voters' form tokens issued before the change become invalid, so people with a page open will just be asked to resubmit.

## 12. Uninstalling

1. `./build/noodle agent uninstall` (removes the background job and its copy)
2. Release or delete your polls first if you want their holds removed from the calendar: `noodle release <poll>` / `noodle delete <poll>`
3. Delete the `noodle/` folder on your web host
4. Remove the Keychain item: open *Keychain Access*, search for `noodle-api-secret`, delete it. Or: `security delete-generic-password -s noodle-api-secret -a example.com` (use your host name).
5. Delete `~/.config/noodle/`
6. If you wish, delete the *Noodle Holds* calendar in Calendar.app

## 13. Advanced: keep the code and data outside the web folder

If your host lets you write **above** the web root, the most robust layout keeps `app/` and `data/` out of the public folder entirely. Then nothing sensitive can ever be requested over HTTP, whatever the web server does with `.htaccess`.

Example (paths vary by host):

```
/home/you/noodle-private/app/        ← the app/ folder, including config.php
/home/you/noodle-private/data/       ← created automatically
/home/you/public_html/noodle/        ← index.php, api.php, assets/, .htaccess
```

Then:

1. In `index.php` and `api.php`, change the line that starts with `$app =` to your absolute path, for example `$app = '/home/you/noodle-private/app';`.
2. In `app/config.php`, change `data_dir` to an absolute path, for example `'/home/you/noodle-private/data'`.
3. Run `./build/noodle doctor`.
4. When you update later, rebuild with `./build/noodle build --no-config` so that `app/config.php` is left out of `dist/noodle/`, and your edited server copy is never overwritten.

## 14. Advanced: nginx

nginx ignores `.htaccess`. Noodle's data files still return 404 when requested directly thanks to the PHP guard line, but you should also block the private folders in your server block. A minimal example, assuming Noodle lives at `/noodle/`:

```nginx
location ^~ /noodle/app/  { return 404; }
location ^~ /noodle/data/ { return 404; }

location /noodle/ {
    index index.php;
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;   # adjust to your PHP-FPM socket
    }
}
```

Noodle sets its own security headers from PHP, so you do not need to add them in nginx.
