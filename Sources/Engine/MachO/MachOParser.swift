import Foundation

public final class MachOParser {
    public let mapped: MappedFile
    public let sourceName: String
    public let sliceOffset: Int
    public let sliceSize: Int
    public let baseVM: UInt64
    public let maxVM: UInt64
    public let architecture: String

    public private(set) var segments: [SegmentInfo] = []
    public private(set) var sections: [SectionInfo] = []
    public private(set) var fairPlayEncrypted = false
    public private(set) var cryptId: UInt32 = 0
    public private(set) var cryptOffset: UInt32 = 0
    public private(set) var cryptSize: UInt32 = 0

    public private(set) var hasChainedFixups = false
    public private(set) var chainedFixupPointers: [Int: UInt64] = [:]
    public private(set) var functionStartsRVA: [UInt64] = []
    public private(set) var exportedSymbols: [NativeSymbolEntry] = []
    public private(set) var symtabSymbols: [NativeSymbolEntry] = []
    public private(set) var objcClasses: [ObjCClassInfo] = []
    public private(set) var swiftTypes: [SwiftTypeInfo] = []
    public private(set) var xrefFunctions: [NativeSymbolEntry] = []
    public private(set) var filteredRealMoneyCount: Int = 0

    private var sortedSegments: [SegmentInfo] = []
    private var textCodeRanges: [(startVM: UInt64, endVM: UInt64)] = []
    private var cstringByVM: [UInt64: String] = [:]
    private var selRefByVM: [UInt64: String] = [:]

    public enum MachOError: Error, LocalizedError {
        case invalidHeader(String)
        case noArm64Slice

        public var errorDescription: String? {
            switch self {
            case let .invalidHeader(msg): return "Tệp Mach-O không hợp lệ: \(msg)"
            case .noArm64Slice: return "Không tìm thấy slice ARM64 trong Fat Mach-O"
            }
        }
    }

