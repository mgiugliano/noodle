// noodle: local companion CLI for the Noodle scheduling server.
// Build:  swiftc -O client/noodle.swift -o noodle
import Foundation
import CryptoKit
import Security

// MARK: - Output helpers

func out(_ s: String) { print(s) }
func fail(_ s: String) -> Never {
    FileHandle.standardError.write(Data(("error: " + s + "\n").utf8))
    exit(1)
}

// MARK: - Config (~/.config/noodle/config.json) and Keychain secret

struct Config: Codable {
    var serverURL: String
    var timezone: String
    var busyCalendars: [String]   // calendar names that count as busy; empty = all (except Birthdays)
    var holdCalendar: String      // calendar that receives tentative holds
    var bookCalendar: String      // where a booked (confirmed) event ends up; "" = stay in holdCalendar
    var workHours: String         // "09:00-17:00"
    var days: String              // "Mon-Fri" or "Mon,Wed,Fri"
    var durationMinutes: Int
    var stepMinutes: Int
    var bufferMinutes: Int
    var minNoticeHours: Int
    var perDay: Int
    var maxSlots: Int
    var tentativeBlocks: Bool     // tentative events block time
    var allDayBlocks: Bool        // all-day events block the whole day
    var holds: Bool               // create tentative holds for proposed slots
    var onClose: String           // notify | auto_book_best | release_all
    var reserved: [String]        // time to keep free of polls, e.g. "Fri 13:00-17:00", "2026-12-24..2027-01-06"

    init(serverURL: String) {
        self.serverURL = serverURL; timezone = TimeZone.current.identifier; busyCalendars = []
        holdCalendar = "Noodle Holds"; bookCalendar = ""; workHours = "09:00-17:00"; days = "Mon-Fri"
        durationMinutes = 60; stepMinutes = 30; bufferMinutes = 15; minNoticeHours = 24
        perDay = 3; maxSlots = 12; tentativeBlocks = true; allDayBlocks = true; holds = true; onClose = "notify"; reserved = []
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        var d = Config(serverURL: try c.decode(String.self, forKey: .serverURL))
        d.timezone = try c.decodeIfPresent(String.self, forKey: .timezone) ?? d.timezone
        d.busyCalendars = try c.decodeIfPresent([String].self, forKey: .busyCalendars) ?? d.busyCalendars
        d.holdCalendar = try c.decodeIfPresent(String.self, forKey: .holdCalendar) ?? d.holdCalendar
        d.bookCalendar = try c.decodeIfPresent(String.self, forKey: .bookCalendar) ?? d.bookCalendar
        d.workHours = try c.decodeIfPresent(String.self, forKey: .workHours) ?? d.workHours
        d.days = try c.decodeIfPresent(String.self, forKey: .days) ?? d.days
        d.durationMinutes = try c.decodeIfPresent(Int.self, forKey: .durationMinutes) ?? d.durationMinutes
        d.stepMinutes = try c.decodeIfPresent(Int.self, forKey: .stepMinutes) ?? d.stepMinutes
        d.bufferMinutes = try c.decodeIfPresent(Int.self, forKey: .bufferMinutes) ?? d.bufferMinutes
        d.minNoticeHours = try c.decodeIfPresent(Int.self, forKey: .minNoticeHours) ?? d.minNoticeHours
        d.perDay = try c.decodeIfPresent(Int.self, forKey: .perDay) ?? d.perDay
        d.maxSlots = try c.decodeIfPresent(Int.self, forKey: .maxSlots) ?? d.maxSlots
        d.tentativeBlocks = try c.decodeIfPresent(Bool.self, forKey: .tentativeBlocks) ?? d.tentativeBlocks
        d.allDayBlocks = try c.decodeIfPresent(Bool.self, forKey: .allDayBlocks) ?? d.allDayBlocks
        d.holds = try c.decodeIfPresent(Bool.self, forKey: .holds) ?? d.holds
        d.onClose = try c.decodeIfPresent(String.self, forKey: .onClose) ?? d.onClose
        d.reserved = try c.decodeIfPresent([String].self, forKey: .reserved) ?? d.reserved
        self = d
    }
}

