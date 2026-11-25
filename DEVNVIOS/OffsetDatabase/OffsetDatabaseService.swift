import Foundation

public struct GameAnalysisBundle: Sendable {
    public let record: GameRecord
    public let metadata: BinaryMetadataModel
    public let offsets: [DiscoveredOffset]
    public let symbols: [SymbolEntry]
    public let runtime: RuntimeSnapshot
    public let report: AnalysisReport
    public let history: [AnalysisHistoryEntry]
}

/// Manages per-game analysis folders on disk:
/// `<Root>/Games/<GameDirectoryName>/`
///   - `metadata.json`
///   - `offsets.json`
///   - `symbols.json`
///   - `runtime.json`
///   - `report.json`
///   - `history.json`
/// Supports full indexing, crash-safe recovery from disk folders, fast search/filtering, and selective deletion.
public final class OffsetDatabaseService: @unchecked Sendable {
    public let gamesRootURL: URL
    private let indexFileURL: URL
    private let lock = NSLock()
    private let encoder: JSONEncoder
    private let decoder: JSONDecoder

    public init(baseDirectoryURL: URL) throws {
        self.gamesRootURL = baseDirectoryURL.appendingPathComponent("Games", isDirectory: true)
        self.indexFileURL = gamesRootURL.appendingPathComponent("index.json")

        let enc = JSONEncoder()
        enc.outputFormatting = [.prettyPrinted, .sortedKeys]
        enc.dateEncodingStrategy = .iso8601
        self.encoder = enc

        let dec = JSONDecoder()
        dec.dateDecodingStrategy = .iso8601
        self.decoder = dec

        try FileManager.default.createDirectory(at: gamesRootURL, withIntermediateDirectories: true)
    }

    public func gameDirectoryURL(for directoryName: String) -> URL {
        let safe = HexFormatters.sanitizeDirectoryName(directoryName)
        return gamesRootURL.appendingPathComponent(safe, isDirectory: true)
    }

    public func saveGameAnalysis(
        record: GameRecord,
        metadata: BinaryMetadataModel,
        offsets: [DiscoveredOffset],
        symbols: [SymbolEntry],
        runtime: RuntimeSnapshot,
        report: AnalysisReport
    ) throws -> GameRecord {
        lock.lock()
        defer { lock.unlock() }

        let safeDirName = HexFormatters.sanitizeDirectoryName(record.directoryName)
        let dirURL = gamesRootURL.appendingPathComponent(safeDirName, isDirectory: true)
        try FileManager.default.createDirectory(at: dirURL, withIntermediateDirectories: true)

        // Load existing history.json if present so cross-version history accumulates
        let historyURL = dirURL.appendingPathComponent("history.json")
        var historyEntries: [AnalysisHistoryEntry] = []
        if let existingData = try? Data(contentsOf: historyURL),
           let decoded = try? decoder.decode([AnalysisHistoryEntry].self, from: existingData) {
            historyEntries = decoded
        }

        var snapshotMap: [String: UInt64] = [:]
        for item in offsets where item.rva > 0 {
            snapshotMap[item.name] = item.rva
        }

        let newHistory = AnalysisHistoryEntry(
            gameName: record.gameName,
            bundleIdentifier: record.bundleIdentifier,
            bundleVersion: record.bundleVersion,
            binarySHA256: metadata.binarySHA256,
            analyzedAt: report.generatedAt,
            durationSeconds: report.durationSeconds,
            totalOffsets: offsets.count,
            verifiedCount: report.verifiedCount,
            encryptionState: metadata.encryptionState,
            offsetSnapshotByName: snapshotMap
        )
        historyEntries.insert(newHistory, at: 0)
        if historyEntries.count > 30 {
            historyEntries = Array(historyEntries.prefix(30))
        }

        // Persist the 6 required JSON files inside <GameName>/
        try writeJSON(metadata, to: dirURL.appendingPathComponent("metadata.json"))
        try writeJSON(offsets, to: dirURL.appendingPathComponent("offsets.json"))
        try writeJSON(symbols, to: dirURL.appendingPathComponent("symbols.json"))
        try writeJSON(runtime, to: dirURL.appendingPathComponent("runtime.json"))
        try writeJSON(report, to: dirURL.appendingPathComponent("report.json"))
        try writeJSON(historyEntries, to: historyURL)

        var updatedRecord = record
        updatedRecord.directoryName = safeDirName
        updatedRecord.analysisState = .completed
        updatedRecord.totalOffsets = offsets.count
        updatedRecord.verifiedOffsets = report.verifiedCount
        updatedRecord.probableOffsets = report.probableCount
        updatedRecord.lastAnalyzedAt = report.generatedAt

        var allRecords = (try? loadRecordsUnlocked()) ?? []
        if let idx = allRecords.firstIndex(where: { $0.id == updatedRecord.id || $0.directoryName == safeDirName }) {
            allRecords[idx] = updatedRecord
        } else {
            allRecords.insert(updatedRecord, at: 0)
        }
        try writeJSON(allRecords, to: indexFileURL)

        return updatedRecord
    }

