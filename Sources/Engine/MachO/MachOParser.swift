import Foundation

public final class MachOParser {
    public let mapped: MappedFile
    public let sourceName: String
    public let sliceOffset: Int
    public let sliceSize: Int
    public let baseVM: UInt64
    public let minValidVM: UInt64
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
                    let segName = Self.readFixedName(in: mapped, at: cmdPos + 8, length: 16)
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
                        let sectName = Self.readFixedName(in: mapped, at: secPos, length: 16)
                        let sSegName = Self.readFixedName(in: mapped, at: secPos + 16, length: 16)
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
            case 0x22, 0x80000022: // LC_DYLD_INFO / LC_DYLD_INFO_ONLY
                if cmdsize >= 48 {
                    let exportOff = mapped.readUInt32(at: cmdPos + 40)
                    let exportSize = mapped.readUInt32(at: cmdPos + 44)
                    if exportsTrieCmd == nil && exportSize > 0 {
                        exportsTrieCmd = (exportOff, exportSize)
                    }
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
        self.minValidVM = sections.map(\.addr).filter { $0 > self.baseVM }.min() ?? (self.baseVM + 0x1000)
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

    private static func readFixedName(in mapped: MappedFile, at offset: Int, length: Int) -> String {
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
        guard vm != 0 && vm >= baseVM else { return nil }
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
        if raw >= minValidVM && raw < maxVM { return raw }
        // Strip PAC / high tag bits (bits 48..63) if tagged pointer
        let masked = raw & 0x0000_FFFF_FFFF_FFFF
        if masked >= minValidVM && masked < maxVM {
            return masked
        }
        // Relative offset form (rare dyld chained fallback)
        let low36 = raw & 0x000F_FFFF_FFFF
        let relTarget = baseVM + low36
        if low36 > 0 && relTarget >= minValidVM && relTarget < maxVM && (raw >> 63) == 0 && (raw & 0x00FF_0000_0000_0000) != 0 {
            return relTarget
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

            func walkChain(startRel: Int, pageEndRel: Int) {
                var curRel = startRel
                var guardSteps = 0

                while curRel >= startRel && curRel + 8 <= pageEndRel && guardSteps < 4096 {
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
                                if targetVM >= minValidVM && targetVM < maxVM { fixups[curRel] = targetVM }
                                nextStride = Int((raw >> 51) & 0x7FF)
                            } else {
                                let targetLow = raw & 0x07FF_FFFF_FFFF
                                let high8 = (raw >> 43) & 0xFF
                                let absTarget = (high8 << 56) | targetLow
                                if absTarget >= minValidVM && absTarget < maxVM {
                                    fixups[curRel] = absTarget
                                } else if baseVM + targetLow >= minValidVM && baseVM + targetLow < maxVM {
                                    fixups[curRel] = baseVM + targetLow
                                }
                                nextStride = Int((raw >> 51) & 0x7FF)
                            }
                        } else {
                            nextStride = Int((raw >> 51) & 0x7FF)
                        }
                    case 2: // DYLD_CHAINED_PTR_64 (target is full VM address, high8 top byte)
                        strideUnit = 4
                        let isBind = (raw >> 63) & 1
                        if isBind == 0 {
                            let targetLow = raw & 0x000F_FFFF_FFFF
                            let high8 = (raw >> 36) & 0xFF
                            let absTarget = (high8 << 56) | targetLow
                            if absTarget >= minValidVM && absTarget < maxVM {
                                fixups[curRel] = absTarget
                            } else if targetLow >= minValidVM && targetLow < maxVM {
                                fixups[curRel] = targetLow
                            } else if baseVM + targetLow >= minValidVM && baseVM + targetLow < maxVM {
                                fixups[curRel] = baseVM + targetLow
                            }
                        }
                        nextStride = Int((raw >> 51) & 0xFFF)
                    case 6: // DYLD_CHAINED_PTR_64_OFFSET
                        strideUnit = 4
                        let isBind = (raw >> 63) & 1
                        if isBind == 0 {
                            let targetLow = raw & 0x000F_FFFF_FFFF
                            let targetVM = baseVM + targetLow
                            if targetVM >= minValidVM && targetVM < maxVM {
                                fixups[curRel] = targetVM
                            }
                        }
                        nextStride = Int((raw >> 51) & 0xFFF)
                    case 7: // DYLD_CHAINED_PTR_ARM64E_KERNEL (stride 4)
                        strideUnit = 4
                        let isAuth = (raw >> 63) & 1
                        let isBind = (raw >> 62) & 1
                        if isBind == 0 {
                            let targetOff = isAuth == 1 ? (raw & 0xFFFF_FFFF) : (raw & 0x07FF_FFFF_FFFF)
                            let targetVM = baseVM + targetOff
                            if targetVM >= minValidVM && targetVM < maxVM {
                                fixups[curRel] = targetVM
                            }
                        }
                        nextStride = Int((raw >> 51) & 0x7FF)
                    case 9, 12: // DYLD_CHAINED_PTR_ARM64E_USERLAND / USERLAND24 (stride 8)
                        strideUnit = 8
                        let isAuth = (raw >> 63) & 1
                        let isBind = (raw >> 62) & 1
                        if isBind == 0 {
                            let targetOff = isAuth == 1 ? (raw & 0xFFFF_FFFF) : (raw & 0x07FF_FFFF_FFFF)
                            let targetVM = baseVM + targetOff
                            if targetVM >= minValidVM && targetVM < maxVM {
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

            for pIdx in 0..<pageCount {
                let pageStart = mapped.readUInt16(at: segAbs + 22 + pIdx * 2)
                if pageStart == 0xFFFF { continue }
                let pageBaseVM = baseVM &+ segOffset &+ UInt64(pIdx) &* UInt64(pageSize)
                guard let pageBaseRel = vmToSliceRelOffset(pageBaseVM) else { continue }
                let pageEndRel = min(sliceSize, pageBaseRel + pageSize)
                if (pageStart & 0x8000) == 0 {
                    walkChain(startRel: pageBaseRel + Int(pageStart), pageEndRel: pageEndRel)
                } else {
                    var ovIdx = Int(pageStart & 0x7FFF)
                    var ovGuard = 0
                    while ovGuard < 2048 && segAbs + 22 + ovIdx * 2 + 2 <= absBase + dataSize {
                        ovGuard += 1
                        let ovEntry = mapped.readUInt16(at: segAbs + 22 + ovIdx * 2)
                        walkChain(startRel: pageBaseRel + Int(ovEntry & 0x7FFF), pageEndRel: pageEndRel)
                        if (ovEntry & 0x8000) != 0 { break }
                        ovIdx += 1
                    }
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
        self.functionStartsRVA = Array(Set(out)).sorted()
    }

    private func decodeExportsTrie(dataOff: Int, dataSize: Int) {
        guard dataOff >= 0 && dataSize > 0 && dataOff + dataSize <= sliceSize else { return }
        let baseAbs = sliceOffset + dataOff
        let endAbs = baseAbs + dataSize
        var out: [NativeSymbolEntry] = []
        var nodesVisited = 0

        func walk(nodeRel: Int, prefix: String, depth: Int) {
            if depth > 128 || nodesVisited >= 2_000_000 { return }
            nodesVisited += 1
            var pos = baseAbs + nodeRel
            guard pos < endAbs else { return }
            let termSize = Int(mapped.readULEB128(at: &pos))
            if termSize > 0 && pos + termSize <= endAbs {
                var infoPos = pos
                let flags = mapped.readULEB128(at: &infoPos)
                if (flags & 0x08) == 0 { // not re-export
                    let symName = prefix.hasPrefix("_") ? String(prefix.dropFirst()) : prefix
                    if (flags & 0x10) != 0 { // STUB_AND_RESOLVER
                        let stubRVA = mapped.readULEB128(at: &infoPos)
                        _ = mapped.readULEB128(at: &infoPos) // resolver
                        if !symName.isEmpty {
                            if SafetyGuard.isRealMoneyBlocked(symName) {
                                filteredRealMoneyCount += 1
                            } else {
                                let va = baseVM + stubRVA
                                let fOff = UInt64(vmToSliceRelOffset(va) ?? 0)
                                out.append(NativeSymbolEntry(name: symName, rva: stubRVA, va: va, fileOffset: fOff, source: "DYLD_EXPORT"))
                            }
                        }
                    } else if (flags & 0x03) <= 2 { // REGULAR (0), THREAD_LOCAL (1), ABSOLUTE (2)
                        let rawVal = mapped.readULEB128(at: &infoPos)
                        let isAbs = (flags & 0x03) == 2
                        let va = (isAbs && rawVal >= baseVM) ? rawVal : (baseVM + rawVal)
                        let rva = va >= baseVM ? (va - baseVM) : rawVal
                        if !symName.isEmpty {
                            if SafetyGuard.isRealMoneyBlocked(symName) {
                                filteredRealMoneyCount += 1
                            } else {
                                let fOff = UInt64(vmToSliceRelOffset(va) ?? 0)
                                out.append(NativeSymbolEntry(name: symName, rva: rva, va: va, fileOffset: fOff, source: "DYLD_EXPORT"))
                            }
                        }
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
                let edge = mapped.readCString(at: pos, maxLength: min(512, endAbs - pos))
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
        var out: [NativeSymbolEntry] = []
        out.reserveCapacity(min(nsyms, 65_536))
        for i in 0..<nsyms {
            let nlistAbs = sliceOffset + symoff + i * 16
            let strx = Int(mapped.readUInt32(at: nlistAbs))
            let nType = mapped.readUInt8(at: nlistAbs + 4)
            let nSect = mapped.readUInt8(at: nlistAbs + 5)
            let nValue = mapped.readUInt64(at: nlistAbs + 8)
            if (nType & 0xE0) != 0 || nSect == 0 || nValue < baseVM || nValue >= maxVM { continue }
            guard strx > 0 && strx < strsize else { continue }
            var name = mapped.readCString(at: sliceOffset + stroff + strx, maxLength: min(1024, strsize - strx))
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

    public func runDeepNativeScan(isCancelled: () -> Bool = { false }) {
        defer {
            cstringByVM.removeAll(keepingCapacity: false)
            selRefByVM.removeAll(keepingCapacity: false)
        }
        buildStringMaps(isCancelled: isCancelled)
        if !fairPlayEncrypted && !isCancelled() {
            if functionStartsRVA.isEmpty {
                scanArm64PrologueFunctions(isCancelled: isCancelled)
            }
            if !isCancelled() { parseObjCClasses(isCancelled: isCancelled) }
            if !isCancelled() { parseSwiftTypes(isCancelled: isCancelled) }
            if !isCancelled() { scanArm64StringXRefs(isCancelled: isCancelled) }
        }
    }

    public func makeSnapshot() -> NativeBinarySnapshot {
        NativeBinarySnapshot(
            sourceName: sourceName,
            architecture: architecture,
            baseVM: baseVM,
            fairPlayEncrypted: fairPlayEncrypted,
            cryptId: cryptId,
            objcClasses: objcClasses,
            swiftTypes: swiftTypes,
            exportedSymbols: exportedSymbols,
            symtabSymbols: symtabSymbols,
            functionStartsRVA: functionStartsRVA,
            xrefFunctions: xrefFunctions,
            filteredRealMoneyCount: filteredRealMoneyCount
        )
    }

    private func buildStringMaps(isCancelled: () -> Bool) {
        let basePtr = mapped.baseAddress.advanced(by: sliceOffset)
        for sec in sections where sec.segName == "__TEXT" && (sec.sectName == "__cstring" || sec.sectName == "__objc_methname") {
            let start = Int(sec.offset)
            let end = min(sliceSize, start + Int(sec.size))
            guard start >= 0 && start < end && !isInEncryptedRange(sliceRelOffset: start) else { continue }
            var p = start
            var strCount = 0
            while p < end {
                if (strCount & 0xFFF) == 0 && isCancelled() { return }
                var len = 0
                let maxScan = end - p
                while len < maxScan && basePtr[p + len] != 0 {
                    len += 1
                }
                if len >= 2 && len <= 256 {
                    let vm = sec.addr + UInt64(p - start)
                    let slice = UnsafeBufferPointer(start: basePtr + p, count: len)
                    cstringByVM[vm] = String(decoding: slice, as: UTF8.self)
                }
                strCount += 1
                p += len + 1
            }
        }

        for sec in sections where sec.sectName == "__cfstring" {
            let start = Int(sec.offset)
            let cnt = Int(sec.size / 32)
            guard start >= 0 && start + cnt * 32 <= sliceSize && !isInEncryptedRange(sliceRelOffset: start) else { continue }
            for i in 0..<cnt {
                if (i & 0xFFF) == 0 && isCancelled() { return }
                let entryOff = start + i * 32
                let cstrPtrVM = decodePointer(atSliceRelOffset: entryOff + 16)
                if let str = cstringByVM[cstrPtrVM] {
                    cstringByVM[sec.addr + UInt64(i * 32)] = str
                } else if let strOff = vmToSliceRelOffset(cstrPtrVM), !isInEncryptedRange(sliceRelOffset: strOff) {
                    let s = readSliceCString(strOff, maxLength: 256)
                    if !s.isEmpty {
                        cstringByVM[sec.addr + UInt64(i * 32)] = s
                    }
                }
            }
        }

        for sec in sections where sec.sectName == "__objc_selrefs" {
            let start = Int(sec.offset)
            let cnt = Int(sec.size / 8)
            guard start >= 0 && start + cnt * 8 <= sliceSize && !isInEncryptedRange(sliceRelOffset: start) else { continue }
            for i in 0..<cnt {
                if (i & 0xFFF) == 0 && isCancelled() { return }
                let targetVM = decodePointer(atSliceRelOffset: start + i * 8)
                if let str = cstringByVM[targetVM] {
                    selRefByVM[sec.addr + UInt64(i * 8)] = str
                } else if let strOff = vmToSliceRelOffset(targetVM), !isInEncryptedRange(sliceRelOffset: strOff) {
                    let s = readSliceCString(strOff, maxLength: 256)
                    if !s.isEmpty {
                        selRefByVM[sec.addr + UInt64(i * 8)] = s
                    }
                }
            }
        }
    }

    private func parseObjCClasses(isCancelled: () -> Bool) {
        let clsSecs = sections.filter {
            ($0.sectName == "__objc_classlist" || $0.sectName == "__objc_nlclslist")
                && $0.size >= 8
                && Int($0.offset + $0.size) <= sliceSize
                && !isInEncryptedRange(sliceRelOffset: Int($0.offset))
        }
        var results: [ObjCClassInfo] = []
        var seenVM = Set<UInt64>()

        for sec in clsSecs {
            let cnt = Int(sec.size / 8)
            let base = Int(sec.offset)
            for i in 0..<cnt {
                if (i & 0xFF) == 0 && isCancelled() { break }
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
                    let isDirect = (entsizeAndFlags & 0x4000_0000) != 0
                    let entsize = max(isSmall ? 12 : 24, Int(entsizeAndFlags & 0xFFFF))
                    guard mCount > 0 && mCount < 65_536 && mlOff + 8 + mCount * entsize <= sliceSize else { return }

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
                                    if isDirect {
                                        let direct = readSliceCString(selRefOff, maxLength: 200)
                                        if !direct.isEmpty {
                                            selName = direct
                                        }
                                    } else {
                                        let ptrVM = decodePointer(atSliceRelOffset: selRefOff)
                                        if let ptrOff = vmToSliceRelOffset(ptrVM) {
                                            selName = readSliceCString(ptrOff, maxLength: 200)
                                        }
                                        if selName.isEmpty {
                                            selName = readSliceCString(selRefOff, maxLength: 200)
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
                    if ivCount > 0 && ivCount < 65_536 && ivOff + 8 + ivCount * entsize <= sliceSize {
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
                    if prCount > 0 && prCount < 65_536 && prOff + 8 + prCount * entsize <= sliceSize {
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

        // Obj-C Categories (__objc_catlist / __objc_nlcatlist)
        let catSecs = sections.filter {
            ($0.sectName == "__objc_catlist" || $0.sectName == "__objc_nlcatlist") && $0.size >= 8
                && Int($0.offset + $0.size) <= sliceSize
                && !isInEncryptedRange(sliceRelOffset: Int($0.offset))
        }
        var seenCatVM = Set<UInt64>()
        for sec in catSecs {
            let cnt = Int(sec.size / 8)
            let base = Int(sec.offset)
            for i in 0..<cnt {
                let catVM = decodePointer(atSliceRelOffset: base + i * 8)
                guard catVM != 0, !seenCatVM.contains(catVM), let catOff = vmToSliceRelOffset(catVM), catOff + 48 <= sliceSize, !isInEncryptedRange(sliceRelOffset: catOff) else { continue }
                seenCatVM.insert(catVM)
                let catNameVM = decodePointer(atSliceRelOffset: catOff)
                let hostClsVM = decodePointer(atSliceRelOffset: catOff + 8)
                let catName = vmToSliceRelOffset(catNameVM).map { readSliceCString($0, maxLength: 120) } ?? "Category"
                var hostName = "NSObject"
                if let hOff = vmToSliceRelOffset(hostClsVM), hOff + 40 <= sliceSize {
                    let hRoVM = decodePointer(atSliceRelOffset: hOff + 32) & ~0x7
                    if let hRoOff = vmToSliceRelOffset(hRoVM), hRoOff + 32 <= sliceSize {
                        let hnVM = decodePointer(atSliceRelOffset: hRoOff + 24)
                        if let hnOff = vmToSliceRelOffset(hnVM) {
                            let s = readSliceCString(hnOff, maxLength: 120)
                            if !s.isEmpty { hostName = s }
                        }
                    }
                }
                let fullCatName = "\(hostName)+\(catName)"
                if SafetyGuard.isRealMoneyBlocked(fullCatName) {
                    filteredRealMoneyCount += 1
                    continue
                }
                var catMethods: [ObjCMethodInfo] = []
                func readCatMethods(atPtrOff ptrOff: Int, isClassMethod: Bool) {
                    let mlVM = decodePointer(atSliceRelOffset: ptrOff)
                    guard mlVM != 0, let mlOff = vmToSliceRelOffset(mlVM), mlOff + 8 <= sliceSize, !isInEncryptedRange(sliceRelOffset: mlOff) else { return }
                    let flagsAndSize = readSliceUInt32(mlOff)
                    let mCnt = Int(readSliceUInt32(mlOff + 4))
                    let isSmall = (flagsAndSize & 0x8000_0000) != 0
                    let isDirect = (flagsAndSize & 0x4000_0000) != 0
                    let entsize = max(isSmall ? 12 : 24, Int(flagsAndSize & 0xFFFF))
                    guard mCnt > 0 && mCnt < 65_536 && mlOff + 8 + mCnt * entsize <= sliceSize else { return }
                    for mi in 0..<mCnt {
                        let mOff = mlOff + 8 + mi * entsize
                        var selName = ""
                        var impVM: UInt64 = 0
                        if isSmall {
                            let relSel = Int64(readSliceInt32(mOff))
                            let relImp = Int64(readSliceInt32(mOff + 8))
                            if let curVM = sliceRelOffsetToVM(mOff) {
                                let selRefVM = UInt64(bitPattern: Int64(bitPattern: curVM) &+ relSel)
                                if let s = selRefByVM[selRefVM] {
                                    selName = s
                                } else if let selRefOff = vmToSliceRelOffset(selRefVM) {
                                    if isDirect {
                                        selName = readSliceCString(selRefOff, maxLength: 200)
                                    } else {
                                        let ptrVM = decodePointer(atSliceRelOffset: selRefOff)
                                        if let ptrOff = vmToSliceRelOffset(ptrVM) {
                                            selName = readSliceCString(ptrOff, maxLength: 200)
                                        }
                                        if selName.isEmpty {
                                            selName = readSliceCString(selRefOff, maxLength: 200)
                                        }
                                    }
                                }
                            }
                            if let curImpVM = sliceRelOffsetToVM(mOff + 8) {
                                impVM = UInt64(bitPattern: Int64(bitPattern: curImpVM) &+ relImp)
                            }
                        } else {
                            let selVM = decodePointer(atSliceRelOffset: mOff)
                            impVM = decodePointer(atSliceRelOffset: mOff + 16)
                            selName = vmToSliceRelOffset(selVM).map { readSliceCString($0, maxLength: 140) } ?? ""
                        }
                        if selName.isEmpty { continue }
                        if SafetyGuard.isRealMoneyBlocked("\(fullCatName).\(selName)") {
                            filteredRealMoneyCount += 1
                            continue
                        }
                        let rva = impVM >= baseVM ? (impVM - baseVM) : 0
                        let fOff = UInt64(vmToSliceRelOffset(impVM) ?? 0)
                        catMethods.append(ObjCMethodInfo(name: selName, types: "", rva: rva, va: impVM, fileOffset: fOff, isClassMethod: isClassMethod))
                    }
                }
                readCatMethods(atPtrOff: catOff + 16, isClassMethod: false)
                readCatMethods(atPtrOff: catOff + 24, isClassMethod: true)
                if !catMethods.isEmpty {
                    results.append(ObjCClassInfo(name: fullCatName, superName: hostName, methods: catMethods, ivars: [], properties: []))
                }
            }
        }

        // Obj-C Protocols (__objc_protolist)
        let protoSecs = sections.filter {
            $0.sectName == "__objc_protolist" && $0.size >= 8
                && Int($0.offset + $0.size) <= sliceSize
                && !isInEncryptedRange(sliceRelOffset: Int($0.offset))
        }
        var seenProto = Set<String>()
        for sec in protoSecs {
            let cnt = Int(sec.size / 8)
            let base = Int(sec.offset)
            for i in 0..<cnt {
                let pVM = decodePointer(atSliceRelOffset: base + i * 8)
                guard pVM != 0, let pOff = vmToSliceRelOffset(pVM), pOff + 24 <= sliceSize, !isInEncryptedRange(sliceRelOffset: pOff) else { continue }
                let nmVM = decodePointer(atSliceRelOffset: pOff + 8)
                let pName = vmToSliceRelOffset(nmVM).map { readSliceCString($0, maxLength: 140) } ?? ""
                if pName.isEmpty || seenProto.contains(pName) { continue }
                seenProto.insert(pName)
                if SafetyGuard.isRealMoneyBlocked(pName) {
                    filteredRealMoneyCount += 1
                    continue
                }
                results.append(ObjCClassInfo(name: "Protocol::\(pName)", superName: "Protocol", methods: [], ivars: [], properties: []))
            }
        }

        self.objcClasses = results
    }

    private func parseSwiftTypes(isCancelled: () -> Bool) {
        var out: [SwiftTypeInfo] = []
        var seenDesc = Set<UInt64>()

        for swiftSec in sections where swiftSec.sectName == "__swift5_types" && swiftSec.size >= 4 && Int(swiftSec.offset + swiftSec.size) <= sliceSize && !isInEncryptedRange(sliceRelOffset: Int(swiftSec.offset)) {
            let cnt = Int(swiftSec.size / 4)
            for i in 0..<cnt {
                if (i & 0xFF) == 0 && isCancelled() { break }
                let entryOff = Int(swiftSec.offset) + i * 4
                let rel = Int64(readSliceInt32(entryOff))
                guard let entryVM = sliceRelOffsetToVM(entryOff) else { continue }
                let descVM = UInt64(bitPattern: Int64(bitPattern: entryVM) &+ rel)
                if seenDesc.contains(descVM) { continue }
                seenDesc.insert(descVM)
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
                let name = readSliceCString(nameOff, maxLength: 200)
                if name.isEmpty { continue }
                if SafetyGuard.isRealMoneyBlocked(name) {
                    filteredRealMoneyCount += 1
                    continue
                }

                var fields: [String] = []
                if kindId >= 16 && kindId <= 18 && descOff + 20 <= sliceSize {
                    let fdRelOff = descOff + 16
                    if let fdFieldVM = sliceRelOffsetToVM(fdRelOff) {
                        let fdPtrRel = Int64(readSliceInt32(fdRelOff))
                        if fdPtrRel != 0 {
                            let fdVM = UInt64(bitPattern: Int64(bitPattern: fdFieldVM) &+ fdPtrRel)
                            if let fdOff = vmToSliceRelOffset(fdVM), fdOff + 16 <= sliceSize, !isInEncryptedRange(sliceRelOffset: fdOff) {
                                let recordSize = Int(readSliceUInt16(fdOff + 10))
                                let numFields = Int(readSliceUInt32(fdOff + 12))
                                if recordSize >= 12 && numFields > 0 && numFields <= 2048 && fdOff + 16 + numFields * recordSize <= sliceSize {
                                    for fi in 0..<numFields {
                                        let recOff = fdOff + 16 + fi * recordSize
                                        let fNameFieldOff = recOff + 8
                                        guard let fnVMBase = sliceRelOffsetToVM(fNameFieldOff) else { continue }
                                        let fnRel = Int64(readSliceInt32(fNameFieldOff))
                                        if fnRel == 0 { continue }
                                        let fnVM = UInt64(bitPattern: Int64(bitPattern: fnVMBase) &+ fnRel)
                                        if let fnOff = vmToSliceRelOffset(fnVM) {
                                            let fname = readSliceCString(fnOff, maxLength: 140)
                                            if !fname.isEmpty && !SafetyGuard.isRealMoneyBlocked("\(name).\(fname)") {
                                                fields.append(fname)
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                out.append(SwiftTypeInfo(
                    kind: kindName,
                    name: name,
                    fields: fields,
                    descriptorRVA: descVM >= baseVM ? (descVM - baseVM) : 0
                ))
            }
        }

        for protoSec in sections where protoSec.sectName == "__swift5_protos" && protoSec.size >= 4 && Int(protoSec.offset + protoSec.size) <= sliceSize && !isInEncryptedRange(sliceRelOffset: Int(protoSec.offset)) {
            let cnt = Int(protoSec.size / 4)
            for i in 0..<cnt {
                let entryOff = Int(protoSec.offset) + i * 4
                let rel = Int64(readSliceInt32(entryOff))
                guard let entryVM = sliceRelOffsetToVM(entryOff) else { continue }
                let descVM = UInt64(bitPattern: Int64(bitPattern: entryVM) &+ rel)
                if seenDesc.contains(descVM) { continue }
                seenDesc.insert(descVM)
                guard let descOff = vmToSliceRelOffset(descVM), descOff + 12 <= sliceSize, !isInEncryptedRange(sliceRelOffset: descOff) else { continue }
                let nameRelOff = descOff + 8
                guard let nameFieldVM = sliceRelOffsetToVM(nameRelOff) else { continue }
                let nameVM = UInt64(bitPattern: Int64(bitPattern: nameFieldVM) &+ Int64(readSliceInt32(nameRelOff)))
                guard let nameOff = vmToSliceRelOffset(nameVM) else { continue }
                let name = readSliceCString(nameOff, maxLength: 200)
                if name.isEmpty { continue }
                if SafetyGuard.isRealMoneyBlocked(name) {
                    filteredRealMoneyCount += 1
                    continue
                }
                out.append(SwiftTypeInfo(
                    kind: "protocol",
                    name: name,
                    fields: [],
                    descriptorRVA: descVM >= baseVM ? (descVM - baseVM) : 0
                ))
            }
        }

        self.swiftTypes = out
    }

    private func executableTextSections() -> [SectionInfo] {
        return sections.filter { sec in
            sec.segName == "__TEXT"
                && (sec.sectName == "__text" || (sec.flags & 0x8000_0000) != 0)
                && sec.sectName != "__stubs"
                && sec.size >= 16
                && Int(sec.offset + sec.size) <= sliceSize
                && !isInEncryptedRange(sliceRelOffset: Int(sec.offset))
        }
    }

    private func enclosingFunctionStartRVA(forRVA rva: UInt64) -> UInt64? {
        guard !functionStartsRVA.isEmpty else { return nil }
        var lo = 0
        var hi = functionStartsRVA.count - 1
        var best: Int = -1
        while lo <= hi {
            let mid = (lo + hi) >> 1
            let v = functionStartsRVA[mid]
            if v <= rva {
                best = mid
                lo = mid + 1
            } else {
                hi = mid - 1
            }
        }
        return best >= 0 ? functionStartsRVA[best] : nil
    }

    private func scanArm64PrologueFunctions(isCancelled: () -> Bool) {
        let execSecs = executableTextSections()
        guard !execSecs.isEmpty else { return }
        var starts: [UInt64] = []

        @inline(__always)
        func isTerminatorOrPad(_ w: UInt32) -> Bool {
            if w == 0xD65F_03C0 || w == 0xD65F_0BFF || w == 0xD65F_0FFF || w == 0xD503_201F {
                return true
            }
            let top16 = w >> 16
            if top16 == 0xD420 || top16 == 0x0000 { // BRK / UDF 0
                return true
            }
            if (w & 0xFC00_0000) == 0x1400_0000 { // unconditional B
                return true
            }
            return false
        }

        for sec in execSecs {
            let start = Int(sec.offset)
            let end = min(sliceSize, start + Int(sec.size))
            guard start + 8 <= end else { continue }
            var p = start
            while p + 4 <= end {
                if ((p - start) & 0xFFFF) == 0 && isCancelled() { return }
                let w = readSliceUInt32(p)
                let isPac = (w == 0xD503_237F)
                let isFrameStp = ((w & 0xFFE0_7FFF) == 0xA9A0_7BFD) // STP X29, X30, [SP, #imm]!
                if isPac || isFrameStp {
                    if p == start || isTerminatorOrPad(readSliceUInt32(p - 4)) {
                        let pcVM = sec.addr + UInt64(p - start)
                        if pcVM >= baseVM && pcVM < maxVM {
                            starts.append(pcVM - baseVM)
                        }
                        if isPac { p += 4 } // skip checking next frame STP if preceded by PACIBSP
                    }
                }
                p += 4
            }
        }

        if !starts.isEmpty {
            self.functionStartsRVA = Array(Set(starts)).sorted()
        }
    }

    private func scanArm64StringXRefs(isCancelled: () -> Bool) {
        let execSecs = executableTextSections()
        guard !execSecs.isEmpty && !cstringByVM.isEmpty else { return }
        struct XRefKey: Hashable {
            let f: UInt64
            let t: UInt64
        }
        var out: [NativeSymbolEntry] = []
        var seenXRefs = Set<XRefKey>()
        out.reserveCapacity(8192)

        for textSec in execSecs {
            let start = Int(textSec.offset)
            let end = min(sliceSize, start + Int(textSec.size))
            var p = start
            while p + 8 <= end {
                if ((p - start) & 0xFFFF) == 0 && isCancelled() { break }
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

                    func emitXRef(str s: String, targetVM: UInt64, pcVM: UInt64, fileOff: Int) {
                        guard s.count >= 4 && s.count <= 96 else { return }
                        let rva = pcVM >= baseVM ? (pcVM - baseVM) : 0
                        let enclosing = enclosingFunctionStartRVA(forRVA: rva)
                        let key = XRefKey(f: enclosing ?? rva, t: targetVM)
                        guard seenXRefs.insert(key).inserted else { return }
                        if SafetyGuard.isRealMoneyBlocked(s) {
                            filteredRealMoneyCount += 1
                            return
                        }
                        let label: String
                        if let fRVA = enclosing {
                            label = "sub_" + String(fRVA, radix: 16, uppercase: true) + " -> \"" + s + "\""
                        } else {
                            label = "XRef -> \"" + s + "\""
                        }
                        out.append(NativeSymbolEntry(
                            name: label,
                            rva: rva,
                            va: pcVM,
                            fileOffset: UInt64(fileOff),
                            source: "ARM64_XREF"
                        ))
                    }

                    let maxLook = min(4, (end - p) / 4 - 1)
                    if maxLook >= 1 {
                        for step in 1...maxLook {
                            let w2 = readSliceUInt32(p + step * 4)
                            if ((w2 & 0xFFC0_0000) == 0x9100_0000) && Int((w2 >> 5) & 0x1F) == rd {
                                let addImm = UInt64((w2 >> 10) & 0xFFF)
                                let targetVM = pageBase + addImm
                                if let s = cstringByVM[targetVM] {
                                    emitXRef(str: s, targetVM: targetVM, pcVM: pcVM, fileOff: p)
                                }
                                break
                            } else if ((w2 & 0xFFC0_0000) == 0xF940_0000) && Int((w2 >> 5) & 0x1F) == rd {
                                let ldrImm = UInt64((w2 >> 10) & 0xFFF) * 8
                                let targetVM = pageBase + ldrImm
                                if let s = selRefByVM[targetVM] {
                                    emitXRef(str: s, targetVM: targetVM, pcVM: pcVM, fileOff: p)
                                } else if let s = cstringByVM[targetVM] {
                                    emitXRef(str: s, targetVM: targetVM, pcVM: pcVM, fileOff: p)
                                }
                                break
                            }
                            // Stop lookahead if another instruction overwrites rd
                            if (w2 & 0x9F00_0000) == 0x9000_0000 && Int(w2 & 0x1F) == rd {
                                break
                            }
                        }
                    }
                }
                p += 4
            }
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
        let fregs: [Int: Double] = [:]
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
