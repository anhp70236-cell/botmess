import XCTest
@testable import DEVNVIOS

final class DEVNVIOSAnalyzerTests: XCTestCase {
    private var tempWorkspaceURL: URL!
    private var storageManager: StorageManagerService!
    private var databaseService: OffsetDatabaseService!
    private var importService: IPAImportService!
    private var exportService: ExportService!

    override func setUpWithError() throws {
        try super.setUpWithError()
        tempWorkspaceURL = FileManager.default.temporaryDirectory
            .appendingPathComponent("DEVNVIOSTests_\(UUID().uuidString)", isDirectory: true)
        storageManager = try StorageManagerService(rootDirectoryURL: tempWorkspaceURL)
        databaseService = try OffsetDatabaseService(baseDirectoryURL: storageManager.rootDirectoryURL)
        importService = IPAImportService(storageManager: storageManager)
        exportService = ExportService(storageManager: storageManager)
    }

    override func tearDownWithError() throws {
        if let tempWorkspaceURL, FileManager.default.fileExists(atPath: tempWorkspaceURL.path) {
            try? FileManager.default.removeItem(at: tempWorkspaceURL)
        }
        try super.tearDownWithError()
    }

    @MainActor
    func testImportIPAAndPerGameFolderCreation_Heo() throws {
        let ipaURL = tempWorkspaceURL.appendingPathComponent("Input/Heo.ipa")
        try TestIPABuilder.buildSyntheticIPA(at: ipaURL, options: .init(gameName: "Heo"))

        let coordinator = AnalysisPipelineCoordinator(
            storageManager: storageManager,
            databaseService: databaseService,
            importService: importService
        )

        let bundle = try coordinator.executeFullPipeline(ipaSourceURL: ipaURL)
        XCTAssertEqual(bundle.record.gameName, "Heo")
        XCTAssertEqual(bundle.record.directoryName, "Heo")
        XCTAssertTrue(bundle.metadata.isARM64)
        XCTAssertTrue(bundle.metadata.isUnityIL2CPP)
        XCTAssertEqual(bundle.metadata.il2cppMetadataVersion, 29)
        XCTAssertFalse(bundle.offsets.isEmpty)
        XCTAssertGreaterThan(bundle.report.verifiedCount, 0)

        // Verify all 6 per-game JSON files inside Games/Heo/
        let heoFolder = databaseService.gameDirectoryURL(for: "Heo")
        let expectedFiles = [
            "metadata.json",
            "offsets.json",
            "symbols.json",
            "runtime.json",
            "report.json",
            "history.json"
        ]
        for fileName in expectedFiles {
            let fileURL = heoFolder.appendingPathComponent(fileName)
            XCTAssertTrue(FileManager.default.fileExists(atPath: fileURL.path), "Missing \(fileName) in Heo/")
            let size = (try FileManager.default.attributesOfItem(atPath: fileURL.path)[.size] as? NSNumber)?.intValue ?? 0
            XCTAssertGreaterThan(size, 10, "\(fileName) must contain real JSON data")
        }

        // Verify real offset linkage: Heo.Gameplay.PlayerController.get_Health -> RVA 0x1000
        let healthMethod = bundle.offsets.first { $0.name == "Heo.Gameplay.PlayerController.get_Health" }
        XCTAssertNotNil(healthMethod)
        XCTAssertEqual(healthMethod?.rva, 0x1000)
        XCTAssertEqual(healthMethod?.virtualAddress, 0x1_0000_1000)
        XCTAssertEqual(healthMethod?.fileOffset, 0x1000)
        XCTAssertEqual(healthMethod?.verificationStatus, .verified)
        XCTAssertEqual(healthMethod?.category, .playerHealth)

        // Verify Financial / Payment / Wallet exclusion compliance
        let leakedFinancial = bundle.offsets.contains {
            let l = $0.name.lowercased()
            return l.contains("storekit") || l.contains("payment") || l.contains("wallet") || l.contains("billing")
        }
        XCTAssertFalse(leakedFinancial, "Financial/payment symbols must never be collected")
    }

    func testMachO64UniversalFatAndARM64XRefDecoding() throws {
        let thin = TestIPABuilder.buildSyntheticARM64MachO(includeSymbols: true, encryptText: false)
        let fat = TestIPABuilder.wrapAsUniversalFatARM64(thinMachO: thin)

        let output = try StaticBinaryAnalyzer.analyze(binaryData: fat, moduleName: "UniversalGame")
        XCTAssertTrue(output.machO.isUniversalFat)
        XCTAssertTrue(output.machO.isARM64)
        XCTAssertEqual(output.machO.architectureName, "arm64")
        XCTAssertEqual(output.machO.minimumOSVersion, "16.0")
        XCTAssertNotNil(output.machO.uuidString)
        XCTAssertEqual(output.machO.virtualAddressToSliceFileOffset(0x1_0000_1000), 0x1000)

        // Verify ARM64 ADRP+ADD XRefs resolved both string references and the +0x48 LDR float field offset
        let healthRef = output.arm64StringXRefs.first { $0.referencedString == "PlayerHealthComponent" }
        XCTAssertNotNil(healthRef)
        XCTAssertEqual(healthRef?.enclosingFunctionVA, 0x1_0000_1000)
        XCTAssertEqual(healthRef?.nearbyFieldOffsetHint, 0x48)
    }

