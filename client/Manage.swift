import Foundation
import EventKit

// MARK: - Safe terminal text (names come from strangers: strip control and formatting characters)

func clean(_ s: String) -> String {
    String(String.UnicodeScalarView(s.unicodeScalars.filter {
        $0.properties.generalCategory != .control && $0.properties.generalCategory != .format
    }))
}

// MARK: - Poll data from the server

struct Opt { let id: String; let start: Date; let end: Date }
struct VoteRow { let id: String; let name: String; let choices: [String: String] }
struct PollData {
    let slug: String, title: String
    let open: Bool, reason: String?
    let options: [Opt], votes: [VoteRow]
    let deadline: Date?, maxVotes: Int?
}

/// nil when the poll no longer exists on the server.
func fetchPoll(_ cfg: Config, _ slug: String) -> PollData? {
    let r = api(cfg, ["action": "get_poll", "slug": slug])
    if r.status == 404 { return nil }
    guard r.status == 200, let j = r.json, let p = j["poll"] as? [String: Any], let st = j["state"] as? [String: Any] else {
        fail("server answered \(r.status) when fetching the poll")
    }
    let opts: [Opt] = (p["options"] as? [[String: Any]] ?? []).compactMap {
        guard let id = $0["id"] as? String, let s = ($0["start"] as? String).flatMap(parseISO), let e = ($0["end"] as? String).flatMap(parseISO) else { return nil }
        return Opt(id: id, start: s, end: e)
    }
    let votes: [VoteRow] = (p["votes"] as? [[String: Any]] ?? []).map {
        VoteRow(id: $0["id"] as? String ?? "", name: clean($0["name"] as? String ?? ""), choices: $0["choices"] as? [String: String] ?? [:])
    }
    let settings = p["settings"] as? [String: Any] ?? [:]
    return PollData(slug: slug, title: clean(p["title"] as? String ?? ""), open: st["open"] as? Bool ?? false,
                    reason: st["closed_reason"] as? String, options: opts, votes: votes,
                    deadline: (settings["deadline"] as? String).flatMap(parseISO), maxVotes: settings["max_votes"] as? Int)
}

func resolveSlug(_ cfg: Config, _ a: String?) -> String {
    guard let a, !a.isEmpty else { fail("which poll? Give a slug, a unique prefix, or \"latest\" (see: noodle polls)") }
    if a.range(of: "^[a-z2-7]{26}$", options: .regularExpression) != nil { return a }
    let r = api(cfg, ["action": "list_polls"])
    guard r.status == 200, let polls = r.json?["polls"] as? [[String: Any]] else { fail("cannot list polls (\(r.status))") }
    let slugs = polls.compactMap { $0["slug"] as? String }
    if a == "latest" { guard let f = slugs.first else { fail("no polls yet") }; return f }
    let m = slugs.filter { $0.hasPrefix(a.lowercased()) }
    guard a.count >= 3, m.count == 1 else { fail(m.isEmpty ? "no poll matches \"\(a)\"" : "\"\(a)\" matches several polls; type more characters") }
    return m[0]
}

func scoreOf(_ poll: PollData, _ o: Opt) -> (yes: Int, maybe: Int, no: Int, score: Int) {
    var y = 0, m = 0, n = 0
    for v in poll.votes { switch v.choices[o.id] { case "y": y += 1; case "m": m += 1; default: n += 1 } }
    return (y, m, n, y * 2 + m)
}

func bestOption(_ poll: PollData) -> Opt? {
    let ranked = poll.options.map { ($0, scoreOf(poll, $0)) }.filter { $0.1.score > 0 }
        .sorted { ($0.1.score, $0.1.yes, -$0.0.start.timeIntervalSince1970) > ($1.1.score, $1.1.yes, -$1.0.start.timeIntervalSince1970) }
    return ranked.first?.0
}

func confirmPrompt(_ q: String) -> Bool {
    print(q + " [y/N] ", terminator: "")
    return (readLine() ?? "").trimmingCharacters(in: .whitespaces).lowercased() == "y"
}