    public init(url: URL, sourceName: String? = nil) throws {
        let mapped = try MappedFile(url: url)
        self.mapped = mapped
        self.sourceName = sourceName ?? url.lastPathComponent

        guard mapped.count >= 32 else {
            throw MachOError.invalidHeader("Tệp quá nhỏ")
        }

        let magicLE = mapped.readUInt32(at: 0)
        let magicBE = mapped.readUInt32BE(at: 0)

        var chosenOffset = 0
        var chosenSize = mapped.count
        var archName = "arm64"

        if magicBE == 0xCAFEBABE || magicLE == 0xCAFEBABE {
            // Fat / Universal binary
            let nfat = Int(mapped.readUInt32BE(at: 4))
            var found = false
            for i in 0..<min(nfat, 16) {
                let entryOff = 8 + i * 20
                guard mapped.inRange(entryOff, 20) else { break }
                let cpuType = mapped.readUInt32BE(at: entryOff)
                let cpuSub = mapped.readUInt32BE(at: entryOff + 4)
                let off = Int(mapped.readUInt32BE(at: entryOff + 8))
                let sz = Int(mapped.readUInt32BE(at: entryOff + 12))
                if cpuType == 0x0100000C && off >= 0 && sz > 0 && mapped.inRange(off, sz) {
                    chosenOffset = off
                    chosenSize = sz
                    archName = (cpuSub & 0x00FF_FFFF) == 2 ? "arm64e" : "arm64"
                    found = true
                    break
                }
            }
            guard found else { throw MachOError.noArm64Slice }
        } else if magicBE == 0xCAFEBABF || magicLE == 0xCAFEBABF {
            // 64-bit Fat binary
            let nfat = Int(mapped.readUInt32BE(at: 4))
            var found = false
            for i in 0..<min(nfat, 16) {
                let entryOff = 8 + i * 32
                guard mapped.inRange(entryOff, 32) else { break }
                let cpuType = mapped.readUInt32BE(at: entryOff)
                let cpuSub = mapped.readUInt32BE(at: entryOff + 4)
                let off = Int(UInt64(mapped.readUInt32BE(at: entryOff + 8)) << 32 | UInt64(mapped.readUInt32BE(at: entryOff + 12)))
                let sz = Int(UInt64(mapped.readUInt32BE(at: entryOff + 16)) << 32 | UInt64(mapped.readUInt32BE(at: entryOff + 20)))
                if cpuType == 0x0100000C && off >= 0 && sz > 0 && mapped.inRange(off, sz) {
                    chosenOffset = off
                    chosenSize = sz
                    archName = (cpuSub & 0x00FF_FFFF) == 2 ? "arm64e" : "arm64"
                    found = true
                    break
                }
            }
            guard found else { throw MachOError.noArm64Slice }
        }

        self.sliceOffset = chosenOffset
        self.sliceSize = chosenSize

        let sliceMagic = mapped.readUInt32(at: chosenOffset)
        guard sliceMagic == 0xFEEDFACF else {
            throw MachOError.invalidHeader(String(format: "Magic 0x%08X không phải Mach-O 64-bit", sliceMagic))
        }

        let cpuType = mapped.readUInt32(at: chosenOffset + 4)
        let cpuSub = mapped.readUInt32(at: chosenOffset + 8)
        if cpuType == 0x0100000C {
            archName = (cpuSub & 0x00FF_FFFF) == 2 ? "arm64e" : "arm64"
        }
        self.architecture = archName

        let ncmds = Int(mapped.readUInt32(at: chosenOffset + 16))
        let sizeofcmds = Int(mapped.readUInt32(at: chosenOffset + 20))

        var symtabCmd: (symoff: UInt32, nsyms: UInt32, stroff: UInt32, strsize: UInt32)?
        var funcStartsCmd: (dataoff: UInt32, datasize: UInt32)?
        var exportsTrieCmd: (dataoff: UInt32, datasize: UInt32)?
        var chainedFixupsCmd: (dataoff: UInt32, datasize: UInt32)?

        var cmdPos = chosenOffset + 32
        let cmdsEnd = min(chosenOffset + chosenSize, cmdPos + sizeofcmds)
        var minTextBase: UInt64 = UInt64.max
        var computedMaxVM: UInt64 = 0

        for _ in 0..<ncmds {
            guard cmdPos + 8 <= cmdsEnd else { break }
            let cmd = mapped.readUInt32(at: cmdPos)
            let cmdsize = Int(mapped.readUInt32(at: cmdPos + 4))
            guard cmdsize >= 8 && cmdPos + cmdsize <= cmdsEnd else { break }

            switch cmd {
            case 0x19: // LC_SEGMENT_64
                if cmdsize >= 72 {
                    let segName = readFixedName(at: cmdPos + 8, length: 16)
                    let vmAddr = mapped.readUInt64(at: cmdPos + 24)
                    let vmSize = mapped.readUInt64(at: cmdPos + 32)
                    let fileOff = mapped.readUInt64(at: cmdPos + 40)
                    let fileSz = mapped.readUInt64(at: cmdPos + 48)
                    let maxProt = mapped.readUInt32(at: cmdPos + 56)
                    let initProt = mapped.readUInt32(at: cmdPos + 60)
                    let nsects = Int(mapped.readUInt32(at: cmdPos + 64))

                    let seg = SegmentInfo(
                        name: segName,
                        vmAddr: vmAddr,
                        vmSize: vmSize,
                        fileOffset: fileOff,
                        fileSize: fileSz,
                        initProt: initProt,
                        maxProt: maxProt
                    )
                    segments.append(seg)
                    if segName == "__TEXT" && vmAddr > 0 {
                        minTextBase = min(minTextBase, vmAddr)
                    }
                    if vmSize > 0 && vmAddr + vmSize > computedMaxVM {
                        computedMaxVM = vmAddr + vmSize
                    }

                    var secPos = cmdPos + 72
                    for _ in 0..<nsects {
                        guard secPos + 80 <= cmdPos + cmdsize else { break }
                        let sectName = readFixedName(at: secPos, length: 16)
                        let sSegName = readFixedName(at: secPos + 16, length: 16)
                        let addr = mapped.readUInt64(at: secPos + 32)
                        let size = mapped.readUInt64(at: secPos + 40)
                        let offset = UInt64(mapped.readUInt32(at: secPos + 48))
                        let flags = mapped.readUInt32(at: secPos + 64)
                        let sec = SectionInfo(
                            segName: sSegName,
                            sectName: sectName,
                            addr: addr,
                            size: size,
                            offset: offset,
                            flags: flags
                        )
                        sections.append(sec)
                        if sSegName == "__TEXT" && (sectName == "__text" || sectName == "__stubs" || (flags & 0x8000_0000) != 0) && size > 0 {
                            textCodeRanges.append((addr, addr + size))
                        }
                        secPos += 80
                    }
                }
            case 0x2C: // LC_ENCRYPTION_INFO_64
                if cmdsize >= 20 {
                    self.cryptOffset = mapped.readUInt32(at: cmdPos + 8)
                    self.cryptSize = mapped.readUInt32(at: cmdPos + 12)
                    self.cryptId = mapped.readUInt32(at: cmdPos + 16)
                    self.fairPlayEncrypted = (self.cryptId != 0)
                }
            case 0x02: // LC_SYMTAB
                if cmdsize >= 24 {
                    symtabCmd = (
                        mapped.readUInt32(at: cmdPos + 8),
                        mapped.readUInt32(at: cmdPos + 12),
                        mapped.readUInt32(at: cmdPos + 16),
                        mapped.readUInt32(at: cmdPos + 20)
                    )
                }
            case 0x26: // LC_FUNCTION_STARTS
                if cmdsize >= 16 {
                    funcStartsCmd = (mapped.readUInt32(at: cmdPos + 8), mapped.readUInt32(at: cmdPos + 12))
                }
            case 0x80000033: // LC_DYLD_EXPORTS_TRIE
                if cmdsize >= 16 {
                    exportsTrieCmd = (mapped.readUInt32(at: cmdPos + 8), mapped.readUInt32(at: cmdPos + 12))
                }
            case 0x80000034: // LC_DYLD_CHAINED_FIXUPS
                if cmdsize >= 16 {
                    chainedFixupsCmd = (mapped.readUInt32(at: cmdPos + 8), mapped.readUInt32(at: cmdPos + 12))
                }
            default:
                break
            }

            cmdPos += cmdsize
        }

        if minTextBase != UInt64.max {
            self.baseVM = minTextBase
        } else {
            self.baseVM = segments.first(where: { $0.vmSize > 0 && $0.name != "__PAGEZERO" })?.vmAddr ?? 0x1_0000_0000
        }
        self.maxVM = max(computedMaxVM, self.baseVM + UInt64(chosenSize))
        self.sortedSegments = segments.filter { $0.fileSize > 0 && $0.vmSize > 0 }.sorted { $0.vmAddr < $1.vmAddr }

        if let cf = chainedFixupsCmd {
            decodeChainedFixups(dataOff: Int(cf.dataoff), dataSize: Int(cf.datasize))
        }
        if let fs = funcStartsCmd {
            decodeFunctionStarts(dataOff: Int(fs.dataoff), dataSize: Int(fs.datasize))
        }
        if let ex = exportsTrieCmd {
            decodeExportsTrie(dataOff: Int(ex.dataoff), dataSize: Int(ex.datasize))
        }
        if let st = symtabCmd {
            decodeSymtab(symoff: Int(st.symoff), nsyms: Int(st.nsyms), stroff: Int(st.stroff), strsize: Int(st.strsize))
        }
    }

    private func readFixedName(at offset: Int, length: Int) -> String {
        guard mapped.inRange(offset, length) else { return "" }
        var bytes: [UInt8] = []
        bytes.reserveCapacity(length)
        for i in 0..<length {
            let b = mapped.baseAddress[offset + i]
            if b == 0 { break }
            bytes.append(b)
        }
        return String(decoding: bytes, as: UTF8.self)
    }

    @inline(__always)
    public func isInEncryptedRange(sliceRelOffset: Int) -> Bool {
        guard fairPlayEncrypted, sliceRelOffset >= 0 else { return false }
        let off = UInt64(sliceRelOffset)
        return off >= UInt64(cryptOffset) && off < UInt64(cryptOffset) + UInt64(cryptSize)
    }

