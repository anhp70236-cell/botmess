// OffsetDumper - app iOS: chọn IPA game -> dump offset (máu, nhiệm vụ, người chơi, xe, bản đồ)
// Đọc IPA kiểu streaming (không nạp cả file vào RAM), giải nén binary Mach-O, đọc bảng symbol + chuỗi.
import UIKit
import UniformTypeIdentifiers
import Compression

@_silgen_name("__cxa_demangle")
func cxa_demangle(_ mangled: UnsafePointer<CChar>?, _ out: UnsafeMutablePointer<CChar>?,
                  _ len: UnsafeMutablePointer<Int>?, _ status: UnsafeMutablePointer<Int32>?) -> UnsafeMutablePointer<CChar>?

func demangle(_ s: String) -> String {
    var n = s
    if n.hasPrefix("_") { n.removeFirst() }
    guard n.hasPrefix("_Z") else { return n }
    var st: Int32 = 0
    if let p = cxa_demangle(n, nil, nil, &st), st == 0 {
        let r = String(cString: p); free(p); return r
    }
    return n
}

// ======================= ZIP (đọc ngẫu nhiên, hỗ trợ zip64) =======================
func zu16(_ a: [UInt8], _ o: Int) -> UInt16 { UInt16(a[o]) | UInt16(a[o+1]) << 8 }
func zu32(_ a: [UInt8], _ o: Int) -> UInt32 { UInt32(zu16(a, o)) | UInt32(zu16(a, o+2)) << 16 }
func zu64(_ a: [UInt8], _ o: Int) -> UInt64 { UInt64(zu32(a, o)) | UInt64(zu32(a, o+4)) << 32 }

struct ZipEntry { let name: String; let method: UInt16; let comp: UInt64; let size: UInt64; let localOff: UInt64 }

final class ZipReader {
    let fd: Int32
    let size: UInt64
    init?(path: String) {
        let f = open(path, O_RDONLY)
        if f < 0 { return nil }
        var st = stat()
        fstat(f, &st)
        fd = f
        size = UInt64(st.st_size)
    }
    deinit { close(fd) }

    func readInto(_ off: UInt64, _ buf: inout [UInt8], _ n: Int) -> Bool {
        var done = 0
        while done < n {
            let r = buf.withUnsafeMutableBytes { pread(fd, $0.baseAddress! + done, n - done, off_t(off) + off_t(done)) }
            if r <= 0 { return false }
            done += r
        }
        return true
    }
    func read(_ off: UInt64, _ n: Int) -> [UInt8]? {
        var b = [UInt8](repeating: 0, count: n)
        return readInto(off, &b, n) ? b : nil
    }

    func entries() -> [ZipEntry]? {
        let tail = Int(min(size, 65535 + 22 + 64))
        guard let t = read(size - UInt64(tail), tail) else { return nil }
        var e = -1
        var i = tail - 22
        while i >= 0 {
            if t[i] == 0x50 && t[i+1] == 0x4b && t[i+2] == 5 && t[i+3] == 6 { e = i; break }
            i -= 1
        }
        guard e >= 0 else { return nil }
        var total = UInt64(zu16(t, e + 10))
        var cdSize = UInt64(zu32(t, e + 12))
        var cdOff = UInt64(zu32(t, e + 16))
        if cdOff == 0xFFFFFFFF || total == 0xFFFF || cdSize == 0xFFFFFFFF {
            if e >= 20, zu32(t, e - 20) == 0x07064b50 {
                let z64 = zu64(t, e - 20 + 8)
                if let z = read(z64, 56), zu32(z, 0) == 0x06064b50 {
                    total = zu64(z, 32); cdSize = zu64(z, 40); cdOff = zu64(z, 48)
                }
            }
        }
        guard let cd = read(cdOff, Int(cdSize)) else { return nil }
        var res = [ZipEntry]()
        var p = 0
        while p + 46 <= cd.count, zu32(cd, p) == 0x02014b50 {
            let method = zu16(cd, p + 10)
            var comp = UInt64(zu32(cd, p + 20))
            var unc = UInt64(zu32(cd, p + 24))
            let nl = Int(zu16(cd, p + 28)), xl = Int(zu16(cd, p + 30)), cl = Int(zu16(cd, p + 32))
            var lo = UInt64(zu32(cd, p + 42))
            let name = String(decoding: cd[(p + 46)..<(p + 46 + nl)], as: UTF8.self)
            var x = p + 46 + nl
            let xend = x + xl
            while x + 4 <= xend {
                let id = zu16(cd, x), sz = Int(zu16(cd, x + 2))
                var q = x + 4
                if id == 1 {
                    if unc == 0xFFFFFFFF { unc = zu64(cd, q); q += 8 }
                    if comp == 0xFFFFFFFF { comp = zu64(cd, q); q += 8 }
                    if lo == 0xFFFFFFFF { lo = zu64(cd, q); q += 8 }
                }
                x += 4 + sz
            }
            res.append(ZipEntry(name: name, method: method, comp: comp, size: unc, localOff: lo))
            p += 46 + nl + xl + cl
        }
        return res
    }