    func testStrippedBinarySymbolLessDiscovery() throws {
        let strippedMachO = TestIPABuilder.buildSyntheticARM64MachO(includeSymbols: false, encryptText: false)
        let staticOutput = try StaticBinaryAnalyzer.analyze(binaryData: strippedMachO, moduleName: "StrippedGame")
        XCTAssertEqual(staticOutput.symbolStripStatus, .stripped)
        XCTAssertTrue(staticOutput.machO.symbols.isEmpty)
        XCTAssertEqual(staticOutput.machO.functionStartRVAs.count, 2)

        let engine = AdvancedOffsetDiscoveryEngine()
        let noIL2CPP = IL2CPPAnalysisResult(
            availability: .notPresent,
            metadataVersion: nil,
            types: [],
            methods: [],
            fields: [],
            relevantStringLiterals: [],
            diagnostics: []
        )
        let discovery = try engine.discoverOffsets(
            staticOutput: staticOutput,
            il2cppResult: noIL2CPP,
            bundleIdentifier: "com.devnvios.stripped",
            bundleVersion: "1.0",
            previousHistory: []
        )
        XCTAssertFalse(discovery.offsets.isEmpty)
        XCTAssertTrue(discovery.offsets.contains(where: { $0.rva == 0x1000 && $0.discoverySource == .arm64PatternAnalysis }))
    }

    func testEncryptedIPASafeHandlingWithoutBypassOrFakeOffsets() throws {
        let encMachO = TestIPABuilder.buildSyntheticARM64MachO(includeSymbols: false, encryptText: true)
        let staticOutput = try StaticBinaryAnalyzer.analyze(binaryData: encMachO, moduleName: "EncryptedGame")
        XCTAssertTrue(staticOutput.machO.encryptionInfo.isEncrypted)
        XCTAssertEqual(staticOutput.machO.encryptionInfo.cryptID, 1)
        XCTAssertTrue(staticOutput.arm64StringXRefs.isEmpty, "Encrypted __TEXT must not be disassembled into garbage XRefs")

        // Also verify encrypted/obfuscated IL2CPP metadata detection
        let obfuscatedMeta = Data(repeating: 0xAA, count: 512)
        let il2cppRes = IL2CPPMetadataParser.parse(data: obfuscatedMeta)
        XCTAssertEqual(il2cppRes.availability, .encryptedOrObfuscated)
        XCTAssertTrue(il2cppRes.types.isEmpty)

        // Truncated metadata detection
        let truncatedMeta = Data([0xAF, 0x1B, 0xB1, 0xFA, 29, 0, 0, 0])
        let truncRes = IL2CPPMetadataParser.parse(data: truncatedMeta)
        XCTAssertEqual(truncRes.availability, .truncatedOrIncomplete)
    }

    @MainActor
    func testCrossVersionComparisonSearchFilterAndExport() throws {
        let coordinator = AnalysisPipelineCoordinator(
            storageManager: storageManager,
            databaseService: databaseService,
            importService: importService
        )

        let v1URL = tempWorkspaceURL.appendingPathComponent("Input/Heo_v1.ipa")
        try TestIPABuilder.buildSyntheticIPA(
            at: v1URL,
            options: .init(gameName: "Heo", version: "1.0.0", shiftCodeRVA: 0)
        )
        _ = try coordinator.executeFullPipeline(ipaSourceURL: v1URL)

        let v2URL = tempWorkspaceURL.appendingPathComponent("Input/Heo_v2.ipa")
        try TestIPABuilder.buildSyntheticIPA(
            at: v2URL,
            options: .init(gameName: "Heo", version: "1.1.0", shiftCodeRVA: 0x100)
        )
        let v2Bundle = try coordinator.executeFullPipeline(ipaSourceURL: v2URL)

        XCTAssertNotNil(v2Bundle.report.crossVersionDelta)
        XCTAssertEqual(v2Bundle.report.crossVersionDelta?.previousVersion, "1.0.0")
        XCTAssertEqual(v2Bundle.report.crossVersionDelta?.currentVersion, "1.1.0")
        XCTAssertFalse(v2Bundle.report.crossVersionDelta?.shiftedRVAEntries.isEmpty ?? true)

        // Search & Filter
        let cameraFiltered = databaseService.filterOffsets(v2Bundle.offsets, category: .rotation)
        XCTAssertFalse(cameraFiltered.isEmpty)
        let rvaSearch = databaseService.filterOffsets(v2Bundle.offsets, searchQuery: "0x00001100")
        XCTAssertFalse(rvaSearch.isEmpty)

        // Export JSON & Markdown
        let jsonURL = try exportService.exportOffsetsJSON(gameName: "Heo", offsets: v2Bundle.offsets)
        let fullURL = try exportService.exportFullAnalysisPackageJSON(bundle: v2Bundle)
        let mdURL = try exportService.exportMarkdownReport(bundle: v2Bundle)
        XCTAssertTrue(FileManager.default.fileExists(atPath: jsonURL.path))
        XCTAssertTrue(FileManager.default.fileExists(atPath: fullURL.path))
        XCTAssertTrue(FileManager.default.fileExists(atPath: mdURL.path))
    }