let configDir: URL = {
    if let o = ProcessInfo.processInfo.environment["NOODLE_CONFIG_DIR"] { return URL(fileURLWithPath: o) }
    return FileManager.default.homeDirectoryForCurrentUser.appendingPathComponent(".config/noodle")
}()
let configFile = configDir.appendingPathComponent("config.json")

func loadConfig() -> Config {
    guard let d = try? Data(contentsOf: configFile) else {
        fail("not initialised. Run: noodle init --url https://your.site/noodle")
    }
    do { return try JSONDecoder().decode(Config.self, from: d) }
    catch {
        fail("cannot read \(configFile.path): it is not valid JSON or has a wrongly typed value. Check quotes and commas (no comma after the last item). Details: \(error.localizedDescription)")
    }
}

func saveConfig(_ c: Config) {
    do {
        try FileManager.default.createDirectory(at: configDir, withIntermediateDirectories: true,
                                                attributes: [.posixPermissions: 0o700])
        let enc = JSONEncoder(); enc.outputFormatting = [.prettyPrinted, .sortedKeys]
        try enc.encode(c).write(to: configFile, options: .atomic)
        try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: configFile.path)
    } catch { fail("cannot write config: \(error.localizedDescription)") }
}

let keychainService = "noodle-api-secret"

func keychainGet(account: String) -> String? {
    let q: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: keychainService,
                            kSecAttrAccount as String: account, kSecReturnData as String: true,
                            kSecMatchLimit as String: kSecMatchLimitOne]
    var item: CFTypeRef?
    guard SecItemCopyMatching(q as CFDictionary, &item) == errSecSuccess, let d = item as? Data else { return nil }
    return String(data: d, encoding: .utf8)
}

func keychainSet(account: String, value: String) {
    let base: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: keychainService,
                               kSecAttrAccount as String: account]
    SecItemDelete(base as CFDictionary)
    var add = base
    add[kSecValueData as String] = Data(value.utf8)
    add[kSecAttrAccessible as String] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly
    let st = SecItemAdd(add as CFDictionary, nil)
    if st != errSecSuccess { fail("Keychain write failed (\(st))") }
}

func randomHex(_ bytes: Int) -> String {
    var b = [UInt8](repeating: 0, count: bytes)
    guard SecRandomCopyBytes(kSecRandomDefault, bytes, &b) == errSecSuccess else { fail("no secure randomness") }
    return b.map { String(format: "%02x", $0) }.joined()
}

func randomAlnum(_ n: Int) -> String {
    let chars = Array("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789")
    var b = [UInt8](repeating: 0, count: n)
    _ = SecRandomCopyBytes(kSecRandomDefault, n, &b)
    return String(b.map { chars[Int($0) % chars.count] })
}

// MARK: - Signed API client

struct APIResponse { let status: Int; let json: [String: Any]?; let headers: [String: String] }

func hostKey(_ url: String) -> String { URL(string: url)?.host ?? url }

func httpSend(_ req: URLRequest) -> (Data?, HTTPURLResponse?) {
    let sem = DispatchSemaphore(value: 0)
    var result: (Data?, HTTPURLResponse?) = (nil, nil)
    let cfg = URLSessionConfiguration.ephemeral
    cfg.timeoutIntervalForRequest = 20
    let session = URLSession(configuration: cfg)
    session.dataTask(with: req) { d, r, _ in result = (d, r as? HTTPURLResponse); sem.signal() }.resume()
    sem.wait()
    return result
}