    func extract(_ e: ZipEntry, to url: URL, progress: ((Double) -> Void)? = nil) -> Bool {
        guard let lh = read(e.localOff, 30), zu32(lh, 0) == 0x04034b50 else { return false }
        let nl = Int(zu16(lh, 26)), xl = Int(zu16(lh, 28))
        var pos = e.localOff + 30 + UInt64(nl + xl)
        FileManager.default.createFile(atPath: url.path, contents: nil)
        guard let out = FileHandle(forWritingAtPath: url.path) else { return false }
        defer { try? out.close() }
        let bufSize = 1 << 20
        var src = [UInt8](repeating: 0, count: bufSize)
        var remaining = e.comp
        let total = max(e.comp, 1)

        if e.method == 0 {
            while remaining > 0 {
                let n = Int(min(UInt64(bufSize), remaining))
                guard readInto(pos, &src, n) else { return false }
                out.write(Data(src[0..<n]))
                pos += UInt64(n); remaining -= UInt64(n)
                progress?(1 - Double(remaining) / Double(total))
            }
            return true
        }
        guard e.method == 8 else { return false }

        var dst = [UInt8](repeating: 0, count: bufSize)
        let dummy = UnsafeMutablePointer<UInt8>.allocate(capacity: 1)
        defer { dummy.deallocate() }
        var stream = compression_stream(dst_ptr: dummy, dst_size: 0, src_ptr: UnsafePointer(dummy), src_size: 0, state: nil)
        guard compression_stream_init(&stream, COMPRESSION_STREAM_DECODE, COMPRESSION_ZLIB) == COMPRESSION_STATUS_OK else { return false }
        defer { compression_stream_destroy(&stream) }
        var status = COMPRESSION_STATUS_OK
        repeat {
            let n = Int(min(UInt64(bufSize), remaining))
            guard readInto(pos, &src, n) else { return false }
            pos += UInt64(n); remaining -= UInt64(n)
            let flags: Int32 = remaining == 0 ? Int32(COMPRESSION_STREAM_FINALIZE.rawValue) : 0
            src.withUnsafeBufferPointer { sp in
                stream.src_ptr = sp.baseAddress!
                stream.src_size = n
                repeat {
                    dst.withUnsafeMutableBufferPointer { dp in
                        stream.dst_ptr = dp.baseAddress!
                        stream.dst_size = bufSize
                        status = compression_stream_process(&stream, flags)
                        let produced = bufSize - stream.dst_size
                        if produced > 0 { out.write(Data(bytes: dp.baseAddress!, count: produced)) }
                    }
                } while status == COMPRESSION_STATUS_OK && (stream.src_size > 0 || stream.dst_size == 0)
            }
            if status == COMPRESSION_STATUS_ERROR { return false }
            progress?(1 - Double(remaining) / Double(total))
        } while remaining > 0 && status != COMPRESSION_STATUS_END
        return true
    }
}

