import Foundation

@main
struct EngineTests {
    static func main() throws {
        print("Running IPA Dumper Pro Core Engine Tests...")
        try testSafetyGuard()
        try testStreamingZipRoundTrip()
        try testFullIL2CPPAndMachOPipeline()
        try testEncryptedMetadataFallback()
        print("ALL ENGINE TESTS PASSED!")
    }

    static func assertTrue(_ cond: Bool, _ msg: String) {
        if !cond {
            fputs("ASSERTION FAILED: \(msg)\n", stderr)
            exit(1)
        }
    }

    static func testSafetyGuard() throws {
        assertTrue(SafetyGuard.isRealMoneyBlocked("PurchaseDiamonds"), "PurchaseDiamonds must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("StoreKitManager"), "StoreKitManager must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("SKPaymentTransaction"), "SKPaymentTransaction must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("BuyGemsPack"), "BuyGemsPack must be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("PlayerHealth"), "PlayerHealth must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("EnemySpawner"), "EnemySpawner must NOT be blocked")

        let bankCheck = SafetyGuard.detectRestrictedApp(
            fileName: "Vietcombank.ipa",
            bundleId: "com.vietcombank.mobile",
            appName: "VCB Digibank",
            executableName: "VCB",
            urlSchemes: [],
            entryPaths: []
        )
        assertTrue(bankCheck.isBlocked, "Banking app must be blocked")

        let gameCheck = SafetyGuard.detectRestrictedApp(
            fileName: "OfflineRPG.ipa",
            bundleId: "com.indie.offlinerpg",
            appName: "Offline RPG",
            executableName: "OfflineRPG",
            urlSchemes: ["offlinerpg"],
            entryPaths: ["Payload/OfflineRPG.app/Frameworks/UnityFramework.framework/UnityFramework"]
        )
        assertTrue(!gameCheck.isBlocked, "Offline RPG must NOT be blocked")
        print("[PASS] SafetyGuard")
    }

    static func testStreamingZipRoundTrip() throws {
        let tmp = FileManager.default.temporaryDirectory.appendingPathComponent("zip_test_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tmp, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tmp) }

        let srcFile = tmp.appendingPathComponent("hello.txt")
        let payload = String(repeating: "IL2CPP_STREAM_TEST_LINE\n", count: 5000)
        try payload.write(to: srcFile, atomically: true, encoding: .utf8)

        let zipURL = tmp.appendingPathComponent("archive.zip")
        let writer = try StreamingZipWriter(destinationURL: zipURL)
        try writer.addFile(at: srcFile, entryName: "Payload/Test.app/hello.txt")
        try writer.finalize()

        let reader = try StreamingZipReader(url: zipURL)
        assertTrue(reader.entries.count == 1, "ZIP must contain 1 entry")
        let entry = reader.entries[0]
        assertTrue(entry.path == "Payload/Test.app/hello.txt", "Entry path mismatch")
        let extractedURL = tmp.appendingPathComponent("out.txt")
        try reader.extractEntry(entry, to: extractedURL)
        let readBack = try String(contentsOf: extractedURL, encoding: .utf8)
        assertTrue(readBack == payload, "Extracted ZIP payload mismatch")
        print("[PASS] StreamingZip RoundTrip")
    }

    static func testFullIL2CPPAndMachOPipeline() throws {
        let tmp = FileManager.default.temporaryDirectory.appendingPathComponent("il2cpp_test_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tmp, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tmp) }

        let (metaData, machoData, plistData) = buildSyntheticIL2CPPAssets()
        let metaSrc = tmp.appendingPathComponent("global-metadata.dat")
        let machoSrc = tmp.appendingPathComponent("UnityFramework")
        let plistSrc = tmp.appendingPathComponent("Info.plist")
        try metaData.write(to: metaSrc)
        try machoData.write(to: machoSrc)
        try plistData.write(to: plistSrc)

        let ipaURL = tmp.appendingPathComponent("OfflineHero.ipa")
        let zw = try StreamingZipWriter(destinationURL: ipaURL)
        try zw.addFile(at: plistSrc, entryName: "Payload/OfflineHero.app/Info.plist")
        try zw.addFile(at: machoSrc, entryName: "Payload/OfflineHero.app/Frameworks/UnityFramework.framework/UnityFramework")
        try zw.addFile(at: metaSrc, entryName: "Payload/OfflineHero.app/Data/Managed/Metadata/global-metadata.dat")
        try zw.finalize()

        let outRoot = tmp.appendingPathComponent("outputs")
        var opts = DumpOptions.default
        opts.workerThreads = 2
        opts.autoExportZip = true

        let coordinator = DumpCoordinator()
        let result = try coordinator.runIPA(ipaURL: ipaURL, outputsRootURL: outRoot, options: opts) { _ in }

        assertTrue(result.diagnostics.metadataStatus.isValid, "Metadata must be detected as valid")
        assertTrue(result.stats.filteredRealMoneyCount >= 2, "Real-money symbols must be filtered")
        assertTrue(result.stats.dummyDllsCount == 2, "Should create Il2CppDummyDll.dll + Assembly-CSharp.dll")

        let dumpCS = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("dump.cs"), encoding: .utf8)
        assertTrue(dumpCS.contains("class PlayerStats"), "dump.cs missing PlayerStats")
        assertTrue(dumpCS.contains("// TypeDefIndex: 0"), "dump.cs missing TypeDefIndex")
        assertTrue(dumpCS.contains("int health = 50; // 0x18"), "dump.cs missing health field with default & offset")
        assertTrue(dumpCS.contains("// RVA: 0x"), "dump.cs missing method RVA")
        assertTrue(dumpCS.contains("int GetHealth()"), "dump.cs missing GetHealth method")
        assertTrue(dumpCS.contains("int MaxHealth { get; }"), "dump.cs missing MaxHealth property")
        assertTrue(!dumpCS.contains("IAPManager"), "dump.cs leaked blocked IAPManager")
        assertTrue(!dumpCS.contains("PurchaseDiamonds"), "dump.cs leaked blocked PurchaseDiamonds")

        let headerText = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("il2cpp.h"), encoding: .utf8)
        assertTrue(headerText.contains("GameCore_PlayerStats_Fields"), "il2cpp.h missing PlayerStats struct")

        let scriptData = try Data(contentsOf: result.outputDirectoryURL.appendingPathComponent("script.json"))
        let scriptObj = try JSONSerialization.jsonObject(with: scriptData) as? [String: Any]
        assertTrue(scriptObj?["ScriptMethod"] != nil, "script.json missing ScriptMethod")

        let slData = try Data(contentsOf: result.outputDirectoryURL.appendingPathComponent("stringliteral.json"))
        let slArr = try JSONSerialization.jsonObject(with: slData) as? [[String: Any]]
        assertTrue(slArr?.count == 1, "stringliteral.json should only contain 1 non-blocked literal")

        // Verify ECMA-335 PE32 + CLI Metadata binary layout for Dummy DLLs
        let dllDir = result.outputDirectoryURL.appendingPathComponent("DummyDll")
        try validateECMA335DLL(at: dllDir.appendingPathComponent("Il2CppDummyDll.dll"))
        try validateECMA335DLL(at: dllDir.appendingPathComponent("Assembly-CSharp.dll"))

        print("[PASS] Full IL2CPP v29 + Mach-O + DummyDLL + RealMoneyFilter Pipeline")
    }

    static func testEncryptedMetadataFallback() throws {
        let tmp = FileManager.default.temporaryDirectory.appendingPathComponent("enc_test_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tmp, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tmp) }

        // Deterministic high-entropy pseudo-random bytes simulating encrypted global-metadata.dat
        var state: UInt64 = 0x1234_5678_9ABC_DEF0
        var encBytes = [UInt8](repeating: 0, count: 8192)
        for i in 0..<encBytes.count {
            state ^= state << 13
            state ^= state >> 7
            state ^= state << 17
            encBytes[i] = UInt8(state & 0xFF)
        }
        let encMetaURL = tmp.appendingPathComponent("global-metadata.dat")
        try Data(encBytes).write(to: encMetaURL)

        let (_, machoData, _) = buildSyntheticIL2CPPAssets()
        let machoURL = tmp.appendingPathComponent("GameBinary")
        try machoData.write(to: machoURL)

        let coordinator = DumpCoordinator()
        let result = try coordinator.runStandalone(
            machOURL: machoURL,
            metadataURL: encMetaURL,
            outputsRootURL: tmp.appendingPathComponent("out"),
            options: .default
        ) { _ in }

        if case .encryptedOrProtected = result.diagnostics.metadataStatus {
            // Expected
        } else {
            assertTrue(false, "Expected encryptedOrProtected status")
        }
        let report = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("report.txt"), encoding: .utf8)
        assertTrue(report.contains("Protected/Encrypted"), "report.txt must note protected metadata")
        print("[PASS] Encrypted Metadata Detection + Mach-O Fallback")
    }

    static func validateECMA335DLL(at url: URL) throws {
        let data = try Data(contentsOf: url)
        assertTrue(data.count > 0x280, "DLL too small: \(url.lastPathComponent)")
        assertTrue(data[0] == 0x4D && data[1] == 0x5A, "Missing MZ header")
        let lfanew = Int(readU32(data, 0x3C))
        assertTrue(readU32(data, lfanew) == 0x0000_4550, "Missing PE\\0\\0 signature")
        let clrRva = readU32(data, 0x168)
        assertTrue(clrRva == 0x2008, "Unexpected CLR header RVA")
        // .text raw starts at 0x200, metadata at +0x60 => 0x260
        let bsjb = readU32(data, 0x260)
        assertTrue(bsjb == 0x424A_5342, "Missing BSJB CLI metadata root")
    }

    // MARK: - Synthetic Binary Builder

    static func buildSyntheticIL2CPPAssets() -> (meta: Data, macho: Data, plist: Data) {
        // 1. Build String Table
        var strBlob = Data([0])
        var strOffsets: [String: UInt32] = [:]
        func addStr(_ s: String) -> UInt32 {
            if let v = strOffsets[s] { return v }
            let off = UInt32(strBlob.count)
            strBlob.append(contentsOf: s.utf8)
            strBlob.append(0)
            strOffsets[s] = off
            return off
        }

        let sAsm = addStr("Assembly-CSharp.dll")
        let sNs = addStr("GameCore")
        let sPlayer = addStr("PlayerStats")
        let sIAP = addStr("IAPManager")
        let sHealth = addStr("health")
        let sGetHealth = addStr("GetHealth")
        let sPurchase = addStr("PurchaseDiamonds")
        let sMaxHealth = addStr("MaxHealth")
        let sSys = addStr("System")
        let sObj = addStr("Object")

        // 2. Build Tables
        // Images (40 bytes * 1)
        var images = Data(repeating: 0, count: 40)
        putU32(&images, 0, sAsm)
        putU32(&images, 8, 0)  // typeStart
        putU32(&images, 12, 3) // typeCount
        putU32(&images, 16, 0xFFFF_FFFF)
        putU32(&images, 20, 0x2000_0001)

        // TypeDefs (88 bytes * 3: PlayerStats, IAPManager, System.Object)
        var typeDefs = Data(repeating: 0, count: 88 * 3)
        // Type 0: PlayerStats (1 field, 1 method, 1 prop)
        putU32(&typeDefs, 0, sPlayer)
        putU32(&typeDefs, 4, sNs)
        putU32(&typeDefs, 16, 0xFFFF_FFFF)
        putU32(&typeDefs, 20, 0xFFFF_FFFF)
        putU32(&typeDefs, 32, 0) // fieldStart
        putU32(&typeDefs, 36, 0) // methodStart
        putU32(&typeDefs, 44, 0) // propStart
        putU32(&typeDefs, 48, 0xFFFF_FFFF)
        putU32(&typeDefs, 60, 0x0010_0001) // public
        putU16(&typeDefs, 64, 1) // methodCount
        putU16(&typeDefs, 66, 1) // propCount
        putU16(&typeDefs, 68, 1) // fieldCount
        putU32(&typeDefs, 84, 0x0200_0002)

        // Type 1: IAPManager (0 fields, 1 method) -> should be filtered!
        let t1 = 88
        putU32(&typeDefs, t1 + 0, sIAP)
        putU32(&typeDefs, t1 + 4, sNs)
        putU32(&typeDefs, t1 + 16, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 20, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 32, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 36, 1) // methodStart
        putU32(&typeDefs, t1 + 44, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 48, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 60, 0x0010_0001)
        putU16(&typeDefs, t1 + 64, 1) // methodCount
        putU32(&typeDefs, t1 + 84, 0x0200_0003)

        // Type 2: System.Object
        let t2 = 176
        putU32(&typeDefs, t2 + 0, sObj)
        putU32(&typeDefs, t2 + 4, sSys)
        putU32(&typeDefs, t2 + 16, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 20, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 32, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 36, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 44, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 48, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 60, 0x0010_0001)
        putU32(&typeDefs, t2 + 84, 0x0200_0004)

        // Fields (12 bytes * 1): health -> typeIndex 0 (Int32)
        var fields = Data(repeating: 0, count: 12)
        putU32(&fields, 0, sHealth)
        putU32(&fields, 4, 0) // Il2CppType[0] = Int32
        putU32(&fields, 8, 0x0400_0001)

        // FieldDefaultValues (12 bytes * 1) + Data ([0x64] = compressed 50)
        var fieldDefs = Data(repeating: 0, count: 12)
        putU32(&fieldDefs, 0, 0) // fieldIndex 0
        putU32(&fieldDefs, 4, 0) // typeIndex 0
        putU32(&fieldDefs, 8, 0) // dataOffset 0
        let defData = Data([0x64, 0x00, 0x00, 0x00]) // 0x64 >> 1 = 50

        // Methods (32 bytes * 2, mTokOff = 20)
        var methods = Data(repeating: 0, count: 64)
        // Method 0: GetHealth() -> Int32 (token 0x06000001)
        putU32(&methods, 0, sGetHealth)
        putU32(&methods, 4, 0)
        putU32(&methods, 8, 0) // returnType Il2CppType[0] = Int32
        putU32(&methods, 12, 0xFFFF_FFFF)
        putU32(&methods, 20, 0x0600_0001)
        putU16(&methods, 24, 0x0086)
        putU16(&methods, 28, 0xFFFF)
        putU16(&methods, 30, 0)

        // Method 1: PurchaseDiamonds() -> Int32 (token 0x06000002)
        putU32(&methods, 32, sPurchase)
        putU32(&methods, 36, 1)
        putU32(&methods, 40, 0)
        putU32(&methods, 44, 0xFFFF_FFFF)
        putU32(&methods, 52, 0x0600_0002)
        putU16(&methods, 56, 0x0086)
        putU16(&methods, 60, 0xFFFF)
        putU16(&methods, 62, 0)

        // Properties (24 bytes * 1): MaxHealth get=0, set=-1
        var props = Data(repeating: 0, count: 24)
        putU32(&props, 0, sMaxHealth)
        putU32(&props, 4, 0)
        putU32(&props, 8, 0xFFFF_FFFF)
        putU32(&props, 12, 0)
        putU32(&props, 16, 0x1700_0001)

        // StringLiterals: 0 = "Player spawned", 1 = "VerifyReceipt"
        let lit0 = Array("Player spawned".utf8)
        let lit1 = Array("VerifyReceipt".utf8)
        var slData = Data(lit0)
        slData.append(contentsOf: lit1)
        var slTable = Data(repeating: 0, count: 16)
        putU32(&slTable, 0, UInt32(lit0.count))
        putU32(&slTable, 4, 0)
        putU32(&slTable, 8, UInt32(lit1.count))
        putU32(&slTable, 12, UInt32(lit0.count))

        // Assemble global-metadata.dat
        let headerSize = 288
        var meta = Data(repeating: 0, count: headerSize)
        func appendSec(_ blob: Data, offHdr: Int, szHdr: Int) {
            let off = UInt32(meta.count)
            let sz = UInt32(blob.count)
            meta.append(blob)
            while meta.count % 4 != 0 { meta.append(0) }
            putU32(&meta, offHdr, off)
            putU32(&meta, szHdr, sz)
        }
        putU32(&meta, 0, 0xFAB1_1BAF)
        putU32(&meta, 4, 29)
        appendSec(slTable, offHdr: 8, szHdr: 12)
        appendSec(slData, offHdr: 16, szHdr: 20)
        appendSec(strBlob, offHdr: 24, szHdr: 28)
        appendSec(props, offHdr: 40, szHdr: 44)
        appendSec(methods, offHdr: 48, szHdr: 52)
        appendSec(fieldDefs, offHdr: 64, szHdr: 68)
        appendSec(defData, offHdr: 72, szHdr: 76)
        appendSec(fields, offHdr: 96, szHdr: 100)
        appendSec(typeDefs, offHdr: 160, szHdr: 164)
        appendSec(images, offHdr: 168, szHdr: 172)

        // 3. Build Synthetic 64-bit ARM64 Mach-O
        // Layout:
        //   0x0000 - 0x0FFF: Mach-O header + 2 LC_SEGMENT_64 (__TEXT vm=0x100000000 sz=0x2000, __DATA vm=0x100002000 sz=0x2000)
        //   0x1000 - 0x1FFF: __TEXT,__text (func 0: MOV W0, #100; RET; func 1: RET) + __TEXT,__cstring ("Assembly-CSharp.dll\0")
        //   0x2000 - 0x3FFF: __DATA,__data (methodPtrs, CodeGenModule, CodeReg, Il2CppType, fieldOffsets, MetaReg)
        var macho = Data(repeating: 0, count: 0x4000)
        let baseVM: UInt64 = 0x1_0000_0000

        // __TEXT,__text at 0x1000
        putU32(&macho, 0x1000, 0x5280_0C80) // MOV W0, #100
        putU32(&macho, 0x1004, 0xD65F_03C0) // RET
        putU32(&macho, 0x1010, 0xD65F_03C0) // RET
        // __cstring at 0x1200
        let dllBytes = Array("Assembly-CSharp.dll\0".utf8)
        for (i, b) in dllBytes.enumerated() { macho[0x1200 + i] = b }

        // __DATA at 0x2000 (VM 0x100002000)
        // 0x2000: methodPointers[2] = [0x100001000, 0x100001010]
        putU64(&macho, 0x2000, baseVM + 0x1000)
        putU64(&macho, 0x2008, baseVM + 0x1010)
        // 0x2020: Il2CppCodeGenModule { namePtr, methodCount=2, methodPointers }
        putU64(&macho, 0x2020, baseVM + 0x1200)
        putU64(&macho, 0x2028, 2)
        putU64(&macho, 0x2030, baseVM + 0x2000)
        // 0x2040: codeGenModules*[1] = [0x100002020]
        putU64(&macho, 0x2040, baseVM + 0x2020)
        // 0x2060: CodeRegistration { codeGenModulesCount=1, codeGenModules**=0x100002040 }
        putU64(&macho, 0x2060, 1)
        putU64(&macho, 0x2068, baseVM + 0x2040)

        // 0x2100: Il2CppType[0] (Int32: typeEnum=0x08 @ +10, attrs=0x0006 @ +8)
        putU16(&macho, 0x2108, 0x0006)
        macho[0x210A] = 0x08
        // 0x2120: types*[1] = [0x100002100]
        putU64(&macho, 0x2120, baseVM + 0x2100)

        // 0x2140: fieldOffsets for Type 0 = [0x18]
        putU32(&macho, 0x2140, 0x18)
        // 0x2150: fieldOffsets*[3] = [0x100002140, 0, 0]
        putU64(&macho, 0x2150, baseVM + 0x2140)
        // 0x2170: typeDefSizes[3]
        putU32(&macho, 0x2170, 0x20)

        // 0x2200: MetadataRegistration tail: typesCount @ 0x21E0 (=p-32), types** @ 0x21E8 (=p-24), fieldOffsetsCount @ 0x2200 (=3), fieldOffsets** @ 0x2208, tdSizesCount @ 0x2210 (=3), tdSizes* @ 0x2218
        putU64(&macho, 0x21E0, 1)
        putU64(&macho, 0x21E8, baseVM + 0x2120)
        putU64(&macho, 0x2200, 3)
        putU64(&macho, 0x2208, baseVM + 0x2150)
        putU64(&macho, 0x2210, 3)
        putU64(&macho, 0x2218, baseVM + 0x2170)

        // Write Mach-O Header (32 bytes) + 2 LC_SEGMENT_64 (each 72 + nsects*80)
        let segTextCmdSize: UInt32 = 72 + 2 * 80 // 232
        let segDataCmdSize: UInt32 = 72 + 1 * 80 // 152
        putU32(&macho, 0, 0xFEED_FACF)
        putU32(&macho, 4, 0x0100_000C) // ARM64
        putU32(&macho, 8, 0)
        putU32(&macho, 12, 6)          // MH_DYLIB
        putU32(&macho, 16, 2)          // ncmds
        putU32(&macho, 20, segTextCmdSize + segDataCmdSize)

        var c = 32
        // Segment __TEXT
        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segTextCmdSize)
        writeFixed(&macho, c + 8, "__TEXT")
        putU64(&macho, c + 24, baseVM); putU64(&macho, c + 32, 0x2000)
        putU64(&macho, c + 40, 0); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 5); putU32(&macho, c + 60, 5); putU32(&macho, c + 64, 2)
        c += 72
        // Section __text
        writeFixed(&macho, c, "__text"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, baseVM + 0x1000); putU64(&macho, c + 40, 0x200)
        putU32(&macho, c + 48, 0x1000); putU32(&macho, c + 64, 0x8000_0400)
        c += 80
        // Section __cstring
        writeFixed(&macho, c, "__cstring"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, baseVM + 0x1200); putU64(&macho, c + 40, 0x200)
        putU32(&macho, c + 48, 0x1200); putU32(&macho, c + 64, 0x02)
        c += 80