    @inline(__always)
    public func isInTextCode(_ vm: UInt64) -> Bool {
        for r in textCodeRanges {
            if vm >= r.startVM && vm < r.endVM { return true }
        }
        return false
    }

    /// Converts a VM address to a slice-relative file offset (without sliceOffset added).
    public func vmToSliceRelOffset(_ vm: UInt64) -> Int? {
        guard vm >= baseVM else { return nil }
        for seg in sortedSegments {
            if vm >= seg.vmAddr && vm < seg.vmAddr + seg.vmSize {
                let delta = vm - seg.vmAddr
                if delta < seg.fileSize {
                    let rel = Int(seg.fileOffset + delta)
                    if rel >= 0 && rel < sliceSize {
                        return rel
                    }
                }
            }
        }
        return nil
    }

    /// Converts a slice-relative file offset to VM address.
    public func sliceRelOffsetToVM(_ rel: Int) -> UInt64? {
        guard rel >= 0 && rel < sliceSize else { return nil }
        let off = UInt64(rel)
        for seg in sortedSegments {
            if off >= seg.fileOffset && off < seg.fileOffset + seg.fileSize {
                return seg.vmAddr + (off - seg.fileOffset)
            }
        }
        return nil
    }

    /// Decodes a 64-bit pointer at `sliceRelOffset` (applying dyld chained fixups rebase or arm64e PAC strip).
    public func decodePointer(atSliceRelOffset rel: Int) -> UInt64 {
        guard rel >= 0 && rel + 8 <= sliceSize else { return 0 }
        if let fixed = chainedFixupPointers[rel] {
            return fixed
        }
        let absOff = sliceOffset + rel
        let raw = mapped.readUInt64(at: absOff)
        if raw == 0 { return 0 }
        if raw >= baseVM && raw < maxVM { return raw }
        // Strip PAC / high tag bits (bits 48..63) if tagged pointer
        let masked = raw & 0x0000_FFFF_FFFF_FFFF
        if masked >= baseVM && masked < maxVM {
            return masked
        }
        // Relative offset form (rare dyld chained fallback)
        let low36 = raw & 0x000F_FFFF_FFFF
        if low36 > 0 && baseVM + low36 < maxVM && (raw >> 63) == 0 && (raw & 0x00FF_0000_0000_0000) != 0 {
            return baseVM + low36
        }
        return 0
    }

    @inline(__always)
    public func readSliceUInt32(_ rel: Int) -> UInt32 {
        guard rel >= 0 && rel + 4 <= sliceSize else { return 0 }
        return mapped.readUInt32(at: sliceOffset + rel)
    }

    @inline(__always)
    public func readSliceInt32(_ rel: Int) -> Int32 {
        return Int32(bitPattern: readSliceUInt32(rel))
    }

    @inline(__always)
    public func readSliceUInt16(_ rel: Int) -> UInt16 {
        guard rel >= 0 && rel + 2 <= sliceSize else { return 0 }
        return mapped.readUInt16(at: sliceOffset + rel)
    }

    @inline(__always)
    public func readSliceUInt8(_ rel: Int) -> UInt8 {
        guard rel >= 0 && rel < sliceSize else { return 0 }
        return mapped.readUInt8(at: sliceOffset + rel)
    }

    @inline(__always)
    public func readSliceCString(_ rel: Int, maxLength: Int = 256) -> String {
        guard rel >= 0 && rel < sliceSize else { return "" }
        return mapped.readCString(at: sliceOffset + rel, maxLength: min(maxLength, sliceSize - rel))
    }

    private func decodeChainedFixups(dataOff: Int, dataSize: Int) {
        guard dataOff >= 0 && dataSize >= 28 && dataOff + dataSize <= sliceSize else { return }
        let absBase = sliceOffset + dataOff
        let startsOffset = Int(mapped.readUInt32(at: absBase + 4))
        guard startsOffset >= 0 && startsOffset + 4 <= dataSize else { return }

        let startsAbs = absBase + startsOffset
        let segCount = Int(mapped.readUInt32(at: startsAbs))
        guard segCount > 0 && segCount <= 64 else { return }

        var fixups: [Int: UInt64] = [:]
        fixups.reserveCapacity(8192)
        hasChainedFixups = true

        for sIdx in 0..<segCount {
            let segInfoOffPos = startsAbs + 4 + sIdx * 4
            guard segInfoOffPos + 4 <= absBase + dataSize else { break }
            let segInfoRel = Int(mapped.readUInt32(at: segInfoOffPos))
            if segInfoRel == 0 { continue }

            let segAbs = startsAbs + segInfoRel
            guard segAbs + 24 <= absBase + dataSize else { continue }
            let pageSize = Int(mapped.readUInt16(at: segAbs + 4))
            let pointerFormat = mapped.readUInt16(at: segAbs + 6)
            let segOffset = mapped.readUInt64(at: segAbs + 8)
            let pageCount = Int(mapped.readUInt16(at: segAbs + 20))
            guard pageSize > 0 && pageCount > 0 && segAbs + 22 + pageCount * 2 <= absBase + dataSize else { continue }

            for pIdx in 0..<pageCount {
                let pageStart = mapped.readUInt16(at: segAbs + 22 + pIdx * 2)
                if pageStart == 0xFFFF { continue }
                var curRel = Int(segOffset) + pIdx * pageSize + Int(pageStart)
                var guardSteps = 0

                while curRel >= 0 && curRel + 8 <= sliceSize && guardSteps < 4096 {
                    guardSteps += 1
                    let raw = mapped.readUInt64(at: sliceOffset + curRel)
                    var nextStride = 0
                    var strideUnit = 4

                    switch pointerFormat {
                    case 1: // DYLD_CHAINED_PTR_ARM64E
                        strideUnit = 8
                        let isBind = (raw >> 62) & 1
                        let isAuth = (raw >> 63) & 1
                        if isBind == 0 {
                            if isAuth == 1 {
                                let targetLow = raw & 0xFFFF_FFFF
                                let targetVM = baseVM + targetLow
                                if targetVM >= baseVM && targetVM < maxVM { fixups[curRel] = targetVM }
                                nextStride = Int((raw >> 51) & 0x7FF)
                            } else {
                                let targetLow = raw & 0x07FF_FFFF_FFFF
                                let high8 = (raw >> 43) & 0xFF
                                let targetVM = (high8 << 56) | targetLow
                                if targetVM >= baseVM && targetVM < maxVM { fixups[curRel] = targetVM }
                                else if baseVM + targetLow < maxVM { fixups[curRel] = baseVM + targetLow }
                                nextStride = Int((raw >> 51) & 0x7FF)
                            }
                        } else {
                            nextStride = Int((raw >> 51) & 0x7FF)
                        }
                    case 2: // DYLD_CHAINED_PTR_64
                        strideUnit = 4
                        let isBind = (raw >> 63) & 1
                        if isBind == 0 {
                            let targetLow = raw & 0x000F_FFFF_FFFF
                            let targetVM = baseVM + targetLow
                            if targetVM >= baseVM && targetVM < maxVM {
                                fixups[curRel] = targetVM
                            }
                        }
                        nextStride = Int((raw >> 51) & 0xFFF)
                    case 6: // DYLD_CHAINED_PTR_64_OFFSET
                        strideUnit = 4
                        let isBind = (raw >> 63) & 1
                        if isBind == 0 {
                            let targetLow = raw & 0x000F_FFFF_FFFF
                            let targetVM = baseVM + targetLow
                            if targetVM >= baseVM && targetVM < maxVM {
                                fixups[curRel] = targetVM
                            }
                        }
                        nextStride = Int((raw >> 51) & 0xFFF)
                    case 7, 9, 12: // ARM64E_USERLAND / KERNEL / USERLAND24
                        strideUnit = 8
                        let isAuth = (raw >> 63) & 1
                        let isBind = (raw >> 62) & 1
                        if isBind == 0 {
                            let targetOff = isAuth == 1 ? (raw & 0xFFFF_FFFF) : (raw & 0x07FF_FFFF_FFFF)
                            let targetVM = baseVM + targetOff
                            if targetVM >= baseVM && targetVM < maxVM {
                                fixups[curRel] = targetVM
                            }
                        }
                        nextStride = Int((raw >> 51) & 0x7FF)
                    default:
                        nextStride = 0
                    }

                    if nextStride == 0 { break }
                    curRel += nextStride * strideUnit
                }
            }
        }
        self.chainedFixupPointers = fixups
    }