    public func upsertRecord(_ record: GameRecord) throws {
        lock.lock()
        defer { lock.unlock() }
        var allRecords = (try? loadRecordsUnlocked()) ?? []
        if let idx = allRecords.firstIndex(where: { $0.id == record.id || $0.directoryName == record.directoryName }) {
            allRecords[idx] = record
        } else {
            allRecords.insert(record, at: 0)
        }
        try writeJSON(allRecords, to: indexFileURL)
    }

    public func loadAllRecords() -> [GameRecord] {
        lock.lock()
        defer { lock.unlock() }
        if let records = try? loadRecordsUnlocked(), !records.isEmpty {
            return records.sorted { ($0.lastAnalyzedAt ?? $0.importedAt) > ($1.lastAnalyzedAt ?? $1.importedAt) }
        }
        return (try? rebuildIndexFromDiskUnlocked()) ?? []
    }

    /// Reconstructs game index directly from `<GameName>/*.json` folders if index was removed or restored from backup.
    @discardableResult
    public func rebuildIndexFromDisk() throws -> [GameRecord] {
        lock.lock()
        defer { lock.unlock() }
        return try rebuildIndexFromDiskUnlocked()
    }

    public func loadGameBundle(for record: GameRecord) throws -> GameAnalysisBundle {
        lock.lock()
        defer { lock.unlock() }

        let dirURL = gameDirectoryURL(for: record.directoryName)
        guard FileManager.default.fileExists(atPath: dirURL.path) else {
            throw AnalysisError.databaseError("Game folder '\(record.directoryName)' does not exist.")
        }

        let metadata: BinaryMetadataModel = try readJSON(from: dirURL.appendingPathComponent("metadata.json"))
        let offsets: [DiscoveredOffset] = try readJSON(from: dirURL.appendingPathComponent("offsets.json"))
        let symbols: [SymbolEntry] = (try? readJSON(from: dirURL.appendingPathComponent("symbols.json"))) ?? []
        let runtime: RuntimeSnapshot = try readJSON(from: dirURL.appendingPathComponent("runtime.json"))
        let report: AnalysisReport = try readJSON(from: dirURL.appendingPathComponent("report.json"))
        let history: [AnalysisHistoryEntry] = (try? readJSON(from: dirURL.appendingPathComponent("history.json"))) ?? []

        return GameAnalysisBundle(
            record: record,
            metadata: metadata,
            offsets: offsets,
            symbols: symbols,
            runtime: runtime,
            report: report,
            history: history
        )
    }

    public func loadHistory(for directoryName: String) -> [AnalysisHistoryEntry] {
        lock.lock()
        defer { lock.unlock() }
        let url = gameDirectoryURL(for: directoryName).appendingPathComponent("history.json")
        return (try? readJSON(from: url)) ?? []
    }

    public func filterOffsets(
        _ offsets: [DiscoveredOffset],
        searchQuery: String = "",
        category: OffsetCategory? = nil,
        verificationStatus: OffsetVerificationStatus? = nil,
        dataType: OffsetDataType? = nil,
        source: DiscoverySource? = nil
    ) -> [DiscoveredOffset] {
        let trimmedQuery = searchQuery.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()

        return offsets.filter { item in
            if let category, item.category != category { return false }
            if let verificationStatus, item.verificationStatus != verificationStatus { return false }
            if let dataType, item.dataType != dataType { return false }
            if let source, item.discoverySource != source { return false }

            guard !trimmedQuery.isEmpty else { return true }
            return item.name.lowercased().contains(trimmedQuery)
                || item.value.lowercased().contains(trimmedQuery)
                || item.rvaFormatted.lowercased().contains(trimmedQuery)
                || item.moduleName.lowercased().contains(trimmedQuery)
                || item.category.rawValue.lowercased().contains(trimmedQuery)
        }
    }

