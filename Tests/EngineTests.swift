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
        try testChainedFixupsAndDyldInfoOnlyAndMultiBinary()
        try testIL2CPPv24CodeGenModulesAndGenericMethodsAndUnicode()
        try testUnityFrameworkBaseVMZeroAndCFString()
        try testCyclicExportsTrieAndZeroVMNegativeADRP()
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
        assertTrue(SafetyGuard.isRealMoneyBlocked("PurchaseSubscription"), "PurchaseSubscription must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("VIPSubscription"), "VIPSubscription must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("BuyBattlePass"), "BuyBattlePass must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("VerifyReceipt"), "VerifyReceipt must be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("PlayerHealth"), "PlayerHealth must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("EnemySpawner"), "EnemySpawner must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("Material.SetPass"), "Material.SetPass must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("AssetBundle.GetBundle"), "AssetBundle.GetBundle must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("UniRx.EventSubscription"), "UniRx.EventSubscription must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("SpriteAtlas.AddPack"), "SpriteAtlas.AddPack must NOT be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("AddGold"), "AddGold must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("SetGems"), "SetGems must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("GetCoins"), "GetCoins must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("UnlockVIP"), "UnlockVIP must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("RestorePurchases"), "RestorePurchases must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("CheckoutFlow"), "CheckoutFlow must be blocked")
        assertTrue(SafetyGuard.isRealMoneyBlocked("CreditCard"), "CreditCard must be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("GetComponent"), "GetComponent must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("SetPosition"), "SetPosition must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("AddListener"), "AddListener must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("DirectHit"), "DirectHit must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("RecordScore"), "RecordScore must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("CheckCollision"), "CheckCollision must NOT be blocked")
        assertTrue(!SafetyGuard.isRealMoneyBlocked("CardDeck"), "CardDeck must NOT be blocked")

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
        assertTrue(readBack == payload, "Extracted ZIP payload mismatch (expected=\(payload.utf8.count), actual=\(readBack.utf8.count), comp=\(entry.compressedSize), uncomp=\(entry.uncompressedSize))")
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

    static func testChainedFixupsAndDyldInfoOnlyAndMultiBinary() throws {
        let tmp = FileManager.default.temporaryDirectory.appendingPathComponent("chained_test_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tmp, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tmp) }

        let baseVM: UInt64 = 0x1_0000_0000
        var macho = Data(repeating: 0, count: 0x6000)
        // __text at 0x1000: RET, STP X29, X30, [SP, #-0x10]!, RET
        putU32(&macho, 0x1000, 0xD65F_03C0) // RET (also sub_1000)
        putU32(&macho, 0x1004, 0xA9BF_7BFD) // STP X29, X30, [SP, #-0x10]! -> prologue function start!
        putU32(&macho, 0x1008, 0xD65F_03C0) // RET

        for (i, b) in Array("ChainedObjCClass\0".utf8).enumerated() { macho[0x1400 + i] = b }
        for (i, b) in Array("MultiStartClass\0".utf8).enumerated() { macho[0x1420 + i] = b }

        // __DATA has a +0x2000 VM gap after __TEXT (vmAddr = baseVM + 0x4000, fileOffset = 0x2000)
        // so segment_offset (0x4000) differs from fileOffset (0x2000), testing VM-to-file mapping!
        let dataVM: UInt64 = baseVM + 0x4000
        let linkeditVM: UInt64 = baseVM + 0x6000

        // __objc_classlist at file 0x2000 (vm dataVM + 0x0000): 2 entries rebased with format-2 (DYLD_CHAINED_PTR_64)
        // encoded via DYLD_CHAINED_PTR_START_MULTI overflow chains
        putU64(&macho, 0x2000, (dataVM + 0x0100) | (UInt64(1) << 44)) // reserved bits set; format 2 uses bits 0..35 + high8
        putU64(&macho, 0x2008, (dataVM + 0x0200) | (UInt64(1) << 44))

        // Class 1 at file 0x2100 -> ro1 at file 0x2140 -> name at 0x1400
        putU64(&macho, 0x2100 + 32, dataVM + 0x0140)
        putU64(&macho, 0x2140 + 24, baseVM + 0x1400)
        // Class 2 at file 0x2200 -> ro2 at file 0x2240 -> name at 0x1420
        putU64(&macho, 0x2200 + 32, dataVM + 0x0240)
        putU64(&macho, 0x2240 + 24, baseVM + 0x1420)

        // LC_DYLD_CHAINED_FIXUPS payload at 0x4000
        putU32(&macho, 0x4004, 0x20)          // starts_offset = 0x20
        putU32(&macho, 0x4020, 3)             // seg_count = 3 (__TEXT, __DATA, __LINKEDIT)
        putU32(&macho, 0x4024, 0)             // seg 0 (__TEXT): none
        putU32(&macho, 0x4028, 16)            // seg 1 (__DATA) offset from starts = 16 -> 0x4030
        putU32(&macho, 0x402C, 0)             // seg 2 (__LINKEDIT): none

        // dyld_chained_starts_in_segment at 0x4030
        putU32(&macho, 0x4030, 64)            // size
        putU16(&macho, 0x4034, 0x1000)        // page_size = 4096
        putU16(&macho, 0x4036, 2)             // DYLD_CHAINED_PTR_64
        putU64(&macho, 0x4038, 0x4000)        // segment_offset = dataVM - baseVM = 0x4000 (not raw file offset 0x2000!)
        putU16(&macho, 0x4044, 2)             // page_count = 2
        putU16(&macho, 0x4046, 0x8002)        // page_start[0]: START_MULTI | ovIdx 2
        putU16(&macho, 0x4048, 0xFFFF)        // page_start[1]: START_NONE
        putU16(&macho, 0x404A, 0x0000)        // page_start[2]: chain 1 at 0x0000
        putU16(&macho, 0x404C, 0x8008)        // page_start[3]: START_LAST | 0x0008

        // LC_DYLD_INFO_ONLY exports trie at 0x4200: "_dyldExportedFn" -> RVA 0x1004
        let edge1 = Array("_dyldExportedFn\0".utf8)
        macho[0x4200] = 0 // root termSize = 0
        macho[0x4201] = 1 // 1 child
        for (i, b) in edge1.enumerated() { macho[0x4202 + i] = b }
        macho[0x4202 + edge1.count] = 19 // child node offset = 19
        macho[0x4200 + 19] = 3     // termSize = 3
        macho[0x4200 + 20] = 0     // flags = REGULAR
        macho[0x4200 + 21] = 0x84  // uleb128(0x1004) byte 0
        macho[0x4200 + 22] = 0x20  // uleb128(0x1004) byte 1
        macho[0x4200 + 23] = 0     // childCount = 0

        let segTextSz: UInt32 = 72 + 2 * 80
        let segDataSz: UInt32 = 72 + 1 * 80
        let segLinkSz: UInt32 = 72
        let dyldInfoSz: UInt32 = 48
        let chainedSz: UInt32 = 16
        let totalCmdSz = segTextSz + segDataSz + segLinkSz + dyldInfoSz + chainedSz

        putU32(&macho, 0, 0xFEED_FACF); putU32(&macho, 4, 0x0100_000C)
        putU32(&macho, 12, 2); putU32(&macho, 16, 5); putU32(&macho, 20, totalCmdSz)
        var c = 32
        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segTextSz); writeFixed(&macho, c + 8, "__TEXT")
        putU64(&macho, c + 24, baseVM); putU64(&macho, c + 32, 0x2000); putU64(&macho, c + 40, 0); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 5); putU32(&macho, c + 60, 5); putU32(&macho, c + 64, 2)
        c += 72
        writeFixed(&macho, c, "__text"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, baseVM + 0x1000); putU64(&macho, c + 40, 0x400); putU32(&macho, c + 48, 0x1000); putU32(&macho, c + 64, 0x8000_0400)
        c += 80
        writeFixed(&macho, c, "__cstring"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, baseVM + 0x1400); putU64(&macho, c + 40, 0x200); putU32(&macho, c + 48, 0x1400); putU32(&macho, c + 64, 0x02)
        c += 80

        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segDataSz); writeFixed(&macho, c + 8, "__DATA")
        putU64(&macho, c + 24, dataVM); putU64(&macho, c + 32, 0x2000); putU64(&macho, c + 40, 0x2000); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 3); putU32(&macho, c + 60, 3); putU32(&macho, c + 64, 1)
        c += 72
        writeFixed(&macho, c, "__objc_classlist"); writeFixed(&macho, c + 16, "__DATA")
        putU64(&macho, c + 32, dataVM); putU64(&macho, c + 40, 16); putU32(&macho, c + 48, 0x2000); putU32(&macho, c + 64, 0)
        c += 80

        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segLinkSz); writeFixed(&macho, c + 8, "__LINKEDIT")
        putU64(&macho, c + 24, linkeditVM); putU64(&macho, c + 32, 0x2000); putU64(&macho, c + 40, 0x4000); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 1); putU32(&macho, c + 60, 1); putU32(&macho, c + 64, 0)
        c += 72

        putU32(&macho, c, 0x8000_0022); putU32(&macho, c + 4, dyldInfoSz) // LC_DYLD_INFO_ONLY
        putU32(&macho, c + 40, 0x4200); putU32(&macho, c + 44, 0x40)
        c += 48

        putU32(&macho, c, 0x8000_0034); putU32(&macho, c + 4, chainedSz) // LC_DYLD_CHAINED_FIXUPS
        putU32(&macho, c + 8, 0x4000); putU32(&macho, c + 12, 0x200)

        // Second Mach-O dylib inside Frameworks/HelperKit.dylib
        var dylib = Data(repeating: 0, count: 0x4000)
        putU32(&dylib, 0x1000, 0xD65F_03C0)
        let edge2 = Array("_helperDylibFn\0".utf8)
        dylib[0x2000] = 0; dylib[0x2001] = 1
        for (i, b) in edge2.enumerated() { dylib[0x2002 + i] = b }
        dylib[0x2002 + edge2.count] = 18
        dylib[0x2000 + 18] = 3; dylib[0x2000 + 19] = 0; dylib[0x2000 + 20] = 0x80; dylib[0x2000 + 21] = 0x20
        dylib[0x2000 + 22] = 0

        let dTextSz: UInt32 = 72 + 80
        let dExpSz: UInt32 = 16
        putU32(&dylib, 0, 0xFEED_FACF); putU32(&dylib, 4, 0x0100_000C)
        putU32(&dylib, 12, 6); putU32(&dylib, 16, 2); putU32(&dylib, 20, dTextSz + dExpSz)
        var dc = 32
        putU32(&dylib, dc, 0x19); putU32(&dylib, dc + 4, dTextSz); writeFixed(&dylib, dc + 8, "__TEXT")
        putU64(&dylib, dc + 24, baseVM); putU64(&dylib, dc + 32, 0x3000); putU64(&dylib, dc + 40, 0); putU64(&dylib, dc + 48, 0x3000)
        putU32(&dylib, dc + 56, 5); putU32(&dylib, dc + 60, 5); putU32(&dylib, dc + 64, 1)
        dc += 72
        writeFixed(&dylib, dc, "__text"); writeFixed(&dylib, dc + 16, "__TEXT")
        putU64(&dylib, dc + 32, baseVM + 0x1000); putU64(&dylib, dc + 40, 0x100); putU32(&dylib, dc + 48, 0x1000); putU32(&dylib, dc + 64, 0x8000_0400)
        dc += 80
        putU32(&dylib, dc, 0x8000_0033); putU32(&dylib, dc + 4, dExpSz)
        putU32(&dylib, dc + 8, 0x2000); putU32(&dylib, dc + 12, 0x40)

        let plistDict: [String: Any] = [
            "CFBundleDisplayName": "NativeMultiTest",
            "CFBundleIdentifier": "com.indie.nativemulti",
            "CFBundleShortVersionString": "1.0",
            "CFBundleExecutable": "GameApp"
        ]
        let plistData = try PropertyListSerialization.data(fromPropertyList: plistDict, format: .xml, options: 0)

        let appURL = tmp.appendingPathComponent("GameApp")
        let dylibURL = tmp.appendingPathComponent("HelperKit.dylib")
        let plistURL = tmp.appendingPathComponent("Info.plist")
        let assetURL = tmp.appendingPathComponent("sharedassets0")
        try macho.write(to: appURL)
        try dylib.write(to: dylibURL)
        try plistData.write(to: plistURL)
        try Data(repeating: 0x55, count: 8192).write(to: assetURL)

        let ipaURL = tmp.appendingPathComponent("NativeMulti.ipa")
        let zw = try StreamingZipWriter(destinationURL: ipaURL)
        try zw.addFile(at: plistURL, entryName: "Payload/GameApp.app/Info.plist")
        try zw.addFile(at: appURL, entryName: "Payload/GameApp.app/GameApp")
        try zw.addFile(at: assetURL, entryName: "Payload/GameApp.app/Data/sharedassets0")
        try zw.addFile(at: dylibURL, entryName: "Payload/GameApp.app/Frameworks/HelperKit.dylib")
        try zw.finalize()

        var opts = DumpOptions.default
        opts.autoExportZip = true
        let result = try DumpCoordinator().runIPA(ipaURL: ipaURL, outputsRootURL: tmp.appendingPathComponent("out"), options: opts) { _ in }

        let dumpCS = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("dump.cs"), encoding: .utf8)
        let summaryText = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("offsets_summary.txt"), encoding: .utf8)
        let nativeText = try String(contentsOf: result.outputDirectoryURL.appendingPathComponent("native_functions.txt"), encoding: .utf8)
        let scriptURL = result.outputDirectoryURL.appendingPathComponent("script.json")
        let scriptText = try String(contentsOf: scriptURL, encoding: .utf8)
        let scriptJSON = try JSONSerialization.jsonObject(with: try Data(contentsOf: scriptURL)) as? [String: Any]
        assertTrue(scriptJSON?["ScriptMethod"] != nil && scriptJSON?["Addresses"] != nil, "Streaming script.json must be valid JSON")

        assertTrue(dumpCS.contains("ChainedObjCClass"), "Format-2 chained pointer Obj-C class missing")
        assertTrue(dumpCS.contains("MultiStartClass"), "DYLD_CHAINED_PTR_START_MULTI Obj-C class missing")
        assertTrue(nativeText.contains("dyldExportedFn"), "LC_DYLD_INFO_ONLY export missing")
        assertTrue(nativeText.contains("helperDylibFn"), "Embedded dylib export missing")
        assertTrue(summaryText.contains("helperDylibFn"), "offsets_summary.txt missing secondary dylib")
        assertTrue(scriptText.contains("dyldExportedFn") && scriptText.contains("helperDylibFn"), "script.json missing exports")

        let zipReader = try StreamingZipReader(url: result.outputDirectoryURL.appendingPathComponent("NativeMultiTest_dump.zip"))
        assertTrue(zipReader.entries.contains(where: { $0.path == "dump.cs" }), "ZIP should use clean relative path 'dump.cs', got: \(zipReader.entries.map { $0.path })")
        print("[PASS] Chained Fixups (Format 2 + START_MULTI) + LC_DYLD_INFO_ONLY + Multi-Binary + ZIP Paths")
    }

    static func testIL2CPPv24CodeGenModulesAndGenericMethodsAndUnicode() throws {
        let tmp = FileManager.default.temporaryDirectory.appendingPathComponent("v24_gen_test_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tmp, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tmp) }

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
        let sNs = addStr("CoreGame")
        let sClass = addStr("ChiếnBinh_战士")
        let sField = addStr("power")
        let sPrefixed = addStr("Prefix_GenericAttack")
        let sMethod = sPrefixed + 7 // suffix-shared string where prev byte != 0!

        var images = Data(repeating: 0, count: 40)
        putU32(&images, 0, sAsm); putU32(&images, 8, 0); putU32(&images, 12, 1)
        putU32(&images, 16, 0xFFFF_FFFF); putU32(&images, 20, 0x2000_0001)

        var typeDefs = Data(repeating: 0, count: 88)
        putU32(&typeDefs, 0, sClass); putU32(&typeDefs, 4, sNs)
        putU32(&typeDefs, 12, 0xFFFF_FFFF); putU32(&typeDefs, 16, 0xFFFF_FFFF)
        putU32(&typeDefs, 24, 0xFFFF_FFFF); putU32(&typeDefs, 28, 0x0010_0001)
        putU32(&typeDefs, 32, 0); putU32(&typeDefs, 36, 0)
        putU32(&typeDefs, 40, 0xFFFF_FFFF); putU32(&typeDefs, 44, 0xFFFF_FFFF)
        putU16(&typeDefs, 64, 1); putU16(&typeDefs, 68, 1); putU32(&typeDefs, 84, 0x0200_0002)

        var fields = Data(repeating: 0, count: 12)
        putU32(&fields, 0, sField); putU32(&fields, 4, 0); putU32(&fields, 8, 0x0400_0001)

        var methods = Data(repeating: 0, count: 32)
        putU32(&methods, 0, sMethod); putU32(&methods, 4, 0); putU32(&methods, 8, 0)
        putU32(&methods, 12, 0xFFFF_FFFF); putU32(&methods, 16, 0xFFFF_FFFF)
        putU32(&methods, 20, 0x0600_0001); putU16(&methods, 24, 0x0086); putU16(&methods, 28, 0xFFFF)

        var meta = Data(repeating: 0, count: 288)
        func appendSec(_ blob: Data, offHdr: Int, szHdr: Int) {
            let off = UInt32(meta.count); let sz = UInt32(blob.count)
            meta.append(blob); while meta.count % 4 != 0 { meta.append(0) }
            putU32(&meta, offHdr, off); putU32(&meta, szHdr, sz)
        }
        putU32(&meta, 0, 0xFAB1_1BAF); putU32(&meta, 4, 24) // version 24!
        appendSec(strBlob, offHdr: 24, szHdr: 28)
        appendSec(methods, offHdr: 48, szHdr: 52)
        appendSec(fields, offHdr: 96, szHdr: 100)
        appendSec(typeDefs, offHdr: 160, szHdr: 164)
        appendSec(images, offHdr: 168, szHdr: 172)

        // Mach-O with CodeGenModules + genericMethodPointers + MetaReg(genericMethodTable + methodSpecs + types + fieldOffsets)
        var macho = Data(repeating: 0, count: 0x4000)
        let baseVM: UInt64 = 0x1_0000_0000
        putU32(&macho, 0x1000, 0xD65F_03C0)
        putU32(&macho, 0x1020, 0xD65F_03C0)
        putU32(&macho, 0x1030, 0xD65F_03C0)
        for (i, b) in Array("Assembly-CSharp.dll\0".utf8).enumerated() { macho[0x1200 + i] = b }

        // Method ptrs table at 0x2000 (1 method -> RVA 0x1000)
        putU64(&macho, 0x2000, baseVM + 0x1000)
        // GenericMethodPointers array at 0x2010 (2 instantiated RVAs: 0x1020, 0x1030)
        putU64(&macho, 0x2010, baseVM + 0x1020)
        putU64(&macho, 0x2018, baseVM + 0x1030)
        // CodeGenModule at 0x2020: { namePtr, methodCount=1, methodPtrs }
        putU64(&macho, 0x2020, baseVM + 0x1200)
        putU64(&macho, 0x2028, 1)
        putU64(&macho, 0x2030, baseVM + 0x2000)
        // CodeGenModules* at 0x2040
        putU64(&macho, 0x2040, baseVM + 0x2020)

        // Il2CppCodeRegistration at 0x2050:
        // genericMethodPointersCount=2 @ 0x2050, genericMethodPointers=0x2010 @ 0x2058
        // codeGenModulesCount=1 @ 0x2060, codeGenModules=0x2040 @ 0x2068
        putU64(&macho, 0x2050, 2); putU64(&macho, 0x2058, baseVM + 0x2010)
        putU64(&macho, 0x2060, 1); putU64(&macho, 0x2068, baseVM + 0x2040)

        // FieldOffsets[0] = 0x18 @ 0x2100; fieldOffsetsTable @ 0x2110; tdSize @ 0x2118
        putU32(&macho, 0x2100, 0x18)
        putU64(&macho, 0x2110, baseVM + 0x2100)
        putU32(&macho, 0x2118, 0x20)
        // Il2CppType[0] = Int32 (0x08) @ 0x2120; types* @ 0x2130
        macho[0x2120 + 10] = 0x08
        putU64(&macho, 0x2130, baseVM + 0x2120)

        // genericMethodTable (2 entries * 12 bytes = 24 bytes) @ 0x2140:
        // entry0: specIdx=0, methIdx=0, invIdx=0; entry1: specIdx=1, methIdx=1, invIdx=0
        putU32(&macho, 0x2140, 0); putU32(&macho, 0x2144, 0)
        putU32(&macho, 0x214C, 1); putU32(&macho, 0x2150, 1)
        // methodSpecs (2 entries * 12 bytes = 24 bytes) @ 0x2160: both point to methodDefIndex 0
        putU32(&macho, 0x2160, 0); putU32(&macho, 0x2164, 0); putU32(&macho, 0x2168, 0)
        putU32(&macho, 0x216C, 0); putU32(&macho, 0x2170, 0); putU32(&macho, 0x2174, 0)

        // MetaReg anchored at 0x2230:
        // p - 48 (0x2200): gmCnt=2, gmPtr=0x2140
        // p - 32 (0x2210): typesCnt=1, types=0x2130
        // p - 16 (0x2220): methodSpecsCnt=2, methodSpecs=0x2160
        // p      (0x2230): foCnt=1, foPtr=0x2110, tdCnt=1, tdSizePtr=0x2118
        putU64(&macho, 0x2200, 2); putU64(&macho, 0x2208, baseVM + 0x2140)
        putU64(&macho, 0x2210, 1); putU64(&macho, 0x2218, baseVM + 0x2130)
        putU64(&macho, 0x2220, 2); putU64(&macho, 0x2228, baseVM + 0x2160)
        putU64(&macho, 0x2230, 1); putU64(&macho, 0x2238, baseVM + 0x2110)
        putU64(&macho, 0x2240, 1); putU64(&macho, 0x2248, baseVM + 0x2118)

        let segTextSz: UInt32 = 72 + 2 * 80
        let segDataSz: UInt32 = 72 + 80
        putU32(&macho, 0, 0xFEED_FACF); putU32(&macho, 4, 0x0100_000C)
        putU32(&macho, 12, 6); putU32(&macho, 16, 2); putU32(&macho, 20, segTextSz + segDataSz)
        var c = 32
        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segTextSz); writeFixed(&macho, c + 8, "__TEXT")
        putU64(&macho, c + 24, baseVM); putU64(&macho, c + 32, 0x2000); putU64(&macho, c + 40, 0); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 5); putU32(&macho, c + 60, 5); putU32(&macho, c + 64, 2)
        c += 72
        writeFixed(&macho, c, "__text"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, baseVM + 0x1000); putU64(&macho, c + 40, 0x200); putU32(&macho, c + 48, 0x1000); putU32(&macho, c + 64, 0x8000_0400)
        c += 80
        writeFixed(&macho, c, "__cstring"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, baseVM + 0x1200); putU64(&macho, c + 40, 0x200); putU32(&macho, c + 48, 0x1200); putU32(&macho, c + 64, 0x02)
        c += 80
        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segDataSz); writeFixed(&macho, c + 8, "__DATA")
        putU64(&macho, c + 24, baseVM + 0x2000); putU64(&macho, c + 32, 0x2000); putU64(&macho, c + 40, 0x2000); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 3); putU32(&macho, c + 60, 3); putU32(&macho, c + 64, 1)
        c += 72
        writeFixed(&macho, c, "__data"); writeFixed(&macho, c + 16, "__DATA")
        putU64(&macho, c + 32, baseVM + 0x2000); putU64(&macho, c + 40, 0x2000); putU32(&macho, c + 48, 0x2000); putU32(&macho, c + 64, 0)

        let plistDict: [String: Any] = ["CFBundleDisplayName": "V24GenGame", "CFBundleIdentifier": "com.indie.v24gen", "CFBundleExecutable": "V24Gen"]
        let plistData = try PropertyListSerialization.data(fromPropertyList: plistDict, format: .xml, options: 0)

        let mURL = tmp.appendingPathComponent("global-metadata.dat")
        let fwURL = tmp.appendingPathComponent("UnityFramework")
        let pURL = tmp.appendingPathComponent("Info.plist")
        try meta.write(to: mURL); try macho.write(to: fwURL); try plistData.write(to: pURL)

        let ipaURL = tmp.appendingPathComponent("V24Gen.ipa")
        let zw = try StreamingZipWriter(destinationURL: ipaURL)
        try zw.addFile(at: pURL, entryName: "Payload/V24Gen.app/Info.plist")
        try zw.addFile(at: fwURL, entryName: "Payload/V24Gen.app/Frameworks/UnityFramework.framework/UnityFramework")
        try zw.addFile(at: mURL, entryName: "Payload/V24Gen.app/Data/Managed/Metadata/global-metadata.dat")
        try zw.finalize()

        let res = try DumpCoordinator().runIPA(ipaURL: ipaURL, outputsRootURL: tmp.appendingPathComponent("out"), options: .default) { _ in }
        let dumpCS = try String(contentsOf: res.outputDirectoryURL.appendingPathComponent("dump.cs"), encoding: .utf8)
        let summaryText = try String(contentsOf: res.outputDirectoryURL.appendingPathComponent("offsets_summary.txt"), encoding: .utf8)
        let scriptText = try String(contentsOf: res.outputDirectoryURL.appendingPathComponent("script.json"), encoding: .utf8)

        assertTrue(res.session.diagnostics.metadataStatus.isValid, "IL2CPP v24 Unicode metadata must validate")
        assertTrue(dumpCS.contains("ChiếnBinh_战士"), "Unicode type name missing in dump.cs")
        assertTrue(dumpCS.contains("RVA: 0x1000"), "v24 CodeGenModule method RVA 0x1000 missing in dump.cs")
        assertTrue(dumpCS.contains("0x1020") && dumpCS.contains("0x1030"), "Generic instantiation RVAs missing in dump.cs")
        assertTrue(summaryText.contains("0x1020") && summaryText.contains("0x1030"), "Generic instantiation RVAs missing in offsets_summary.txt")
        assertTrue(scriptText.contains("4128") && scriptText.contains("4144"), "script.json missing decimal RVAs for generic instantiations (0x1020=4128, 0x1030=4144)")
        print("[PASS] IL2CPP v24 CodeGenModules + GenericMethods + Unicode Identifiers")
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

    static func testUnityFrameworkBaseVMZeroAndCFString() throws {
        let tmp = FileManager.default.temporaryDirectory.appendingPathComponent("unityfw_zero_vm_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tmp, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tmp) }

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

        let sAsm0 = addStr("AbstractMod.dll")
        let sAsm1 = addStr("GameLogic.dll")
        let sNs = addStr("Battle")
        let sType0 = addStr("IAbstractActor")
        let sType1 = addStr("HeroController")
        let sMeth = addStr("UpdateHero")

        // Images (40 bytes * 2): AbstractMod.dll (typeStart=0, cnt=1) + GameLogic.dll (typeStart=1, cnt=1)
        var images = Data(repeating: 0, count: 80)
        putU32(&images, 0, sAsm0); putU32(&images, 8, 0); putU32(&images, 12, 1)
        putU32(&images, 16, 0xFFFF_FFFF); putU32(&images, 20, 0x2000_0001)
        putU32(&images, 40, sAsm1); putU32(&images, 48, 1); putU32(&images, 52, 1)
        putU32(&images, 56, 0xFFFF_FFFF); putU32(&images, 60, 0x2000_0002)

        // TypeDefs (88 * 2): [0] has 0 methods; [1] has methodStart=0, count=1
        var typeDefs = Data(repeating: 0, count: 88 * 2)
        putU32(&typeDefs, 0, sType0); putU32(&typeDefs, 4, sNs)
        putU32(&typeDefs, 12, 0xFFFF_FFFF); putU32(&typeDefs, 16, 0xFFFF_FFFF)
        putU32(&typeDefs, 24, 0xFFFF_FFFF); putU32(&typeDefs, 28, 0x0000_0021) // interface
        putU32(&typeDefs, 32, 0xFFFF_FFFF); putU32(&typeDefs, 36, 0xFFFF_FFFF)
        putU32(&typeDefs, 40, 0xFFFF_FFFF); putU32(&typeDefs, 44, 0xFFFF_FFFF)
        putU32(&typeDefs, 84, 0x0200_0002)

        putU32(&typeDefs, 88 + 0, sType1); putU32(&typeDefs, 88 + 4, sNs)
        putU32(&typeDefs, 88 + 12, 0xFFFF_FFFF); putU32(&typeDefs, 88 + 16, 0xFFFF_FFFF)
        putU32(&typeDefs, 88 + 24, 0xFFFF_FFFF); putU32(&typeDefs, 88 + 28, 0x0010_0001)
        putU32(&typeDefs, 88 + 32, 0xFFFF_FFFF); putU32(&typeDefs, 88 + 36, 0)
        putU32(&typeDefs, 88 + 40, 0xFFFF_FFFF); putU32(&typeDefs, 88 + 44, 0xFFFF_FFFF)
        putU16(&typeDefs, 88 + 64, 1); putU32(&typeDefs, 88 + 84, 0x0200_0003)

        var methods = Data(repeating: 0, count: 32)
        putU32(&methods, 0, sMeth); putU32(&methods, 4, 1); putU32(&methods, 8, 0)
        putU32(&methods, 12, 0xFFFF_FFFF); putU32(&methods, 16, 0xFFFF_FFFF)
        putU32(&methods, 20, 0x0600_0001); putU16(&methods, 24, 0x0086); putU16(&methods, 28, 0xFFFF)

        var meta = Data(repeating: 0, count: 288)
        func appendSec(_ blob: Data, offHdr: Int, szHdr: Int) {
            let off = UInt32(meta.count); let sz = UInt32(blob.count)
            meta.append(blob); while meta.count % 4 != 0 { meta.append(0) }
            putU32(&meta, offHdr, off); putU32(&meta, szHdr, sz)
        }
        putU32(&meta, 0, 0xFAB1_1BAF); putU32(&meta, 4, 29)
        appendSec(strBlob, offHdr: 24, szHdr: 28)
        appendSec(methods, offHdr: 48, szHdr: 52)
        appendSec(typeDefs, offHdr: 160, szHdr: 164)
        appendSec(images, offHdr: 168, szHdr: 172)

        // UnityFramework MH_DYLIB with baseVM = 0!
        var macho = Data(repeating: 0, count: 0x4000)
        // __text at 0x1000: ADRP X0, page(0x2300) (=0x2000 -> imm21 = +1 page -> 0x90000000 | (1 << 29) = 0xB0000000),
        // ADD X0, X0, #0x300 (0x910C0000) -> references CFString at 0x2300! Then RET (0xD65F03C0)
        putU32(&macho, 0x1000, 0xB000_0000) // ADRP X0, #0x1000 (0x1000 + 0x1000 = 0x2000)
        putU32(&macho, 0x1004, 0x910C_0000) // ADD X0, X0, #0x300 -> 0x2300 (__cfstring[0])
        putU32(&macho, 0x1008, 0xD65F_03C0) // RET

        for (i, b) in Array("AbstractMod.dll\0".utf8).enumerated() { macho[0x1200 + i] = b }
        for (i, b) in Array("GameLogic.dll\0".utf8).enumerated() { macho[0x1220 + i] = b }
        for (i, b) in Array("BossSpawnedCFString\0".utf8).enumerated() { macho[0x1240 + i] = b }

        // Put small non-pointer integer values 1 and 2 in __data (must decode as 0 on baseVM = 0!)
        putU64(&macho, 0x2000, 1)
        putU64(&macho, 0x2008, 2)

        // Module 0 ("AbstractMod.dll"): 4 NULL method pointers at 0x2020 (tests abstract-module acceptance!)
        // 0x2020..0x203F are all 0
        // Module 1 ("GameLogic.dll"): 1 concrete method pointer -> 0x1000 at 0x2040
        putU64(&macho, 0x2040, 0x1000)

        // CodeGenModule 0 @ 0x2060: name=0x1200, mCnt=4, ptrs=0x2020
        putU64(&macho, 0x2060, 0x1200); putU64(&macho, 0x2068, 4); putU64(&macho, 0x2070, 0x2020)
        // CodeGenModule 1 @ 0x2080: name=0x1220, mCnt=1, ptrs=0x2040
        putU64(&macho, 0x2080, 0x1220); putU64(&macho, 0x2088, 1); putU64(&macho, 0x2090, 0x2040)
        // CodeGenModules* array @ 0x20A0
        putU64(&macho, 0x20A0, 0x2060); putU64(&macho, 0x20A8, 0x2080)
        // CodeRegistration.codeGenModulesCount = 2, ptr = 0x20A0 @ 0x20B0
        putU64(&macho, 0x20B0, 2); putU64(&macho, 0x20B8, 0x20A0)

        // MetaReg @ 0x2100: fieldOffsetsCount=2 @ 0x2100, fieldOffsetsTable=0x2120, tdSizesCnt=2, tdSizes=0x2130
        putU64(&macho, 0x2100, 2); putU64(&macho, 0x2108, 0x2120)
        putU64(&macho, 0x2110, 2); putU64(&macho, 0x2118, 0x2130)
        putU32(&macho, 0x2120, 0x10)
        putU32(&macho, 0x2130, 0x20)

        // __cfstring entry (32 bytes) @ 0x2300: +16 cstring ptr -> 0x1240 ("BossSpawnedCFString")
        putU64(&macho, 0x2300 + 16, 0x1240)

        let segTextSz: UInt32 = 72 + 2 * 80
        let segDataSz: UInt32 = 72 + 2 * 80
        putU32(&macho, 0, 0xFEED_FACF); putU32(&macho, 4, 0x0100_000C)
        putU32(&macho, 12, 6); putU32(&macho, 16, 2); putU32(&macho, 20, segTextSz + segDataSz)
        var c = 32
        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segTextSz); writeFixed(&macho, c + 8, "__TEXT")
        putU64(&macho, c + 24, 0); putU64(&macho, c + 32, 0x2000); putU64(&macho, c + 40, 0); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 5); putU32(&macho, c + 60, 5); putU32(&macho, c + 64, 2)
        c += 72
        writeFixed(&macho, c, "__text"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, 0x1000); putU64(&macho, c + 40, 0x200); putU32(&macho, c + 48, 0x1000); putU32(&macho, c + 64, 0x8000_0400)
        c += 80
        writeFixed(&macho, c, "__cstring"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, 0x1200); putU64(&macho, c + 40, 0x200); putU32(&macho, c + 48, 0x1200); putU32(&macho, c + 64, 0x02)
        c += 80

        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segDataSz); writeFixed(&macho, c + 8, "__DATA")
        putU64(&macho, c + 24, 0x2000); putU64(&macho, c + 32, 0x2000); putU64(&macho, c + 40, 0x2000); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 3); putU32(&macho, c + 60, 3); putU32(&macho, c + 64, 2)
        c += 72
        writeFixed(&macho, c, "__data"); writeFixed(&macho, c + 16, "__DATA")
        putU64(&macho, c + 32, 0x2000); putU64(&macho, c + 40, 0x300); putU32(&macho, c + 48, 0x2000); putU32(&macho, c + 64, 0)
        c += 80
        writeFixed(&macho, c, "__cfstring"); writeFixed(&macho, c + 16, "__DATA")
        putU64(&macho, c + 32, 0x2300); putU64(&macho, c + 40, 32); putU32(&macho, c + 48, 0x2300); putU32(&macho, c + 64, 0)

        let plistDict: [String: Any] = ["CFBundleDisplayName": "ZeroVMUnity", "CFBundleIdentifier": "com.indie.zerovm", "CFBundleExecutable": "ZeroVMUnity"]
        let plistData = try PropertyListSerialization.data(fromPropertyList: plistDict, format: .xml, options: 0)

        let mURL = tmp.appendingPathComponent("global-metadata.dat")
        let fwURL = tmp.appendingPathComponent("UnityFramework")
        let pURL = tmp.appendingPathComponent("Info.plist")
        try meta.write(to: mURL); try macho.write(to: fwURL); try plistData.write(to: pURL)

        let parsedMachO = try MachOParser(url: fwURL, sourceName: "UnityFramework")
        assertTrue(parsedMachO.baseVM == 0, "Expected UnityFramework dylib baseVM == 0")
        assertTrue(parsedMachO.vmToSliceRelOffset(0) == nil, "vmToSliceRelOffset(0) must return nil, never Mach-O header")
        assertTrue(parsedMachO.decodePointer(atSliceRelOffset: 0x2000) == 0, "Small int 1 must NOT decode as pointer on baseVM==0")
        assertTrue(parsedMachO.decodePointer(atSliceRelOffset: 0x20B8) == 0x20A0, "Valid pointer 0x20A0 must decode on baseVM==0")

        let ipaURL = tmp.appendingPathComponent("ZeroVMUnity.ipa")
        let zw = try StreamingZipWriter(destinationURL: ipaURL)
        try zw.addFile(at: pURL, entryName: "Payload/ZeroVMUnity.app/Info.plist")
        try zw.addFile(at: fwURL, entryName: "Payload/ZeroVMUnity.app/Frameworks/UnityFramework.framework/UnityFramework")
        try zw.addFile(at: mURL, entryName: "Payload/ZeroVMUnity.app/Data/Managed/Metadata/global-metadata.dat")
        try zw.finalize()

        let res = try DumpCoordinator().runIPA(ipaURL: ipaURL, outputsRootURL: tmp.appendingPathComponent("out"), options: .default) { _ in }
        let dumpCS = try String(contentsOf: res.outputDirectoryURL.appendingPathComponent("dump.cs"), encoding: .utf8)
        let nativeText = try String(contentsOf: res.outputDirectoryURL.appendingPathComponent("native_functions.txt"), encoding: .utf8)

        assertTrue(dumpCS.contains("UpdateHero") && dumpCS.contains("RVA: 0x1000"), "Module 1 method RVA 0x1000 must survive Module 0 having all-NULL abstract pointers")
        assertTrue(nativeText.contains("BossSpawnedCFString"), "ARM64 XRef to __cfstring entry missing")
        print("[PASS] UnityFramework baseVM==0 + Abstract Module + __cfstring XRef")
    }

    static func testCyclicExportsTrieAndZeroVMNegativeADRP() throws {
        let tmp = FileManager.default.temporaryDirectory.appendingPathComponent("trie_cycle_test_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tmp, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tmp) }

        let baseVM: UInt64 = 0
        var macho = Data(repeating: 0, count: 0x3000)

        // __text at 0x1000:
        // 0x1000: ADRP X0, #-2 (pcVM=0x1000 -> pageBase=0xFFFF_FFFF_FFFF_F000); ADD X0, X0, #0xFFF -> wraps without trapping
        // 0x1008: ADRP X1, #0  (pcVM=0x1000 -> pageBase=0x1000); ADD X1, X1, #0x200 -> 0x1200 ("ValidXRefTarget")
        putU32(&macho, 0x1000, 0xD0FF_FFE0) // ADRP X0, #-2
        putU32(&macho, 0x1004, 0x913F_FC00) // ADD X0, X0, #0xFFF
        putU32(&macho, 0x1008, 0x9000_0001) // ADRP X1, #0
        putU32(&macho, 0x100C, 0x9108_0021) // ADD X1, X1, #0x200
        putU32(&macho, 0x1010, 0xD65F_03C0) // RET

        for (i, b) in Array("ValidXRefTarget\0".utf8).enumerated() { macho[0x1200 + i] = b }

        // LC_DYLD_EXPORTS_TRIE at 0x2000 (size 0x100):
        // Node 0: child "_safeDylibExport" -> 32, child invalid-UTF8 [0xFF, 0xFE, 0x5F] -> 48 (mutual cycle 48 <-> 64)
        macho[0x2000] = 0 // termSize = 0
        macho[0x2001] = 2 // 2 children
        let safeEdge = Array("_safeDylibExport\0".utf8)
        for (i, b) in safeEdge.enumerated() { macho[0x2002 + i] = b }
        macho[0x2002 + safeEdge.count] = 32 // child offset = 32
        let badEdge: [UInt8] = [0xFF, 0xFE, 0x5F, 0x00]
        let badStart = 0x2002 + safeEdge.count + 1
        for (i, b) in badEdge.enumerated() { macho[badStart + i] = b }
        macho[badStart + badEdge.count] = 48 // child offset = 48

        // Node 32 -> export _safeDylibExport at RVA 0x1008
        macho[0x2020] = 3
        macho[0x2021] = 0
        macho[0x2022] = 0x88 // uleb128(0x1008) byte 0
        macho[0x2023] = 0x20 // uleb128(0x1008) byte 1
        macho[0x2024] = 0

        // Node 48 -> child "_loop" -> 64
        macho[0x2030] = 0
        macho[0x2031] = 1
        let loopEdge = Array("_loop\0".utf8)
        for (i, b) in loopEdge.enumerated() { macho[0x2032 + i] = b }
        macho[0x2032 + loopEdge.count] = 64

        // Node 64 -> back to Node 48 (cycle!)
        macho[0x2040] = 0
        macho[0x2041] = 1
        let backEdge = Array("_cycle\0".utf8)
        for (i, b) in backEdge.enumerated() { macho[0x2042 + i] = b }
        macho[0x2042 + backEdge.count] = 48

        let segTextSz: UInt32 = 72 + 2 * 80
        let segLinkSz: UInt32 = 72
        let expSz: UInt32 = 16
        putU32(&macho, 0, 0xFEED_FACF); putU32(&macho, 4, 0x0100_000C)
        putU32(&macho, 12, 6); putU32(&macho, 16, 3); putU32(&macho, 20, segTextSz + segLinkSz + expSz)

        var c = 32
        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segTextSz); writeFixed(&macho, c + 8, "__TEXT")
        putU64(&macho, c + 24, baseVM); putU64(&macho, c + 32, 0x2000); putU64(&macho, c + 40, 0); putU64(&macho, c + 48, 0x2000)
        putU32(&macho, c + 56, 5); putU32(&macho, c + 60, 5); putU32(&macho, c + 64, 2)
        c += 72
        writeFixed(&macho, c, "__text"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, 0x1000); putU64(&macho, c + 40, 0x200); putU32(&macho, c + 48, 0x1000); putU32(&macho, c + 64, 0x8000_0400)
        c += 80
        writeFixed(&macho, c, "__cstring"); writeFixed(&macho, c + 16, "__TEXT")
        putU64(&macho, c + 32, 0x1200); putU64(&macho, c + 40, 0x100); putU32(&macho, c + 48, 0x1200); putU32(&macho, c + 64, 0x02)
        c += 80
        putU32(&macho, c, 0x19); putU32(&macho, c + 4, segLinkSz); writeFixed(&macho, c + 8, "__LINKEDIT")
        putU64(&macho, c + 24, 0x2000); putU64(&macho, c + 32, 0x1000); putU64(&macho, c + 40, 0x2000); putU64(&macho, c + 48, 0x1000)
        putU32(&macho, c + 56, 1); putU32(&macho, c + 60, 1); putU32(&macho, c + 64, 0)
        c += 72
        putU32(&macho, c, 0x8000_0033); putU32(&macho, c + 4, expSz)
        putU32(&macho, c + 8, 0x2000); putU32(&macho, c + 12, 0x100)

        let dylibURL = tmp.appendingPathComponent("CyclicTrie.dylib")
        try macho.write(to: dylibURL)
        let parsed = try MachOParser(url: dylibURL, sourceName: "CyclicTrie.dylib")
        parsed.runDeepNativeScan()

        assertTrue(parsed.exportedSymbols.contains(where: { $0.name == "safeDylibExport" && $0.rva == 0x1008 }), "Valid export missing alongside cyclic/invalid-UTF8 trie node")
        assertTrue(parsed.xrefFunctions.contains(where: { $0.name.contains("ValidXRefTarget") }), "Valid XRef missing after negative ADRP on baseVM==0")
        print("[PASS] Cyclic Exports Trie + Invalid UTF-8 Edge + Negative Zero-VM ADRP")
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
