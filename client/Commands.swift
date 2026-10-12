import Foundation
import EventKit

// MARK: - Local bookkeeping of polls and their calendar holds (used later by `sync`/`book`)

struct HoldRecord: Codable { var optionId: String; var eventId: String; var start: String; var end: String }
struct LocalPoll: Codable {
    var title: String; var created: String; var holds: [HoldRecord]
    var notified: Bool? = nil; var settled: Bool? = nil; var bookedOption: String? = nil
}

let pollsFile = configDir.appendingPathComponent("polls.json")

func loadLocalPolls() -> [String: LocalPoll] {
    guard let d = try? Data(contentsOf: pollsFile), let p = try? JSONDecoder().decode([String: LocalPoll].self, from: d) else { return [:] }
    return p
}

func saveLocalPolls(_ p: [String: LocalPoll]) {
    do {
        try FileManager.default.createDirectory(at: configDir, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
        try JSONEncoder().encode(p).write(to: pollsFile, options: .atomic)
        try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: pollsFile.path)
    } catch { fail("cannot save local poll state: \(error.localizedDescription)") }
}

func pollLink(_ cfg: Config, _ slug: String) -> String { cfg.serverURL + "/index.php?p=" + slug }

func copyToClipboard(_ s: String) {
    let p = Process(); p.executableURL = URL(fileURLWithPath: "/usr/bin/pbcopy")
    let pipe = Pipe(); p.standardInput = pipe
    guard (try? p.run()) != nil else { return }
    pipe.fileHandleForWriting.write(Data(s.utf8)); try? pipe.fileHandleForWriting.close(); p.waitUntilExit()
}

// MARK: - config / polls

func cmdConfig() {
    let cfg = loadConfig()
    saveConfig(cfg)   // writes any missing keys with defaults so they can be edited
    print("Config file: \(configFile.path)\n")
    if let d = try? Data(contentsOf: configFile), let s = String(data: d, encoding: .utf8) { print(s) }
}

func cmdPolls() {
    let cfg = loadConfig()
    let r = api(cfg, ["action": "list_polls"])
    guard r.status == 200, let polls = r.json?["polls"] as? [[String: Any]] else { fail("server answered \(r.status)") }
    if polls.isEmpty { print("No polls yet."); return }
    for p in polls {
        let open = (p["open"] as? Bool) == true ? "open  " : "closed"
        print("\(p["slug"] as? String ?? "?")  \(open)  \(p["votes"] as? Int ?? 0) votes  \(p["title"] as? String ?? "")")
    }
}

// MARK: - new

func parseNumberList(_ s: String, max: Int) -> Set<Int>? {
    var out = Set<Int>()
    for part in s.split(whereSeparator: { $0 == "," || $0 == " " }) {
        let r = part.split(separator: "-").compactMap { Int($0) }
        if r.count == 1, (1...max).contains(r[0]) { out.insert(r[0]) }
        else if r.count == 2, r[0] <= r[1], r[0] >= 1, r[1] <= max { out.formUnion(r[0]...r[1]) }
        else { return nil }
    }
    return out
}