    @MainActor
    func testStorageManagerClearCacheDeleteIPAKeepOffsetsAndRecovery() throws {
        // Verify >= 3 GB workspace floor
        storageManager.configuredQuotaBytes = 1 * 1_073_741_824 // Attempt setting 1 GB
        XCTAssertGreaterThanOrEqual(
            storageManager.configuredQuotaBytes,
            StorageUsageSnapshot.minimumSupportedQuotaBytes,
            "Storage Manager must enforce minimum 3 GB quota capability"
        )

        let ipaURL = tempWorkspaceURL.appendingPathComponent("Input/HeoStorage.ipa")
        try TestIPABuilder.buildSyntheticIPA(at: ipaURL, options: .init(gameName: "HeoStorage"))

        let coordinator = AnalysisPipelineCoordinator(
            storageManager: storageManager,
            databaseService: databaseService,
            importService: importService
        )
        let bundle = try coordinator.executeFullPipeline(ipaSourceURL: ipaURL)

        // Create temporary cache file and verify clearCache removes it without losing DB
        let dummyCache = try storageManager.makeTemporaryExtractionFolder(prefix: "test")
            .appendingPathComponent("temp.bin")
        try Data(repeating: 0x42, count: 4096).write(to: dummyCache)
        XCTAssertGreaterThan(storageManager.computeUsageSnapshot().cacheBytes, 0)

        try storageManager.clearCache()
        XCTAssertEqual(storageManager.computeUsageSnapshot().cacheBytes, 0)

        // Delete raw IPA while keeping offsets intact
        let updatedRec = try storageManager.deleteStoredIPA(for: bundle.record, database: databaseService)
        XCTAssertNotNil(updatedRec)
        XCTAssertFalse(updatedRec!.hasStoredIPA)
        XCTAssertEqual(storageManager.computeUsageSnapshot().ipaBytes, 0)

        let reloadedBundle = try databaseService.loadGameBundle(for: updatedRec!)
        XCTAssertEqual(reloadedBundle.offsets.count, bundle.offsets.count, "Offsets must remain intact after IPA deletion")

        // Delete index.json and test full disk recovery
        let indexURL = databaseService.gamesRootURL.appendingPathComponent("index.json")
        try FileManager.default.removeItem(at: indexURL)
        let recovered = try databaseService.rebuildIndexFromDisk()
        XCTAssertEqual(recovered.count, 1)
        XCTAssertEqual(recovered.first?.gameName, "HeoStorage")
    }

    func testRuntimeInspectorDyldAndAuthorizedTraceMapping() throws {
        let inspector = RuntimeInspectorService()
        let hostModules = inspector.inspectCurrentProcessModules(limit: 10)
        XCTAssertFalse(hostModules.isEmpty, "In-process dyld inspection must list loaded Mach-O images")

        let machOData = TestIPABuilder.buildSyntheticARM64MachO(includeSymbols: true, encryptText: false)
        let parsed = try MachOParser.parse(data: machOData, moduleName: "HeoGame")

        let traceJSON = """
        {
          "moduleName": "HeoGame",
          "aslrSlideHex": "0x02400000",
          "symbols": [
            {
              "name": "PlayerController.RuntimePosition",
              "moduleName": "HeoGame",
              "runtimeAddressHex": "0x102401000"
            }
          ]
        }
        """.data(using: .utf8)!

        let trace = try inspector.parseAuthorizedTraceJSON(traceJSON)
        let snap = inspector.buildRuntimeSnapshot(machO: parsed, discoveredOffsets: [], authorizedTrace: trace)
        XCTAssertEqual(snap.mappedRuntimeSymbols.count, 1)
        XCTAssertEqual(snap.mappedRuntimeSymbols.first?.resolvedStaticVA, 0x1_0000_1000)
        XCTAssertEqual(snap.mappedRuntimeSymbols.first?.resolvedRVA, 0x1000)
        XCTAssertEqual(snap.mappedRuntimeSymbols.first?.resolvedFileOffset, 0x1000)
    }

    @MainActor
    func testAppContainerUIStateInitialization() throws {
        let container = AppContainer(customRootURL: tempWorkspaceURL)
        XCTAssertEqual(container.games.count, 0)
        container.updateStorageQuotaGB(8.0)
        XCTAssertEqual(container.storageSnapshot.configuredQuotaBytes, 8 * 1_073_741_824)
        container.themeMode = .slateContrast
        XCTAssertEqual(container.themeMode, .slateContrast)
    }
}