    public func markIPARemoved(for recordID: UUID) throws -> GameRecord? {
        lock.lock()
        defer { lock.unlock() }
        var records = (try? loadRecordsUnlocked()) ?? []
        guard let idx = records.firstIndex(where: { $0.id == recordID }) else { return nil }
        records[idx].storedIPAFileName = nil
        try writeJSON(records, to: indexFileURL)
        return records[idx]
    }

    public func clearHistory(for directoryName: String? = nil) throws {
        lock.lock()
        defer { lock.unlock() }
        if let directoryName {
            let historyURL = gameDirectoryURL(for: directoryName).appendingPathComponent("history.json")
            if FileManager.default.fileExists(atPath: historyURL.path) {
                try writeJSON([AnalysisHistoryEntry](), to: historyURL)
            }
        } else {
            let contents = (try? FileManager.default.contentsOfDirectory(at: gamesRootURL, includingPropertiesForKeys: [.isDirectoryKey])) ?? []
            for folder in contents {
                let historyURL = folder.appendingPathComponent("history.json")
                if FileManager.default.fileExists(atPath: historyURL.path) {
                    try writeJSON([AnalysisHistoryEntry](), to: historyURL)
                }
            }
        }
    }

    public func deleteGame(_ record: GameRecord) throws {
        lock.lock()
        defer { lock.unlock() }
        let dirURL = gameDirectoryURL(for: record.directoryName)
        if FileManager.default.fileExists(atPath: dirURL.path) {
            try FileManager.default.removeItem(at: dirURL)
        }
        var records = (try? loadRecordsUnlocked()) ?? []
        records.removeAll { $0.id == record.id || $0.directoryName == record.directoryName }
        try writeJSON(records, to: indexFileURL)
    }

    // MARK: - Private

    private func loadRecordsUnlocked() throws -> [GameRecord] {
        guard FileManager.default.fileExists(atPath: indexFileURL.path) else {
            return []
        }
        return try readJSON(from: indexFileURL)
    }

    private func rebuildIndexFromDiskUnlocked() throws -> [GameRecord] {
        let items = try FileManager.default.contentsOfDirectory(
            at: gamesRootURL,
            includingPropertiesForKeys: [.isDirectoryKey],
            options: [.skipsHiddenFiles]
        )
        var rebuilt: [GameRecord] = []
        for folder in items {
            var isDir: ObjCBool = false
            guard FileManager.default.fileExists(atPath: folder.path, isDirectory: &isDir), isDir.boolValue else {
                continue
            }
            let metaURL = folder.appendingPathComponent("metadata.json")
            let offsetsURL = folder.appendingPathComponent("offsets.json")
            guard let meta: BinaryMetadataModel = try? readJSON(from: metaURL),
                  let offsets: [DiscoveredOffset] = try? readJSON(from: offsetsURL) else {
                continue
            }
            let verified = offsets.filter { $0.verificationStatus == .verified }.count
            let probable = offsets.filter { $0.verificationStatus == .probable }.count
            rebuilt.append(
                GameRecord(
                    gameName: meta.gameName,
                    directoryName: folder.lastPathComponent,
                    bundleIdentifier: meta.bundleIdentifier,
                    bundleVersion: meta.bundleShortVersion,
                    executableName: meta.primaryModuleName,
                    storedIPAFileName: nil,
                    ipaFileSize: meta.ipaFileSize,
                    architectureSummary: meta.cpuArchitecture,
                    encryptionState: meta.encryptionState,
                    isUnityIL2CPP: meta.isUnityIL2CPP,
                    analysisState: .completed,
                    totalOffsets: offsets.count,
                    verifiedOffsets: verified,
                    probableOffsets: probable,
                    importedAt: meta.analyzedAt,
                    lastAnalyzedAt: meta.analyzedAt
                )
            )
        }
        rebuilt.sort { ($0.lastAnalyzedAt ?? $0.importedAt) > ($1.lastAnalyzedAt ?? $1.importedAt) }
        try writeJSON(rebuilt, to: indexFileURL)
        return rebuilt
    }

    private func writeJSON<T: Encodable>(_ value: T, to url: URL) throws {
        do {
            let data = try encoder.encode(value)
            try data.write(to: url, options: .atomic)
        } catch {
            throw AnalysisError.databaseError("Failed writing \(url.lastPathComponent): \(error.localizedDescription)")
        }
    }

    private func readJSON<T: Decodable>(from url: URL) throws -> T {
        do {
            let data = try Data(contentsOf: url)
            return try decoder.decode(T.self, from: data)
        } catch {
            throw AnalysisError.databaseError("Failed reading \(url.lastPathComponent): \(error.localizedDescription)")
        }
    }
}
