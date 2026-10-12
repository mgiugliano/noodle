# Configuration

Noodle has two separate configurations:

1. **Client settings**, on your Mac: `~/.config/noodle/config.json`. Everything about *how you want slots proposed*.
2. **Server settings**, on your web host: `app/config.php`. Generated for you; you rarely touch it.

## Client settings

The file is plain JSON. Run `noodle config` to print the path and all settings; it also writes any missing key with its default value, so you can see everything you can change. To use a different folder (for testing), set the environment variable `NOODLE_CONFIG_DIR`.

```json
{
  "serverURL": "https://example.com/noodle",
  "timezone": "Europe/Berlin",
  "busyCalendars": ["Work", "Personal"],
  "reserved": ["Mon-Fri 12:00-13:30", "Fri 14:00-18:00"],
  "holdCalendar": "Noodle Holds",
  "bookCalendar": "Work",
  "workHours": "09:00-17:00",
  "days": "Mon-Fri",
  "durationMinutes": 60,
  "stepMinutes": 30,
  "bufferMinutes": 15,
  "minNoticeHours": 24,
  "perDay": 3,
  "maxSlots": 12,
  "tentativeBlocks": true,
  "allDayBlocks": true,
  "holds": true,
  "onClose": "notify"
}
```

| Key | Type | Default | Meaning |
|---|---|---|---|
| `serverURL` | string | set by `init` | Public address of the `noodle` folder. Must be `https://` (or `http://localhost...` for testing). |
| `timezone` | string | your Mac's zone | IANA time zone used to interpret working hours and shown on the poll page, for example `Europe/Berlin` or `America/New_York`. |
| `busyCalendars` | list of strings | `[]` | Calendar names (exactly as `noodle calendars` prints them) that count as busy. **Empty means every calendar except Birthdays**, including holiday and subscribed calendars. |
| `reserved` | list of strings | `[]` | Time that is never offered. See the rule syntax below. |
| `holdCalendar` | string | `Noodle Holds` | Calendar that receives the tentative `HOLD:` events. Created in your default account if it does not exist. It always counts as busy. |
| `bookCalendar` | string | `""` | Calendar where the confirmed event goes after `book`. Empty keeps it in `holdCalendar`. Must exist and be writable. |
| `workHours` | string | `09:00-17:00` | Window in which slots may start and end, `HH:MM-HH:MM` (24 h). `9-17` also works. |
| `days` | string | `Mon-Fri` | Weekdays to use. Ranges and lists: `Mon-Fri`, `Mon,Wed,Fri`, `Fri-Mon`. |
| `durationMinutes` | integer | `60` | Slot length. |
| `stepMinutes` | integer | `30` | Granularity of start times inside the working hours. |
| `bufferMinutes` | integer | `15` | Minimum gap between a slot and any busy event. |
| `minNoticeHours` | integer | `24` | Slots starting sooner than this from now are never offered. |
| `perDay` | integer | `3` | Maximum slots per day (evenly spread within the day). |
| `maxSlots` | integer | `12` | Maximum slots per poll (evenly spread over the range). The server's hard limit is 60. |
| `tentativeBlocks` | boolean | `true` | Whether tentative events block time. |
| `allDayBlocks` | boolean | `true` | Whether all-day events block the whole day. |
| `holds` | boolean | `true` | Create tentative calendar holds. `false` disables them for every poll. |
| `onClose` | string | `notify` | What `sync` does when a poll closes: see below. |

Command-line options of `noodle new` override these settings for one poll.

### `onClose`

| Value | Behaviour |
|---|---|
| `notify` | Show a notification with the best slot. Keep the holds until you run `book` or `release`. |
| `auto_book_best` | Book the best slot (highest score; ties go to more *yes* answers, then the earliest slot), delete the other holds. If nobody voted, release all holds. |
| `release_all` | Delete every hold. |

The score of a slot is 2 points per *yes* and 1 per *if need be*.

### Reserved-time rules

Each entry in `reserved` (or each comma-separated item of `--reserve`) is one rule. A rule is a **day part** optionally followed by a **time window**. Without a time window the whole day is reserved.

| Rule | Meaning |
|---|---|
| `Fri 13:00-17:00` | Every Friday from 13:00 to 17:00 |
| `Mon-Fri 12:00-14:00` | Lunch on every weekday |
| `Mon,Wed 08:00-09:30` | Monday and Wednesday mornings |
| `Wed` | Every Wednesday, all day |
| `2026-10-22` | That whole date |
| `2026-10-22 14:00-16:00` | A time window on that date |
| `2026-12-24..2027-01-06` | A range of whole days (holidays, travel) |

Times use `timezone`. A rule Noodle cannot understand stops the command with examples, so a typo never silently ignores a reservation.

### Tips

- Start with `noodle new --title test --dry-run` after every settings change to see the effect.
- Marking a calendar event as **Free** in Calendar.app lets you keep it visible without blocking time.
- Name your busy calendars explicitly. Relying on "all calendars" usually blocks days because of holiday or subscribed calendars.

## Files on your Mac

| Path | Content |
|---|---|
| `~/.config/noodle/config.json` | The settings above (mode 600) |
| `~/.config/noodle/polls.json` | For each poll you created: title, the identifiers of its calendar holds, whether it was settled (mode 600) |
| Keychain item `noodle-api-secret` | The management secret. Account name = the server's host name |
| `~/Library/LaunchAgents/com.example.noodle.sync.plist` | Agent definition (only if installed) |
| `~/Library/Application Support/Noodle/noodle` | The copy of the program the agent runs (only if installed) |
| `~/Library/Logs/noodle-sync.log` | Agent log (only if installed) |

## Server settings (`app/config.php`)

`noodle init` and `noodle build` generate this file:

```php
<?php
return [
    'secret'   => '...64 hex characters...',
    'data_dir' => __DIR__ . '/../data',
    'max_body' => 262144,
];
```

| Key | Default | Meaning |
|---|---|---|
| `secret` | generated | The management secret. Identical to the value in your Keychain. Also used to derive the voting-form signing key and the keys for hashing addresses in the rate limiter. |
| `data_dir` | `app/../data` | Where polls are stored. An absolute path outside the web folder is the most robust choice. |
| `max_body` | `262144` | Maximum size in bytes of a management request. |
| `vote_rate_limit` | `12` *(optional key)* | Maximum vote submissions per client address per 10 minutes. Raise it if many people share one address (an office, or a proxy that hides visitors' real addresses). |

Treat this file like a password. It is generated with mode 600 and must never be committed or shared.
