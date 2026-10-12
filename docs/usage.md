# Usage

All day-to-day work happens in the `noodle` command-line tool. This page lists every command, then shows the typical workflows.

Conventions used below:

- `./build/noodle` is the program (or `noodle` if you made an alias)
- `<poll>` is a poll identifier. You can pass the full 26-character id, any **unique prefix** (at least 3 characters, for example `pjr`), or the word **`latest`** for your newest poll. `noodle polls` lists them.

## Contents

- [Workflows](#workflows)
- [Command reference](#command-reference)
- [`new`: all options](#new-all-options)
- [How slots are chosen](#how-slots-are-chosen)
- [What happens to your calendar](#what-happens-to-your-calendar)
- [For invitees: what they see and can do](#for-invitees-what-they-see-and-can-do)

---

## Workflows

### The everyday case

```sh
noodle new --title "Project sync" --duration 45 --deadline "+3d"
# Enter to publish. The link is now on your clipboard: paste it into an email.

# ...some days later...
noodle results latest
noodle book latest best
```

### See before you publish

```sh
noodle new --title "Planning" --from 2026-11-02 --to 2026-11-13 --dry-run
```

`--dry-run` shows the slots it would propose. Nothing is published and nothing is written to your calendar.

### A poll with a limited number of seats

```sh
noodle new --title "Workshop" --duration 120 --max-votes 8 --no-holds
```

The poll closes automatically after the 8th vote. `--no-holds` skips the calendar holds.

### Let the agent do the cleanup

In `~/.config/noodle/config.json` set `"onClose": "auto_book_best"` and install the agent:

```sh
noodle agent install
```

From then on, when a poll closes, the best slot is confirmed in your calendar, the other holds are deleted, and you get a notification.

### Export the answers

```sh
noodle results latest --csv > answers.csv
```

### Take back control

```sh
noodle close latest            # no more votes
noodle release latest          # delete this poll's holds, keep the poll
noodle delete-vote latest "Alex Morgan"
noodle delete latest           # remove the poll from the server, and its holds
```

## Command reference

| Command | What it does |
|---|---|
| `init --url URL [--repo PATH] [--force]` | One-time setup: creates the secret (Keychain), writes the config and builds `dist/noodle/`. `--force` replaces an existing secret and keeps your settings. |
| `build [--repo PATH] [--no-config]` | Rebuilds `dist/noodle/` after an update, with the existing secret. `--no-config` omits `app/config.php`. |
| `doctor` | Self-check of the live installation (signing, replay protection, headers, protected folders). |
| `ping` | Sends one signed request and reports whether the server accepted it. |
| `config` | Prints the config path and all settings; writes defaults for any missing key. |
| `calendars` | Lists the calendars Noodle can see (and whether they are writable). |
| `new --title "..." [options]` | Finds free slots, lets you trim them, publishes the poll, creates holds, copies the link. |
| `polls` | Lists your polls on the server: id, open/closed, number of votes, title. |
| `results <poll> [--csv]` | Per-slot totals with the best slot starred, and every response with its id. `--csv` prints a spreadsheet-safe CSV instead. |
| `close <poll>` | Stops accepting votes. |
| `book <poll> <slot number or "best"> [--yes]` | Confirms one slot in your calendar, deletes every other hold, closes the poll, and tells you whom to inform. |
| `release <poll> [--yes]` | Deletes all calendar holds of the poll without booking anything. |
| `delete-vote <poll> <name or vote id>` | Removes one response (asks for confirmation). |
| `delete <poll> [--yes]` | Permanently deletes the poll from the server and its holds from your calendar. |
| `sync` | Settles closed polls and removes expired holds. This is what the agent runs. You can run it by hand. |
| `agent install \| status \| uninstall \| print` | Manages the background agent (launchd, every 15 minutes). |
| `selftest` | Tests the slot finder on synthetic data (does not touch your calendar). |

## `new`: all options

| Option | Default | Meaning |
|---|---|---|
| `--title "..."` | *(required)* | Poll title (max 120 characters) |
| `--description "..."` | none | Text under the title (max 2000 characters) |
| `--from YYYY-MM-DD` | tomorrow | First day to consider |
| `--to YYYY-MM-DD` | 14 days after `--from` | Last day to consider (max range 120 days) |
| `--duration MIN` | `durationMinutes` | Length of each slot, 5 to 480 minutes |
| `--hours HH:MM-HH:MM` | `workHours` | Window in which slots may start and end |
| `--days Mon-Fri` | `days` | Weekdays to use: `Mon-Fri`, `Mon,Wed,Fri`, `Sat-Sun`... |
| `--calendars "A,B"` | `busyCalendars` | Calendars that count as busy, for this poll only |
| `--reserve "rule,rule"` | none | Extra reserved-time rules for this poll only (see [configuration](configuration.md#reserved-time-rules)) |
| `--deadline X` | none | Voting closes then: `"2026-11-05 18:00"` (your time zone) or relative `"+3d"`, `"+48h"` |
| `--max-votes N` | none | Voting closes after N responses (1 to 500) |
| `--no-maybe` | off | Remove the *if need be* answer: only yes or no |
| `--hide-votes` | off | Voters see only the totals, not who answered what |
| `--no-holds` | off | Do not create tentative events in your calendar |
| `--dry-run` | off | Show the proposed slots and stop |
| `--yes` | off | Publish without the review prompt |

At the review prompt: press **Enter** to publish, type numbers to drop slots (`3`, `2,5`, `4-7`), or `q` to cancel.

## How slots are chosen

For each day between `--from` and `--to` that matches your weekdays, Noodle tries start times inside your working hours, every `stepMinutes` (30 by default), for the requested duration. A candidate is rejected if:

- it starts sooner than `minNoticeHours` from now (24 by default)
- it comes closer than `bufferMinutes` (15 by default) to a busy event, or overlaps one
- it overlaps a `reserved` rule

Busy time comes from the calendars in `busyCalendars` (all calendars except Birthdays when the list is empty) **plus the hold calendar**, so two polls never offer the same time. An event is ignored if it is marked **Free**, cancelled, or a meeting you **declined**. Tentative events and all-day events block time unless you turn that off (`tentativeBlocks`, `allDayBlocks`).

If more than `perDay` candidates remain on a day, evenly spread ones are kept. If more than `maxSlots` remain in total, an even sample across the whole range is kept. The server accepts at most 60 slots per poll.

## What happens to your calendar

- `new` creates one **tentative** event per proposed slot, titled `HOLD: <poll title>`, in the calendar named by `holdCalendar` (default *Noodle Holds*, created if missing). The event notes contain the poll link. Holds are recorded by their event identifiers in `~/.config/noodle/polls.json`.
- `book` turns the winning hold into a normal **busy** event (renamed to the poll title, with the names of the people who answered *yes* and *if need be* in the notes), optionally moves it to `bookCalendar`, and deletes the other holds.
- `release`, `delete` and `sync` delete only events whose identifiers Noodle recorded itself. Noodle never searches for or deletes other events.
- If you delete a hold by hand, Noodle copes: `book` simply creates the event again.
- Holds in the past are removed by `sync`.

## For invitees: what they see and can do

- The poll page shows the title, description, time zone, number of options and responses, and the deadline.
- They choose **Yes**, **If need be** or **No** for each slot, type their name and submit. **Quick fill** buttons set all answers at once.
- After submitting, they see their own row marked "you". Returning from the **same browser** shows the form prefilled so they can **update** their vote. The link between a vote and a browser is a random cookie; there is no password, and a different browser cannot change someone else's vote.
- Two people cannot use the same name (compared without regard to case).
- When the poll is closed (deadline, full, or closed by you) the form disappears and a banner explains why. If the poll is merely *full*, people who already voted can still change their answers.
- Times appear in the poll's time zone. A button **Show in my time zone** converts them in the browser.
- The page works with JavaScript disabled; JavaScript only adds the time-zone switch and the quick-fill buttons.
