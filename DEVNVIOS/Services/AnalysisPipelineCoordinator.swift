import Foundation
import Combine

@MainActor
public final class AnalysisPipelineCoordinator: ObservableObject {
    @Published public private(set) var isAnalyzing: Bool = false
    @Published public private(set) var currentGameName: String = ""
    @Published public private(set) var currentStageTitle: String = "Idle"
    @Published public private(set) var progress: Double = 0.0
    @Published public private(set) var queuedCount: Int = 0
    @Published public private(set) var logEntries: [AnalysisLogEntry] = []
    @Published public private(set) var latestCompletedBundle: GameAnalysisBundle?
    @Published public private(set) var lastErrorMessage: String?

    nonisolated private let storageManager: StorageManagerService
    nonisolated private let databaseService: OffsetDatabaseService
    nonisolated private let importService: IPAImportService
    nonisolated private let discoveryEngine: AdvancedOffsetDiscoveryEngine
    private var activeTask: Task<Void, Never>?

    public var onDatabaseUpdated: (() -> Void)?

    public init(
        storageManager: StorageManagerService,
        databaseService: OffsetDatabaseService,
        importService: IPAImportService,
        discoveryEngine: AdvancedOffsetDiscoveryEngine = AdvancedOffsetDiscoveryEngine()
    ) {
        self.storageManager = storageManager
        self.databaseService = databaseService
        self.importService = importService
        self.discoveryEngine = discoveryEngine
    }

    public func importAndAnalyzeIPAs(urls: [URL]) {
        guard !urls.isEmpty else { return }
        activeTask?.cancel()
        lastErrorMessage = nil
        queuedCount = urls.count

        activeTask = Task { [weak self] in
            guard let self else { return }
            for (index, url) in urls.enumerated() {
                if Task.isCancelled { break }
                self.queuedCount = urls.count - index
                await self.runSingleURLImportAndAnalysis(url: url)
            }
            self.queuedCount = 0
            self.isAnalyzing = false
        }
    }

    public func reanalyze(record: GameRecord, authorizedTrace: AuthorizedRuntimeTracePayload? = nil) {
        guard let ipaName = record.storedIPAFileName else {
            lastErrorMessage = "Original IPA file was deleted to save space. Stored offsets remain available in Game Details, or re-import the IPA to run a fresh binary scan."
            return
        }
        let ipaURL = storageManager.storedIPAURL(for: ipaName)
        guard FileManager.default.fileExists(atPath: ipaURL.path) else {
            lastErrorMessage = "Stored IPA archive is no longer present on disk."
            return
        }

        activeTask?.cancel()
        lastErrorMessage = nil
        queuedCount = 1

        activeTask = Task { [weak self] in
            guard let self else { return }
            await self.runAnalysisForStoredRecord(record: record, ipaURL: ipaURL, authorizedTrace: authorizedTrace)
            self.queuedCount = 0
            self.isAnalyzing = false
        }
    }

    public func cancelCurrentAnalysis() {
        activeTask?.cancel()
        activeTask = nil
        isAnalyzing = false
        queuedCount = 0
        currentStageTitle = "Cancelled"
        let cancelEntry = AnalysisLogEntry(level: .warning, stage: "Pipeline", message: "Analysis cancelled by user.")
        logEntries.append(cancelEntry)
    }

    /// Synchronous/async testable pipeline entry point used by both UI coordinator and XCTest suite.
    nonisolated public func executeFullPipeline(
        ipaSourceURL: URL,
        preferredGameName: String? = nil,
        authorizedTrace: AuthorizedRuntimeTracePayload? = nil,
        logger: AnalysisLogger? = nil,
        progressHandler: (@Sendable (Double, String) -> Void)? = nil
    ) throws -> GameAnalysisBundle {
        let startTime = Date()
        progressHandler?(0.05, "Importing IPA Archive")
        logger?.stage("Import", "Importing IPA archive '\(ipaSourceURL.lastPathComponent)'...")

        let inspection = try importService.importIPA(
            from: ipaSourceURL,
            preferredGameName: preferredGameName
        ) { copyFrac in
            progressHandler?(0.05 + copyFrac * 0.15, "Copying IPA (\(Int(copyFrac * 100))%)")
        }

        return try executeStoredInspectionPipeline(
            inspection: inspection,
            existingRecordID: nil,
            authorizedTrace: authorizedTrace,
            startTime: startTime,
            logger: logger,
            progressHandler: progressHandler
        )
    }

