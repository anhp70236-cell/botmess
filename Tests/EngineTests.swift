import Foundation

@main
struct EngineTests {
    static func main() throws {
        print("Running IPA Dumper Pro Core Engine Tests...")
        try testSafetyGuard()
        try testStreamingZipRoundTrip()
        try testFullIL2CPPAndMachOPipeline()
        try testEncryptedMetadataFallback()
        try testPrefixedPathAndMetadataOverride()
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
        let payload = String(repeating: "IL2CPP_STREAM_TEST_LINE\n", count: 100_000) // ~2.4 MB (>4 chunks)
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
        print("[PASS] StreamingZip RoundTrip (Apple Compression + CRC32)")
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

        assertTrue(result.session.diagnostics.metadataStatus.isValid, "Metadata must be detected as valid")
        assertTrue(result.session.stats.filteredRealMoneyCount >= 2, "Real-money symbols must be filtered")
        assertTrue(result.session.stats.dummyDllsCount == 2, "Should create Il2CppDummyDll.dll + Assembly-CSharp.dll")
        assertTrue(result.session.stats.eventsCount >= 1, "Should parse event OnDamage")

        let dumpCS = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("dump.cs"), encoding: .utf8)
        assertTrue(dumpCS.contains("class PlayerStats<T>"), "dump.cs missing generic class PlayerStats<T>")
        assertTrue(dumpCS.contains("// TypeDefIndex: 0"), "dump.cs missing TypeDefIndex")
        assertTrue(dumpCS.contains("int health = 50; // 0x18"), "dump.cs missing health field with default & offset")
        assertTrue(dumpCS.contains("// RVA: 0x"), "dump.cs missing method RVA")
        assertTrue(dumpCS.contains("int GetHealth()"), "dump.cs missing GetHealth method")
        assertTrue(dumpCS.contains("int MaxHealth { get; }"), "dump.cs missing MaxHealth property")
        assertTrue(dumpCS.contains("event object OnDamage;"), "dump.cs missing OnDamage event")
        assertTrue(dumpCS.contains("enum HeroState"), "dump.cs missing HeroState enum")
        assertTrue(dumpCS.contains("Active = 1"), "dump.cs missing enum constant Active = 1")
        assertTrue(!dumpCS.contains("IAPManager"), "dump.cs leaked blocked IAPManager")
        assertTrue(!dumpCS.contains("PurchaseDiamonds"), "dump.cs leaked blocked PurchaseDiamonds")

