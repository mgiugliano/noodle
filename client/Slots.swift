import Foundation

struct Interval: Equatable { var start: Date; var end: Date }

struct SlotParams {
    var tz: TimeZone
    var firstDay: Date          // any instant on the first day (in tz)
    var lastDay: Date           // any instant on the last day (in tz), inclusive
    var workStartMin: Int       // minutes after local midnight
    var workEndMin: Int
    var weekdays: Set<Int>      // Calendar weekday numbers: 1 = Sunday ... 7 = Saturday
    var duration: Int
    var step: Int
    var buffer: Int
    var minNoticeHours: Int
    var perDay: Int
    var maxSlots: Int
}

/// Pick `n` items evenly spread across `a` (keeps chronological order).
func spread<T>(_ a: [T], _ n: Int) -> [T] {
    if n <= 0 { return [] }
    if a.count <= n { return a }
    if n == 1 { return [a[a.count / 2]] }
    return (0..<n).map { a[$0 * (a.count - 1) / (n - 1)] }
}

/// Free slots within working hours that clear every busy interval by `buffer` minutes.
func computeSlots(busy: [Interval], p: SlotParams, now: Date) -> [Interval] {
    var cal = Calendar(identifier: .gregorian)
    cal.timeZone = p.tz
    let earliest = now.addingTimeInterval(Double(p.minNoticeHours) * 3600)
    let buf = Double(p.buffer) * 60
    var perDayPicks: [[Interval]] = []

    var day = cal.startOfDay(for: p.firstDay)
    let last = cal.startOfDay(for: p.lastDay)
    while day <= last {
        if p.weekdays.contains(cal.component(.weekday, from: day)) {
            var cands: [Interval] = []
            var t = p.workStartMin
            while t + p.duration <= p.workEndMin {
                if let s = cal.date(byAdding: .minute, value: t, to: day) {
                    let e = s.addingTimeInterval(Double(p.duration) * 60)
                    if s >= earliest && !busy.contains(where: { $0.start < e.addingTimeInterval(buf) && $0.end > s.addingTimeInterval(-buf) }) {
                        cands.append(Interval(start: s, end: e))
                    }
                }
                t += p.step
            }
            if !cands.isEmpty { perDayPicks.append(spread(cands, p.perDay)) }
        }
        guard let next = cal.date(byAdding: .day, value: 1, to: day) else { break }
        day = next
    }
    let flat = perDayPicks.flatMap { $0 }
    return flat.count <= p.maxSlots ? flat : spread(flat, p.maxSlots)
}

// MARK: - Parsing helpers for CLI/config strings

func parseHours(_ s: String) -> (Int, Int)? {
    let parts = s.split(separator: "-").map(String.init)
    guard parts.count == 2 else { return nil }
    func mins(_ t: String) -> Int? {
        let hm = t.split(separator: ":").map(String.init)
        guard let h = Int(hm[0]), h >= 0, h <= 24 else { return nil }
        let m = hm.count > 1 ? Int(hm[1]) ?? -1 : 0
        return (m >= 0 && m < 60) ? h * 60 + m : nil
    }
    guard let a = mins(parts[0]), let b = mins(parts[1]), b > a else { return nil }
    return (a, b)
}

func parseDays(_ s: String) -> Set<Int>? {
    let names = ["sun": 1, "mon": 2, "tue": 3, "wed": 4, "thu": 5, "fri": 6, "sat": 7]
    func num(_ t: String) -> Int? { names[String(t.lowercased().prefix(3))] }
    var out = Set<Int>()
    for part in s.split(separator: ",") {
        let r = part.split(separator: "-").map(String.init)
        if r.count == 1 { guard let n = num(r[0]) else { return nil }; out.insert(n) }
        else if r.count == 2 {
            guard let a = num(r[0]), let b = num(r[1]) else { return nil }
            var d = a
            while true { out.insert(d); if d == b { break }; d = d % 7 + 1 }
        } else { return nil }
    }
    return out.isEmpty ? nil : out
}

func isoUTC(_ d: Date) -> String {
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.timeZone = TimeZone(identifier: "UTC")
    f.dateFormat = "yyyy-MM-dd'T'HH:mm:ss'Z'"
    return f.string(from: d)
}