func notify(_ title: String, _ body: String) {
    func esc(_ s: String) -> String { s.replacingOccurrences(of: "\\", with: "\\\\").replacingOccurrences(of: "\"", with: "\\\"") }
    let p = Process(); p.executableURL = URL(fileURLWithPath: "/usr/bin/osascript")
    p.arguments = ["-e", "display notification \"\(esc(body))\" with title \"\(esc(title))\""]
    try? p.run(); p.waitUntilExit()
}

// MARK: - results / close / delete

func cmdResults(_ args: [String]) {
    let cfg = loadConfig()
    let slug = resolveSlug(cfg, args.first { !$0.hasPrefix("--") })
    guard let poll = fetchPoll(cfg, slug) else { fail("poll not found") }
    let tz = TimeZone(identifier: cfg.timezone) ?? .current
    let best = bestOption(poll)

    if args.contains("--csv") {
        func q(_ s: String) -> String {
            var t = s
            if let f = t.first, "=+-@\t\r".contains(f) { t = "'" + t }   // block spreadsheet formula injection
            return "\"" + t.replacingOccurrences(of: "\"", with: "\"\"") + "\""
        }
        print((["Name"] + poll.options.map { fmtSlot(Interval(start: $0.start, end: $0.end), tz: tz) }).map(q).joined(separator: ","))
        for v in poll.votes {
            let cells = poll.options.map { ["y": "Yes", "m": "If need be"][v.choices[$0.id] ?? "n"] ?? "No" }
            print(([v.name] + cells).map(q).joined(separator: ","))
        }
        return
    }

    let status = poll.open ? "OPEN" : "CLOSED (\(poll.reason ?? "?"))"
    print("\n\(poll.title)\n  \(status) · \(poll.votes.count)\(poll.maxVotes.map { " of \($0)" } ?? "") responses · \(slug)")
    if let d = poll.deadline { print("  deadline: \(fmtInstant(d, tz: tz))") }
    print("")
    for (i, o) in poll.options.enumerated() {
        let s = scoreOf(poll, o)
        let bar = String(repeating: "█", count: s.yes) + String(repeating: "▒", count: s.maybe)
        let star = (best?.id == o.id) ? "  ★ best" : ""
        print(String(format: "  %2d. ", i + 1) + fmtSlot(Interval(start: o.start, end: o.end), tz: tz)
              + "   ✓\(s.yes) ~\(s.maybe) ✗\(s.no)  \(bar)\(star)")
    }
    if poll.votes.isEmpty { print("\n  No responses yet."); return }
    print("\n  Responses (✓ yes  ~ if need be  ✗ no), in slot order:\n")
    let w = max(8, poll.votes.map { $0.name.count }.max() ?? 8)
    for v in poll.votes {
        let marks = poll.options.map { ["y": "✓", "m": "~"][v.choices[$0.id] ?? "n"] ?? "✗" }.joined(separator: " ")
        print("  " + v.name.padding(toLength: w, withPad: " ", startingAt: 0) + "  " + marks + "   (\(v.id))")
    }
    print("")
}

func cmdClose(_ args: [String]) {
    let cfg = loadConfig()
    let slug = resolveSlug(cfg, args.first)
    let r = api(cfg, ["action": "close_poll", "slug": slug])
    if r.status == 200 { print("Closed \(slug). Voting is now disabled.") } else { fail("server answered \(r.status)") }
}

func cmdDeleteVote(_ args: [String]) {
    let cfg = loadConfig()
    guard args.count >= 2 else { fail("usage: noodle delete-vote <poll> <name|vote-id>") }
    let slug = resolveSlug(cfg, args[0])
    guard let poll = fetchPoll(cfg, slug) else { fail("poll not found") }
    let key = args.dropFirst().joined(separator: " ")
    let hits = poll.votes.filter { $0.id == key || $0.name.lowercased() == key.lowercased() }
    guard hits.count == 1 else { fail(hits.isEmpty ? "no response matches \"\(key)\"" : "several responses match; use the id from `noodle results`") }
    guard confirmPrompt("Remove the response from \"\(hits[0].name)\"?") else { print("Cancelled."); return }
    let r = api(cfg, ["action": "delete_vote", "slug": slug, "vote_id": hits[0].id])
    if r.status == 200 { print("Removed.") } else { fail("server answered \(r.status)") }
}