// ======================= Mach-O =======================
struct Rd {
    let p: UnsafeRawBufferPointer
    var count: Int { p.count }
    func u8(_ o: Int) -> UInt8 { o >= 0 && o < p.count ? p[o] : 0 }
    func u32(_ o: Int) -> UInt32 { o >= 0 && o + 4 <= p.count ? p.loadUnaligned(fromByteOffset: o, as: UInt32.self) : 0 }
    func u64(_ o: Int) -> UInt64 { o >= 0 && o + 8 <= p.count ? p.loadUnaligned(fromByteOffset: o, as: UInt64.self) : 0 }
    func be32(_ o: Int) -> UInt32 { u32(o).byteSwapped }
    func cstr(_ o: Int, max: Int = 8192) -> String {
        guard o >= 0 && o < p.count else { return "" }
        var e = o
        let lim = Swift.min(p.count, o + max)
        while e < lim && p[e] != 0 { e += 1 }
        return String(decoding: UnsafeRawBufferPointer(rebasing: p[o..<e]), as: UTF8.self)
    }
}

struct Seg { let name: String; let vm: UInt64; let vmsize: UInt64; let fo: UInt64 }
struct Sym { let va: UInt64; let fo: UInt64; let name: String }
struct MachoResult { var syms: [Sym] = []; var strs: [Sym] = []; var cryptid: UInt32 = 0; var nsymTotal = 0 }

// ======================= Phân loại =======================
let excludeKW = ["purchase", "storekit", "iap", "inapp", "in_app", "billing", "payment", "transaction",
                 "receipt", "skproduct", "skpayment", "productid", "product_id", "realmoney", "real_money", "subscription"]
let catTitles = ["### MAU / SUC KHOE / SAT THUONG", "### NHIEM VU / SCRIPT / MARKER",
                 "### NGUOI CHOI / TEN / NHAN VAT", "### XE", "### BAN DO / THE GIOI"]
let catKW: [[String]] = [
    ["health", "armour", "armor", "damage", "heal", "wasted", "busted", "revive", "blood", "injur", "dead", "kill"],
    ["mission", "thescripts", "runningscript", "objective", "checkpoint", "marker", "brief", "blip", "quest",
     "cutscene", "reward", "highlight", "cmessages", "setpiece"],
    ["playerinfo", "playerped", "findplayer", "player", "username", "nickname", "profile", "pedgroup", "savegame", "genericgamestorage"],
    ["cvehicle", "cautomobile", "cbike", "cboat", "cheli", "cplane", "ctrain", "cargenerator", "vehicle",
     "transmission", "chandling", "handling", "cbmx", "cquadbike"],
    ["cworld", "cpathfind", "cradar", "cwaterlevel", "cthezones", "czone", "cstreaming", "centryexit", "interior",
     "cmap", "waypoint", "teleport", "ccullzones", "cpopulation", "ctimecycle", "cweather"]
]
func isExcluded(_ l: String) -> Bool { excludeKW.contains { l.contains($0) } }
func classify(_ l: String) -> Int? {
    for (i, kws) in catKW.enumerated() where kws.contains(where: { l.contains($0) }) { return i }
    return nil
}