    nonisolated private func executeStoredInspectionPipeline(
        inspection: ImportedIPAInspection,
        existingRecordID: UUID?,
        authorizedTrace: AuthorizedRuntimeTracePayload?,
        startTime: Date,
        logger: AnalysisLogger?,
        progressHandler: (@Sendable (Double, String) -> Void)?
    ) throws -> GameAnalysisBundle {
        let tempFolder = try storageManager.makeTemporaryExtractionFolder(prefix: inspection.directoryName)
        defer {
            try? FileManager.default.removeItem(at: tempFolder)
        }

        logger?.info(
            stage: "Import",
            "App: \(inspection.gameName) (\(inspection.bundleIdentifier) v\(inspection.bundleShortVersion)) | Size: \(HexFormatters.formatBytes(inspection.ipaFileSize))"
        )

        progressHandler?(0.25, "Extracting Mach-O Executable")
        let reader = try IPAZipArchiveReader(archiveURL: inspection.storedIPAURL)
        let entries = try reader.listEntries()

        let targetBinaryPath: String
        let analysisModuleName: String
        if inspection.hasUnityFramework {
            targetBinaryPath = "\(inspection.appBundlePrefix)Frameworks/UnityFramework.framework/UnityFramework"
            analysisModuleName = "UnityFramework"
        } else {
            targetBinaryPath = "\(inspection.appBundlePrefix)\(inspection.executableName)"
            analysisModuleName = inspection.executableName
        }

        guard let binaryEntry = entries.first(where: { $0.path == targetBinaryPath && !$0.isDirectory }) else {
            throw AnalysisError.missingExecutable(analysisModuleName)
        }

        let extractedBinaryURL = tempFolder.appendingPathComponent(analysisModuleName)
        try reader.extractEntryToFile(binaryEntry, destinationURL: extractedBinaryURL) { frac in
            progressHandler?(0.25 + frac * 0.20, "Extracting \(analysisModuleName) (\(Int(frac * 100))%)")
        }

        let binaryData = try Data(contentsOf: extractedBinaryURL, options: .mappedIfSafe)
        progressHandler?(0.50, "Analyzing Mach-O & ARM64 Instructions")

        let staticOutput = try StaticBinaryAnalyzer.analyze(
            binaryData: binaryData,
            moduleName: analysisModuleName,
            logger: logger
        )

        progressHandler?(0.68, "Analyzing IL2CPP Metadata")
        let il2cppResult: IL2CPPAnalysisResult
        let metadataPath = "\(inspection.appBundlePrefix)Data/Managed/Metadata/global-metadata.dat"
        if let metaEntry = entries.first(where: { $0.path == metadataPath && !$0.isDirectory }) {
            let extractedMetaURL = tempFolder.appendingPathComponent("global-metadata.dat")
            try reader.extractEntryToFile(metaEntry, destinationURL: extractedMetaURL)
            let metaData = try Data(contentsOf: extractedMetaURL, options: .mappedIfSafe)
            il2cppResult = IL2CPPMetadataParser.parse(data: metaData, logger: logger)
        } else {
            il2cppResult = IL2CPPAnalysisResult(
                availability: .notPresent,
                metadataVersion: nil,
                types: [],
                methods: [],
                fields: [],
                relevantStringLiterals: [],
                diagnostics: ["global-metadata.dat not present (native Swift/ObjC/C++ application or non-IL2CPP engine)."]
            )
        }

        progressHandler?(0.82, "Discovering & Validating Offsets")
        let priorHistory = databaseService.loadHistory(for: inspection.directoryName)
        let discovery = try discoveryEngine.discoverOffsets(
            staticOutput: staticOutput,
            il2cppResult: il2cppResult,
            bundleIdentifier: inspection.bundleIdentifier,
            bundleVersion: inspection.bundleShortVersion,
            previousHistory: priorHistory,
            authorizedTrace: authorizedTrace,
            logger: logger
        )

        progressHandler?(0.93, "Saving Per-Game Offset Database")
        let machO = staticOutput.machO
        let encState: BinaryEncryptionState = {
            if machO.encryptionInfo.isEncrypted {
                return .fairPlayEncrypted
            }
            if il2cppResult.availability == .encryptedOrObfuscated {
                return .partiallyProtected
            }
            return .unencrypted
        }()

        let metadataModel = BinaryMetadataModel(
            gameName: inspection.gameName,
            bundleIdentifier: inspection.bundleIdentifier,
            bundleShortVersion: inspection.bundleShortVersion,
            bundleBuildVersion: inspection.bundleBuildVersion,
            primaryModuleName: inspection.executableName,
            analysisModuleName: analysisModuleName,
            isUnityIL2CPP: inspection.hasGlobalMetadata || inspection.hasUnityFramework,
            il2cppMetadataVersion: il2cppResult.metadataVersion,
            il2cppMetadataStatus: il2cppResult.availability.rawValue,
            cpuArchitecture: machO.architectureName,
            isARM64: machO.isARM64,
            isUniversalFatBinary: machO.isUniversalFat,
            machOUUID: machO.uuidString,
            minimumOSVersion: machO.minimumOSVersion,
            defaultBaseAddress: machO.defaultBaseAddress,
            entryPointRVA: machO.entryPointRVA,
            encryptionState: encState,
            cryptID: machO.encryptionInfo.cryptID,
            encryptedOffset: machO.encryptionInfo.cryptOffset,
            encryptedSize: machO.encryptionInfo.cryptSize,
            symbolStripStatus: staticOutput.symbolStripStatus,
            totalSymbolsCount: machO.symbols.count,
            functionStartsCount: machO.functionStartRVAs.count,
            segments: machO.segments.map { $0.toSummary() },
            binaryFileSize: Int64(binaryData.count),
            ipaFileSize: inspection.ipaFileSize,
            binarySHA256: staticOutput.binarySHA256
        )

        let verifiedCount = discovery.offsets.filter { $0.verificationStatus == .verified }.count
        let probableCount = discovery.offsets.filter { $0.verificationStatus == .probable }.count
        let unverifiedCount = discovery.offsets.filter { $0.verificationStatus == .unverified }.count
        let undeterminedCount = discovery.offsets.filter { $0.verificationStatus == .undetermined }.count

        var catBreakdown: [String: Int] = [:]
        for item in discovery.offsets {
            catBreakdown[item.category.rawValue, default: 0] += 1
        }

        let elapsed = max(0.01, Date().timeIntervalSince(startTime))
        let report = AnalysisReport(
            gameName: inspection.gameName,
            bundleIdentifier: inspection.bundleIdentifier,
            bundleVersion: inspection.bundleShortVersion,
            durationSeconds: elapsed,
            totalOffsets: discovery.offsets.count,
            verifiedCount: verifiedCount,
            probableCount: probableCount,
            unverifiedCount: unverifiedCount,
            undeterminedCount: undeterminedCount,
            categoryBreakdown: catBreakdown,
            methodSummaries: discovery.methodSummaries,
            crossVersionDelta: discovery.crossVersionDelta,
            warnings: discovery.warnings,
            limitations: discovery.limitations
        )

        let initialRecord = GameRecord(
            id: existingRecordID ?? UUID(),
            gameName: inspection.gameName,
            directoryName: inspection.directoryName,
            bundleIdentifier: inspection.bundleIdentifier,
            bundleVersion: inspection.bundleShortVersion,
            executableName: inspection.executableName,
            storedIPAFileName: inspection.storedIPAFileName,
            ipaFileSize: inspection.ipaFileSize,
            architectureSummary: machO.architectureName.uppercased(),
            encryptionState: encState,
            isUnityIL2CPP: metadataModel.isUnityIL2CPP,
            analysisState: .completed,
            totalOffsets: discovery.offsets.count,
            verifiedOffsets: verifiedCount,
            probableOffsets: probableCount
        )

        let savedRecord = try databaseService.saveGameAnalysis(
            record: initialRecord,
            metadata: metadataModel,
            offsets: discovery.offsets,
            symbols: Array(machO.symbols.prefix(10_000)),
            runtime: discovery.runtimeSnapshot,
            report: report
        )

        progressHandler?(1.0, "Completed")
        logger?.success(
            stage: "Database",
            "Saved \(inspection.directoryName)/{metadata,offsets,symbols,runtime,report,history}.json (\(String(format: "%.2fs", elapsed)))."
        )

        return try databaseService.loadGameBundle(for: savedRecord)
    }

