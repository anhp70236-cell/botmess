import Foundation

public enum MachOParser {
    public static func parse(data: Data, moduleName: String) throws -> ParsedMachOImage {
        let rootCursor = BinaryCursor(data: data)
        guard let rawMagicBE = rootCursor.readUInt32BE(at: 0),
              let rawMagicLE = rootCursor.readUInt32LE(at: 0) else {
            throw AnalysisError.invalidMachOBinary("Binary file is smaller than 4 bytes.")
        }

        var isUniversal = false
        var sliceOffset = 0
        var sliceSize = data.count

        if rawMagicBE == MachOConstants.fatMagic || rawMagicLE == MachOConstants.fatMagic {
            isUniversal = true
            guard let nFatArch = rootCursor.readUInt32BE(at: 4), nFatArch > 0, nFatArch < 32 else {
                throw AnalysisError.invalidMachOBinary("Invalid Universal Fat header architecture count.")
            }
            var selectedOffset: Int?
            var selectedSize: Int?
            for i in 0..<Int(nFatArch) {
                let archPos = 8 + i * 20
                guard let cputype = rootCursor.readUInt32BE(at: archPos),
                      let offset = rootCursor.readUInt32BE(at: archPos + 8),
                      let size = rootCursor.readUInt32BE(at: archPos + 12) else {
                    break
                }
                if cputype == MachOConstants.cpuTypeARM64 || selectedOffset == nil {
                    selectedOffset = Int(offset)
                    selectedSize = Int(size)
                    if cputype == MachOConstants.cpuTypeARM64 {
                        break
                    }
                }
            }
            guard let off = selectedOffset, let sz = selectedSize, rootCursor.hasBytes(at: off, count: sz) else {
                throw AnalysisError.invalidMachOBinary("Universal binary slice exceeds file bounds.")
            }
            sliceOffset = off
            sliceSize = sz
        } else if rawMagicBE == MachOConstants.fatMagic64 || rawMagicLE == MachOConstants.fatMagic64 {
            isUniversal = true
            guard let nFatArch = rootCursor.readUInt32BE(at: 4), nFatArch > 0, nFatArch < 32 else {
                throw AnalysisError.invalidMachOBinary("Invalid Fat64 architecture count.")
            }
            var selectedOffset: Int?
            var selectedSize: Int?
            for i in 0..<Int(nFatArch) {
                let archPos = 8 + i * 32
                guard let cputype = rootCursor.readUInt32BE(at: archPos),
                      let offset = rootCursor.readUInt64BE(at: archPos + 8),
                      let size = rootCursor.readUInt64BE(at: archPos + 16) else {
                    break
                }
                if cputype == MachOConstants.cpuTypeARM64 || selectedOffset == nil {
                    selectedOffset = Int(offset)
                    selectedSize = Int(size)
                    if cputype == MachOConstants.cpuTypeARM64 {
                        break
                    }
                }
            }
            guard let off = selectedOffset, let sz = selectedSize, rootCursor.hasBytes(at: off, count: sz) else {
                throw AnalysisError.invalidMachOBinary("Fat64 binary slice exceeds file bounds.")
            }
            sliceOffset = off
            sliceSize = sz
        }

        guard let sliceData = rootCursor.slice(offset: sliceOffset, length: sliceSize) else {
            throw AnalysisError.invalidMachOBinary("Unable to isolate Mach-O slice.")
        }

        let cursor = BinaryCursor(data: sliceData)
        guard let magic = cursor.readUInt32LE(at: 0) else {
            throw AnalysisError.invalidMachOBinary("Missing Mach-O header magic.")
        }
        if magic == MachOConstants.mhMagic32 {
            throw AnalysisError.unsupportedArchitecture("32-bit Mach-O (armv7) is legacy; ARM64 64-bit Mach-O required.")
        }
        guard magic == MachOConstants.mhMagic64 else {
            throw AnalysisError.invalidMachOBinary(String(format: "Unexpected Mach-O magic 0x%08X", magic))
        }

        // mach_header_64 is 32 bytes
        guard cursor.hasBytes(at: 0, count: 32),
              let cpuType = cursor.readUInt32LE(at: 4),
              let cpuSubtype = cursor.readUInt32LE(at: 8),
              let fileType = cursor.readUInt32LE(at: 12),
              let ncmds = cursor.readUInt32LE(at: 16),
              let sizeofcmds = cursor.readUInt32LE(at: 20),
              let flags = cursor.readUInt32LE(at: 24) else {
            throw AnalysisError.invalidMachOBinary("Truncated mach_header_64.")
        }

        let commandsEnd = 32 + Int(sizeofcmds)
        guard commandsEnd <= cursor.count else {
            throw AnalysisError.invalidMachOBinary("Load commands exceed Mach-O slice size.")
        }

        var segments: [ParsedMachOSegment] = []
        var sectionsByOrdinal: [Int: ParsedMachOSection] = [:]
        var nextSectionOrdinal = 1
        var uuidString: String?
        var minOSVersion: String?
        var entryPointRVA: UInt64?
        var encryptionInfo = ParsedEncryptionInfo(cryptOffset: 0, cryptSize: 0, cryptID: 0)

        var symOff: UInt32 = 0
        var nSyms: UInt32 = 0
        var strOff: UInt32 = 0
        var strSize: UInt32 = 0

        var funcStartsOff: UInt32 = 0
        var funcStartsSize: UInt32 = 0

        var cmdOffset = 32
        for _ in 0..<Int(ncmds) {
            if Task.isCancelled { throw AnalysisError.cancelled }
            guard cmdOffset + 8 <= commandsEnd,
                  let cmd = cursor.readUInt32LE(at: cmdOffset),
                  let cmdSize = cursor.readUInt32LE(at: cmdOffset + 4),
                  cmdSize >= 8,
                  cmdOffset + Int(cmdSize) <= commandsEnd else {
                break
            }

            switch cmd {
            case MachOConstants.lcSegment64:
                if cmdSize >= 72 {
                    let segName = cursor.readFixedString(at: cmdOffset + 8, length: 16)
                    let vmAddr = cursor.readUInt64LE(at: cmdOffset + 24) ?? 0
                    let vmSize = cursor.readUInt64LE(at: cmdOffset + 32) ?? 0
                    let fileOff = cursor.readUInt64LE(at: cmdOffset + 40) ?? 0
                    let fileSize = cursor.readUInt64LE(at: cmdOffset + 48) ?? 0
                    let maxProt = cursor.readInt32LE(at: cmdOffset + 56) ?? 0
                    let initProt = cursor.readInt32LE(at: cmdOffset + 60) ?? 0
                    let nsects = cursor.readUInt32LE(at: cmdOffset + 64) ?? 0

                    var parsedSections: [ParsedMachOSection] = []
                    var sectCursor = cmdOffset + 72
                    for _ in 0..<Int(nsects) {
                        guard sectCursor + 80 <= cmdOffset + Int(cmdSize) else { break }
                        let sectName = cursor.readFixedString(at: sectCursor, length: 16)
                        let sSegName = cursor.readFixedString(at: sectCursor + 16, length: 16)
                        let sAddr = cursor.readUInt64LE(at: sectCursor + 32) ?? 0
                        let sSize = cursor.readUInt64LE(at: sectCursor + 40) ?? 0
                        let sOffset = cursor.readUInt32LE(at: sectCursor + 48) ?? 0
                        let sAlign = cursor.readUInt32LE(at: sectCursor + 52) ?? 0
                        let sFlags = cursor.readUInt32LE(at: sectCursor + 64) ?? 0

                        let sec = ParsedMachOSection(
                            index: nextSectionOrdinal,
                            sectionName: sectName,
                            segmentName: sSegName.isEmpty ? segName : sSegName,
                            address: sAddr,
                            size: sSize,
                            fileOffset: sOffset,
                            alignment: sAlign,
                            flags: sFlags
                        )
                        parsedSections.append(sec)
                        sectionsByOrdinal[nextSectionOrdinal] = sec
                        nextSectionOrdinal += 1
                        sectCursor += 80
                    }

                    segments.append(
                        ParsedMachOSegment(
                            name: segName,
                            vmAddress: vmAddr,
                            vmSize: vmSize,
                            fileOffset: fileOff,
                            fileSize: fileSize,
                            maxProt: maxProt,
                            initProt: initProt,
                            sections: parsedSections
                        )
                    )
                }

            case MachOConstants.lcUUID:
                if cmdSize >= 24, let bytes = cursor.slice(offset: cmdOffset + 8, length: 16) {
                    let b = Array(bytes)
                    uuidString = String(
                        format: "%02X%02X%02X%02X-%02X%02X-%02X%02X-%02X%02X-%02X%02X%02X%02X%02X%02X",
                        b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7],
                        b[8], b[9], b[10], b[11], b[12], b[13], b[14], b[15]
                    )
                }

            case MachOConstants.lcEncryptionInfo64, MachOConstants.lcEncryptionInfo:
                if cmdSize >= 20 {
                    let cOff = cursor.readUInt32LE(at: cmdOffset + 8) ?? 0
                    let cSize = cursor.readUInt32LE(at: cmdOffset + 12) ?? 0
                    let cID = cursor.readUInt32LE(at: cmdOffset + 16) ?? 0
                    encryptionInfo = ParsedEncryptionInfo(cryptOffset: cOff, cryptSize: cSize, cryptID: cID)
                }

            case MachOConstants.lcBuildVersion:
                if cmdSize >= 24, let minOS = cursor.readUInt32LE(at: cmdOffset + 12) {
                    minOSVersion = formatPackedVersion(minOS)
                }

            case MachOConstants.lcVersionMinIPhoneOS:
                if minOSVersion == nil, cmdSize >= 16, let ver = cursor.readUInt32LE(at: cmdOffset + 8) {
                    minOSVersion = formatPackedVersion(ver)
                }

            case MachOConstants.lcMain:
                if cmdSize >= 24 {
                    entryPointRVA = cursor.readUInt64LE(at: cmdOffset + 8)
                }

            case MachOConstants.lcSymtab:
                if cmdSize >= 24 {
                    symOff = cursor.readUInt32LE(at: cmdOffset + 8) ?? 0
                    nSyms = cursor.readUInt32LE(at: cmdOffset + 12) ?? 0
                    strOff = cursor.readUInt32LE(at: cmdOffset + 16) ?? 0
                    strSize = cursor.readUInt32LE(at: cmdOffset + 20) ?? 0
                }

            case MachOConstants.lcFunctionStarts:
                if cmdSize >= 16 {
                    funcStartsOff = cursor.readUInt32LE(at: cmdOffset + 8) ?? 0
                    funcStartsSize = cursor.readUInt32LE(at: cmdOffset + 12) ?? 0
                }

            default:
                break
            }

            cmdOffset += Int(cmdSize)
        }