func analyze(_ url: URL, wantStrings: Bool) -> MachoResult? {
    guard let data = try? Data(contentsOf: url, options: .alwaysMapped) else { return nil }
    return data.withUnsafeBytes { raw -> MachoResult? in
        let r = Rd(p: raw)
        guard r.count > 64 else { return nil }
        var sb = 0
        var magic = r.u32(0)
        if magic == 0xBEBAFECA {
            let n = Int(r.be32(4)); var chosen = -1
            for i in 0..<n {
                let b = 8 + i * 20
                let cpu = r.be32(b); let off = Int(r.be32(b + 8))
                if cpu == 0x0100000C { chosen = off; break }
                if chosen < 0 { chosen = off }
            }
            guard chosen >= 0 else { return nil }
            sb = chosen; magic = r.u32(sb)
        }
        guard magic == 0xFEEDFACF else { return nil }
        let ncmds = Int(r.u32(sb + 16))
        var off = sb + 32
        var segs = [Seg]()
        var cstrs = [(addr: UInt64, size: UInt64, off: UInt64)]()
        var symoff = 0, nsyms = 0, stroff = 0
        var res = MachoResult()
        for _ in 0..<ncmds {
            guard off + 8 <= r.count else { break }
            let cmd = r.u32(off), sz = Int(r.u32(off + 4))
            if cmd == 0x19 {
                segs.append(Seg(name: r.cstr(off + 8, max: 16), vm: r.u64(off + 24), vmsize: r.u64(off + 32), fo: r.u64(off + 40)))
                let nsec = Int(r.u32(off + 64)); var so = off + 72
                for _ in 0..<nsec {
                    if r.cstr(so, max: 16) == "__cstring" { cstrs.append((r.u64(so + 32), r.u64(so + 40), UInt64(r.u32(so + 48)))) }
                    so += 80
                }
            } else if cmd == 0x2 {
                symoff = Int(r.u32(off + 8)); nsyms = Int(r.u32(off + 12)); stroff = Int(r.u32(off + 16))
            } else if cmd == 0x2C {
                res.cryptid = r.u32(off + 16)
            }
            if sz <= 0 { break }
            off += sz
        }
        func foff(_ va: UInt64) -> UInt64 {
            for s in segs where va >= s.vm && va < s.vm + s.vmsize { return va - s.vm + s.fo }
            return 0
        }
        res.nsymTotal = nsyms
        for i in 0..<nsyms {
            let e = sb + symoff + i * 16
            let type = r.u8(e + 4)
            if type & 0xE0 != 0 || type & 0x0E != 0x0E { continue }
            let val = r.u64(e + 8)
            if val == 0 { continue }
            let raw = r.cstr(sb + stroff + Int(r.u32(e)))
            if raw.isEmpty { continue }
            res.syms.append(Sym(va: val, fo: foff(val), name: demangle(raw)))
        }
        if wantStrings && res.cryptid == 0 {
            for c in cstrs {
                var i = Int(c.off), end = Int(c.off + c.size)
                end = min(end + sb, r.count); i += sb
                var start = i
                while i < end {
                    if r.u8(i) == 0 {
                        let len = i - start
                        if len >= 4 && len <= 100 {
                            let s = r.cstr(start, max: 101)
                            if s.utf8.allSatisfy({ $0 >= 32 && $0 < 127 }) {
                                let l = s.lowercased()
                                if !isExcluded(l), classify(l) != nil {
                                    let rel = UInt64(start - sb)
                                    res.strs.append(Sym(va: c.addr + (rel - c.off), fo: rel, name: s))
                                }
                            }
                        }
                        start = i + 1
                    }
                    i += 1
                }
            }
        }
        return res
    }
}

func hex(_ v: UInt64) -> String { "0x" + String(v, radix: 16, uppercase: true) }

func render(binary: String, r: MachoResult, all: Bool) -> (filtered: String, everything: String?) {
    var groups = [[Sym]](repeating: [], count: 5)
    var rest = [Sym]()
    for s in r.syms {
        let l = s.name.lowercased()
        if isExcluded(l) { continue }
        if let c = classify(l) { groups[c].append(s) } else if all { rest.append(s) }
    }
    var out = "OFFSET DUMP - \(binary)\n"
    out += "Format: VA | file offset (trong slice) | ten\n"
    out += "Symbol tong: \(r.nsymTotal) | khop bo loc: \(groups.reduce(0) { $0 + $1.count })\n"
    if r.cryptid != 0 {
        out += "CANH BAO: binary con ma hoa FairPlay (cryptid=1). Ten ham/offset van dung nhung chuoi khong doc duoc; nen dung IPA da decrypt.\n"
    }
    out += "Luu y: offset FIELD (vd m_fHealth trong CPed) KHONG co trong symbol, tool nay khong dung duoc.\n"
    out += "Da loai bo cac muc mua bang tien that (purchase/storekit/iap/billing/payment...).\n"
    out += String(repeating: "=", count: 70) + "\n"
    for i in 0..<5 {
        out += "\n" + catTitles[i] + " (\(groups[i].count))\n"
        for s in groups[i].sorted(by: { $0.va < $1.va }) { out += "\(hex(s.va)) | \(hex(s.fo)) | \(s.name)\n" }
    }
    if !r.strs.isEmpty {
        out += "\n### CHUOI (string) LIEN QUAN (\(r.strs.count))\n"
        for s in r.strs.prefix(5000) { out += "\(hex(s.va)) | \(hex(s.fo)) | \(s.name)\n" }
    }
    var every: String? = nil
    if all {
        var e = "TAT CA SYMBOL (khong gom muc mua tien that) - \(binary)\n"
        for s in (groups.flatMap { $0 } + rest).sorted(by: { $0.va < $1.va }) { e += "\(hex(s.va)) | \(hex(s.fo)) | \(s.name)\n" }
        every = e
    }
    return (out, every)
}