    private func decodeFunctionStarts(dataOff: Int, dataSize: Int) {
        guard dataOff >= 0 && dataSize > 0 && dataOff + dataSize <= sliceSize else { return }
        var pos = sliceOffset + dataOff
        let end = pos + dataSize
        var curRVA: UInt64 = 0
        var out: [UInt64] = []
        out.reserveCapacity(min(dataSize, 150_000))
        while pos < end {
            let delta = mapped.readULEB128(at: &pos)
            if delta == 0 { break }
            curRVA += delta
            if baseVM + curRVA < maxVM {
                out.append(curRVA)
            }
        }
        self.functionStartsRVA = out
    }

    private func decodeExportsTrie(dataOff: Int, dataSize: Int) {
        guard dataOff >= 0 && dataSize > 0 && dataOff + dataSize <= sliceSize else { return }
        let baseAbs = sliceOffset + dataOff
        let endAbs = baseAbs + dataSize
        var out: [NativeSymbolEntry] = []

        func walk(nodeRel: Int, prefix: String, depth: Int) {
            if depth > 64 || out.count >= 50_000 { return }
            var pos = baseAbs + nodeRel
            guard pos < endAbs else { return }
            let termSize = Int(mapped.readULEB128(at: &pos))
            if termSize > 0 && pos + termSize <= endAbs {
                var infoPos = pos
                let flags = mapped.readULEB128(at: &infoPos)
                if (flags & 0x08) == 0 && (flags & 0x03) == 0 { // regular export
                    let rva = mapped.readULEB128(at: &infoPos)
                    let symName = prefix.hasPrefix("_") ? String(prefix.dropFirst()) : prefix
                    if !symName.isEmpty && !SafetyGuard.isRealMoneyBlocked(symName) {
                        let va = baseVM + rva
                        let fOff = UInt64(vmToSliceRelOffset(va) ?? 0)
                        out.append(NativeSymbolEntry(name: symName, rva: rva, va: va, fileOffset: fOff, source: "DYLD_EXPORT"))
                    }
                } else if SafetyGuard.isRealMoneyBlocked(prefix) {
                    filteredRealMoneyCount += 1
                }
            }
            pos += termSize
            guard pos < endAbs else { return }
            let childCount = Int(mapped.readUInt8(at: pos))
            pos += 1
            for _ in 0..<childCount {
                guard pos < endAbs else { break }
                let edge = mapped.readCString(at: pos, maxLength: min(256, endAbs - pos))
                pos += edge.utf8.count + 1
                guard pos < endAbs else { break }
                let childOffset = Int(mapped.readULEB128(at: &pos))
                if childOffset > 0 && childOffset < dataSize {
                    walk(nodeRel: childOffset, prefix: prefix + edge, depth: depth + 1)
                }
            }
        }

        walk(nodeRel: 0, prefix: "", depth: 0)
        self.exportedSymbols = out
    }

    private func decodeSymtab(symoff: Int, nsyms: Int, stroff: Int, strsize: Int) {
        guard symoff >= 0 && stroff >= 0 && symoff + nsyms * 16 <= sliceSize && stroff + strsize <= sliceSize else { return }
        let limit = min(nsyms, 120_000)
        var out: [NativeSymbolEntry] = []
        out.reserveCapacity(min(limit, 8192))
        for i in 0..<limit {
            let nlistAbs = sliceOffset + symoff + i * 16
            let strx = Int(mapped.readUInt32(at: nlistAbs))
            let nType = mapped.readUInt8(at: nlistAbs + 4)
            let nSect = mapped.readUInt8(at: nlistAbs + 5)
            let nValue = mapped.readUInt64(at: nlistAbs + 8)
            if (nType & 0xE0) != 0 || nSect == 0 || nValue < baseVM || nValue >= maxVM { continue }
            guard strx > 0 && strx < strsize else { continue }
            var name = mapped.readCString(at: sliceOffset + stroff + strx, maxLength: min(256, strsize - strx))
            if name.isEmpty { continue }
            if name.hasPrefix("_") { name.removeFirst() }
            if SafetyGuard.isRealMoneyBlocked(name) {
                filteredRealMoneyCount += 1
                continue
            }
            let rva = nValue - baseVM
            let fOff = UInt64(vmToSliceRelOffset(nValue) ?? 0)
            out.append(NativeSymbolEntry(name: name, rva: rva, va: nValue, fileOffset: fOff, source: "SYMTAB"))
        }
        self.symtabSymbols = out
    }

