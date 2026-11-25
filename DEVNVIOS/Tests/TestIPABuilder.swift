import Foundation
@testable import DEVNVIOS

enum TestIPABuilder {
    struct SyntheticBuildOptions {
        var gameName: String = "Heo"
        var executableName: String = "HeoGame"
        var bundleID: String = "com.devnvios.test.heo"
        var version: String = "1.0.0"
        var includeSymbols: Bool = true
        var includeIL2CPP: Bool = true
        var encryptTextSegment: Bool = false
        var shiftCodeRVA: UInt64 = 0
        var wrapInUniversalFat: Bool = false
    }

    static func buildSyntheticIPA(at destinationURL: URL, options: SyntheticBuildOptions = SyntheticBuildOptions()) throws {
        let plistDict: [String: Any] = [
            "CFBundleDisplayName": options.gameName,
            "CFBundleName": options.gameName,
            "CFBundleExecutable": options.executableName,
            "CFBundleIdentifier": options.bundleID,
            "CFBundleShortVersionString": options.version,
            "CFBundleVersion": "100"
        ]
        let plistData = try PropertyListSerialization.data(fromPropertyList: plistDict, format: .xml, options: 0)

        var machOData = buildSyntheticARM64MachO(
            includeSymbols: options.includeSymbols,
            encryptText: options.encryptTextSegment,
            extraFuncOffset: options.shiftCodeRVA
        )
        if options.wrapInUniversalFat {
            machOData = wrapAsUniversalFatARM64(thinMachO: machOData)
        }

        var archiveItems: [IPAZipArchiveWriter.ArchiveItem] = [
            .init(path: "Payload/\(options.executableName).app/Info.plist", data: plistData, compress: true),
            .init(path: "Payload/\(options.executableName).app/\(options.executableName)", data: machOData, compress: true)
        ]

        if options.includeIL2CPP {
            let metaData = buildSyntheticIL2CPPMetadataV29()
            archiveItems.append(
                .init(
                    path: "Payload/\(options.executableName).app/Data/Managed/Metadata/global-metadata.dat",
                    data: metaData,
                    compress: true
                )
            )
        }

        try IPAZipArchiveWriter.writeArchive(items: archiveItems, to: destinationURL)
    }