    // MARK: - Async UI Execution Wrappers

    private func runSingleURLImportAndAnalysis(url: URL) async {
        isAnalyzing = true
        progress = 0.02
        currentGameName = url.deletingPathExtension().lastPathComponent
        currentStageTitle = "Preparing Import..."
        logEntries.removeAll()

        let logger = AnalysisLogger { [weak self] entries in
            Task { @MainActor [weak self] in
                self?.logEntries = entries
            }
        }

        do {
            let bundle = try await Task.detached(priority: .userInitiated) { [self] in
                try self.executeFullPipeline(
                    ipaSourceURL: url,
                    preferredGameName: nil,
                    authorizedTrace: nil,
                    logger: logger
                ) { frac, stage in
                    Task { @MainActor [weak self] in
                        self?.progress = frac
                        self?.currentStageTitle = stage
                    }
                }
            }.value

            currentGameName = bundle.record.gameName
            latestCompletedBundle = bundle
            currentStageTitle = "Analysis Complete"
            progress = 1.0
            onDatabaseUpdated?()
        } catch is CancellationError {
            currentStageTitle = "Cancelled"
        } catch let err as AnalysisError where err == .cancelled {
            currentStageTitle = "Cancelled"
        } catch {
            lastErrorMessage = error.localizedDescription
            currentStageTitle = "Failed"
            logger.error(stage: "Pipeline", error.localizedDescription)
        }
    }