// ======================= UI =======================
@main
final class AppDelegate: UIResponder, UIApplicationDelegate {
    var window: UIWindow?
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        window = UIWindow(frame: UIScreen.main.bounds)
        window?.rootViewController = MainVC()
        window?.makeKeyAndVisible()
        return true
    }
}

final class MainVC: UIViewController, UIDocumentPickerDelegate {
    let pickBtn = UIButton(type: .system)
    let shareBtn = UIButton(type: .system)
    let sw = UISwitch()
    let bar = UIProgressView(progressViewStyle: .default)
    let tv = UITextView()
    var outputs = [URL]()

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = UIColor(red: 0.17, green: 0.19, blue: 0.22, alpha: 1)
        let title = UILabel(); title.text = "Offset Dump"; title.textColor = .white
        title.font = .boldSystemFont(ofSize: 24)

        pickBtn.setTitle("Chọn IPA & Dump", for: .normal)
        pickBtn.backgroundColor = UIColor(red: 0.36, green: 0.56, blue: 0.82, alpha: 1)
        pickBtn.setTitleColor(.white, for: .normal); pickBtn.layer.cornerRadius = 12
        pickBtn.titleLabel?.font = .boldSystemFont(ofSize: 17)
        pickBtn.addTarget(self, action: #selector(pick), for: .touchUpInside)

        shareBtn.setTitle("Chia sẻ / Lưu kết quả", for: .normal)
        shareBtn.addTarget(self, action: #selector(share), for: .touchUpInside)

        let swl = UILabel(); swl.text = "Xuất cả file ALL symbol (không lọc)"; swl.textColor = .lightGray
        swl.font = .systemFont(ofSize: 14)
        let swRow = UIStackView(arrangedSubviews: [swl, sw]); swRow.spacing = 8

        tv.isEditable = false; tv.backgroundColor = UIColor(red: 0.12, green: 0.14, blue: 0.17, alpha: 1)
        tv.textColor = UIColor(red: 0.78, green: 0.8, blue: 0.84, alpha: 1)
        tv.font = .monospacedSystemFont(ofSize: 12, weight: .regular); tv.layer.cornerRadius = 10
        tv.text = "Chọn file IPA (game đã decrypt). Kết quả lưu trong Files > On My iPhone > Offset Dump.\n"

        let stack = UIStackView(arrangedSubviews: [title, pickBtn, swRow, bar, shareBtn, tv])
        stack.axis = .vertical; stack.spacing = 12
        stack.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(stack)
        NSLayoutConstraint.activate([
            stack.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor, constant: 12),
            stack.leadingAnchor.constraint(equalTo: view.leadingAnchor, constant: 16),
            stack.trailingAnchor.constraint(equalTo: view.trailingAnchor, constant: -16),
            stack.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor, constant: -12),
            pickBtn.heightAnchor.constraint(equalToConstant: 48)
        ])
    }

    func log(_ s: String) { DispatchQueue.main.async { self.tv.text += s + "\n"; self.tv.scrollRangeToVisible(NSRange(location: self.tv.text.count - 1, length: 1)) } }
    func prog(_ v: Double) { DispatchQueue.main.async { self.bar.progress = Float(v) } }
    func busy(_ b: Bool) { DispatchQueue.main.async { self.pickBtn.isEnabled = !b; self.pickBtn.alpha = b ? 0.5 : 1; UIApplication.shared.isIdleTimerDisabled = b } }

    @objc func pick() {
        let p = UIDocumentPickerViewController(forOpeningContentTypes: [.item], asCopy: false)
        p.delegate = self
        present(p, animated: true)
    }
    @objc func share() {
        guard !outputs.isEmpty else { log("Chưa có kết quả."); return }
        let a = UIActivityViewController(activityItems: outputs, applicationActivities: nil)
        a.popoverPresentationController?.sourceView = shareBtn
        present(a, animated: true)
    }
    func documentPicker(_ c: UIDocumentPickerViewController, didPickDocumentsAt urls: [URL]) {
        guard let url = urls.first else { return }
        let ok = url.startAccessingSecurityScopedResource()
        let all = sw.isOn
        busy(true); outputs.removeAll()
        DispatchQueue.global(qos: .userInitiated).async {
            self.dump(ipa: url, all: all)
            if ok { url.stopAccessingSecurityScopedResource() }
            self.busy(false)
        }
    }

    func dump(ipa: URL, all: Bool) {
        let fm = FileManager.default
        guard let zip = ZipReader(path: ipa.path), let ents = zip.entries() else {
            log("Không đọc được file (không phải IPA/zip hợp lệ)."); return
        }
        log("IPA: \(zip.size / 1_048_576) MB, \(ents.count) mục")
        let tmp = fm.temporaryDirectory.appendingPathComponent("od_tmp")
        try? fm.removeItem(at: tmp); try? fm.createDirectory(at: tmp, withIntermediateDirectories: true)
        let docs = fm.urls(for: .documentDirectory, in: .userDomainMask)[0]

        // tên executable chính
        var mainExe = ""
        if let ie = ents.first(where: { $0.name.hasSuffix(".app/Info.plist") && $0.name.split(separator: "/").count == 3 }) {
            let pu = tmp.appendingPathComponent("Info.plist")
            if zip.extract(ie, to: pu), let d = try? Data(contentsOf: pu),
               let pl = try? PropertyListSerialization.propertyList(from: d, options: [], format: nil) as? [String: Any] {
                mainExe = (pl["CFBundleExecutable"] as? String) ?? ""
            }
        }
        // danh sách binary cần quét
        var cands = [ZipEntry]()
        for e in ents where e.size > 64 * 1024 && e.name.hasPrefix("Payload/") && !e.name.hasSuffix("/") {
            let parts = e.name.split(separator: "/").map(String.init)
            if parts.count == 3 && parts[2] == mainExe { cands.append(e) }
            else if parts.count == 5 && parts[2] == "Frameworks" && parts[3].hasSuffix(".framework")
                        && parts[4] == String(parts[3].dropLast(10)) { cands.append(e) }
            else if e.name.hasSuffix(".dylib") { cands.append(e) }
        }
        guard !cands.isEmpty else { log("Không thấy binary nào trong IPA."); return }
        log("Binary cần quét: \(cands.count)")

        for (i, e) in cands.enumerated() {
            let bname = (e.name as NSString).lastPathComponent
            log("\n[\(i + 1)/\(cands.count)] \(bname) (\(e.size / 1_048_576) MB) - giải nén...")
            let bu = tmp.appendingPathComponent("bin_\(i)")
            let okx = zip.extract(e, to: bu) { self.prog(($0 + Double(i)) / Double(cands.count)) }
            guard okx else { log("  lỗi giải nén, bỏ qua"); continue }
            log("  đang đọc symbol...")
            let want = true
            if let r = analyze(bu, wantStrings: want) {
                let (f, every) = render(binary: bname, r: r, all: all)
                let fu = docs.appendingPathComponent("Dump_\(bname).txt")
                try? f.write(to: fu, atomically: true, encoding: .utf8); outputs.append(fu)
                if let ev = every {
                    let eu = docs.appendingPathComponent("ALL_\(bname).txt")
                    try? ev.write(to: eu, atomically: true, encoding: .utf8); outputs.append(eu)
                }
                log("  xong: \(r.syms.count) symbol, \(r.strs.count) chuỗi khớp" + (r.cryptid != 0 ? " | CÒN MÃ HÓA (cryptid=1)" : ""))
            } else {
                log("  không phải Mach-O arm64, bỏ qua")
            }
            try? fm.removeItem(at: bu)
        }
        try? fm.removeItem(at: tmp)
        prog(1)
        log("\nXONG. Đã lưu \(outputs.count) file trong Files > On My iPhone > Offset Dump. Bấm \"Chia sẻ\" để gửi file.")
    }
}