    public func runDeepNativeScan() {
        buildStringMaps()
        if !fairPlayEncrypted {
            parseObjCClasses()
            parseSwiftTypes()
            scanArm64StringXRefs()
        }
    }

    private func buildStringMaps() {
        for sec in sections where sec.segName == "__TEXT" && sec.sectName == "__cstring" {
            let start = Int(sec.offset)
            let end = min(sliceSize, start + Int(sec.size))
            guard start >= 0 && start < end && !isInEncryptedRange(sliceRelOffset: start) else { continue }
            var p = start
            var count = 0
            while p < end && count < 150_000 {
                let s = readSliceCString(p, maxLength: min(256, end - p))
                let byteLen = s.utf8.count
                if byteLen >= 2 {
                    let vm = sec.addr + UInt64(p - start)
                    cstringByVM[vm] = s
                    count += 1
                }
                p += byteLen + 1
            }
        }

        for sec in sections where sec.sectName == "__objc_selrefs" {
            let start = Int(sec.offset)
            let cnt = Int(sec.size / 8)
            guard start >= 0 && start + cnt * 8 <= sliceSize && !isInEncryptedRange(sliceRelOffset: start) else { continue }
            for i in 0..<min(cnt, 80_000) {
                let targetVM = decodePointer(atSliceRelOffset: start + i * 8)
                if let str = cstringByVM[targetVM] {
                    selRefByVM[sec.addr + UInt64(i * 8)] = str
                } else if let strOff = vmToSliceRelOffset(targetVM), !isInEncryptedRange(sliceRelOffset: strOff) {
                    let s = readSliceCString(strOff, maxLength: 160)
                    if !s.isEmpty {
                        selRefByVM[sec.addr + UInt64(i * 8)] = s
                    }
                }
            }
        }
    }