        let textBase = segments.first(where: { $0.name == "__TEXT" })?.vmAddress ?? 0x1_0000_0000
        let textFileOff = segments.first(where: { $0.name == "__TEXT" })?.fileOffset ?? 0

        // Parse LC_FUNCTION_STARTS (ULEB128 delta table of function RVAs)
        var functionStartRVAs: [UInt64] = []
        if funcStartsOff > 0 && funcStartsSize > 0 {
            let fsStart = Int(funcStartsOff)
            let fsEnd = fsStart + Int(funcStartsSize)
            if cursor.hasBytes(at: fsStart, count: Int(funcStartsSize)) {
                var pos = fsStart
                var runningOffset = textFileOff
                var isFirst = true
                while pos < fsEnd {
                    guard let delta = cursor.readULEB128(at: &pos, endOffset: fsEnd) else { break }
                    if delta == 0 && !isFirst { continue }
                    isFirst = false
                    runningOffset &+= delta
                    if runningOffset >= textFileOff {
                        functionStartRVAs.append(runningOffset - textFileOff)
                    }
                    if functionStartRVAs.count >= 250_000 { break }
                }
            }
        }

        // Helper for VA -> slice file offset
        func vaToFileOffset(_ va: UInt64) -> UInt64? {
            for seg in segments where seg.name != "__PAGEZERO" && seg.vmSize > 0 && seg.fileSize > 0 {
                if va >= seg.vmAddress && va < seg.vmAddress + seg.vmSize {
                    let delta = va - seg.vmAddress
                    return delta < seg.fileSize ? (seg.fileOffset + delta) : nil
                }
            }
            return nil
        }