func api(_ cfg: Config, _ payload: [String: Any], secretOverride: String? = nil,
         nonce: String? = nil, timestamp: Int? = nil) -> APIResponse {
    guard let secret = secretOverride ?? keychainGet(account: hostKey(cfg.serverURL)) else {
        fail("no secret in Keychain for \(hostKey(cfg.serverURL)). Run init again.")
    }
    guard let body = try? JSONSerialization.data(withJSONObject: payload, options: [.sortedKeys]) else { fail("bad payload") }
    let ts = timestamp ?? Int(Date().timeIntervalSince1970)
    let nonce = nonce ?? randomAlnum(24)
    let bodyHash = SHA256.hash(data: body).map { String(format: "%02x", $0) }.joined()
    let msg = Data("noodle-v1\n\(ts)\n\(nonce)\n\(bodyHash)".utf8)
    let mac = HMAC<SHA256>.authenticationCode(for: msg, using: SymmetricKey(data: Data(secret.utf8)))
    let sig = mac.map { String(format: "%02x", $0) }.joined()

    var req = URLRequest(url: URL(string: cfg.serverURL.trimmingCharacters(in: CharacterSet(charactersIn: "/")) + "/api.php")!)
    req.httpMethod = "POST"
    req.httpBody = body
    req.setValue("application/json", forHTTPHeaderField: "Content-Type")
    req.setValue(String(ts), forHTTPHeaderField: "X-Noodle-Timestamp")
    req.setValue(nonce, forHTTPHeaderField: "X-Noodle-Nonce")
    req.setValue(sig, forHTTPHeaderField: "X-Noodle-Signature")
    let (data, resp) = httpSend(req)
    guard let resp else { fail("cannot reach \(cfg.serverURL)") }
    var hdrs: [String: String] = [:]
    for (k, v) in resp.allHeaderFields { hdrs[String(describing: k).lowercased()] = String(describing: v) }
    let json = data.flatMap { try? JSONSerialization.jsonObject(with: $0) as? [String: Any] }
    return APIResponse(status: resp.statusCode, json: json, headers: hdrs)
}

func plainGET(_ url: String) -> (Int, String)? {
    guard let u = URL(string: url) else { return nil }
    let (d, r) = httpSend(URLRequest(url: u))
    guard let r else { return nil }
    return (r.statusCode, String(data: d ?? Data(), encoding: .utf8) ?? "")
}

// MARK: - Commands

func arg(_ name: String, in args: [String]) -> String? {
    guard let i = args.firstIndex(of: name), i + 1 < args.count else { return nil }
    return args[i + 1]
}

/// Build dist/noodle/: public files + app/ + generated config.php (no test files, no data).
func assembleDist(repo: URL, secret: String, includeConfig: Bool = true) {
    let serverDir = repo.appendingPathComponent("server")
    let fm = FileManager.default
    guard fm.fileExists(atPath: serverDir.appendingPathComponent("public/api.php").path) else {
        fail("run this from the Noodle project folder, or pass --repo PATH")
    }
    let dist = repo.appendingPathComponent("dist/noodle")
    try? fm.removeItem(at: dist)
    do {
        try fm.createDirectory(at: dist, withIntermediateDirectories: true)
        for name in try fm.contentsOfDirectory(atPath: serverDir.appendingPathComponent("public").path)
        where name != "check.php" && name != "_probe" {
            try fm.copyItem(at: serverDir.appendingPathComponent("public/\(name)"), to: dist.appendingPathComponent(name))
        }
        try fm.copyItem(at: serverDir.appendingPathComponent("app"), to: dist.appendingPathComponent("app"))
        let phpConfig = """
        <?php
        // Generated by `noodle init` / `noodle build`. Keep private. Do not commit.
        return [
            'secret'   => '\(secret)',
            'data_dir' => __DIR__ . '/../data',
            'max_body' => 262144,
        ];

        """
        if includeConfig {
            let cfgPath = dist.appendingPathComponent("app/config.php")
            try phpConfig.write(to: cfgPath, atomically: true, encoding: .utf8)
            try fm.setAttributes([.posixPermissions: 0o600], ofItemAtPath: cfgPath.path)
        }
    } catch { fail("cannot build upload folder: \(error.localizedDescription)") }
}

