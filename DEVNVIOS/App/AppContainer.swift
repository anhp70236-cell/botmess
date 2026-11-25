import Foundation
import SwiftUI

public enum AppTab: Int, CaseIterable, Identifiable {
    case home = 0
    case analysis = 1
    case gameDetails = 2
    case storage = 3
    case settings = 4

    public var id: Int { rawValue }
}

@MainActor
public final class AppContainer: ObservableObject {
    public let storageManager: StorageManagerService
    public let databaseService: OffsetDatabaseService
    public let importService: IPAImportService
    public let exportService: ExportService
    public let runtimeInspector: RuntimeInspectorService
    public let pipeline: AnalysisPipelineCoordinator

    @Published public var selectedTab: AppTab = .home
    @Published public var games: [GameRecord] = []
    @Published public var activeGameForDetails: GameRecord?
    @Published public var storageSnapshot: StorageUsageSnapshot
    @Published public var isShowingFileImporter: Bool = false
    @Published public var alertMessage: String?

    @AppStorage("com.devnvios.themeMode") public var themeModeRaw: String = AppThemeMode.matteDark.rawValue

    public var themeMode: AppThemeMode {
        get { AppThemeMode(rawValue: themeModeRaw) ?? .matteDark }
        set {
            themeModeRaw = newValue.rawValue
            objectWillChange.send()
        }
    }

    public var totalOffsetsAcrossLibrary: Int {
        games.reduce(0) { $0 + $1.totalOffsets }
    }

    public init(customRootURL: URL? = nil) {
        let storage = try! StorageManagerService(rootDirectoryURL: customRootURL)
        let db = try! OffsetDatabaseService(baseDirectoryURL: storage.rootDirectoryURL)
        let importer = IPAImportService(storageManager: storage)
        let exporter = ExportService(storageManager: storage)
        let inspector = RuntimeInspectorService()
        let coordinator = AnalysisPipelineCoordinator(
            storageManager: storage,
            databaseService: db,
            importService: importer,
            discoveryEngine: AdvancedOffsetDiscoveryEngine(runtimeInspector: inspector)
        )

        self.storageManager = storage
        self.databaseService = db
        self.importService = importer
        self.exportService = exporter
        self.runtimeInspector = inspector
        self.pipeline = coordinator
        self.storageSnapshot = storage.computeUsageSnapshot()
        self.games = db.loadAllRecords()
        self.activeGameForDetails = self.games.first

        coordinator.onDatabaseUpdated = { [weak self] in
            self?.refreshAll()
        }
    }

    public func refreshAll() {
        games = databaseService.loadAllRecords()
        storageSnapshot = storageManager.computeUsageSnapshot()
        if let active = activeGameForDetails {
            activeGameForDetails = games.first(where: { $0.id == active.id }) ?? games.first
        } else {
            activeGameForDetails = games.first
        }
    }

    public func handleImportedURLs(_ urls: [URL]) {
        guard !urls.isEmpty else { return }
        selectedTab = .analysis
        pipeline.importAndAnalyzeIPAs(urls: urls)
    }

    public func openGameDetails(_ record: GameRecord) {
        activeGameForDetails = record
        selectedTab = .gameDetails
    }

    public func deleteStoredIPAOnly(for record: GameRecord) {
        do {
            _ = try storageManager.deleteStoredIPA(for: record, database: databaseService)
            refreshAll()
        } catch {
            alertMessage = error.localizedDescription
        }
    }

    public func deleteGameCompletely(_ record: GameRecord) {
        do {
            _ = try storageManager.deleteStoredIPA(for: record, database: databaseService)
            try databaseService.deleteGame(record)
            refreshAll()
        } catch {
            alertMessage = error.localizedDescription
        }
    }

    public func clearCache() {
        do {
            try storageManager.clearCache()
            refreshAll()
        } catch {
            alertMessage = error.localizedDescription
        }
    }

    public func clearAllHistory() {
        do {
            try databaseService.clearHistory(for: nil)
            refreshAll()
        } catch {
            alertMessage = error.localizedDescription
        }
    }

    public func rebuildDatabaseIndex() {
        do {
            _ = try databaseService.rebuildIndexFromDisk()
            refreshAll()
        } catch {
            alertMessage = error.localizedDescription
        }
    }

    public func updateStorageQuotaGB(_ gigabytes: Double) {
        let clampedGB = max(3.0, min(64.0, gigabytes))
        storageManager.configuredQuotaBytes = Int64(clampedGB * 1_073_741_824.0)
        storageSnapshot = storageManager.computeUsageSnapshot()
    }
}
