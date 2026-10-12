# Troubleshooting

Start here: **`./build/noodle doctor`** checks the live installation and usually names the problem. If you need to look deeper, your web host's PHP error log (often in the hosting control panel under *Errors* or *Logs*) contains the details that Noodle deliberately keeps out of web pages.

## Contents

- [Installation and upload](#installation-and-upload)
- [Creating polls](#creating-polls)
- [Calendar and Keychain](#calendar-and-keychain)
- [The voting page](#the-voting-page)
- [Booking, holds and the agent](#booking-holds-and-the-agent)

---

## Installation and upload

**`check.php` or `api.php` shows "Forbidden" (403).**
The web server refused the request before PHP ran. Check, in order: file permissions (files `644`, folders `755`), a restrictive `.htaccess` in the site root or a parent folder, a security filter (ModSecurity) on the host, and that the `noodle` folder really is inside the public web folder. Test with two tiny files, `hello.txt` and `hello.php` (`<?php echo 'ok';`), in the same folder to see whether the folder or only PHP is blocked.

**`noodle doctor`: "Signed request accepted" fails with status 401.**
The secret on the server does not match your Keychain, or the clocks differ by more than five minutes.
- Upload `dist/noodle/app/config.php` again (it must come from the same `init`/`build` that produced your Keychain secret).
- Check that your Mac's clock is set automatically (System Settings → General → Date & Time).
- After `init --force`, you must upload the new `config.php`.

**`doctor`: "Signed request accepted" fails with status 404 or an HTML page.**
`serverURL` does not match where you uploaded the files. Open `https://your-address/api.php` in a browser: you should get a small JSON `{"error":"method_not_allowed"}`. Fix the address with `noodle init --url … --force` (your settings are kept).

**`doctor`: status 500.**
Most often PHP cannot write the `data/` folder, or `app/config.php` is missing or unreadable. Check folder permissions (the folder containing `data/` must be writable by PHP) and the error log.

**`doctor`: "app/ and data/ are not downloadable" fails.**
Your host does not honour `.htaccess`. Note which URL it names. Move `app/` and `data/` above the web root ([guide](installation.md#13-advanced-keep-the-code-and-data-outside-the-web-folder)) or add the nginx rules.
(When you run `doctor` against a local `php -S` development server, this check fails by design, because PHP's built-in server ignores `.htaccess`.)

**"Security headers set" fails.**
Something between PHP and the browser strips headers, or an old `api.php` is still on the server. Re-upload `dist/noodle/` completely.

**I cannot see the `.htaccess` files in Finder.** Press `Cmd+Shift+.` to show hidden files.

**My host shows "Parse error" or "Unexpected `never`".**
PHP is older than 8.1. Choose PHP 8.1 or newer in your hosting control panel.

## Creating polls

**"not initialised. Run: noodle init …"** There is no `~/.config/noodle/config.json`, or `NOODLE_CONFIG_DIR` points elsewhere.

**"cannot read …/config.json: it is not valid JSON".**
You edited the file and broke the syntax. Typical causes: a doubled or curly quote (`""` or `“ ”`), a missing comma between items, or a comma after the last item. Paste the file into any JSON validator, or restore defaults by moving the file away and running `noodle init --url … --force` (keeps nothing, so copy your settings first).

**"no free slots found in that range".**
The range is too busy or too restrictive. Look at `--from/--to`, `workHours`, `days`, `minNoticeHours`, `bufferMinutes` and `reserved`. A frequent cause is an empty `busyCalendars`, which makes **every** calendar count, including holiday and subscribed calendars with all-day events. List your real calendars in `busyCalendars`, or set `allDayBlocks` to `false`.

**The proposed slots overlap something I know is in my calendar.**
That calendar is not in `busyCalendars`, or the event is marked *Free*, was declined, or is tentative with `tentativeBlocks` off. Check the exact calendar spelling with `noodle calendars`.

**"server refused the poll (422)".** The message names the problem: for example too many slots (max 60), a title longer than 120 characters, or a slot longer than 7 days.

**"cannot understand a reserved-time rule".** See the [rule syntax](configuration.md#reserved-time-rules). Times are `HH:MM-HH:MM`, dates `YYYY-MM-DD`, weekdays `Mon`–`Sun`.

**The link was not copied.** The tool prints it anyway. Clipboard access uses `pbcopy`.

## Calendar and Keychain

**"Calendar access denied".** Open System Settings → Privacy & Security → Calendars and enable the app that runs Noodle (Terminal, iTerm, your editor...). For the background agent, enable the entry for the Noodle program.

**The Calendar permission prompt never appears.** Run Noodle from a normal Terminal window, not from a script launched by another process. If you previously denied access, re-enable it in System Settings as above.

**macOS asks for Keychain access every time I rebuild.**
Each rebuild produces a new program, and an ad-hoc signature changes with every build. Install an "Apple Development" certificate (Xcode → Settings → Accounts → Manage Certificates); `build.sh` then signs consistently and "Always Allow" persists. Without one, click **Always Allow** after each rebuild.

**A command seems to hang.** It may be waiting for a macOS dialog (Keychain or Calendar access) that is hidden behind other windows. Look for it, answer it, and the command continues.

**"no secret in Keychain for …".** The Keychain item was deleted or belongs to another host name. Run `noodle init --url … --force` and upload the new `config.php`.

**The hold calendar was created in an account I did not want.**
Noodle creates `holdCalendar` in the account of your default calendar for new events. Create a calendar with that exact name in the account you prefer *before* the first poll, and Noodle will use it.

## The voting page

**"Poll not found".** The link was cut off when copied, the poll was deleted, or the folder moved. Run `noodle polls` to list your polls.

**A voter sees "The form expired or was sent too quickly".**
The page was open for more than seven days, or the form was submitted within two seconds of loading (a browser autofill that submits instantly, or a bot). Reloading and submitting again fixes it.

**A voter sees "Someone has already voted with that name".**
Names must be unique (case-insensitive). If it is the same person on a new device or after clearing cookies, remove the old vote with `noodle delete-vote <poll> "<name>"` so they can vote again.

**A voter cannot change their vote.**
The edit cookie is tied to one browser. Another browser, a private window or cleared cookies cannot edit it. Remove the old vote as above.

**"Too many attempts".** The per-address limit was hit (12 submissions per 10 minutes). If many people share one address, or a proxy hides visitors' addresses, raise `vote_rate_limit` in `app/config.php`.

**The page looks unstyled.** The `assets/` folder was not uploaded, or the CSP is blocked by a host-level header. Check that `https://your-address/assets/noodle.css` loads.

**Times look wrong.** The page shows times in the poll's time zone, which is the `timezone` setting at creation time. Visitors can press **Show in my time zone**. Fix the zone in `config.json` for future polls.

## Booking, holds and the agent

**`book` says it could not confirm the event.**
Calendar access was denied, or the hold calendar is read-only. Nothing else was changed. Fix the cause and run it again.

**I deleted a hold by hand and now `book` complains.** It does not: it recreates the event for the winning slot.

**Holds are still in my calendar after the poll closed.**
With `onClose` set to `notify` (the default) holds are kept until you run `book` or `release`. Set `auto_book_best` or `release_all`, and install the agent, to automate this.

**The agent never does anything.**
Run `noodle agent status`, which shows the state and the last log lines. `Nothing to do.` is normal when no unsettled poll exists. If the log shows a Calendar permission error, allow the program under System Settings → Privacy & Security → Calendars. After rebuilding, run `noodle agent install` again.

**No notification appears.** Check System Settings → Notifications for "Script Editor" (macOS shows notifications sent through `osascript` under that name) and Focus modes.

**Start again from scratch (testing).** `noodle delete <poll> --yes` removes the poll and its holds. To discard local bookkeeping only, delete `~/.config/noodle/polls.json`; holds then remain in the calendar and must be deleted by hand (look for events titled `HOLD:` in your hold calendar).