func cmdDelete(_ args: [String]) {
    let cfg = loadConfig()
    let slug = resolveSlug(cfg, args.first { !$0.hasPrefix("--") })
    var local = loadLocalPolls()
    let title = local[slug]?.title ?? slug
    guard args.contains("--yes") || confirmPrompt("Permanently delete the poll \"\(title)\" and all its responses from the server?") else { print("Cancelled."); return }
    if let lp = local[slug], !lp.holds.isEmpty {
        requestCalendarAccess()
        for h in lp.holds { _ = deleteEvent(id: h.eventId) }
    }
    let r = api(cfg, ["action": "delete_poll", "slug": slug])
    guard r.status == 200 else { fail("server answered \(r.status)") }
    local[slug] = nil
    saveLocalPolls(local)
    print("Deleted.")
}

// MARK: - book / release / sync

/// Confirm one slot, delete every other hold, close the poll. Returns true on success.
@discardableResult
func settleBooking(_ cfg: Config, poll: PollData, option: Opt, askConfirmation: Bool) -> Bool {
    let tz = TimeZone(identifier: cfg.timezone) ?? .current
    let slotText = fmtSlot(Interval(start: option.start, end: option.end), tz: tz)
    let yes = poll.votes.filter { $0.choices[option.id] == "y" }.map { $0.name }
    let maybe = poll.votes.filter { $0.choices[option.id] == "m" }.map { $0.name }
    var local = loadLocalPolls()
    var lp = local[poll.slug] ?? LocalPoll(title: poll.title, created: isoUTC(Date()), holds: [])
    let others = lp.holds.filter { $0.optionId != option.id }

    if askConfirmation {
        print("\nBook: \(poll.title)\n  \(slotText)\n  Yes: \(yes.isEmpty ? "-" : yes.joined(separator: ", "))")
        if !maybe.isEmpty { print("  If need be: \(maybe.joined(separator: ", "))") }
        print("  This confirms the event, deletes \(others.count) other hold(s)\(poll.open ? ", and closes the poll" : "").")
        guard confirmPrompt("Proceed?") else { print("Cancelled."); return false }
    }

    requestCalendarAccess()
    var notes = "Scheduled with Noodle.\nYes: \(yes.joined(separator: ", "))"
    if !maybe.isEmpty { notes += "\nIf need be: \(maybe.joined(separator: ", "))" }
    var confirmed = false
    if let rec = lp.holds.first(where: { $0.optionId == option.id }) {
        confirmed = confirmEvent(id: rec.eventId, title: poll.title, notes: notes, moveTo: cfg.bookCalendar)
    }
    if !confirmed {   // hold missing (never created, or deleted by hand): create the event directly
        let cal = ensureHoldCalendar(cfg.bookCalendar.isEmpty ? cfg.holdCalendar : cfg.bookCalendar)
        if let id = try? createHold(calendar: cal, title: poll.title, slot: Interval(start: option.start, end: option.end), notes: notes) {
            confirmed = confirmEvent(id: id, title: poll.title, notes: notes, moveTo: cfg.bookCalendar)
            lp.holds.append(HoldRecord(optionId: option.id, eventId: id, start: isoUTC(option.start), end: isoUTC(option.end)))
        }
    }
    guard confirmed else { FileHandle.standardError.write(Data("error: could not confirm the calendar event; nothing else was changed\n".utf8)); return false }

    var removed = 0
    for h in others where deleteEvent(id: h.eventId) { removed += 1 }
    lp.holds = lp.holds.filter { $0.optionId == option.id }
    lp.settled = true; lp.bookedOption = option.id
    local[poll.slug] = lp
    saveLocalPolls(local)
    if poll.open { _ = api(cfg, ["action": "close_poll", "slug": poll.slug]) }
    print("Booked \(slotText). Removed \(removed) unused hold(s).")
    if !yes.isEmpty { print("Tell these people: \(yes.joined(separator: ", "))") }
    return true
}