func cmdBuild(_ args: [String]) {
    let cfg = loadConfig()
    guard let secret = keychainGet(account: hostKey(cfg.serverURL)) else { fail("no secret in Keychain; run `noodle init` first") }
    let repo = URL(fileURLWithPath: arg("--repo", in: args) ?? FileManager.default.currentDirectoryPath)
    let withConfig = !args.contains("--no-config")
    assembleDist(repo: repo, secret: secret, includeConfig: withConfig)
    out(withConfig ? "Upload folder rebuilt (same secret, nothing to re-configure):" : "Upload folder rebuilt WITHOUT app/config.php (your server copy stays as it is):")
    out("  \(repo.appendingPathComponent("dist/noodle").path)")
    out("Upload its CONTENTS to your server's noodle/ folder, overwriting existing files. Then run: noodle doctor")
}

/// init: create the secret, store it in the Keychain, write config.json, and build a ready-to-upload folder.
func cmdInit(_ args: [String]) {
    guard var url = arg("--url", in: args) else { fail("usage: noodle init --url https://your.site/noodle [--repo PATH] [--force]") }
    url = url.trimmingCharacters(in: CharacterSet(charactersIn: "/ "))
    guard let u = URL(string: url), let host = u.host else { fail("invalid URL") }
    let local = host == "127.0.0.1" || host == "localhost"
    guard u.scheme == "https" || local else { fail("the server URL must use https://") }

    let repo = URL(fileURLWithPath: arg("--repo", in: args) ?? FileManager.default.currentDirectoryPath)
    let serverDir = repo.appendingPathComponent("server")
    guard FileManager.default.fileExists(atPath: serverDir.appendingPathComponent("public/api.php").path) else {
        fail("run this from the Noodle project folder, or pass --repo PATH")
    }
    if keychainGet(account: host) != nil && !args.contains("--force") {
        fail("a secret for \(host) already exists. Use --force to replace it (the uploaded config.php must then be replaced too).")
    }
    let secret = randomHex(32)
    keychainSet(account: host, value: secret)
    // Keep the user's existing settings (calendars, reserved times...); only the server address changes.
    if let d = try? Data(contentsOf: configFile), var existing = try? JSONDecoder().decode(Config.self, from: d) {
        existing.serverURL = url
        saveConfig(existing)
    } else {
        saveConfig(Config(serverURL: url))
    }

    assembleDist(repo: repo, secret: secret)

    out("Initialised for \(url)")
    out("Secret stored in your macOS Keychain (service \"\(keychainService)\").")
    out("")
    out("Upload the CONTENTS of this folder to your server's noodle/ directory (FTP):")
    out("  \(repo.appendingPathComponent("dist/noodle").path)")
    out("Then run:  noodle doctor")
}

func cmdPing() {
    let cfg = loadConfig()
    let r = api(cfg, ["action": "ping"])
    if r.status == 200 { out("OK: signed request accepted by \(cfg.serverURL)") }
    else { fail("server answered \(r.status): \(r.json?["error"] ?? "?")") }
}