        // Segment __DATA
        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segDataCmdSize)
        writeFixed(&macho, c + 8, "__DATA")
        putU64(&macho, c + 24, baseVM + 0x2000); putU64(&macho, c + 32, 0x2000)
        putU64(&macho, c + 40, 0x2000); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 3); putU32(&macho, c + 60, 3); putU32(&macho, c + 64, 1)
        c += 72
        // Section __data
        writeFixed(&macho, c, "__data"); writeFixed(&macho, c + 16, "__DATA")
        putU64(&macho, c + 32, baseVM + 0x2000); putU64(&macho, c + 40, 0x2000)
        putU32(&macho, c + 48, 0x2000); putU32(&macho, c + 64, 0)

        let plistDict: [String: Any] = [
            "CFBundleDisplayName": "OfflineHero",
            "CFBundleIdentifier": "com.indie.offlinehero",
            "CFBundleShortVersionString": "1.2.0",
            "CFBundleExecutable": "OfflineHero"
        ]
        let plistData = try! PropertyListSerialization.data(fromPropertyList: plistDict, format: .xml, options: 0)

        return (meta, macho, plistData)
    }

    static func readU32(_ d: Data, _ o: Int) -> UInt32 {
        UInt32(d[o]) | (UInt32(d[o + 1]) << 8) | (UInt32(d[o + 2]) << 16) | (UInt32(d[o + 3]) << 24)
    }

    static func putU16(_ d: inout Data, _ o: Int, _ v: UInt16) {
        d[o] = UInt8(v & 0xFF); d[o + 1] = UInt8((v >> 8) & 0xFF)
    }

    static func putU32(_ d: inout Data, _ o: Int, _ v: UInt32) {
        d[o] = UInt8(v & 0xFF); d[o + 1] = UInt8((v >> 8) & 0xFF)
        d[o + 2] = UInt8((v >> 16) & 0xFF); d[o + 3] = UInt8((v >> 24) & 0xFF)
    }

    static func putU64(_ d: inout Data, _ o: Int, _ v: UInt64) {
        putU32(&d, o, UInt32(v & 0xFFFF_FFFF))
        putU32(&d, o + 4, UInt32((v >> 32) & 0xFFFF_FFFF))
    }

    static func writeFixed(_ d: inout Data, _ o: Int, _ s: String) {
        for (i, b) in s.utf8.prefix(16).enumerated() { d[o + i] = b }
    }
}