func cmdBook(_ args: [String]) {
    let cfg = loadConfig()
    let pos = args.filter { !$0.hasPrefix("--") }
    guard pos.count >= 2 else { fail("usage: noodle book <poll> <slot number|best> [--yes]") }
    let slug = resolveSlug(cfg, pos[0])
    guard let poll = fetchPoll(cfg, slug) else { fail("poll not found") }
    let opt: Opt
    if pos[1] == "best" { guard let b = bestOption(poll) else { fail("no votes yet, so there is no best slot") }; opt = b }
    else { guard let n = Int(pos[1]), (1...poll.options.count).contains(n) else { fail("slot must be 1-\(poll.options.count) or \"best\"") }; opt = poll.options[n - 1] }
    settleBooking(cfg, poll: poll, option: opt, askConfirmation: !args.contains("--yes"))
}

@discardableResult
func releaseHolds(_ slug: String, markSettled: Bool = true) -> Int {
    var local = loadLocalPolls()
    guard var lp = local[slug] else { return 0 }
    var n = 0
    for h in lp.holds where deleteEvent(id: h.eventId) { n += 1 }
    lp.holds = []
    if markSettled { lp.settled = true }
    local[slug] = lp
    saveLocalPolls(local)
    return n
}

func cmdRelease(_ args: [String]) {
    let cfg = loadConfig()
    let slug = resolveSlug(cfg, args.first { !$0.hasPrefix("--") })
    guard let lp = loadLocalPolls()[slug], !lp.holds.isEmpty else { print("No holds recorded for this poll."); return }
    guard args.contains("--yes") || confirmPrompt("Delete all \(lp.holds.count) holds for \"\(lp.title)\"?") else { print("Cancelled."); return }
    requestCalendarAccess()
    print("Removed \(releaseHolds(slug)) hold(s).")
}

func cmdSync() {
    let cfg = loadConfig()
    var local = loadLocalPolls()
    let active = local.filter { !($0.value.settled ?? false) }
    if active.isEmpty { print("Nothing to do."); return }
    if active.contains(where: { !$0.value.holds.isEmpty }) { requestCalendarAccess() }
    let tz = TimeZone(identifier: cfg.timezone) ?? .current
    let now = Date()

    for (slug, lp) in active {
        guard let poll = fetchPoll(cfg, slug) else {
            print("[\(lp.title)] poll no longer exists on the server: releasing \(releaseHolds(slug)) hold(s).")
            continue
        }
        // Holds whose time has already passed are useless: drop them one by one.
        var cur = loadLocalPolls()[slug] ?? lp
        let (expired, live) = (cur.holds.filter { (parseISO($0.start) ?? .distantFuture) < now }, cur.holds.filter { (parseISO($0.start) ?? .distantFuture) >= now })
        if !expired.isEmpty {
            for h in expired { _ = deleteEvent(id: h.eventId) }
            cur.holds = live; local = loadLocalPolls(); local[slug] = cur; saveLocalPolls(local)
            print("[\(poll.title)] removed \(expired.count) expired hold(s).")
        }
        if poll.open { continue }

        let best = bestOption(poll)
        if cur.notified != true {
            let bt = best.map { "Best: \(fmtSlot(Interval(start: $0.start, end: $0.end), tz: tz)) (\(scoreOf(poll, $0).yes) yes)" } ?? "No votes."
            notify("Noodle: poll closed", "\(poll.title). \(bt)")
            local = loadLocalPolls(); local[slug]?.notified = true; saveLocalPolls(local)
            print("[\(poll.title)] closed (\(poll.reason ?? "?")). \(bt)")
        }
        switch cfg.onClose {
        case "auto_book_best":
            if let b = best { settleBooking(cfg, poll: poll, option: b, askConfirmation: false) }
            else { print("[\(poll.title)] no votes: releasing \(releaseHolds(slug)) hold(s)."); }
        case "release_all":
            print("[\(poll.title)] releasing \(releaseHolds(slug)) hold(s).")
        default:
            if !(loadLocalPolls()[slug]?.holds.isEmpty ?? true) {
                print("[\(poll.title)] holds kept. Run: noodle book \(slug.prefix(6)) best    (or: noodle release \(slug.prefix(6)))")
            }
        }
    }
}

// MARK: - Background agent (launchd)

let agentLabel = "com.example.noodle.sync"