    /// Synthesizes a valid 64-bit ARM64 Mach-O image (`0x4000` bytes) with `__PAGEZERO`, `__TEXT` (`__text`, `__cstring`, `__objc_classname`),
    /// `LC_UUID`, `LC_BUILD_VERSION`, `LC_ENCRYPTION_INFO_64`, `LC_FUNCTION_STARTS`, and `LC_SYMTAB`.
    static func buildSyntheticARM64MachO(
        includeSymbols: Bool,
        encryptText: Bool,
        extraFuncOffset: UInt64 = 0
    ) -> Data {
        var bin = Data(repeating: 0, count: 0x4000)
        let baseVA: UInt64 = 0x1_0000_0000

        let textSectFileOff: UInt32 = 0x1000 + UInt32(extraFuncOffset)
        let textSectVA: UInt64 = baseVA + UInt64(textSectFileOff)
        let cstringFileOff: UInt32 = 0x2000
        let cstringVA: UInt64 = baseVA + UInt64(cstringFileOff)
        let objcClassFileOff: UInt32 = 0x2400
        let objcClassVA: UInt64 = baseVA + UInt64(objcClassFileOff)
        let funcStartsFileOff: UInt32 = 0x2800
        let symtabFileOff: UInt32 = 0x2900
        let strtabFileOff: UInt32 = 0x2B00

        // Populate __cstring
        let str1 = "PlayerHealthComponent"
        let str2 = "MainCameraRotation"
        let str1Off = Int(cstringFileOff)
        let str2Off = str1Off + str1.utf8.count + 1
        writeCString(&bin, at: str1Off, str1)
        writeCString(&bin, at: str2Off, str2)
        let str1VA = cstringVA
        let str2VA = cstringVA + UInt64(str1.utf8.count + 1)

        // Populate __objc_classname
        writeCString(&bin, at: Int(objcClassFileOff), "GameMovementActor")

        // Populate __text with two valid ARM64 functions referencing str1 and str2
        let func1Off = Int(textSectFileOff)
        let func1VA = textSectVA
        let func2Off = func1Off + 0x20
        let func2VA = func1VA + 0x20

        if encryptText {
            // Fill with high-entropy non-instruction bytes to simulate FairPlay encrypted pages
            for idx in func1Off..<(func1Off + 0x80) {
                bin[idx] = UInt8((idx * 73 + 19) & 0xFF)
            }
        } else {
            // Function 1: STP X29, X30, [SP, #-16]! ; ADRP X8, str1Page ; ADD X8, X8, str1Off ; LDR S0, [X0, #0x48] ; RET
            writeUInt32LE(&bin, at: func1Off + 0x00, 0xA9BF_7BFD)
            writeUInt32LE(&bin, at: func1Off + 0x04, encodeADRP(rd: 8, pc: func1VA + 4, targetAddress: str1VA))
            writeUInt32LE(&bin, at: func1Off + 0x08, encodeADDImm64(rd: 8, rn: 8, imm12: str1VA & 0xFFF))
            writeUInt32LE(&bin, at: func1Off + 0x0C, 0xBD40_4800) // LDR S0, [X0, #0x48]
            writeUInt32LE(&bin, at: func1Off + 0x10, 0xD65F_03C0) // RET

            // Function 2: PACIBSP ; ADRP X9, str2Page ; ADD X9, X9, str2Off ; RET
            writeUInt32LE(&bin, at: func2Off + 0x00, 0xD503_237F)
            writeUInt32LE(&bin, at: func2Off + 0x04, encodeADRP(rd: 9, pc: func2VA + 4, targetAddress: str2VA))
            writeUInt32LE(&bin, at: func2Off + 0x08, encodeADDImm64(rd: 9, rn: 9, imm12: str2VA & 0xFFF))
            writeUInt32LE(&bin, at: func2Off + 0x0C, 0xD65F_03C0)
        }

        // Populate LC_FUNCTION_STARTS ULEB128 (func1 RVA delta, then +0x20 for func2)
        var fsBytes: [UInt8] = []
        fsBytes.append(contentsOf: encodeULEB128(UInt64(textSectFileOff)))
        fsBytes.append(contentsOf: encodeULEB128(0x20))
        for (i, b) in fsBytes.enumerated() {
            bin[Int(funcStartsFileOff) + i] = b
        }

        // Populate LC_SYMTAB (3 symbols: 2 gameplay + 1 forbidden payment symbol to test SafetyPolicyFilter)
        var nSyms: UInt32 = 0
        var strSize: UInt32 = 4
        if includeSymbols {
            var cursorStr = Int(strtabFileOff) + 4
            let symDefs: [(String, UInt64)] = [
                ("_PlayerController_get_Health", func1VA),
                ("_CameraFollow_UpdateRotation", func2VA),
                ("_StoreKitPaymentWallet_Purchase", func2VA + 0x10)
            ]
            for (idx, item) in symDefs.enumerated() {
                let strx = UInt32(cursorStr - Int(strtabFileOff))
                writeCString(&bin, at: cursorStr, item.0)
                cursorStr += item.0.utf8.count + 1

                let nlistPos = Int(symtabFileOff) + idx * 16
                writeUInt32LE(&bin, at: nlistPos + 0, strx)
                bin[nlistPos + 4] = 0x0F // N_SECT | N_EXT
                bin[nlistPos + 5] = 1    // section #1 (__TEXT,__text)
                writeUInt16LE(&bin, at: nlistPos + 6, 0)
                writeUInt64LE(&bin, at: nlistPos + 8, item.1)
            }
            nSyms = UInt32(symDefs.count)
            strSize = UInt32(cursorStr - Int(strtabFileOff))
        }

        // Write Load Commands starting at offset 32
        var cmdPos = 32
        var cmdCount: UInt32 = 0

        // 1. LC_SEGMENT_64 __PAGEZERO (72 bytes)
        writeUInt32LE(&bin, at: cmdPos, MachOConstants.lcSegment64)
        writeUInt32LE(&bin, at: cmdPos + 4, 72)
        writeFixedString(&bin, at: cmdPos + 8, "__PAGEZERO", length: 16)
        writeUInt64LE(&bin, at: cmdPos + 24, 0)
        writeUInt64LE(&bin, at: cmdPos + 32, baseVA)
        cmdPos += 72
        cmdCount += 1

        // 2. LC_SEGMENT_64 __TEXT with 3 sections (72 + 3*80 = 312 bytes)
        let textSegCmdSize: UInt32 = 72 + 3 * 80
        writeUInt32LE(&bin, at: cmdPos, MachOConstants.lcSegment64)
        writeUInt32LE(&bin, at: cmdPos + 4, textSegCmdSize)
        writeFixedString(&bin, at: cmdPos + 8, "__TEXT", length: 16)
        writeUInt64LE(&bin, at: cmdPos + 24, baseVA)
        writeUInt64LE(&bin, at: cmdPos + 32, 0x4000)
        writeUInt64LE(&bin, at: cmdPos + 40, 0)
        writeUInt64LE(&bin, at: cmdPos + 48, 0x4000)
        writeUInt32LE(&bin, at: cmdPos + 56, 5) // r-x
        writeUInt32LE(&bin, at: cmdPos + 60, 5) // r-x
        writeUInt32LE(&bin, at: cmdPos + 64, 3) // 3 sections

        let secBase = cmdPos + 72
        // Section 1: __text
        writeSection64(&bin, at: secBase, sect: "__text", seg: "__TEXT", addr: textSectVA, size: 0x100, offset: textSectFileOff, flags: 0x80000400)
        // Section 2: __cstring
        writeSection64(&bin, at: secBase + 80, sect: "__cstring", seg: "__TEXT", addr: cstringVA, size: 0x200, offset: cstringFileOff, flags: 0x02)
        // Section 3: __objc_classname
        writeSection64(&bin, at: secBase + 160, sect: "__objc_classname", seg: "__TEXT", addr: objcClassVA, size: 0x100, offset: objcClassFileOff, flags: 0x02)
        cmdPos += Int(textSegCmdSize)
        cmdCount += 1

        // 3. LC_UUID (24 bytes)
        writeUInt32LE(&bin, at: cmdPos, MachOConstants.lcUUID)
        writeUInt32LE(&bin, at: cmdPos + 4, 24)
        for i in 0..<16 { bin[cmdPos + 8 + i] = UInt8(0xA0 + i) }
        cmdPos += 24
        cmdCount += 1

        // 4. LC_BUILD_VERSION (24 bytes) -> iOS 16.0
        writeUInt32LE(&bin, at: cmdPos, MachOConstants.lcBuildVersion)
        writeUInt32LE(&bin, at: cmdPos + 4, 24)
        writeUInt32LE(&bin, at: cmdPos + 8, 2) // PLATFORM_IOS
        writeUInt32LE(&bin, at: cmdPos + 12, 0x0010_0000) // 16.0
        writeUInt32LE(&bin, at: cmdPos + 16, 0x0011_0000)
        cmdPos += 24
        cmdCount += 1

        // 5. LC_ENCRYPTION_INFO_64 (24 bytes)
        writeUInt32LE(&bin, at: cmdPos, MachOConstants.lcEncryptionInfo64)
        writeUInt32LE(&bin, at: cmdPos + 4, 24)
        writeUInt32LE(&bin, at: cmdPos + 8, encryptText ? textSectFileOff : 0)
        writeUInt32LE(&bin, at: cmdPos + 12, encryptText ? 0x1800 : 0)
        writeUInt32LE(&bin, at: cmdPos + 16, encryptText ? 1 : 0)
        cmdPos += 24
        cmdCount += 1

        // 6. LC_FUNCTION_STARTS (16 bytes)
        writeUInt32LE(&bin, at: cmdPos, MachOConstants.lcFunctionStarts)
        writeUInt32LE(&bin, at: cmdPos + 4, 16)
        writeUInt32LE(&bin, at: cmdPos + 8, funcStartsFileOff)
        writeUInt32LE(&bin, at: cmdPos + 12, UInt32(fsBytes.count))
        cmdPos += 16
        cmdCount += 1

        // 7. LC_SYMTAB (24 bytes)
        writeUInt32LE(&bin, at: cmdPos, MachOConstants.lcSymtab)
        writeUInt32LE(&bin, at: cmdPos + 4, 24)
        writeUInt32LE(&bin, at: cmdPos + 8, nSyms > 0 ? symtabFileOff : 0)
        writeUInt32LE(&bin, at: cmdPos + 12, nSyms)
        writeUInt32LE(&bin, at: cmdPos + 16, nSyms > 0 ? strtabFileOff : 0)
        writeUInt32LE(&bin, at: cmdPos + 20, nSyms > 0 ? strSize : 0)
        cmdPos += 24
        cmdCount += 1

        // mach_header_64 (32 bytes)
        writeUInt32LE(&bin, at: 0, MachOConstants.mhMagic64)
        writeUInt32LE(&bin, at: 4, MachOConstants.cpuTypeARM64)
        writeUInt32LE(&bin, at: 8, 0)
        writeUInt32LE(&bin, at: 12, 2) // MH_EXECUTE
        writeUInt32LE(&bin, at: 16, cmdCount)
        writeUInt32LE(&bin, at: 20, UInt32(cmdPos - 32))
        writeUInt32LE(&bin, at: 24, 0x0020_0085) // MH_PIE | MH_DYLDLINK | MH_NOUNDEFS

        return bin
    }