    private func parseObjCClasses() {
        let clsSecs = sections.filter {
            ($0.sectName == "__objc_classlist" || $0.sectName == "__objc_nlclslist")
                && $0.size >= 8
                && Int($0.offset + $0.size) <= sliceSize
                && !isInEncryptedRange(sliceRelOffset: Int($0.offset))
        }
        var results: [ObjCClassInfo] = []
        var seenVM = Set<UInt64>()

        for sec in clsSecs {
            let cnt = min(Int(sec.size / 8), 40_000)
            let base = Int(sec.offset)
            for i in 0..<cnt {
                let clsVM = decodePointer(atSliceRelOffset: base + i * 8)
                if clsVM == 0 || seenVM.contains(clsVM) { continue }
                seenVM.insert(clsVM)
                guard let clsOff = vmToSliceRelOffset(clsVM), clsOff + 40 <= sliceSize, !isInEncryptedRange(sliceRelOffset: clsOff) else { continue }

                let superVM = decodePointer(atSliceRelOffset: clsOff + 8)
                let roPtrVM = decodePointer(atSliceRelOffset: clsOff + 32) & ~0x7
                guard let roOff = vmToSliceRelOffset(roPtrVM), roOff + 72 <= sliceSize, !isInEncryptedRange(sliceRelOffset: roOff) else { continue }

                let nameVM = decodePointer(atSliceRelOffset: roOff + 24)
                guard let nameOff = vmToSliceRelOffset(nameVM), !isInEncryptedRange(sliceRelOffset: nameOff) else { continue }
                let clsName = readSliceCString(nameOff, maxLength: 160)
                if clsName.isEmpty { continue }

                if SafetyGuard.isRealMoneyBlocked(clsName) {
                    filteredRealMoneyCount += 1
                    continue
                }

                var superName = "NSObject"
                if let supClsOff = vmToSliceRelOffset(superVM), supClsOff + 40 <= sliceSize {
                    let supRoVM = decodePointer(atSliceRelOffset: supClsOff + 32) & ~0x7
                    if let supRoOff = vmToSliceRelOffset(supRoVM), supRoOff + 32 <= sliceSize {
                        let sNameVM = decodePointer(atSliceRelOffset: supRoOff + 24)
                        if let sNameOff = vmToSliceRelOffset(sNameVM) {
                            let sn = readSliceCString(sNameOff, maxLength: 120)
                            if !sn.isEmpty { superName = sn }
                        }
                    }
                }

                var methods: [ObjCMethodInfo] = []
                func readMethodList(at roRel: Int, isClassMethod: Bool) {
                    let mlVM = decodePointer(atSliceRelOffset: roRel + 32)
                    guard mlVM != 0, let mlOff = vmToSliceRelOffset(mlVM), mlOff + 8 <= sliceSize, !isInEncryptedRange(sliceRelOffset: mlOff) else { return }
                    let entsizeAndFlags = readSliceUInt32(mlOff)
                    let mCount = Int(readSliceUInt32(mlOff + 4))
                    let isSmall = (entsizeAndFlags & 0x8000_0000) != 0
                    let entsize = max(isSmall ? 12 : 24, Int(entsizeAndFlags & 0xFFFF))
                    guard mCount > 0 && mCount < 8192 && mlOff + 8 + mCount * entsize <= sliceSize else { return }

                    for mi in 0..<mCount {
                        let mOff = mlOff + 8 + mi * entsize
                        var selName = ""
                        var typeEnc = ""
                        var impVM: UInt64 = 0

                        if isSmall {
                            let relSel = Int64(readSliceInt32(mOff))
                            let relTypes = Int64(readSliceInt32(mOff + 4))
                            let relImp = Int64(readSliceInt32(mOff + 8))
                            if let curVM = sliceRelOffsetToVM(mOff) {
                                let selRefVM = UInt64(bitPattern: Int64(bitPattern: curVM) &+ relSel)
                                if let s = selRefByVM[selRefVM] {
                                    selName = s
                                } else if let selRefOff = vmToSliceRelOffset(selRefVM) {
                                    let direct = readSliceCString(selRefOff, maxLength: 140)
                                    if !direct.isEmpty {
                                        selName = direct
                                    } else {
                                        let ptrVM = decodePointer(atSliceRelOffset: selRefOff)
                                        if let ptrOff = vmToSliceRelOffset(ptrVM) {
                                            selName = readSliceCString(ptrOff, maxLength: 140)
                                        }
                                    }
                                }
                            }
                            if let curTypeVM = sliceRelOffsetToVM(mOff + 4) {
                                let tVM = UInt64(bitPattern: Int64(bitPattern: curTypeVM) &+ relTypes)
                                if let tOff = vmToSliceRelOffset(tVM) {
                                    typeEnc = readSliceCString(tOff, maxLength: 96)
                                }
                            }
                            if let curImpVM = sliceRelOffsetToVM(mOff + 8) {
                                impVM = UInt64(bitPattern: Int64(bitPattern: curImpVM) &+ relImp)
                            }
                        } else {
                            let selVM = decodePointer(atSliceRelOffset: mOff)
                            let tVM = decodePointer(atSliceRelOffset: mOff + 8)
                            impVM = decodePointer(atSliceRelOffset: mOff + 16)
                            if let sOff = vmToSliceRelOffset(selVM) {
                                selName = readSliceCString(sOff, maxLength: 140)
                            }
                            if let tOff = vmToSliceRelOffset(tVM) {
                                typeEnc = readSliceCString(tOff, maxLength: 96)
                            }
                        }

                        guard !selName.isEmpty else { continue }
                        if SafetyGuard.isRealMoneyBlocked("\(clsName).\(selName)") {
                            filteredRealMoneyCount += 1
                            continue
                        }
                        let rva = impVM >= baseVM ? (impVM - baseVM) : 0
                        let fOff = UInt64(vmToSliceRelOffset(impVM) ?? 0)
                        methods.append(ObjCMethodInfo(
                            name: selName,
                            types: typeEnc,
                            rva: rva,
                            va: impVM,
                            fileOffset: fOff,
                            isClassMethod: isClassMethod
                        ))
                    }
                }

                readMethodList(at: roOff, isClassMethod: false)

                let metaVM = decodePointer(atSliceRelOffset: clsOff)
                if let metaOff = vmToSliceRelOffset(metaVM), metaOff != clsOff, metaOff + 40 <= sliceSize {
                    let metaRoVM = decodePointer(atSliceRelOffset: metaOff + 32) & ~0x7
                    if let metaRoOff = vmToSliceRelOffset(metaRoVM), metaRoOff + 40 <= sliceSize {
                        readMethodList(at: metaRoOff, isClassMethod: true)
                    }
                }

                var ivars: [ObjCIvarInfo] = []
                let ivarListVM = decodePointer(atSliceRelOffset: roOff + 48)
                if ivarListVM != 0, let ivOff = vmToSliceRelOffset(ivarListVM), ivOff + 8 <= sliceSize {
                    let entsize = max(32, Int(readSliceUInt32(ivOff) & 0xFFFF))
                    let ivCount = Int(readSliceUInt32(ivOff + 4))
                    if ivCount > 0 && ivCount < 4096 && ivOff + 8 + ivCount * entsize <= sliceSize {
                        for ii in 0..<ivCount {
                            let itemOff = ivOff + 8 + ii * entsize
                            let offPtrVM = decodePointer(atSliceRelOffset: itemOff)
                            let ivNameVM = decodePointer(atSliceRelOffset: itemOff + 8)
                            let ivTypeVM = decodePointer(atSliceRelOffset: itemOff + 16)
                            var ivarOffset: Int32 = 0
                            if let opOff = vmToSliceRelOffset(offPtrVM), opOff + 4 <= sliceSize {
                                ivarOffset = readSliceInt32(opOff)
                            }
                            let ivName = vmToSliceRelOffset(ivNameVM).map { readSliceCString($0, maxLength: 120) } ?? ""
                            let ivType = vmToSliceRelOffset(ivTypeVM).map { readSliceCString($0, maxLength: 96) } ?? ""
                            if !ivName.isEmpty && !SafetyGuard.isRealMoneyBlocked("\(clsName).\(ivName)") {
                                ivars.append(ObjCIvarInfo(name: ivName, typeEncoding: ivType, offset: ivarOffset))
                            }
                        }
                    }
                }

                var props: [ObjCPropertyInfo] = []
                let propListVM = decodePointer(atSliceRelOffset: roOff + 64)
                if propListVM != 0, let prOff = vmToSliceRelOffset(propListVM), prOff + 8 <= sliceSize {
                    let entsize = max(16, Int(readSliceUInt32(prOff) & 0xFFFF))
                    let prCount = Int(readSliceUInt32(prOff + 4))
                    if prCount > 0 && prCount < 4096 && prOff + 8 + prCount * entsize <= sliceSize {
                        for pi in 0..<prCount {
                            let itemOff = prOff + 8 + pi * entsize
                            let pNameVM = decodePointer(atSliceRelOffset: itemOff)
                            let pAttrVM = decodePointer(atSliceRelOffset: itemOff + 8)
                            let pName = vmToSliceRelOffset(pNameVM).map { readSliceCString($0, maxLength: 120) } ?? ""
                            let pAttr = vmToSliceRelOffset(pAttrVM).map { readSliceCString($0, maxLength: 120) } ?? ""
                            if !pName.isEmpty && !SafetyGuard.isRealMoneyBlocked("\(clsName).\(pName)") {
                                props.append(ObjCPropertyInfo(name: pName, attributes: pAttr))
                            }
                        }
                    }
                }

                results.append(ObjCClassInfo(
                    name: clsName,
                    superName: superName,
                    methods: methods,
                    ivars: ivars,
                    properties: props
                ))
            }
        }
        self.objcClasses = results
    }