func parseISO(_ s: String) -> Date? {
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.timeZone = TimeZone(identifier: "UTC")
    f.dateFormat = "yyyy-MM-dd'T'HH:mm:ss'Z'"
    return f.date(from: s)
}

func parseLocalDateTime(_ s: String, tz: TimeZone, now: Date = Date()) -> Date? {
    let t = s.trimmingCharacters(in: .whitespaces)
    if t.hasPrefix("+"), let unit = t.last, let n = Int(t.dropFirst().dropLast()) {
        switch unit {
        case "h": return now.addingTimeInterval(Double(n) * 3600)
        case "d": return now.addingTimeInterval(Double(n) * 86400)
        default: return nil
        }
    }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.timeZone = tz
    for fmt in ["yyyy-MM-dd HH:mm", "yyyy-MM-dd'T'HH:mm", "yyyy-MM-dd"] {
        f.dateFormat = fmt
        if let d = f.date(from: t) { return d }
    }
    return nil
}

func fmtSlot(_ i: Interval, tz: TimeZone) -> String {
    let d = DateFormatter(); d.locale = Locale(identifier: "en_US_POSIX"); d.timeZone = tz; d.dateFormat = "EEE d MMM"
    let t = DateFormatter(); t.locale = Locale(identifier: "en_US_POSIX"); t.timeZone = tz; t.dateFormat = "HH:mm"
    return "\(d.string(from: i.start))  \(t.string(from: i.start))-\(t.string(from: i.end))"
}

// MARK: - Reserved time rules