    static func wrapAsUniversalFatARM64(thinMachO: Data) -> Data {
        var fat = Data(repeating: 0, count: 64)
        writeUInt32BE(&fat, at: 0, MachOConstants.fatMagic)
        writeUInt32BE(&fat, at: 4, 1) // nfat_arch = 1
        writeUInt32BE(&fat, at: 8, MachOConstants.cpuTypeARM64)
        writeUInt32BE(&fat, at: 12, 0)
        writeUInt32BE(&fat, at: 16, 64) // slice offset
        writeUInt32BE(&fat, at: 20, UInt32(thinMachO.count))
        writeUInt32BE(&fat, at: 24, 14) // align 2^14
        fat.append(thinMachO)
        return fat
    }

    /// Builds a valid Unity IL2CPP v29 `global-metadata.dat` binary containing gameplay & financial test records.
    static func buildSyntheticIL2CPPMetadataV29() -> Data {
        var stringTable = Data()
        func addIdent(_ text: String) -> Int32 {
            let idx = Int32(stringTable.count)
            stringTable.append(contentsOf: text.utf8)
            stringTable.append(0)
            return idx
        }

        let emptyNS = addIdent("")
        let gameNS = addIdent("Heo.Gameplay")
        let playerCls = addIdent("PlayerController")
        let camCls = addIdent("CameraFollow")
        let payCls = addIdent("StoreKitBillingWallet")

        let mGetHealth = addIdent("get_Health")
        let mSetPos = addIdent("set_Position")
        let mUpdateRot = addIdent("UpdateRotation")
        let mPay = addIdent("ProcessPayment")

        let fMaxHealth = addIdent("m_MaxHealth")
        let fMoveSpeed = addIdent("m_MoveSpeed")

        // 3 TypeDefinitions (stride 88 bytes each in v29)
        var typeDefs = Data(repeating: 0, count: 3 * 88)
        let typesInput: [(name: Int32, ns: Int32, fStart: Int32, fCount: UInt16, mStart: Int32, mCount: UInt16, token: UInt32)] = [
            (playerCls, gameNS, 0, 2, 0, 2, 0x0200_0001),
            (camCls, gameNS, 2, 0, 2, 1, 0x0200_0002),
            (payCls, emptyNS, 2, 0, 3, 1, 0x0200_0003)
        ]
        for (i, t) in typesInput.enumerated() {
            let b = i * 88
            writeInt32LE(&typeDefs, at: b + 0, t.name)
            writeInt32LE(&typeDefs, at: b + 4, t.ns)
            writeInt32LE(&typeDefs, at: b + 88 - 56, t.fStart)
            writeInt32LE(&typeDefs, at: b + 88 - 52, t.mStart)
            writeUInt16LE(&typeDefs, at: b + 88 - 24, t.mCount)
            writeUInt16LE(&typeDefs, at: b + 88 - 20, t.fCount)
            writeUInt32LE(&typeDefs, at: b + 88 - 4, t.token)
        }

        // 4 MethodDefinitions (stride 32 bytes each in v29)
        var methodDefs = Data(repeating: 0, count: 4 * 32)
        let methodsInput: [(name: Int32, decl: Int32, token: UInt32, params: UInt16)] = [
            (mGetHealth, 0, 0x0600_0001, 0),
            (mSetPos, 0, 0x0600_0002, 1),
            (mUpdateRot, 1, 0x0600_0003, 1),
            (mPay, 2, 0x0600_0004, 2)
        ]
        for (i, m) in methodsInput.enumerated() {
            let b = i * 32
            writeInt32LE(&methodDefs, at: b + 0, m.name)
            writeInt32LE(&methodDefs, at: b + 4, m.decl)
            writeUInt32LE(&methodDefs, at: b + 20, m.token)
            writeUInt16LE(&methodDefs, at: b + 30, m.params)
        }

        // 2 FieldDefinitions (12 bytes each: nameIndex, typeIndex, token)
        var fieldDefs = Data(repeating: 0, count: 2 * 12)
        writeInt32LE(&fieldDefs, at: 0, fMaxHealth)
        writeUInt32LE(&fieldDefs, at: 8, 0x0400_0001)
        writeInt32LE(&fieldDefs, at: 12, fMoveSpeed)
        writeUInt32LE(&fieldDefs, at: 20, 0x0400_0002)

        // 1 String Literal
        let literalBytes = Data("PlayerSpawnState".utf8)
        var literalTable = Data(repeating: 0, count: 8)
        writeUInt32LE(&literalTable, at: 0, UInt32(literalBytes.count))
        writeInt32LE(&literalTable, at: 4, 0)

        let headerSize = 256
        var file = Data(repeating: 0, count: headerSize)

        let litTableOff = file.count; file.append(literalTable)
        let litDataOff = file.count; file.append(literalBytes)
        let strOff = file.count; file.append(stringTable)
        let methOff = file.count; file.append(methodDefs)
        let fldOff = file.count; file.append(fieldDefs)
        let typOff = file.count; file.append(typeDefs)

        writeUInt32LE(&file, at: 0x00, IL2CPPMetadataParser.standardMagic)
        writeInt32LE(&file, at: 0x04, 29)
        writeInt32LE(&file, at: 0x08, Int32(litTableOff))
        writeInt32LE(&file, at: 0x0C, Int32(literalTable.count))
        writeInt32LE(&file, at: 0x10, Int32(litDataOff))
        writeInt32LE(&file, at: 0x14, Int32(literalBytes.count))
        writeInt32LE(&file, at: 0x18, Int32(strOff))
        writeInt32LE(&file, at: 0x1C, Int32(stringTable.count))
        writeInt32LE(&file, at: 0x30, Int32(methOff))
        writeInt32LE(&file, at: 0x34, Int32(methodDefs.count))
        writeInt32LE(&file, at: 0x60, Int32(fldOff))
        writeInt32LE(&file, at: 0x64, Int32(fieldDefs.count))
        writeInt32LE(&file, at: 0xA0, Int32(typOff))
        writeInt32LE(&file, at: 0xA4, Int32(typeDefs.count))

        return file
    }