    private func parseSwiftTypes() {
        guard let swiftSec = sections.first(where: { $0.sectName == "__swift5_types" && $0.size >= 4 && Int($0.offset + $0.size) <= sliceSize && !isInEncryptedRange(sliceRelOffset: Int($0.offset)) }) else {
            return
        }
        let cnt = min(Int(swiftSec.size / 4), 25_000)
        var out: [SwiftTypeInfo] = []
        for i in 0..<cnt {
            let entryOff = Int(swiftSec.offset) + i * 4
            let rel = Int64(readSliceInt32(entryOff))
            guard let entryVM = sliceRelOffsetToVM(entryOff) else { continue }
            let descVM = UInt64(bitPattern: Int64(bitPattern: entryVM) &+ rel)
            guard let descOff = vmToSliceRelOffset(descVM), descOff + 16 <= sliceSize, !isInEncryptedRange(sliceRelOffset: descOff) else { continue }
            let flags = readSliceUInt32(descOff)
            let kindId = flags & 0x1F
            let kindName: String
            switch kindId {
            case 16: kindName = "class"
            case 17: kindName = "struct"
            case 18: kindName = "enum"
            default: kindName = "type"
            }
            let nameRelOff = descOff + 8
            guard let nameFieldVM = sliceRelOffsetToVM(nameRelOff) else { continue }
            let nameVM = UInt64(bitPattern: Int64(bitPattern: nameFieldVM) &+ Int64(readSliceInt32(nameRelOff)))
            guard let nameOff = vmToSliceRelOffset(nameVM) else { continue }
            let name = readSliceCString(nameOff, maxLength: 140)
            if name.isEmpty { continue }
            if SafetyGuard.isRealMoneyBlocked(name) {
                filteredRealMoneyCount += 1
                continue
            }
            out.append(SwiftTypeInfo(
                kind: kindName,
                name: name,
                fields: [],
                descriptorRVA: descVM >= baseVM ? (descVM - baseVM) : 0
            ))
        }
        self.swiftTypes = out
    }

    private func scanArm64StringXRefs() {
        guard let textSec = sections.first(where: { $0.segName == "__TEXT" && $0.sectName == "__text" && $0.size >= 16 && Int($0.offset + $0.size) <= sliceSize && !isInEncryptedRange(sliceRelOffset: Int($0.offset)) }) else {
            return
        }
        let maxBytes = min(Int(textSec.size), 24 * 1024 * 1024)
        let start = Int(textSec.offset)
        let end = start + maxBytes
        var out: [NativeSymbolEntry] = []
        out.reserveCapacity(2048)

        var p = start
        while p + 8 <= end && out.count < 15_000 {
            let w1 = readSliceUInt32(p)
            // ADRP Xd, imm
            if (w1 & 0x9F00_0000) == 0x9000_0000 {
                let rd = Int(w1 & 0x1F)
                let immlo = Int64((w1 >> 29) & 0x3)
                let immhi = Int64((w1 >> 5) & 0x7FFFF)
                var imm21 = (immhi << 2) | immlo
                if (imm21 & 0x10_0000) != 0 { imm21 |= ~0x1F_FFFF }
                let pcVM = textSec.addr + UInt64(p - start)
                let pageBase = (pcVM & ~0xFFF) &+ UInt64(bitPattern: imm21 &* 4096)
                let w2 = readSliceUInt32(p + 4)
                if ((w2 & 0xFFC0_0000) == 0x9100_0000) && Int((w2 >> 5) & 0x1F) == rd {
                    let addImm = UInt64((w2 >> 10) & 0xFFF)
                    let targetVM = pageBase + addImm
                    if let s = cstringByVM[targetVM], s.count >= 4 && s.count <= 96 {
                        if SafetyGuard.isRealMoneyBlocked(s) {
                            filteredRealMoneyCount += 1
                        } else {
                            let rva = pcVM >= baseVM ? (pcVM - baseVM) : 0
                            out.append(NativeSymbolEntry(
                                name: "XRef -> \"\(s)\"",
                                rva: rva,
                                va: pcVM,
                                fileOffset: UInt64(p),
                                source: "ARM64_XREF"
                            ))
                        }
                    }
                }
            }
            p += 4
        }
        self.xrefFunctions = out
    }

    // MARK: - Compact ARM64 Disassembler for Getter / Setter / Const / .ctor Init Values

