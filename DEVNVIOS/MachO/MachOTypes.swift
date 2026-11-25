import Foundation

public enum MachOConstants {
    public static let mhMagic64: UInt32 = 0xFEEDFACF
    public static let mhCigam64: UInt32 = 0xCFFAEDFE
    public static let mhMagic32: UInt32 = 0xFEEDFACE
    public static let fatMagic: UInt32 = 0xCAFEBABE
    public static let fatCigam: UInt32 = 0xBEBAFECA
    public static let fatMagic64: UInt32 = 0xCAFEBABF
    public static let fatCigam64: UInt32 = 0xBFBAFECA

    public static let cpuTypeARM64: UInt32 = 0x0100000C
    public static let cpuTypeARM64_32: UInt32 = 0x0200000C
    public static let cpuTypeARM: UInt32 = 0x0000000C
    public static let cpuTypeX86_64: UInt32 = 0x01000007

    public static let lcSegment64: UInt32 = 0x19
    public static let lcSymtab: UInt32 = 0x02
    public static let lcDysymtab: UInt32 = 0x0B
    public static let lcUUID: UInt32 = 0x1B
    public static let lcEncryptionInfo: UInt32 = 0x21
    public static let lcEncryptionInfo64: UInt32 = 0x2C
    public static let lcVersionMinIPhoneOS: UInt32 = 0x24
    public static let lcFunctionStarts: UInt32 = 0x26
    public static let lcMain: UInt32 = 0x80000028
    public static let lcBuildVersion: UInt32 = 0x32

    public static let nStabMask: UInt8 = 0xE0
    public static let nTypeMask: UInt8 = 0x0E
    public static let nSectType: UInt8 = 0x0E
    public static let nExtBit: UInt8 = 0x01
}

public struct ParsedMachOSection: Sendable, Equatable {
    public let index: Int // 1-based Mach-O section ordinal
    public let sectionName: String
    public let segmentName: String
    public let address: UInt64
    public let size: UInt64
    public let fileOffset: UInt32
    public let alignment: UInt32
    public let flags: UInt32

    public var isExecutableInstructions: Bool {
        (flags & 0x80000000) != 0 || (segmentName == "__TEXT" && sectionName == "__text")
    }
}

public struct ParsedMachOSegment: Sendable, Equatable {
    public let name: String
    public let vmAddress: UInt64
    public let vmSize: UInt64
    public let fileOffset: UInt64
    public let fileSize: UInt64
    public let maxProt: Int32
    public let initProt: Int32
    public let sections: [ParsedMachOSection]

    public func toSummary() -> MachOSegmentSummary {
        MachOSegmentSummary(
            name: name,
            vmAddress: vmAddress,
            vmSize: vmSize,
            fileOffset: fileOffset,
            fileSize: fileSize,
            maxProt: maxProt,
            initProt: initProt,
            sectionNames: sections.map(\.sectionName)
        )
    }
}

public struct ParsedEncryptionInfo: Sendable, Equatable {
    public let cryptOffset: UInt32
    public let cryptSize: UInt32
    public let cryptID: UInt32

    public var isEncrypted: Bool {
        cryptID != 0 && cryptSize > 0
    }
}

public struct ParsedMachOImage: Sendable {
    public let moduleName: String
    public let isUniversalFat: Bool
    public let sliceOffsetInFile: Int
    public let sliceSize: Int
    public let cpuType: UInt32
    public let cpuSubtype: UInt32
    public let fileType: UInt32
    public let flags: UInt32
    public let uuidString: String?
    public let minimumOSVersion: String?
    public let entryPointRVA: UInt64?
    public let encryptionInfo: ParsedEncryptionInfo
    public let segments: [ParsedMachOSegment]
    public let sectionsByOrdinal: [Int: ParsedMachOSection]
    public let symbols: [SymbolEntry]
    public let functionStartRVAs: [UInt64]

    public var isARM64: Bool {
        cpuType == MachOConstants.cpuTypeARM64 || cpuType == MachOConstants.cpuTypeARM64_32
    }

    public var architectureName: String {
        switch cpuType {
        case MachOConstants.cpuTypeARM64:
            if (cpuSubtype & 0xFF) == 2 {
                return "arm64e"
            }
            return "arm64"
        case MachOConstants.cpuTypeARM64_32:
            return "arm64_32"
        case MachOConstants.cpuTypeX86_64:
            return "x86_64"
        case MachOConstants.cpuTypeARM:
            return "armv7"
        default:
            return String(format: "cpu_0x%X", cpuType)
        }
    }

    public var textSegment: ParsedMachOSegment? {
        segments.first { $0.name == "__TEXT" }
    }

    public var defaultBaseAddress: UInt64 {
        textSegment?.vmAddress ?? 0x1_0000_0000
    }

    public func rva(forVirtualAddress va: UInt64) -> UInt64 {
        let base = defaultBaseAddress
        return va >= base ? (va - base) : va
    }

    public func virtualAddress(forRVA rva: UInt64) -> UInt64 {
        defaultBaseAddress &+ rva
    }

    public func virtualAddressToSliceFileOffset(_ va: UInt64) -> UInt64? {
        for seg in segments where seg.name != "__PAGEZERO" && seg.vmSize > 0 && seg.fileSize > 0 {
            if va >= seg.vmAddress && va < seg.vmAddress + seg.vmSize {
                let delta = va - seg.vmAddress
                guard delta < seg.fileSize else { return nil }
                return seg.fileOffset + delta
            }
        }
        return nil
    }

    public func sliceFileOffsetToVirtualAddress(_ fileOffset: UInt64) -> UInt64? {
        for seg in segments where seg.name != "__PAGEZERO" && seg.fileSize > 0 {
            if fileOffset >= seg.fileOffset && fileOffset < seg.fileOffset + seg.fileSize {
                let delta = fileOffset - seg.fileOffset
                return seg.vmAddress + delta
            }
        }
        return nil
    }

    /// Checks whether a slice-relative byte range falls inside FairPlay encrypted pages (`cryptid != 0`).
    public func isSliceRangeEncrypted(offset: UInt64, length: UInt64) -> Bool {
        guard encryptionInfo.isEncrypted else { return false }
        let encStart = UInt64(encryptionInfo.cryptOffset)
        let encEnd = encStart + UInt64(encryptionInfo.cryptSize)
        let rangeEnd = offset + max(length, 1)
        return offset < encEnd && rangeEnd > encStart
    }

    public func isVirtualAddressValid(_ va: UInt64, requireExecutable: Bool = false) -> Bool {
        for seg in segments where seg.name != "__PAGEZERO" && seg.vmSize > 0 {
            if va >= seg.vmAddress && va < seg.vmAddress + seg.vmSize {
                if requireExecutable {
                    return (seg.initProt & 0x04) != 0 || seg.name == "__TEXT"
                }
                return true
            }
        }
        return false
    }

    public func section(forSectionName sectName: String, inSegment segName: String? = nil) -> ParsedMachOSection? {
        for seg in segments {
            if let segName, seg.name != segName { continue }
            if let match = seg.sections.first(where: { $0.sectionName == sectName }) {
                return match
            }
        }
        return nil
    }
}