func cmdNew(_ args: [String]) {
    var cfg = loadConfig()
    guard let title = arg("--title", in: args), !title.isEmpty else { fail("--title is required") }
    guard let tz = TimeZone(identifier: cfg.timezone) else { fail("unknown timezone in config") }
    let now = Date()
    var cal = Calendar(identifier: .gregorian); cal.timeZone = tz

    if let v = arg("--duration", in: args) { guard let n = Int(v), n >= 5, n <= 480 else { fail("bad --duration") }; cfg.durationMinutes = n }
    if let v = arg("--hours", in: args) { cfg.workHours = v }
    if let v = arg("--days", in: args) { cfg.days = v }
    guard let (ws, we) = parseHours(cfg.workHours) else { fail("bad working hours \"\(cfg.workHours)\" (use 09:00-17:00)") }
    guard let wd = parseDays(cfg.days) else { fail("bad days \"\(cfg.days)\" (use Mon-Fri)") }

    let from = arg("--from", in: args).flatMap { parseLocalDateTime($0, tz: tz) } ?? cal.date(byAdding: .day, value: 1, to: now)!
    let to = arg("--to", in: args).flatMap { parseLocalDateTime($0, tz: tz) } ?? cal.date(byAdding: .day, value: 14, to: from)!
    guard to >= cal.startOfDay(for: from) else { fail("--to must not be before --from") }
    guard to.timeIntervalSince(from) < 120 * 86400 else { fail("range too long (max 120 days)") }

    var deadline: Date? = nil
    if let v = arg("--deadline", in: args) {
        guard let d = parseLocalDateTime(v, tz: tz), d > now else { fail("bad or past --deadline (use \"2026-10-30 18:00\" or \"+3d\")") }
        deadline = d
    }
    var maxVotes: Int? = nil
    if let v = arg("--max-votes", in: args) { guard let n = Int(v), (1...500).contains(n) else { fail("bad --max-votes") }; maxVotes = n }
    let calendarNames = arg("--calendars", in: args).map { $0.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces) } }
    let dryRun = args.contains("--dry-run")
    let assumeYes = args.contains("--yes")
    let useHolds = cfg.holds && !args.contains("--no-holds") && !dryRun

    requestCalendarAccess()
    var busy = busyIntervals(cfg: cfg, from: cal.startOfDay(for: from), to: cal.date(byAdding: .day, value: 2, to: to)!, calendarNames: calendarNames)
    let extra = arg("--reserve", in: args).map { $0.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces) } } ?? []
    guard let reserved = reservedIntervals(rules: cfg.reserved + extra, from: from, to: to, tz: tz) else {
        fail("cannot understand a reserved-time rule. Examples: \"Fri 13:00-17:00\", \"Mon-Fri 12:00-13:00\", \"2026-10-21\", \"2026-12-24..2027-01-06\"")
    }
    busy += reserved
    let params = SlotParams(tz: tz, firstDay: from, lastDay: to, workStartMin: ws, workEndMin: we, weekdays: wd,
                            duration: cfg.durationMinutes, step: cfg.stepMinutes, buffer: cfg.bufferMinutes,
                            minNoticeHours: cfg.minNoticeHours, perDay: cfg.perDay, maxSlots: cfg.maxSlots)
    var slots = computeSlots(busy: busy, p: params, now: now)
    guard !slots.isEmpty else { fail("no free slots found in that range. Widen --from/--to, hours, or calendars.") }

    print("\nProposed slots for \"\(title)\" (\(cfg.timezone), \(cfg.durationMinutes) min, \(busy.count) busy events considered):\n")
    func show() { for (i, s) in slots.enumerated() { print(String(format: "  %2d. ", i + 1) + fmtSlot(s, tz: tz)) } }
    show()
    if let d = deadline { print("\n  Voting closes: " + fmtInstant(d, tz: tz)) }
    if let m = maxVotes { print("  Voting closes after \(m) votes") }
    print(useHolds ? "\n  Tentative holds will be created in calendar \"\(cfg.holdCalendar)\"." : "")

    if dryRun { print("\n(dry run: nothing published, nothing written to your calendar)"); return }

    if !assumeYes {
        while true {
            print("\nPress Enter to publish, type numbers to drop (e.g. 2,5-7), or q to cancel: ", terminator: "")
            let line = (readLine() ?? "q").trimmingCharacters(in: .whitespaces)
            if line.lowercased() == "q" { print("Cancelled."); return }
            if line.isEmpty { break }
            guard let drop = parseNumberList(line, max: slots.count) else { print("Did not understand that."); continue }
            slots = slots.enumerated().filter { !drop.contains($0.offset + 1) }.map { $0.element }
            if slots.isEmpty { print("Nothing left. Cancelled."); return }
            print(""); show()
        }
    }

    var settings: [String: Any] = [:]
    if let d = deadline { settings["deadline"] = isoUTC(d) }
    if let m = maxVotes { settings["max_votes"] = m }
    if args.contains("--no-maybe") { settings["allow_maybe"] = false }
    if args.contains("--hide-votes") { settings["hide_votes"] = true }
    let poll: [String: Any] = [
        "title": title, "description": arg("--description", in: args) ?? "", "timezone": cfg.timezone,
        "slots": slots.map { ["start": isoUTC($0.start), "end": isoUTC($0.end)] }, "settings": settings,
    ]
    let r = api(cfg, ["action": "create_poll", "poll": poll])
    guard r.status == 201, let slug = r.json?["slug"] as? String, let opts = r.json?["options"] as? [[String: Any]] else {
        fail("server refused the poll (\(r.status)): \(r.json?["detail"] ?? r.json?["error"] ?? "?")")
    }
    let link = pollLink(cfg, slug)
    var local = loadLocalPolls()
    local[slug] = LocalPoll(title: title, created: isoUTC(now), holds: [])
    saveLocalPolls(local)

    if useHolds {
        let holdCal = ensureHoldCalendar(cfg.holdCalendar)
        var made = 0
        for o in opts {
            guard let oid = o["id"] as? String, let s = (o["start"] as? String).flatMap(parseISO), let e = (o["end"] as? String).flatMap(parseISO) else { continue }
            do {
                let id = try createHold(calendar: holdCal, title: title, slot: Interval(start: s, end: e),
                                        notes: "Noodle poll \(slug) option \(oid)\n\(link)\nThis hold is removed automatically when the poll is settled.")
                local[slug]?.holds.append(HoldRecord(optionId: oid, eventId: id, start: isoUTC(s), end: isoUTC(e)))
                made += 1
            } catch { FileHandle.standardError.write(Data("warning: could not create hold for \(isoUTC(s)): \(error.localizedDescription)\n".utf8)) }
        }
        saveLocalPolls(local)
        print("\nCreated \(made) tentative holds in \"\(cfg.holdCalendar)\".")
    }

    copyToClipboard(link)
    print("\nPoll published with \(opts.count) slots.\n\n  \(link)\n\n(copied to clipboard)")
}
