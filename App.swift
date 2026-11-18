// OffsetDumper v2 - app iOS: chọn IPA game -> bấm DUMP -> xuất toàn bộ offset
// Đọc IPA kiểu streaming (không nạp cả file vào RAM), giải nén binary Mach-O, đọc symbol + export trie + chuỗi.
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

func C(_ h: Int) -> UIColor {
    UIColor(red: CGFloat((h >> 16) & 255) / 255, green: CGFloat((h >> 8) & 255) / 255, blue: CGFloat(h & 255) / 255, alpha: 1)
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
        guard size > 22 else { return nil }
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

func uleb(_ r: Rd, _ o: inout Int) -> UInt64 {
    var res: UInt64 = 0
    var shift: UInt64 = 0
    while o < r.count {
        let b = r.u8(o); o += 1
        res |= UInt64(b & 0x7F) << shift
        if b & 0x80 == 0 { break }
        shift += 7
        if shift > 63 { break }
    }
    return res
}

struct Seg { let name: String; let vm: UInt64; let vmsize: UInt64; let fo: UInt64 }
struct Sym { let va: UInt64; let fo: UInt64; let name: String }
struct MachoResult { var syms: [Sym] = []; var strs: [Sym] = []; var cryptid: UInt32 = 0; var nsymTotal = 0; var trieCount = 0 }

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

func analyze(_ url: URL) -> MachoResult? {
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
        var strSecs = [(addr: UInt64, size: UInt64, off: UInt64)]()
        var symoff = 0, nsyms = 0, stroff = 0
        var trieOff = 0, trieSize = 0
        var res = MachoResult()
        let strNames: Set<String> = ["__cstring", "__objc_classname", "__objc_methname"]
        for _ in 0..<ncmds {
            guard off + 8 <= r.count else { break }
            let cmd = r.u32(off), sz = Int(r.u32(off + 4))
            if cmd == 0x19 {
                segs.append(Seg(name: r.cstr(off + 8, max: 16), vm: r.u64(off + 24), vmsize: r.u64(off + 32), fo: r.u64(off + 40)))
                let nsec = Int(r.u32(off + 64)); var so = off + 72
                for _ in 0..<nsec {
                    if strNames.contains(r.cstr(so, max: 16)) { strSecs.append((r.u64(so + 32), r.u64(so + 40), UInt64(r.u32(so + 48)))) }
                    so += 80
                }
            } else if cmd == 0x2 {
                symoff = Int(r.u32(off + 8)); nsyms = Int(r.u32(off + 12)); stroff = Int(r.u32(off + 16))
            } else if cmd == 0x22 || cmd == 0x80000022 {
                trieOff = Int(r.u32(off + 40)); trieSize = Int(r.u32(off + 44))
            } else if cmd == 0x80000033 {
                trieOff = Int(r.u32(off + 8)); trieSize = Int(r.u32(off + 12))
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
        var seen = Set<String>()
        res.nsymTotal = nsyms
        for i in 0..<nsyms {
            let e = sb + symoff + i * 16
            let type = r.u8(e + 4)
            if type & 0xE0 != 0 || type & 0x0E != 0x0E { continue }
            let val = r.u64(e + 8)
            if val == 0 { continue }
            let raw = r.cstr(sb + stroff + Int(r.u32(e)))
            if raw.isEmpty { continue }
            let nm = demangle(raw)
            if seen.insert("\(val):\(nm)").inserted { res.syms.append(Sym(va: val, fo: foff(val), name: nm)) }
        }
        // export trie (bổ sung symbol bị thiếu trong symtab)
        if trieSize > 0, let textVM = segs.first(where: { $0.name == "__TEXT" })?.vm {
            var stack: [(Int, String)] = [(0, "")]
            var visited = Set<Int>()
            var guardCount = 0
            while let (nodeOff, prefix) = stack.popLast() {
                if nodeOff >= trieSize || !visited.insert(nodeOff).inserted { continue }
                guardCount += 1
                if guardCount > 3_000_000 { break }
                var o = sb + trieOff + nodeOff
                let tsize = Int(uleb(r, &o))
                let after = o + tsize
                if tsize > 0 {
                    var q = o
                    let flags = uleb(r, &q)
                    if flags & 0x8 == 0 {
                        let addr = uleb(r, &q)
                        if !prefix.isEmpty {
                            let va = textVM + addr
                            let nm = demangle(prefix)
                            if seen.insert("\(va):\(nm)").inserted {
                                res.syms.append(Sym(va: va, fo: foff(va), name: nm)); res.trieCount += 1
                            }
                        }
                    }
                }
                o = after
                let nchild = Int(r.u8(o)); o += 1
                for _ in 0..<nchild {
                    let label = r.cstr(o, max: 2048); o += label.utf8.count + 1
                    let childOff = Int(uleb(r, &o))
                    stack.append((childOff, prefix + label))
                }
            }
        }
        res.syms.sort { $0.va < $1.va }
        // chuỗi (cstring + tên class/method ObjC)
        if res.cryptid == 0 {
            for c in strSecs {
                var i = Int(c.off) + sb
                let end = min(Int(c.off + c.size) + sb, r.count)
                var start = i
                while i < end {
                    if r.u8(i) == 0 {
                        let len = i - start
                        if len >= 4 && len <= 200 {
                            let s = r.cstr(start, max: 201)
                            if s.utf8.allSatisfy({ $0 >= 32 && $0 < 127 }) && !isExcluded(s.lowercased()) {
                                let rel = UInt64(start - sb)
                                res.strs.append(Sym(va: c.addr + rel - c.off, fo: rel, name: s))
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

final class OutFile {
    let url: URL
    let fh: FileHandle
    var buf = ""
    init?(_ url: URL) {
        FileManager.default.createFile(atPath: url.path, contents: nil)
        guard let h = FileHandle(forWritingAtPath: url.path) else { return nil }
        self.url = url; fh = h
    }
    func w(_ s: String) { buf += s; if buf.utf8.count > (1 << 20) { flush() } }
    func flush() { if !buf.isEmpty { fh.write(buf.data(using: .utf8) ?? Data()); buf = "" } }
    func close() { flush(); try? fh.close() }
}

// Xuất 3 file: Dump_ (phân loại), ALL_ (toàn bộ symbol), STRINGS_ (toàn bộ chuỗi)
func writeOutputs(binary: String, r: MachoResult, docs: URL) -> [URL] {
    var outs = [URL]()
    var groups = [[Sym]](repeating: [], count: 5)
    var allSyms = [Sym]()
    for s in r.syms {
        let l = s.name.lowercased()
        if isExcluded(l) { continue }
        allSyms.append(s)
        if let c = classify(l) { groups[c].append(s) }
    }
    let note = r.cryptid != 0 ? "CANH BAO: binary con ma hoa FairPlay (cryptid=1): ten ham/offset van dung, nhung chuoi khong doc duoc.\n" : ""

    if let f = OutFile(docs.appendingPathComponent("Dump_\(binary).txt")) {
        f.w("OFFSET DUMP (phan loai) - \(binary)\nFormat: VA | file offset (trong slice) | ten\n")
        f.w("Symbol: \(allSyms.count) (symtab \(r.nsymTotal), export trie them \(r.trieCount)) | chuoi: \(r.strs.count)\n")
        f.w(note)
        f.w("Luu y: offset FIELD (vd m_fHealth trong CPed) khong co trong symbol.\nDa loai cac muc mua bang tien that.\n")
        f.w(String(repeating: "=", count: 70) + "\n")
        for i in 0..<5 {
            f.w("\n" + catTitles[i] + " (\(groups[i].count))\n")
            for s in groups[i] { f.w("\(hex(s.va)) | \(hex(s.fo)) | \(s.name)\n") }
        }
        f.w("\n### CHUOI LIEN QUAN\n")
        var n = 0
        for s in r.strs where classify(s.name.lowercased()) != nil {
            f.w("\(hex(s.va)) | \(hex(s.fo)) | \(s.name)\n"); n += 1
            if n >= 20000 { break }
        }
        f.close(); outs.append(f.url)
    }
    if let f = OutFile(docs.appendingPathComponent("ALL_\(binary).txt")) {
        f.w("TAT CA SYMBOL - \(binary) (\(allSyms.count))\nFormat: VA | file offset | ten\n")
        for s in allSyms { f.w("\(hex(s.va)) | \(hex(s.fo)) | \(s.name)\n") }
        f.close(); outs.append(f.url)
    }
    if !r.strs.isEmpty, let f = OutFile(docs.appendingPathComponent("STRINGS_\(binary).txt")) {
        f.w("TAT CA CHUOI (cstring + ObjC class/method) - \(binary) (\(r.strs.count))\nFormat: VA | file offset | chuoi\n")
        for s in r.strs { f.w("\(hex(s.va)) | \(hex(s.fo)) | \(s.name)\n") }
        f.close(); outs.append(f.url)
    }
    return outs
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
    // Mở IPA từ Files / Chia sẻ -> Offset Dump
    func application(_ app: UIApplication, open url: URL, options: [UIApplication.OpenURLOptionsKey: Any] = [:]) -> Bool {
        let s = url.startAccessingSecurityScopedResource()
        (window?.rootViewController as? MainVC)?.setIPA(url, scoped: s)
        return true
    }
}

final class MainVC: UIViewController, UIDocumentPickerDelegate {
    let fileLabel = UILabel()
    let pickBtn = UIButton(type: .system)
    let listBtn = UIButton(type: .system)
    let dumpBtn = UIButton(type: .system)
    let openBtn = UIButton(type: .system)
    let shareBtn = UIButton(type: .system)
    let bar = UIProgressView(progressViewStyle: .default)
    let statusLabel = UILabel()
    let tv = UITextView()
    var selected: URL?
    var scoped = false
    var outputs = [URL]()
    var running = false
    let docs = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]

    func style(_ b: UIButton, _ t: String, _ bg: UIColor) {
        b.setTitle(t, for: .normal); b.setTitleColor(.white, for: .normal)
        b.backgroundColor = bg; b.layer.cornerRadius = 14
        b.titleLabel?.font = .systemFont(ofSize: 16, weight: .semibold)
        b.heightAnchor.constraint(equalToConstant: 50).isActive = true
    }
    func card(_ views: [UIView]) -> UIView {
        let c = UIView(); c.backgroundColor = C(0x1E232B); c.layer.cornerRadius = 16
        let s = UIStackView(arrangedSubviews: views); s.axis = .vertical; s.spacing = 10
        s.translatesAutoresizingMaskIntoConstraints = false; c.addSubview(s)
        NSLayoutConstraint.activate([
            s.topAnchor.constraint(equalTo: c.topAnchor, constant: 14),
            s.bottomAnchor.constraint(equalTo: c.bottomAnchor, constant: -14),
            s.leadingAnchor.constraint(equalTo: c.leadingAnchor, constant: 14),
            s.trailingAnchor.constraint(equalTo: c.trailingAnchor, constant: -14)
        ])
        return c
    }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = C(0x14171C)
        // tạo file README để thư mục app hiện trong Files
        let readme = docs.appendingPathComponent("README.txt")
        if !FileManager.default.fileExists(atPath: readme.path) {
            try? "Offset Dump\nCopy file IPA vao thu muc nay (hoac chon IPA tu Files trong app). Ket qua dump cung nam o day.\n".write(to: readme, atomically: true, encoding: .utf8)
        }

        let title = UILabel(); title.text = "Offset Dump"; title.textColor = .white
        title.font = .systemFont(ofSize: 30, weight: .bold)
        let sub = UILabel(); sub.text = "Chọn IPA game → bấm DUMP → lấy toàn bộ offset"; sub.textColor = C(0x9AA3B2)
        sub.font = .systemFont(ofSize: 14); sub.numberOfLines = 0

        fileLabel.text = "Chưa chọn IPA"; fileLabel.textColor = .white
        fileLabel.font = .systemFont(ofSize: 15, weight: .medium); fileLabel.numberOfLines = 0
        style(pickBtn, "Chọn IPA từ Files", C(0x5B8FD0))
        pickBtn.addTarget(self, action: #selector(pick), for: .touchUpInside)
        style(listBtn, "Chọn IPA trong thư mục app", C(0x353C48))
        listBtn.addTarget(self, action: #selector(pickFromApp), for: .touchUpInside)

        style(dumpBtn, "BẮT ĐẦU DUMP", C(0x34A853))
        dumpBtn.titleLabel?.font = .systemFont(ofSize: 18, weight: .bold)
        dumpBtn.addTarget(self, action: #selector(startDump), for: .touchUpInside)

        statusLabel.text = "Sẵn sàng"; statusLabel.textColor = C(0x9AA3B2); statusLabel.font = .systemFont(ofSize: 13)
        statusLabel.numberOfLines = 2
        bar.progressTintColor = C(0x5B8FD0); bar.trackTintColor = C(0x353C48)

        tv.isEditable = false; tv.backgroundColor = C(0x1E232B); tv.textColor = C(0xC8CDD6)
        tv.font = .monospacedSystemFont(ofSize: 12, weight: .regular)
        tv.layer.cornerRadius = 16; tv.textContainerInset = UIEdgeInsets(top: 12, left: 10, bottom: 12, right: 10)
        tv.text = "Kết quả lưu trong Files > On My iPhone > Offset Dump.\n"
        tv.heightAnchor.constraint(greaterThanOrEqualToConstant: 120).isActive = true

        style(openBtn, "Mở thư mục kết quả", C(0x353C48))
        openBtn.addTarget(self, action: #selector(openFolder), for: .touchUpInside)
        style(shareBtn, "Chia sẻ kết quả", C(0x353C48))
        shareBtn.addTarget(self, action: #selector(share), for: .touchUpInside)
        let row = UIStackView(arrangedSubviews: [openBtn, shareBtn]); row.spacing = 10; row.distribution = .fillEqually

        let stack = UIStackView(arrangedSubviews: [title, sub, card([fileLabel, pickBtn, listBtn]), dumpBtn,
                                                   card([statusLabel, bar]), tv, row])
        stack.axis = .vertical; stack.spacing = 12
        stack.setCustomSpacing(2, after: title)
        stack.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(stack)
        NSLayoutConstraint.activate([
            stack.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor, constant: 10),
            stack.leadingAnchor.constraint(equalTo: view.leadingAnchor, constant: 16),
            stack.trailingAnchor.constraint(equalTo: view.trailingAnchor, constant: -16),
            stack.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor, constant: -10)
        ])
    }

    func log(_ s: String) {
        DispatchQueue.main.async {
            self.tv.text += s + "\n"
            if self.tv.text.count > 1 { self.tv.scrollRangeToVisible(NSRange(location: self.tv.text.count - 1, length: 1)) }
        }
    }
    func status(_ s: String) { DispatchQueue.main.async { self.statusLabel.text = s } }
    func prog(_ v: Double) { DispatchQueue.main.async { self.bar.progress = Float(min(max(v, 0), 1)) } }
    func busy(_ b: Bool) {
        DispatchQueue.main.async {
            self.running = b
            for x in [self.pickBtn, self.listBtn, self.dumpBtn] { x.isEnabled = !b; x.alpha = b ? 0.5 : 1 }
            UIApplication.shared.isIdleTimerDisabled = b
        }
    }

    // ---- chọn IPA ----
    func setIPA(_ url: URL, scoped s: Bool) {
        if running { return }
        if scoped, let old = selected { old.stopAccessingSecurityScopedResource() }
        selected = url; scoped = s
        let attrs = try? FileManager.default.attributesOfItem(atPath: url.path)
        let size = (attrs?[.size] as? NSNumber)?.uint64Value ?? 0
        DispatchQueue.main.async {
            self.fileLabel.text = "\(url.lastPathComponent)\n\(size / 1_048_576) MB"
            self.statusLabel.text = "Đã chọn IPA. Bấm BẮT ĐẦU DUMP."
        }
    }
    @objc func pick() {
        var types: [UTType] = [.item, .data, .archive, .zip]
        if let t = UTType(filenameExtension: "ipa") { types.insert(t, at: 0) }
        let p = UIDocumentPickerViewController(forOpeningContentTypes: types, asCopy: false)
        p.delegate = self
        p.allowsMultipleSelection = false
        present(p, animated: true)
    }
    func documentPicker(_ c: UIDocumentPickerViewController, didPickDocumentsAt urls: [URL]) {
        guard let url = urls.first else { return }
        setIPA(url, scoped: url.startAccessingSecurityScopedResource())
    }
    @objc func pickFromApp() {
        let fm = FileManager.default
        var items = [URL]()
        for dir in [docs, docs.appendingPathComponent("Inbox")] {
            if let fs = try? fm.contentsOfDirectory(at: dir, includingPropertiesForKeys: nil) {
                items += fs.filter { ["ipa", "zip"].contains($0.pathExtension.lowercased()) }
            }
        }
        guard !items.isEmpty else {
            log("Thư mục app chưa có IPA. Copy IPA vào Files > On My iPhone > Offset Dump rồi bấm lại.")
            return
        }
        let a = UIAlertController(title: "Chọn IPA", message: nil, preferredStyle: .actionSheet)
        for u in items { a.addAction(UIAlertAction(title: u.lastPathComponent, style: .default) { _ in self.setIPA(u, scoped: false) }) }
        a.addAction(UIAlertAction(title: "Huỷ", style: .cancel))
        a.popoverPresentationController?.sourceView = listBtn
        present(a, animated: true)
    }

    @objc func openFolder() {
        let s = docs.absoluteString.replacingOccurrences(of: "file://", with: "shareddocuments://")
        if let u = URL(string: s) { UIApplication.shared.open(u) }
    }
    @objc func share() {
        guard !outputs.isEmpty else { log("Chưa có kết quả."); return }
        let a = UIActivityViewController(activityItems: outputs, applicationActivities: nil)
        a.popoverPresentationController?.sourceView = shareBtn
        present(a, animated: true)
    }

    // ---- dump ----
    @objc func startDump() {
        guard !running else { return }
        guard let url = selected else { log("Chưa chọn IPA."); return }
        busy(true); outputs.removeAll(); prog(0)
        DispatchQueue.global(qos: .userInitiated).async {
            self.dump(ipa: url)
            self.busy(false)
        }
    }

    func dump(ipa: URL) {
        let fm = FileManager.default
        guard let zip = ZipReader(path: ipa.path), let ents = zip.entries() else {
            log("Không đọc được file (không phải IPA/zip hợp lệ)."); status("Lỗi đọc file"); return
        }
        log("\nIPA: \(zip.size / 1_048_576) MB, \(ents.count) mục")
        let tmp = fm.temporaryDirectory.appendingPathComponent("od_tmp")
        try? fm.removeItem(at: tmp); try? fm.createDirectory(at: tmp, withIntermediateDirectories: true)

        var mainExe = ""
        if let ie = ents.first(where: { $0.name.hasSuffix(".app/Info.plist") && $0.name.split(separator: "/").count == 3 }) {
            let pu = tmp.appendingPathComponent("Info.plist")
            if zip.extract(ie, to: pu), let d = try? Data(contentsOf: pu),
               let pl = try? PropertyListSerialization.propertyList(from: d, options: [], format: nil) as? [String: Any] {
                mainExe = (pl["CFBundleExecutable"] as? String) ?? ""
            }
        }
        var cands = [ZipEntry]()
        for e in ents where e.size > 64 * 1024 && e.name.hasPrefix("Payload/") && !e.name.hasSuffix("/") {
            let parts = e.name.split(separator: "/").map(String.init)
            if parts.count == 3 && parts[2] == mainExe { cands.append(e) }
            else if parts.count == 5 && parts[2] == "Frameworks" && parts[3].hasSuffix(".framework")
                        && parts[4] == String(parts[3].dropLast(10)) { cands.append(e) }
            else if e.name.hasSuffix(".dylib") { cands.append(e) }
        }
        guard !cands.isEmpty else { log("Không thấy binary nào trong IPA."); status("Không có binary"); return }
        log("Binary cần quét: \(cands.count)")

        for (i, e) in cands.enumerated() {
            let bname = (e.name as NSString).lastPathComponent
            let n = Double(cands.count)
            log("\n[\(i + 1)/\(cands.count)] \(bname) (\(e.size / 1_048_576) MB)")
            status("[\(i + 1)/\(cands.count)] \(bname): giải nén...")
            let bu = tmp.appendingPathComponent("bin_\(i)")
            let okx = zip.extract(e, to: bu) { self.prog((Double(i) + $0 * 0.6) / n) }
            guard okx else { log("  lỗi giải nén, bỏ qua"); continue }
            status("[\(i + 1)/\(cands.count)] \(bname): đọc symbol & chuỗi...")
            prog((Double(i) + 0.6) / n)
            if let r = analyze(bu) {
                let files = writeOutputs(binary: bname, r: r, docs: docs)
                outputs += files
                log("  \(r.syms.count) symbol (+\(r.trieCount) từ export trie), \(r.strs.count) chuỗi" + (r.cryptid != 0 ? " | CÒN MÃ HÓA (cryptid=1)" : ""))
                for f in files {
                    let sz = ((try? fm.attributesOfItem(atPath: f.path))?[.size] as? NSNumber)?.uint64Value ?? 0
                    log("  → \(f.lastPathComponent) (\(sz / 1024) KB)")
                }
            } else {
                log("  không phải Mach-O arm64, bỏ qua")
            }
            try? fm.removeItem(at: bu)
            prog(Double(i + 1) / n)
        }
        try? fm.removeItem(at: tmp)
        status("Xong! \(outputs.count) file trong thư mục Offset Dump")
        log("\nXONG. Mở Files > On My iPhone > Offset Dump hoặc bấm \"Mở thư mục kết quả\". Gửi file Dump_*.txt (và ALL_*.txt) cho Claude.")
    }
}