@discardableResult
func run(_ path: String, _ args: [String]) -> (Int32, String) {
    let p = Process(); p.executableURL = URL(fileURLWithPath: path); p.arguments = args
    let pipe = Pipe(); p.standardOutput = pipe; p.standardError = pipe
    do { try p.run() } catch { return (127, "\(error)") }
    let d = pipe.fileHandleForReading.readDataToEndOfFile(); p.waitUntilExit()
    return (p.terminationStatus, String(data: d, encoding: .utf8) ?? "")
}

func agentPaths() -> (plist: URL, binary: URL, log: URL) {
    let home = FileManager.default.homeDirectoryForCurrentUser
    return (home.appendingPathComponent("Library/LaunchAgents/\(agentLabel).plist"),
            home.appendingPathComponent("Library/Application Support/Noodle/noodle"),
            home.appendingPathComponent("Library/Logs/noodle-sync.log"))
}

func agentPlist(binary: URL, log: URL) -> [String: Any] {
    var plist: [String: Any] = [
        "Label": agentLabel,
        "ProgramArguments": [binary.path, "sync"],
        "StartInterval": 900,
        "RunAtLoad": true,
        "ProcessType": "Background",
        "StandardOutPath": log.path,
        "StandardErrorPath": log.path,
    ]
    if let c = ProcessInfo.processInfo.environment["NOODLE_CONFIG_DIR"] { plist["EnvironmentVariables"] = ["NOODLE_CONFIG_DIR": c] }
    return plist
}

func cmdAgent(_ args: [String]) {
    let (plistURL, binary, log) = agentPaths()
    let fm = FileManager.default
    let domain = "gui/\(getuid())"
    switch args.first {
    case "print":
        let d = try? PropertyListSerialization.data(fromPropertyList: agentPlist(binary: binary, log: log), format: .xml, options: 0)
        print(String(data: d ?? Data(), encoding: .utf8) ?? "")
    case "install":
        _ = loadConfig()
        guard let me = Bundle.main.executableURL else { fail("cannot locate this program") }
        do {
            try fm.createDirectory(at: binary.deletingLastPathComponent(), withIntermediateDirectories: true)
            try fm.createDirectory(at: plistURL.deletingLastPathComponent(), withIntermediateDirectories: true)
            if fm.fileExists(atPath: binary.path) { try fm.removeItem(at: binary) }
            try fm.copyItem(at: me, to: binary)   // run from a stable local path, not from a synced or removable folder
            let d = try PropertyListSerialization.data(fromPropertyList: agentPlist(binary: binary, log: log), format: .xml, options: 0)
            try d.write(to: plistURL, options: .atomic)
        } catch { fail("cannot install agent: \(error.localizedDescription)") }
        run("/bin/launchctl", ["bootout", "\(domain)/\(agentLabel)"])
        let (st, msg) = run("/bin/launchctl", ["bootstrap", domain, plistURL.path])
        guard st == 0 else { fail("launchctl failed: \(msg)") }
        print("Agent installed. It runs `noodle sync` every 15 minutes and shows a notification when a poll closes.")
        print("  program: \(binary.path)\n  log:     \(log.path)")
        print("After rebuilding Noodle, run `noodle agent install` again to update the copy.")
        print("macOS may ask once for Calendar access for this program; allow it.")
    case "status":
        let (st, msg) = run("/bin/launchctl", ["print", "\(domain)/\(agentLabel)"])
        if st != 0 { print("Agent is not installed."); return }
        for l in msg.split(separator: "\n") where l.contains("state =") || l.contains("last exit code") || l.contains("runs =") { print(l.trimmingCharacters(in: .whitespaces)) }
        if let t = try? String(contentsOf: log, encoding: .utf8) { print("\nLast log lines:\n" + t.split(separator: "\n").suffix(8).joined(separator: "\n")) }
    case "uninstall":
        run("/bin/launchctl", ["bootout", "\(domain)/\(agentLabel)"])
        try? fm.removeItem(at: plistURL)
        try? fm.removeItem(at: binary)
        print("Agent removed. Existing holds stay in your calendar until you book or release them.")
    default:
        print("usage: noodle agent install|status|uninstall|print")
    }
}
