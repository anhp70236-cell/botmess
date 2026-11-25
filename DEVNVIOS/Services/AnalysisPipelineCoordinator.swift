import Foundation
import Combine

@MainActor
public final class AnalysisPipelineCoordinator: ObservableObject {
    @Published public private(set) var isAnalyzing: Bool = false
    @Published public private(set) var currentGameName: String = ""
    @Published public private(set) var currentStageTitle: String = "Sẵn sàng phân tích"
    @Published public private(set) var progress: Double = 0.0
    @Published public private(set) var queuedCount: Int = 0
    @Published public private(set) var logEntries: [AnalysisLogEntry] = []
    @Published public private(set) var latestCompletedBundle: GameAnalysisBundle?
    @Published public var pendingExportPromptBundle: GameAnalysisBundle?
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
        isAnalyzing = true
        progress = 0.02
        currentGameName = urls[0].deletingPathExtension().lastPathComponent
        currentStageTitle = "Đã nhận tệp IPA — đang khởi tạo bộ đọc..."

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
            lastErrorMessage = "Tệp IPA gốc đã được xóa để giải phóng bộ nhớ. Toàn bộ Offset đã dò vẫn được giữ nguyên trong mục Kho Game."
            return
        }
        let ipaURL = storageManager.storedIPAURL(for: ipaName)
        guard FileManager.default.fileExists(atPath: ipaURL.path) else {
            lastErrorMessage = "Không tìm thấy tệp IPA lưu trữ trên ổ đĩa."
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
        currentStageTitle = "Đã dừng phân tích"
        let cancelEntry = AnalysisLogEntry(level: .warning, stage: "Pipeline", message: "Người dùng đã hủy tiến trình phân tích.")
        logEntries.append(cancelEntry)
    }

    nonisolated public func executeFullPipeline(
        ipaSourceURL: URL,
        preferredGameName: String? = nil,
        authorizedTrace: AuthorizedRuntimeTracePayload? = nil,
        logger: AnalysisLogger? = nil,
        progressHandler: (@Sendable (Double, String) -> Void)? = nil
    ) throws -> GameAnalysisBundle {
        let startTime = Date()
        progressHandler?(0.05, "Đang nạp tệp IPA vào bộ nhớ làm việc...")
        logger?.stage("Nhập IPA", "Đang đọc tệp IPA '\(ipaSourceURL.lastPathComponent)'...")

        let inspection = try importService.importIPA(
            from: ipaSourceURL,
            preferredGameName: preferredGameName
        ) { copyFrac in
            progressHandler?(0.05 + copyFrac * 0.15, "Đang sao chép luồng IPA (\(Int(copyFrac * 100))%)")
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
            stage: "Thông tin",
            "Tên Game: \(inspection.gameName) (\(inspection.bundleIdentifier) v\(inspection.bundleShortVersion)) | Dung lượng: \(HexFormatters.formatBytes(inspection.ipaFileSize))"
        )

        progressHandler?(0.25, "Đang giải nén tệp nhị phân thực thi Mach-O...")
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
            progressHandler?(0.25 + frac * 0.20, "Giải nén \(analysisModuleName) (\(Int(frac * 100))%)")
        }

        let binaryData = try Data(contentsOf: extractedBinaryURL, options: .mappedIfSafe)
        progressHandler?(0.50, "Đang phân tích sâu cấu trúc Mach-O, ObjC IMP & lệnh ARM64...")

        let staticOutput = try StaticBinaryAnalyzer.analyze(
            binaryData: binaryData,
            moduleName: analysisModuleName,
            logger: logger
        )

        progressHandler?(0.68, "Đang giải mã cấu trúc IL2CPP Metadata & CodeGenModule...")
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
                diagnostics: ["Không có global-metadata.dat (Ứng dụng/Game gốc C++, Swift, Objective-C hoặc Unreal)."]
            )
        }

        progressHandler?(0.82, "Đang dò tìm, phân loại & xác minh độ chính xác Offset...")
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

        progressHandler?(0.93, "Đang lưu cơ sở dữ liệu Offset vào thư mục máy...")
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

        progressHandler?(1.0, "Hoàn tất phân tích chuyên sâu!")
        logger?.success(
            stage: "Hoàn tất",
            "Đã lưu vào Games/\(inspection.directoryName)/ trong \(String(format: "%.2fs", elapsed)). Đang mở tùy chọn xuất 1 tệp hoặc tách nhiều tệp..."
        )

        return try databaseService.loadGameBundle(for: savedRecord)
    }

    // MARK: - Async UI Wrappers

    private func runSingleURLImportAndAnalysis(url: URL) async {
        isAnalyzing = true
        progress = 0.03
        currentGameName = url.deletingPathExtension().lastPathComponent
        currentStageTitle = "Đang đọc tệp IPA..."
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
            pendingExportPromptBundle = bundle
            currentStageTitle = "Phân tích thành công — Chọn chế độ lưu tệp"
            progress = 1.0
            onDatabaseUpdated?()
        } catch is CancellationError {
            currentStageTitle = "Đã hủy phân tích"
        } catch let err as AnalysisError where err == .cancelled {
            currentStageTitle = "Đã hủy phân tích"
        } catch {
            lastErrorMessage = error.localizedDescription
            currentStageTitle = "Phân tích thất bại"
            logger.error(stage: "Lỗi", error.localizedDescription)
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
        currentStageTitle = "Đang phân tích lại \(record.gameName)..."
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
            pendingExportPromptBundle = bundle
            currentStageTitle = "Phân tích lại hoàn tất!"
            progress = 1.0
            onDatabaseUpdated?()
        } catch {
            lastErrorMessage = error.localizedDescription
            currentStageTitle = "Phân tích thất bại"
            logger.error(stage: "Lỗi", error.localizedDescription)
        }
    }
}