/// doctor: end-to-end security and deployment self-check against the live site.
func cmdDoctor() {
    let cfg = loadConfig()
    let base = cfg.serverURL
    var bad = 0
    func check(_ name: String, _ ok: Bool, _ hint: String = "") {
        out((ok ? "  [ ok ] " : "  [FAIL] ") + name + (ok || hint.isEmpty ? "" : "\n           " + hint))
        if !ok { bad += 1 }
    }
    out("Noodle doctor for \(base)\n")
    check("Uses HTTPS", base.hasPrefix("https://") || base.contains("127.0.0.1") || base.contains("localhost"),
          "Serve the site over HTTPS only.")

    let ping = api(cfg, ["action": "ping"])
    check("Signed request accepted", ping.status == 200, "status \(ping.status): wrong/missing config.php on the server?")
    check("Wrong secret is rejected", api(cfg, ["action": "ping"], secretOverride: String(repeating: "0", count: 64)).status == 401)
    let n = randomAlnum(24)
    _ = api(cfg, ["action": "ping"], nonce: n)
    check("Replayed request is rejected", api(cfg, ["action": "ping"], nonce: n).status == 401, "Nonce store is not working.")
    check("Stale timestamp is rejected",
          api(cfg, ["action": "ping"], timestamp: Int(Date().timeIntervalSince1970) - 4000).status == 401)
    let h = ping.headers
    check("Security headers set", h["content-security-policy"] != nil && h["x-content-type-options"] == "nosniff" && h["referrer-policy"] == "no-referrer",
          "Missing CSP / nosniff / Referrer-Policy.")

    if let g = plainGET(base + "/api.php") { check("api.php refuses GET", g.0 == 405, "status \(g.0)") }
    else { check("api.php reachable", false) }

    // Nothing sensitive may be fetchable.
    let list = api(cfg, ["action": "list_polls"])
    var leaks: [String] = []
    for path in ["/app/config.php", "/app/Store.php", "/data/.htaccess", "/data/nonces/"] {
        if let g = plainGET(base + path) {
            let exposed = g.0 == 200 && !g.1.isEmpty
            if exposed { leaks.append("\(path) -> 200 with content") }
        }
    }
    check("app/ and data/ are not downloadable", leaks.isEmpty, leaks.joined(separator: "; "))

    // If polls exist, their data files must not leak JSON.
    if let polls = list.json?["polls"] as? [[String: Any]], let slug = polls.first?["slug"] as? String {
        let g = plainGET(base + "/data/poll_\(slug).json.php")
        let leaks = g.map { $0.0 == 200 && $0.1.contains("\"title\"") } ?? false
        check("Poll data file does not leak when requested directly", !leaks, "Move data/ out of reach or fix .htaccess.")
    }
    out("")
    if bad == 0 { out("All checks passed.") } else { out("\(bad) check(s) failed."); exit(2) }
}

// MARK: - Entry point

let args = Array(CommandLine.arguments.dropFirst())
switch args.first {
case "init": cmdInit(Array(args.dropFirst()))
case "ping": cmdPing()
case "build": cmdBuild(Array(args.dropFirst()))
case "doctor": cmdDoctor()
case "config": cmdConfig()
case "calendars": cmdCalendars()
case "new": cmdNew(Array(args.dropFirst()))
case "busy": cmdBusy(Array(args.dropFirst()))
case "polls": cmdPolls()
case "selftest": cmdSelfTest()
case "results": cmdResults(Array(args.dropFirst()))
case "close": cmdClose(Array(args.dropFirst()))
case "delete-vote": cmdDeleteVote(Array(args.dropFirst()))
case "delete": cmdDelete(Array(args.dropFirst()))
case "book": cmdBook(Array(args.dropFirst()))
case "release": cmdRelease(Array(args.dropFirst()))
case "sync": cmdSync()
case "agent": cmdAgent(Array(args.dropFirst()))
default:
    out("""
    noodle: calendar-driven scheduling polls

      noodle init --url https://your.site/noodle [--repo PATH] [--force]
      noodle build [--repo PATH] [--no-config]   rebuild the upload folder after updates (keeps your secret)
      noodle ping
      noodle doctor
      noodle config                       show settings (and write defaults to the config file)
      noodle calendars                    list calendars Noodle can see
      noodle busy 2026-10-15 [2026-10-16] show what blocks time on those days, and why
      noodle new --title "..." [--from YYYY-MM-DD --to YYYY-MM-DD --duration 60 --hours 09:00-17:00
                 --days Mon-Fri --calendars "Work,Home" --reserve "Fri 13:00-17:00,2026-10-21" --deadline "+3d" --max-votes 8
                 --description "..." --no-maybe --hide-votes --no-holds --no-reserved --unreserve "rule" --dry-run --yes]
      noodle polls                        list your polls on the server
      noodle results <poll> [--csv]       who voted what; best slot marked   (<poll> = slug, unique prefix, or "latest")
      noodle close <poll>                 stop accepting votes
      noodle book <poll> <slot#|best>     confirm the winner, delete every other hold, close the poll
      noodle release <poll>               delete all holds of a poll without booking
      noodle delete-vote <poll> <name|id> remove one response
      noodle delete <poll>                delete the poll from the server (and its holds)
      noodle sync                         settle closed polls (run automatically by the agent)
      noodle agent install|status|uninstall|print   background agent that runs `sync` every 15 minutes
      noodle selftest                     test the slot finder on synthetic data
    """)
}
