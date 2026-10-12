import Foundation
import EventKit

let eventStore = EKEventStore()

func requestCalendarAccess() {
    let sem = DispatchSemaphore(value: 0)
    var granted = false
    eventStore.requestFullAccessToEvents { ok, _ in granted = ok; sem.signal() }
    if sem.wait(timeout: .now() + 120) == .timedOut {
        fail("timed out waiting for the Calendar permission prompt")
    }
    if !granted {
        fail("Calendar access denied. Allow your terminal app under System Settings > Privacy & Security > Calendars.")
    }
}

func calendarTypeName(_ t: EKCalendarType) -> String {
    switch t {
    case .local: return "local"
    case .calDAV: return "CalDAV/iCloud"
    case .exchange: return "Exchange"
    case .subscription: return "subscribed (read-only)"
    case .birthday: return "birthdays"
    @unknown default: return "other"
    }
}

func cmdCalendars() {
    requestCalendarAccess()
    let cals = eventStore.calendars(for: .event).sorted { ($0.source.title, $0.title) < ($1.source.title, $1.title) }
    print("Calendars visible to Noodle:\n")
    for c in cals {
        let rw = c.allowsContentModifications ? "writable" : "read-only"
        print("  \(c.title)   [\(c.source.title), \(calendarTypeName(c.type)), \(rw)]")
    }
    print("\nPut the names you want treated as BUSY into \"busyCalendars\" in \(configFile.path)")
}

/// Busy time from the chosen calendars (plus the hold calendar, so overlapping polls do not double-book).
func busyIntervals(cfg: Config, from: Date, to: Date, calendarNames: [String]?) -> [Interval] {
    let names = calendarNames ?? cfg.busyCalendars
    var cals = eventStore.calendars(for: .event)
    if names.isEmpty {
        cals = cals.filter { $0.type != .birthday }
    } else {
        let known = Set(cals.map { $0.title })
        for n in names where !known.contains(n) { FileHandle.standardError.write(Data("warning: no calendar named \"\(n)\"\n".utf8)) }
        cals = cals.filter { names.contains($0.title) || $0.title == cfg.holdCalendar }
    }
    guard !cals.isEmpty else { fail("no calendars selected") }

    let pred = eventStore.predicateForEvents(withStart: from, end: to, calendars: cals)
    var out: [Interval] = []
    for ev in eventStore.events(matching: pred) {
        if ev.status == .canceled { continue }
        if ev.availability == .free { continue }
        if ev.availability == .tentative && !cfg.tentativeBlocks && ev.calendar.title != cfg.holdCalendar { continue }
        if ev.attendees?.contains(where: { $0.isCurrentUser && $0.participantStatus == .declined }) == true { continue }
        if ev.isAllDay && !cfg.allDayBlocks { continue }
        out.append(Interval(start: ev.startDate, end: ev.endDate))
    }
    return out
}

func ensureHoldCalendar(_ name: String) -> EKCalendar {
    if let c = eventStore.calendars(for: .event).first(where: { $0.title == name && $0.allowsContentModifications }) { return c }
    let cal = EKCalendar(for: .event, eventStore: eventStore)
    cal.title = name
    guard let src = eventStore.defaultCalendarForNewEvents?.source
            ?? eventStore.sources.first(where: { $0.sourceType == .local }) else { fail("no calendar account to hold the \"\(name)\" calendar") }
    cal.source = src
    do { try eventStore.saveCalendar(cal, commit: true) } catch { fail("cannot create calendar \"\(name)\": \(error.localizedDescription)") }
    return cal
}

func createHold(calendar: EKCalendar, title: String, slot: Interval, notes: String) throws -> String {
    let ev = EKEvent(eventStore: eventStore)
    ev.calendar = calendar
    ev.title = "HOLD: " + title
    ev.startDate = slot.start
    ev.endDate = slot.end
    ev.availability = .tentative
    ev.notes = notes
    try eventStore.save(ev, span: .thisEvent, commit: true)
    return ev.eventIdentifier
}

// MARK: - Settling holds

func deleteEvent(id: String) -> Bool {
    guard let ev = eventStore.event(withIdentifier: id) else { return false }
    do { try eventStore.remove(ev, span: .thisEvent, commit: true); return true } catch { return false }
}

/// Turn a tentative hold into a confirmed event (optionally moving it to another calendar).
func confirmEvent(id: String, title: String, notes: String, moveTo calendarName: String) -> Bool {
    guard let ev = eventStore.event(withIdentifier: id) else { return false }
    ev.title = title
    ev.availability = .busy
    ev.notes = notes
    if !calendarName.isEmpty, let cal = eventStore.calendars(for: .event).first(where: { $0.title == calendarName && $0.allowsContentModifications }) {
        ev.calendar = cal
    }
    do { try eventStore.save(ev, span: .thisEvent, commit: true); return true } catch { return false }
}