        // Parse LC_SYMTAB nlist_64 entries (16 bytes each)
        var symbols: [SymbolEntry] = []
        if symOff > 0 && nSyms > 0 && strOff > 0 && strSize > 0 {
            let maxSyms = min(Int(nSyms), 150_000)
            let symStart = Int(symOff)
            let strStart = Int(strOff)
            let strEnd = strStart + Int(strSize)

            if cursor.hasBytes(at: symStart, count: maxSyms * 16) && strEnd <= cursor.count {
                symbols.reserveCapacity(min(maxSyms, 16_384))
                for i in 0..<maxSyms {
                    if i & 4095 == 0 && Task.isCancelled { throw AnalysisError.cancelled }
                    let entryOff = symStart + i * 16
                    guard let nStrx = cursor.readUInt32LE(at: entryOff),
                          let nType = cursor.readUInt8(at: entryOff + 4),
                          let nSect = cursor.readUInt8(at: entryOff + 5),
                          let nValue = cursor.readUInt64LE(at: entryOff + 8) else {
                        continue
                    }

                    // Skip STAB debug entries and undefined imports with address 0
                    if (nType & MachOConstants.nStabMask) != 0 { continue }
                    if (nType & MachOConstants.nTypeMask) != MachOConstants.nSectType || nValue == 0 {
                        continue
                    }

                    let nameOffset = strStart + Int(nStrx)
                    guard nameOffset >= strStart && nameOffset < strEnd,
                          let rawName = cursor.readCString(at: nameOffset, maxLength: min(512, strEnd - nameOffset)),
                          !rawName.isEmpty,
                          rawName != "<redacted>" else {
                        continue
                    }

                    let clean = cleanSymbolName(rawName)
                    guard SafetyPolicyFilter.isPermittedIdentifier(clean) else {
                        continue
                    }

                    let sec = sectionsByOrdinal[Int(nSect)]
                    let isFunc = sec?.isExecutableInstructions ?? false
                    let rva = nValue >= textBase ? (nValue - textBase) : nValue
                    let fileOff = vaToFileOffset(nValue)

                    symbols.append(
                        SymbolEntry(
                            name: rawName,
                            cleanName: clean,
                            moduleName: moduleName,
                            sectionIndex: nSect,
                            isExternal: (nType & MachOConstants.nExtBit) != 0,
                            isFunctionSymbol: isFunc,
                            virtualAddress: nValue,
                            rva: rva,
                            fileOffset: fileOff
                        )
                    )
                }
            }
        }

        return ParsedMachOImage(
            moduleName: moduleName,
            isUniversalFat: isUniversal,
            sliceOffsetInFile: sliceOffset,
            sliceSize: sliceSize,
            cpuType: cpuType,
            cpuSubtype: cpuSubtype,
            fileType: fileType,
            flags: flags,
            uuidString: uuidString,
            minimumOSVersion: minOSVersion,
            entryPointRVA: entryPointRVA,
            encryptionInfo: encryptionInfo,
            segments: segments,
            sectionsByOrdinal: sectionsByOrdinal,
            symbols: symbols,
            functionStartRVAs: functionStartRVAs
        )
    }

    private static func formatPackedVersion(_ raw: UInt32) -> String {
        let major = (raw >> 16) & 0xFFFF
        let minor = (raw >> 8) & 0xFF
        let patch = raw & 0xFF
        return patch > 0 ? "\(major).\(minor).\(patch)" : "\(major).\(minor)"
    }

    public static func cleanSymbolName(_ raw: String) -> String {
        if raw.hasPrefix("_") && !raw.hasPrefix("__Z") {
            return String(raw.dropFirst())
        }
        return raw
    }
}