        let headerText = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("il2cpp.h"), encoding: .utf8)
        assertTrue(headerText.contains("GameCore_PlayerStats_Fields"), "il2cpp.h missing PlayerStats struct")

        let summaryText = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("offsets_summary.txt"), encoding: .utf8)
        assertTrue(summaryText.contains("GameCore.PlayerStats"), "offsets_summary.txt missing PlayerStats")

        let nativeText = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("native_functions.txt"), encoding: .utf8)
        assertTrue(nativeText.contains("Binary: UnityFramework"), "native_functions.txt missing UnityFramework header")

        let scriptData = try Data(contentsOf: result.outputDirectoryURL.appendingPathComponent("script.json"))
        let scriptObj = try JSONSerialization.jsonObject(with: scriptData) as? [String: Any]
        assertTrue(scriptObj?["ScriptMethod"] != nil, "script.json missing ScriptMethod")
        assertTrue(scriptObj?["ScriptMetadata"] != nil, "script.json missing ScriptMetadata")

        let slData = try Data(contentsOf: result.outputDirectoryURL.appendingPathComponent("stringliteral.json"))
        let slArr = try JSONSerialization.jsonObject(with: slData) as? [[String: Any]]
        assertTrue(slArr?.count == 1, "stringliteral.json should only contain 1 non-blocked literal")

        let dllDir = result.outputDirectoryURL.appendingPathComponent("DummyDll")
        try validateECMA335DLL(at: dllDir.appendingPathComponent("Il2CppDummyDll.dll"))
        try validateECMA335DLL(at: dllDir.appendingPathComponent("Assembly-CSharp.dll"))

        print("[PASS] Full IL2CPP v29 + Mach-O + DummyDLL + RealMoneyFilter Pipeline")
    }

    static func testEncryptedMetadataFallback() throws {
        let tmp = FileManager.default.temporaryDirectory.appendingPathComponent("enc_test_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tmp, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tmp) }

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

        if case .encryptedOrProtected = result.session.diagnostics.metadataStatus {
            // Expected
        } else {
            assertTrue(false, "Expected encryptedOrProtected status")
        }
        let report = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("report.txt"), encoding: .utf8)
        assertTrue(report.contains("Protected/Encrypted"), "report.txt must note protected metadata")
        print("[PASS] Encrypted Metadata Detection + Mach-O Fallback")
    }

    static func testPrefixedPathAndMetadataOverride() throws {
        let tmp = FileManager.default.temporaryDirectory.appendingPathComponent("prefixed_test_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tmp, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tmp) }

        let (plainMetaData, machoData, plistData) = buildSyntheticIL2CPPAssets()
        let plainMetaURL = tmp.appendingPathComponent("plain_global-metadata.dat")
        let encMetaURL = tmp.appendingPathComponent("enc_global-metadata.dat")
        let machoURL = tmp.appendingPathComponent("UnityFramework")
        let plistURL = tmp.appendingPathComponent("Info.plist")

        var encBytes = [UInt8](repeating: 0xAA, count: 4096)
        for i in 0..<encBytes.count { encBytes[i] = UInt8((i * 73 + 19) & 0xFF) }

        try plainMetaData.write(to: plainMetaURL)
        try Data(encBytes).write(to: encMetaURL)
        try machoData.write(to: machoURL)
        try plistData.write(to: plistURL)

        let ipaURL = tmp.appendingPathComponent("WrappedHero.tipa")
        let zw = try StreamingZipWriter(destinationURL: ipaURL)
        try zw.addFile(at: plistURL, entryName: "./Payload/WrappedApp.app/Info.plist")
        try zw.addFile(at: machoURL, entryName: "./Payload/WrappedApp.app/Frameworks/UnityFramework.framework/UnityFramework")
        try zw.addFile(at: encMetaURL, entryName: "./Payload/WrappedApp.app/Data/Managed/Metadata/global-metadata.dat")
        try zw.finalize()

        let inspected = try DumpCoordinator.inspectIPA(at: ipaURL)
        assertTrue(inspected.appName == "OfflineHero", "Should parse Info.plist inside ./Payload/")
        assertTrue(inspected.metadataPath != nil, "Should find metadata inside ./Payload/")

        let result = try DumpCoordinator().runIPA(
            ipaURL: ipaURL,
            overrideMetadataURL: plainMetaURL,
            outputsRootURL: tmp.appendingPathComponent("out"),
            options: .default
        ) { _ in }

        assertTrue(result.session.diagnostics.metadataStatus.isValid, "Override metadata should yield valid IL2CPP status")
        let dumpCS = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("dump.cs"), encoding: .utf8)
        assertTrue(dumpCS.contains("class PlayerStats<T>"), "Override dump.cs missing PlayerStats<T>")
        assertTrue(!dumpCS.contains("IAPManager"), "Override dump.cs leaked IAPManager")
        print("[PASS] Prefixed ./Payload/ IPA + External Metadata Override")
    }

    static func validateECMA335DLL(at url: URL) throws {
        let data = try Data(contentsOf: url)
        assertTrue(data.count > 0x280, "DLL too small: \(url.lastPathComponent)")
        assertTrue(data[0] == 0x4D && data[1] == 0x5A, "Missing MZ header")
        let lfanew = Int(readU32(data, 0x3C))
        assertTrue(readU32(data, lfanew) == 0x0000_4550, "Missing PE\\0\\0 signature")
        let clrRva = readU32(data, 0x168)
        assertTrue(clrRva == 0x2008, "Unexpected CLR header RVA")
        let bsjb = readU32(data, 0x260)
        assertTrue(bsjb == 0x424A_5342, "Missing BSJB CLI metadata root")
    }

    // MARK: - Synthetic Unity v29 Binary Builder

    static func buildSyntheticIL2CPPAssets() -> (meta: Data, macho: Data, plist: Data) {
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
        let sHeroState = addStr("HeroState")
        let sHealth = addStr("health")
        let sActive = addStr("Active")
        let sValuePP = addStr("value__")
        let sGetHealth = addStr("GetHealth")
        let sPurchase = addStr("PurchaseDiamonds")
        let sMaxHealth = addStr("MaxHealth")
        let sOnDamage = addStr("OnDamage")
        let sSys = addStr("System")
        let sObj = addStr("Object")
        let sEnum = addStr("Enum")
        let sGenT = addStr("T")

        // Images (40 bytes * 1)
        var images = Data(repeating: 0, count: 40)
        putU32(&images, 0, sAsm)
        putU32(&images, 8, 0)
        putU32(&images, 12, 5)
        putU32(&images, 16, 0xFFFF_FFFF)
        putU32(&images, 20, 0x2000_0001)

        // GenericParameters (16 bytes * 1) & GenericContainers (16 bytes * 1)
        var genParams = Data(repeating: 0, count: 16)
        putU32(&genParams, 0, 0)
        putU32(&genParams, 4, sGenT)
        var genContainers = Data(repeating: 0, count: 16)
        putU32(&genContainers, 0, 0)
        putU32(&genContainers, 4, 1) // type_argc = 1
        putU32(&genContainers, 8, 0)
        putU32(&genContainers, 12, 0) // genericParameterStart = 0

        // TypeDefs (88 bytes * 5: PlayerStats<T>, IAPManager, HeroState, System.Object, System.Enum)
        var typeDefs = Data(repeating: 0, count: 88 * 5)
        // Type 0: PlayerStats (1 field, 1 method, 1 prop, 1 event, genericContainer=0)
        putU32(&typeDefs, 0, sPlayer)
        putU32(&typeDefs, 4, sNs)
        putU32(&typeDefs, 12, 0xFFFF_FFFF) // declaringType
        putU32(&typeDefs, 16, 0xFFFF_FFFF) // parentType
        putU32(&typeDefs, 20, 0xFFFF_FFFF)
        putU32(&typeDefs, 24, 0)           // genericContainerIndex = 0
        putU32(&typeDefs, 28, 0x0010_0001) // flags (public)
        putU32(&typeDefs, 32, 0)           // fieldStart
        putU32(&typeDefs, 36, 0)           // methodStart
        putU32(&typeDefs, 40, 0)           // eventStart
        putU32(&typeDefs, 44, 0)           // propStart
        putU32(&typeDefs, 48, 0xFFFF_FFFF)
        putU32(&typeDefs, 52, 0xFFFF_FFFF)
        putU16(&typeDefs, 64, 1)           // methodCount
        putU16(&typeDefs, 66, 1)           // propCount
        putU16(&typeDefs, 68, 1)           // fieldCount
        putU16(&typeDefs, 70, 1)           // eventCount
        putU32(&typeDefs, 84, 0x0200_0002)

        // Type 1: IAPManager -> filtered!
        let t1 = 88
        putU32(&typeDefs, t1 + 0, sIAP)
        putU32(&typeDefs, t1 + 4, sNs)
        putU32(&typeDefs, t1 + 12, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 16, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 24, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 28, 0x0010_0001)
        putU32(&typeDefs, t1 + 32, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 36, 1)
        putU32(&typeDefs, t1 + 40, 0xFFFF_FFFF)
        putU32(&typeDefs, t1 + 44, 0xFFFF_FFFF)
        putU16(&typeDefs, t1 + 64, 1)
        putU32(&typeDefs, t1 + 84, 0x0200_0003)

        // Type 2: HeroState (enum, 2 fields: value__, Active = 1)
        let t2 = 176
        putU32(&typeDefs, t2 + 0, sHeroState)
        putU32(&typeDefs, t2 + 4, sNs)
        putU32(&typeDefs, t2 + 12, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 16, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 24, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 28, 0x0010_0101) // public sealed
        putU32(&typeDefs, t2 + 32, 1)           // fieldStart = 1
        putU32(&typeDefs, t2 + 36, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 40, 0xFFFF_FFFF)
        putU32(&typeDefs, t2 + 44, 0xFFFF_FFFF)
        putU16(&typeDefs, t2 + 68, 2)           // fieldCount = 2
        putU32(&typeDefs, t2 + 80, 0x03)        // bitfield: valuetype(1) | enumtype(2)
        putU32(&typeDefs, t2 + 84, 0x0200_0004)

        // Type 3: System.Object
        let t3 = 264
        putU32(&typeDefs, t3 + 0, sObj); putU32(&typeDefs, t3 + 4, sSys)
        putU32(&typeDefs, t3 + 24, 0xFFFF_FFFF); putU32(&typeDefs, t3 + 28, 0x0010_0001)
        putU32(&typeDefs, t3 + 32, 0xFFFF_FFFF); putU32(&typeDefs, t3 + 36, 0xFFFF_FFFF)
        putU32(&typeDefs, t3 + 40, 0xFFFF_FFFF); putU32(&typeDefs, t3 + 44, 0xFFFF_FFFF)
        putU32(&typeDefs, t3 + 84, 0x0200_0005)

        // Type 4: System.Enum
        let t4 = 352
        putU32(&typeDefs, t4 + 0, sEnum); putU32(&typeDefs, t4 + 4, sSys)
        putU32(&typeDefs, t4 + 24, 0xFFFF_FFFF); putU32(&typeDefs, t4 + 28, 0x0010_0081)
        putU32(&typeDefs, t4 + 32, 0xFFFF_FFFF); putU32(&typeDefs, t4 + 36, 0xFFFF_FFFF)
        putU32(&typeDefs, t4 + 40, 0xFFFF_FFFF); putU32(&typeDefs, t4 + 44, 0xFFFF_FFFF)
        putU32(&typeDefs, t4 + 84, 0x0200_0006)

        // Fields (12 bytes * 3): [0]=health, [1]=value__, [2]=Active
        var fields = Data(repeating: 0, count: 36)
        putU32(&fields, 0, sHealth); putU32(&fields, 4, 0); putU32(&fields, 8, 0x0400_0001)
        putU32(&fields, 12, sValuePP); putU32(&fields, 16, 0); putU32(&fields, 20, 0x0400_0002)
        putU32(&fields, 24, sActive); putU32(&fields, 28, 0); putU32(&fields, 32, 0x0400_0003)

        // FieldDefaultValues: field 0 -> 50 (0x64), field 2 -> 1 (0x02)
        var fieldDefs = Data(repeating: 0, count: 24)
        putU32(&fieldDefs, 0, 0); putU32(&fieldDefs, 4, 0); putU32(&fieldDefs, 8, 0)
        putU32(&fieldDefs, 12, 2); putU32(&fieldDefs, 16, 0); putU32(&fieldDefs, 20, 1)
        let defData = Data([0x64, 0x02, 0x00, 0x00])

        // Methods (32 bytes * 2)
        var methods = Data(repeating: 0, count: 64)
        putU32(&methods, 0, sGetHealth); putU32(&methods, 4, 0); putU32(&methods, 8, 0)
        putU32(&methods, 12, 0xFFFF_FFFF); putU32(&methods, 16, 0xFFFF_FFFF)
        putU32(&methods, 20, 0x0600_0001); putU16(&methods, 24, 0x0086); putU16(&methods, 28, 0xFFFF)

        putU32(&methods, 32, sPurchase); putU32(&methods, 36, 1); putU32(&methods, 40, 0)
        putU32(&methods, 44, 0xFFFF_FFFF); putU32(&methods, 48, 0xFFFF_FFFF)
        putU32(&methods, 52, 0x0600_0002); putU16(&methods, 56, 0x0086); putU16(&methods, 60, 0xFFFF)

        // Properties (24 bytes * 1): MaxHealth
        var props = Data(repeating: 0, count: 24)
        putU32(&props, 0, sMaxHealth); putU32(&props, 4, 0); putU32(&props, 8, 0xFFFF_FFFF)
        putU32(&props, 16, 0x1700_0001)

        // Events (24 bytes * 1): OnDamage
        var events = Data(repeating: 0, count: 24)
        putU32(&events, 0, sOnDamage); putU32(&events, 4, 0xFFFF_FFFF)
        putU32(&events, 8, 0xFFFF_FFFF); putU32(&events, 12, 0xFFFF_FFFF); putU32(&events, 16, 0xFFFF_FFFF)
        putU32(&events, 20, 0x1400_0001)

        // StringLiterals v29 order: { dataIndex, length }
        let lit0 = Array("Player spawned".utf8)
        let lit1 = Array("VerifyReceipt".utf8)
        var slData = Data(lit0)
        slData.append(contentsOf: lit1)
        var slTable = Data(repeating: 0, count: 16)
        putU32(&slTable, 0, 0); putU32(&slTable, 4, UInt32(lit0.count))
        putU32(&slTable, 8, UInt32(lit0.count)); putU32(&slTable, 12, UInt32(lit1.count))

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
        appendSec(events, offHdr: 32, szHdr: 36)
        appendSec(props, offHdr: 40, szHdr: 44)
        appendSec(methods, offHdr: 48, szHdr: 52)
        appendSec(fieldDefs, offHdr: 64, szHdr: 68)
        appendSec(defData, offHdr: 72, szHdr: 76)
        appendSec(fields, offHdr: 96, szHdr: 100)
        appendSec(genParams, offHdr: 104, szHdr: 108)
        appendSec(genContainers, offHdr: 120, szHdr: 124)
        appendSec(typeDefs, offHdr: 160, szHdr: 164)
        appendSec(images, offHdr: 168, szHdr: 172)

        // Synthetic ARM64 Mach-O
        var macho = Data(repeating: 0, count: 0x4000)
        let baseVM: UInt64 = 0x1_0000_0000
        putU32(&macho, 0x1000, 0x5280_0C80) // MOV W0, #100
        putU32(&macho, 0x1004, 0xD65F_03C0) // RET
        putU32(&macho, 0x1010, 0xD65F_03C0) // RET
        let dllBytes = Array("Assembly-CSharp.dll\0".utf8)
        for (i, b) in dllBytes.enumerated() { macho[0x1200 + i] = b }

        putU64(&macho, 0x2000, baseVM + 0x1000)
        putU64(&macho, 0x2008, baseVM + 0x1010)
        putU64(&macho, 0x2020, baseVM + 0x1200)
        putU64(&macho, 0x2028, 2)
        putU64(&macho, 0x2030, baseVM + 0x2000)
        putU64(&macho, 0x2040, baseVM + 0x2020)
        putU64(&macho, 0x2060, 1)
        putU64(&macho, 0x2068, baseVM + 0x2040)

        putU16(&macho, 0x2108, 0x0006)
        macho[0x210A] = 0x08
        putU64(&macho, 0x2120, baseVM + 0x2100)

        putU32(&macho, 0x2140, 0x18)
        putU32(&macho, 0x2148, 0x0)
        putU32(&macho, 0x214C, 0x0)
        putU64(&macho, 0x2150, baseVM + 0x2140)
        putU64(&macho, 0x2160, baseVM + 0x2148)
        putU32(&macho, 0x2180, 0x20)

        putU64(&macho, 0x21E0, 1)
        putU64(&macho, 0x21E8, baseVM + 0x2120)
        putU64(&macho, 0x2200, 5)
        putU64(&macho, 0x2208, baseVM + 0x2150)
        putU64(&macho, 0x2210, 5)
        putU64(&macho, 0x2218, baseVM + 0x2180)

        let segTextCmdSize: UInt32 = 72 + 2 * 80
        let segDataCmdSize: UInt32 = 72 + 1 * 80
        putU32(&macho, 0, 0xFEED_FACF)
        putU32(&macho, 4, 0x0100_000C)
        putU32(&macho, 8, 0)
        putU32(&macho, 12, 6)
        putU32(&macho, 16, 2)
        putU32(&macho, 20, segTextCmdSize + segDataCmdSize)

        var c = 32
        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segTextCmdSize)
        writeFixed(&macho, c + 8, "__TEXT")
        putU64(&macho, c + 24, baseVM); putU64(&macho, c + 32, 0x2000)
        putU64(&macho, c + 40, 0); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 5); putU32(&macho, c + 60, 5); putU32(&macho, c + 64, 2)
        c += 72
        writeFixed(&macho, c, "__text"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, baseVM + 0x1000); putU64(&macho, c + 40, 0x200)
        putU32(&macho, c + 48, 0x1000); putU32(&macho, c + 64, 0x8000_0400)
        c += 80
        writeFixed(&macho, c, "__cstring"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, baseVM + 0x1200); putU64(&macho, c + 40, 0x200)
        putU32(&macho, c + 48, 0x1200); putU32(&macho, c + 64, 0x02)
        c += 80

        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segDataCmdSize)
        writeFixed(&macho, c + 8, "__DATA")
        putU64(&macho, c + 24, baseVM + 0x2000); putU64(&macho, c + 32, 0x2000)
        putU64(&macho, c + 40, 0x2000); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 3); putU32(&macho, c + 60, 3); putU32(&macho, c + 64, 1)
        c += 72
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