    // MARK: - Encoding Helpers

    static func encodeADRP(rd: Int, pc: UInt64, targetAddress: UInt64) -> UInt32 {
        let pageDelta = (Int64(bitPattern: targetAddress & ~UInt64(0xFFF)) - Int64(bitPattern: pc & ~UInt64(0xFFF))) >> 12
        let raw21 = UInt32(bitPattern: Int32(truncatingIfNeeded: pageDelta)) & 0x1F_FFFF
        let immlo = raw21 & 0x3
        let immhi = (raw21 >> 2) & 0x7FFFF
        return 0x9000_0000 | (immlo << 29) | (immhi << 5) | UInt32(rd & 0x1F)
    }

    static func encodeADDImm64(rd: Int, rn: Int, imm12: UInt64) -> UInt32 {
        0x9100_0000 | (UInt32(imm12 & 0xFFF) << 10) | (UInt32(rn & 0x1F) << 5) | UInt32(rd & 0x1F)
    }

    private static func encodeULEB128(_ value: UInt64) -> [UInt8] {
        var v = value
        var out: [UInt8] = []
        repeat {
            var byte = UInt8(v & 0x7F)
            v >>= 7
            if v != 0 { byte |= 0x80 }
            out.append(byte)
        } while v != 0
        return out
    }