/// Rules: "Fri 13:00-17:00" (weekly), "Mon-Fri 12:00-13:00", "Wed" (whole weekday),
/// "2026-10-21" or "2026-10-21 14:00-16:00" (one date), "2026-12-24..2027-01-06" (whole days).
/// Returns nil if a rule cannot be understood.
func reservedIntervals(rules: [String], from: Date, to: Date, tz: TimeZone) -> [Interval]? {
    var cal = Calendar(identifier: .gregorian); cal.timeZone = tz
    let df = DateFormatter(); df.locale = Locale(identifier: "en_US_POSIX"); df.timeZone = tz; df.dateFormat = "yyyy-MM-dd"
    func isDate(_ t: String) -> Bool { t.range(of: #"^\d{4}-\d{2}-\d{2}$"#, options: .regularExpression) != nil }
    var out: [Interval] = []
    for raw in rules {
        let parts = raw.split(separator: " ").map(String.init)
        guard (1...2).contains(parts.count) else { return nil }
        var hours: (Int, Int)? = nil
        if parts.count == 2 { guard let h = parseHours(parts[1]) else { return nil }; hours = h }
        let key = parts[0]
        var matches: (Date) -> Bool
        if key.contains("..") {
            let ends = key.components(separatedBy: "..")
            guard ends.count == 2, isDate(ends[0]), isDate(ends[1]), let a = df.date(from: ends[0]), let b = df.date(from: ends[1]), a <= b else { return nil }
            matches = { $0 >= a && $0 <= b }
        } else if isDate(key) {
            guard let a = df.date(from: key) else { return nil }
            matches = { $0 == a }
        } else {
            guard let wd = parseDays(key) else { return nil }
            matches = { wd.contains(cal.component(.weekday, from: $0)) }
        }
        var day = cal.startOfDay(for: from)
        let last = cal.startOfDay(for: to)
        while day <= last {
            if matches(day), let next = cal.date(byAdding: .day, value: 1, to: day) {
                if let (a, b) = hours {
                    if let s = cal.date(byAdding: .minute, value: a, to: day), let e = cal.date(byAdding: .minute, value: b, to: day) {
                        out.append(Interval(start: s, end: e))
                    }
                } else { out.append(Interval(start: day, end: next)) }
            }
            guard let n = cal.date(byAdding: .day, value: 1, to: day) else { break }
            day = n
        }
    }
    return out
}

func fmtInstant(_ d: Date, tz: TimeZone) -> String {
    let f = DateFormatter(); f.locale = Locale(identifier: "en_US_POSIX"); f.timeZone = tz; f.dateFormat = "EEE d MMM, HH:mm"
    return f.string(from: d)
}

// MARK: - Self test (no calendar access needed)

func cmdSelfTest() {
    var failures = 0
    func expect(_ name: String, _ ok: Bool) { print((ok ? "  [ ok ] " : "  [FAIL] ") + name); if !ok { failures += 1 } }
    let utc = TimeZone(identifier: "UTC")!
    var cal = Calendar(identifier: .gregorian); cal.timeZone = utc
    func at(_ d: Int, _ h: Int, _ m: Int = 0) -> Date {   // October 2026 (the 19th is a Monday)
        cal.date(from: DateComponents(timeZone: utc, year: 2026, month: 10, day: d, hour: h, minute: m))!
    }
    let past = at(1, 0)
    var p = SlotParams(tz: utc, firstDay: at(19, 0), lastDay: at(19, 0), workStartMin: 9 * 60, workEndMin: 14 * 60,
                       weekdays: parseDays("Mon-Fri")!, duration: 60, step: 30, buffer: 15, minNoticeHours: 0, perDay: 99, maxSlots: 99)

    let busy = [Interval(start: at(19, 10), end: at(19, 11))]
    let s = computeSlots(busy: busy, p: p, now: past)
    expect("buffer around a 10:00-11:00 meeting leaves 11:30, 12:00, 12:30, 13:00",
           s.map { cal.component(.hour, from: $0.start) * 60 + cal.component(.minute, from: $0.start) } == [690, 720, 750, 780])

    expect("empty calendar gives 9 slots between 09:00 and 14:00", computeSlots(busy: [], p: p, now: past).count == 9)

    p.firstDay = at(24, 0); p.lastDay = at(25, 0)   // Saturday and Sunday
    expect("weekends are skipped", computeSlots(busy: [], p: p, now: past).isEmpty)

    p.firstDay = at(19, 0); p.lastDay = at(19, 0); p.minNoticeHours = 24
    expect("minimum notice hides slots that start too soon", computeSlots(busy: [], p: p, now: at(19, 8)).isEmpty)

    p.minNoticeHours = 0; p.perDay = 3
    let three = computeSlots(busy: [], p: p, now: past)
    expect("per-day cap spreads picks (first, middle, last)", three.count == 3 && three.first!.start == at(19, 9) && three.last!.start == at(19, 13))

    p.firstDay = at(19, 0); p.lastDay = at(23, 0); p.perDay = 3; p.maxSlots = 4
    expect("global cap is respected", computeSlots(busy: [], p: p, now: past).count == 4)

    expect("parseHours", parseHours("09:00-17:30")! == (540, 1050) && parseHours("9-17")! == (540, 1020) && parseHours("17-9") == nil)
    expect("parseDays", parseDays("Mon,Wed,Fri") == [2, 4, 6] && parseDays("Fri-Mon") == [6, 7, 1, 2] && parseDays("xyz") == nil)
    expect("ISO round trip", isoUTC(at(19, 9, 30)) == "2026-10-19T09:30:00Z" && parseISO("2026-10-19T09:30:00Z") == at(19, 9, 30))
    let rs = reservedIntervals(rules: ["Mon 12:00-13:00", "2026-10-20", "Funday 9-10"], from: at(19, 0), to: at(23, 0), tz: utc)
    expect("reserved: weekly window, single date, bad rule rejected",
           rs == nil && reservedIntervals(rules: ["Mon 12:00-13:00", "2026-10-20"], from: at(19, 0), to: at(23, 0), tz: utc)! ==
           [Interval(start: at(19, 12), end: at(19, 13)), Interval(start: at(20, 0), end: at(21, 0))])
    let rr = reservedIntervals(rules: ["2026-10-21..2026-10-22", "Fri 13:00-17:00"], from: at(19, 0), to: at(23, 0), tz: utc)!
    expect("reserved: date range covers two whole days, Friday window found",
           rr.count == 3 && rr[0] == Interval(start: at(21, 0), end: at(22, 0)) && rr[2] == Interval(start: at(23, 13), end: at(23, 17)))
    expect("relative deadline", parseLocalDateTime("+3d", tz: utc, now: at(19, 9)) == at(22, 9))
    print(failures == 0 ? "\nAll self-tests passed." : "\n\(failures) self-test(s) failed.")
    if failures > 0 { exit(2) }
}