    private func runAnalysisForStoredRecord(
        record: GameRecord,
        ipaURL: URL,
        authorizedTrace: AuthorizedRuntimeTracePayload?
    ) async {
        isAnalyzing = true
        progress = 0.10
        currentGameName = record.gameName
        currentStageTitle = "Re-analyzing \(record.gameName)..."
        logEntries.removeAll()

        let logger = AnalysisLogger { [weak self] entries in
            Task { @MainActor [weak self] in
                self?.logEntries = entries
            }
        }

        do {
            let bundle = try await Task.detached(priority: .userInitiated) { [self] in
                let inspection = try self.importService.inspectStoredIPA(
                    at: ipaURL,
                    storedFileName: record.storedIPAFileName ?? ipaURL.lastPathComponent,
                    preferredGameName: record.gameName
                )
                return try self.executeStoredInspectionPipeline(
                    inspection: inspection,
                    existingRecordID: record.id,
                    authorizedTrace: authorizedTrace,
                    startTime: Date(),
                    logger: logger
                ) { frac, stage in
                    Task { @MainActor [weak self] in
                        self?.progress = frac
                        self?.currentStageTitle = stage
                    }
                }
            }.value

            latestCompletedBundle = bundle
            currentStageTitle = "Re-analysis Complete"
            progress = 1.0
            onDatabaseUpdated?()
        } catch {
            lastErrorMessage = error.localizedDescription
            currentStageTitle = "Failed"
            logger.error(stage: "Pipeline", error.localizedDescription)
        }
    }
}