    private static func writeSection64(
        _ data: inout Data,
        at pos: Int,
        sect: String,
        seg: String,
        addr: UInt64,
        size: UInt64,
        offset: UInt32,
        flags: UInt32
    ) {
        writeFixedString(&data, at: pos, sect, length: 16)
        writeFixedString(&data, at: pos + 16, seg, length: 16)
        writeUInt64LE(&data, at: pos + 32, addr)
        writeUInt64LE(&data, at: pos + 40, size)
        writeUInt32LE(&data, at: pos + 48, offset)
        writeUInt32LE(&data, at: pos + 52, 2)
        writeUInt32LE(&data, at: pos + 64, flags)
    }

    private static func writeCString(_ data: inout Data, at offset: Int, _ s: String) {
        for (i, b) in s.utf8.enumerated() { data[offset + i] = b }
        data[offset + s.utf8.count] = 0
    }

    private static func writeFixedString(_ data: inout Data, at offset: Int, _ s: String, length: Int) {
        let bytes = Array(s.utf8.prefix(length))
        for i in 0..<length {
            data[offset + i] = i < bytes.count ? bytes[i] : 0
        }
    }

    private static func writeUInt16LE(_ data: inout Data, at offset: Int, _ value: UInt16) {
        data[offset] = UInt8(value & 0xFF)
        data[offset + 1] = UInt8((value >> 8) & 0xFF)
    }

    private static func writeUInt32LE(_ data: inout Data, at offset: Int, _ value: UInt32) {
        data[offset] = UInt8(value & 0xFF)
        data[offset + 1] = UInt8((value >> 8) & 0xFF)
        data[offset + 2] = UInt8((value >> 16) & 0xFF)
        data[offset + 3] = UInt8((value >> 24) & 0xFF)
    }

    private static func writeInt32LE(_ data: inout Data, at offset: Int, _ value: Int32) {
        writeUInt32LE(&data, at: offset, UInt32(bitPattern: value))
    }

    private static func writeUInt32BE(_ data: inout Data, at offset: Int, _ value: UInt32) {
        data[offset] = UInt8((value >> 24) & 0xFF)
        data[offset + 1] = UInt8((value >> 16) & 0xFF)
        data[offset + 2] = UInt8((value >> 8) & 0xFF)
        data[offset + 3] = UInt8(value & 0xFF)
    }

    private static func writeUInt64LE(_ data: inout Data, at offset: Int, _ value: UInt64) {
        writeUInt32LE(&data, at: offset, UInt32(truncatingIfNeeded: value))
        writeUInt32LE(&data, at: offset + 4, UInt32(truncatingIfNeeded: value >> 32))
    }
}