    public func analyzeArm64FunctionValue(atSliceRelOffset startOff: Int) -> String {
        guard startOff >= 0 && startOff + 4 <= sliceSize && !isInEncryptedRange(sliceRelOffset: startOff) else {
            return ""
        }
        var regs: [Int: Int64] = [:]
        var fregs: [Int: Double] = [:]
        var adrpPages: [Int: UInt64] = [:]
        let steps = min(20, (sliceSize - startOff) / 4)

        for i in 0..<steps {
            let off = startOff + i * 4
            let w = readSliceUInt32(off)
            if w == 0xD65F_03C0 { // RET
                if let f0 = fregs[0] {
                    return String(format: "returns float/double %g", f0)
                }
                if let x0 = regs[0] {
                    return "returns 0x\(String(UInt64(bitPattern: x0), radix: 16, uppercase: true)) (\(x0))"
                }
                return ""
            }
            // ADRP
            if (w & 0x9F00_0000) == 0x9000_0000, let pcVM = sliceRelOffsetToVM(off) {
                let rd = Int(w & 0x1F)
                var imm21 = (Int64((w >> 5) & 0x7FFFF) << 2) | Int64((w >> 29) & 0x3)
                if (imm21 & 0x10_0000) != 0 { imm21 |= ~0x1F_FFFF }
                adrpPages[rd] = (pcVM & ~0xFFF) &+ UInt64(bitPattern: imm21 &* 4096)
                continue
            }
            // ADD imm
            if (w & 0x7F80_0000) == 0x1100_0000 {
                let rd = Int(w & 0x1F)
                let rn = Int((w >> 5) & 0x1F)
                let imm12 = UInt64((w >> 10) & 0xFFF) << (((w >> 22) & 1) == 1 ? 12 : 0)
                if let page = adrpPages[rn] {
                    regs[rd] = Int64(bitPattern: page + imm12)
                } else if let prev = regs[rn] {
                    regs[rd] = prev &+ Int64(bitPattern: imm12)
                }
                continue
            }
            // MOVZ (32-bit / 64-bit)
            if (w & 0x7F80_0000) == 0x5280_0000 {
                let rd = Int(w & 0x1F)
                let imm16 = Int64((w >> 5) & 0xFFFF)
                let hw = Int((w >> 21) & 0x3) * 16
                regs[rd] = imm16 << hw
                continue
            }
            // MOVN
            if (w & 0x7F80_0000) == 0x1280_0000 {
                let rd = Int(w & 0x1F)
                let imm16 = UInt64((w >> 5) & 0xFFFF)
                let hw = Int((w >> 21) & 0x3) * 16
                let is64 = (w >> 31) & 1 == 1
                let val = ~(imm16 << hw)
                regs[rd] = is64 ? Int64(bitPattern: val) : Int64(Int32(truncatingIfNeeded: val))
                continue
            }
            // MOVK
            if (w & 0x7F80_0000) == 0x7280_0000 {
                let rd = Int(w & 0x1F)
                let imm16 = UInt64((w >> 5) & 0xFFFF)
                let hw = Int((w >> 21) & 0x3) * 16
                let cur = UInt64(bitPattern: regs[rd] ?? 0)
                let mask = ~(UInt64(0xFFFF) << hw)
                regs[rd] = Int64(bitPattern: (cur & mask) | (imm16 << hw))
                continue
            }
            // FMOV S0/D0 from Wn/Xn
            if (w & 0xFFE0_FC00) == 0x1E27_0000 || (w & 0xFFE0_FC00) == 0x9E67_0000 {
                let rd = Int(w & 0x1F)
                let rn = Int((w >> 5) & 0x1F)
                if let raw = regs[rn] {
                    if (w >> 31) == 1 {
                        fregs[rd] = Double(bitPattern: UInt64(bitPattern: raw))
                    } else {
                        fregs[rd] = Double(Float(bitPattern: UInt32(truncatingIfNeeded: raw)))
                    }
                }
                continue
            }
            // LDR (literal) S/D
            if (w & 0xBF00_0000) == 0x1C00_0000 {
                let rd = Int(w & 0x1F)
                let isDouble = (w >> 30) & 1 == 1
                var imm19 = Int((w >> 5) & 0x7FFFF)
                if (imm19 & 0x40000) != 0 { imm19 |= ~0x7FFFF }
                let litOff = off + imm19 * 4
                if litOff >= 0 && litOff + 8 <= sliceSize && !isInEncryptedRange(sliceRelOffset: litOff) {
                    if isDouble {
                        fregs[rd] = mapped.readDouble(at: sliceOffset + litOff)
                    } else {
                        fregs[rd] = Double(mapped.readFloat(at: sliceOffset + litOff))
                    }
                }
                continue
            }
        }
        return ""
    }

    public func analyzeArm64CtorInits(atSliceRelOffset startOff: Int) -> [Int32: String] {
        guard startOff >= 0 && startOff + 4 <= sliceSize && !isInEncryptedRange(sliceRelOffset: startOff) else {
            return [:]
        }
        var result: [Int32: String] = [:]
        var regs: [Int: Int64] = [:]
        var fregs: [Int: Double] = [:]
        var thisRegs: Set<Int> = [0]
        let steps = min(36, (sliceSize - startOff) / 4)

        for i in 0..<steps {
            let off = startOff + i * 4
            let w = readSliceUInt32(off)
            if w == 0xD65F_03C0 { break }

            // ORR Xd, XZR, Xm (MOV Xd, Xm)
            if (w & 0xFFE0_FFE0) == 0xAA00_03E0 {
                let rd = Int(w & 0x1F)
                let rm = Int((w >> 16) & 0x1F)
                if thisRegs.contains(rm) { thisRegs.insert(rd) }
                else if let v = regs[rm] { regs[rd] = v }
                continue
            }
            if (w & 0x7F80_0000) == 0x5280_0000 {
                let rd = Int(w & 0x1F)
                regs[rd] = Int64((w >> 5) & 0xFFFF) << (Int((w >> 21) & 0x3) * 16)
                thisRegs.remove(rd)
                continue
            }
            if (w & 0x7F80_0000) == 0x7280_0000 {
                let rd = Int(w & 0x1F)
                let imm16 = UInt64((w >> 5) & 0xFFFF)
                let hw = Int((w >> 21) & 0x3) * 16
                let cur = UInt64(bitPattern: regs[rd] ?? 0)
                regs[rd] = Int64(bitPattern: (cur & ~(UInt64(0xFFFF) << hw)) | (imm16 << hw))
                continue
            }
            // STR Wn/Xn, [Xthis, #imm12]
            if (w & 0xBFC0_0000) == 0xB900_0000 {
                let rt = Int(w & 0x1F)
                let rn = Int((w >> 5) & 0x1F)
                let is64 = (w >> 30) & 1 == 1
                let imm = Int32((w >> 10) & 0xFFF) * (is64 ? 8 : 4)
                if thisRegs.contains(rn) && imm >= 0x10 && imm < 0x4000 {
                    if rt == 31 {
                        result[imm] = "0"
                    } else if let v = regs[rt] {
                        result[imm] = "\(v)"
                    }
                }
                continue
            }
            // STR Sn/Dn, [Xthis, #imm12]
            if (w & 0xBFC0_0000) == 0xBD00_0000 {
                let rt = Int(w & 0x1F)
                let rn = Int((w >> 5) & 0x1F)
                let is64 = (w >> 30) & 1 == 1
                let imm = Int32((w >> 10) & 0xFFF) * (is64 ? 8 : 4)
                if thisRegs.contains(rn) && imm >= 0x10 && imm < 0x4000, let fv = fregs[rt] {
                    result[imm] = String(format: "%g", fv)
                }
                continue
            }
        }
        return result
    }
}
