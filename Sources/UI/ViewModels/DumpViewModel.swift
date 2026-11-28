import Foundation
import SwiftUI
import Darwin

private final class CancellationFlag: @unchecked Sendable {
    private let lock = NSLock()
    private var cancelled = false
    func reset() { lock.lock(); cancelled = false; lock.unlock() }
    func cancel() { lock.lock(); cancelled = true; lock.unlock() }
    var isCancelled: Bool { lock.lock(); defer { lock.unlock() }; return cancelled }
}

@MainActor
final class DumpViewModel: ObservableObject {
    enum InputMode: String, CaseIterable, Identifiable {
        case ipa = "Tệp .IPA (≤ 10 GB)"
        case standalone = "Mach-O + Metadata"
        var id: String { rawValue }
    }

    @Published var inputMode: InputMode = .ipa
    @Published var activeTab: Int = 0
    @Published var selectedIPAURL: URL?
    @Published var overrideMetadataURL: URL?
    @Published var localDocumentsIPAs: [URL] = []
    @Published var selectedMachOURL: URL?
    @Published var selectedMetadataURL: URL?
    @Published var inspectionInfo: IPAInspectionInfo?
    @Published var options: DumpOptions {
        didSet { saveOptions() }
    }
    @Published var selectedTheme: AppThemeStyle {
        didSet {
            UserDefaults.standard.set(selectedTheme.rawValue, forKey: "dip_theme_v2")
        }
    }
    @Published var useInPlacePicker: Bool {
        didSet {
            UserDefaults.standard.set(useInPlacePicker, forKey: "dip_inplace_picker_v3")
        }
    }

    @Published var isInspecting = false
    @Published var isRunning = false
    @Published var currentPhase: DumpPhase = .idle
    @Published var progress: Double = 0
    @Published var statusMessage: String = "Chọn tệp .ipa (tối đa 10 GB) hoặc cặp Mach-O + global-metadata.dat để bắt đầu."
    @Published var logs: [String] = []
    @Published var errorMessage: String?

    @Published var currentSession: DumpSessionRecord?
    @Published var savedSessions: [DumpSessionRecord] = []
    @Published var explorerRows: [ExplorerRow] = []

    @Published var searchQuery = ""
    @Published var selectedKindFilter: ExplorerItemKind? = nil
    @Published var selectedAssemblyFilter: String = "Tất cả"

    private var activeSecurityURLs: [URL] = []
    private var copiedTempURLs: [URL] = []
    private var inspectTask: Task<Void, Never>?
    private let cancellation = CancellationFlag()
    private let optionsKey = "dip_options_v2"

    var documentsRootURL: URL {
        FileManager.default.urls(for: .documentDirectory, in: .userDomainMask).first
            ?? FileManager.default.temporaryDirectory
    }

    var outputsRootURL: URL {
        documentsRootURL.appendingPathComponent("IPADumperOutputs", isDirectory: true)
    }

    nonisolated static var importsRootURL: URL {
        FileManager.default.temporaryDirectory.appendingPathComponent("IPADumperImports", isDirectory: true)
    }

    init() {
        if let data = UserDefaults.standard.data(forKey: optionsKey),
           let decoded = try? JSONDecoder().decode(DumpOptions.self, from: data) {
            self.options = decoded
        } else {
            self.options = .default
        }
        if let tRaw = UserDefaults.standard.string(forKey: "dip_theme_v2"),
           let t = AppThemeStyle(rawValue: tRaw) {
            self.selectedTheme = t
        } else {
            self.selectedTheme = .obsidianDark
        }
        if UserDefaults.standard.object(forKey: "dip_inplace_picker_v3") == nil {
            self.useInPlacePicker = true
        } else {
            self.useInPlacePicker = UserDefaults.standard.bool(forKey: "dip_inplace_picker_v3")
        }
        try? FileManager.default.removeItem(at: Self.importsRootURL)
        loadSavedSessions()
        refreshLocalDocumentsIPAs()
    }

    deinit {
        for url in activeSecurityURLs {
            url.stopAccessingSecurityScopedResource()
        }
        for url in copiedTempURLs {
            Self.removeImportedTempEntry(url)
        }
    }

    private func saveOptions() {
        if let data = try? JSONEncoder().encode(options) {
            UserDefaults.standard.set(data, forKey: optionsKey)
        }
    }

    private func acquireSecurityAccess(for url: URL) {
        if !activeSecurityURLs.contains(url), url.startAccessingSecurityScopedResource() {
            activeSecurityURLs.append(url)
        }
    }

    nonisolated private static func removeImportedTempEntry(_ url: URL) {
        let fm = FileManager.default
        let stdPath = url.standardizedFileURL.path
        let tmpPath = fm.temporaryDirectory.standardizedFileURL.path
        let importsPath = importsRootURL.standardizedFileURL.path
        guard stdPath.hasPrefix(tmpPath) else { return }

        let parent = url.deletingLastPathComponent().standardizedFileURL
        if parent.path.hasPrefix(importsPath + "/"), parent.path != importsPath {
            try? fm.removeItem(at: parent)
        } else {
            try? fm.removeItem(at: url)
        }
    }

    private func cleanupOldCopiedTemps(keeping keepURL: URL?) {
        let protected: Set<String> = Set(
            [keepURL, selectedIPAURL, overrideMetadataURL, selectedMachOURL, selectedMetadataURL]
                .compactMap { $0?.standardizedFileURL.path }
        )
        let tmpPath = FileManager.default.temporaryDirectory.standardizedFileURL.path
        var kept: [URL] = []
        for url in copiedTempURLs {
            let path = url.standardizedFileURL.path
            if protected.contains(path) {
                kept.append(url)
            } else if path.hasPrefix(tmpPath) {
                Self.removeImportedTempEntry(url)
            }
        }
        copiedTempURLs = kept
    }

    nonisolated static func withCoordinatedRead<T>(url: URL, _ body: (URL) throws -> T) throws -> T {
        let fm = FileManager.default
        let stdPath = url.standardizedFileURL.path
        let tmpPath = fm.temporaryDirectory.standardizedFileURL.path
        let docsPath = fm.urls(for: .documentDirectory, in: .userDomainMask).first?.standardizedFileURL.path
        if stdPath.hasPrefix(tmpPath) || (docsPath.map { stdPath.hasPrefix($0) } ?? false) {
            return try body(url)
        }

        let accessed = url.startAccessingSecurityScopedResource()
        defer {
            if accessed { url.stopAccessingSecurityScopedResource() }
        }
        if Darwin.access(url.path, R_OK) == 0 {
            return try body(url)
        }
        var coordError: NSError?
        var innerResult: Result<T, Error>?
        let coordinator = NSFileCoordinator()
        coordinator.coordinate(readingItemAt: url, options: .withoutChanges, error: &coordError) { actualURL in
            do {
                innerResult = .success(try body(actualURL))
            } catch {
                innerResult = .failure(error)
            }
        }
        if let res = innerResult {
            return try res.get()
        }
        if fm.isReadableFile(atPath: url.path) {
            return try body(url)
        }
        if let err = coordError {
            throw err
        }
        return try body(url)
    }

    func refreshLocalDocumentsIPAs() {
        let fm = FileManager.default
        let items = (try? fm.contentsOfDirectory(at: documentsRootURL, includingPropertiesForKeys: [.isRegularFileKey, .fileSizeKey])) ?? []
        localDocumentsIPAs = items.filter { url in
            let ext = url.pathExtension.lowercased()
            return ext == "ipa" || ext == "tipa" || ext == "zip"
        }.sorted { $0.lastPathComponent.lowercased() < $1.lastPathComponent.lowercased() }
    }

    func loadIPAsFromFolder(_ folderURL: URL) {
        acquireSecurityAccess(for: folderURL)
        do {
            let items = try Self.withCoordinatedRead(url: folderURL) { actualFolder in
                try FileManager.default.contentsOfDirectory(
                    at: actualFolder,
                    includingPropertiesForKeys: [.isRegularFileKey, .fileSizeKey]
                )
            }
            let found = items.filter { url in
                let ext = url.pathExtension.lowercased()
                return ext == "ipa" || ext == "tipa" || ext == "zip"
            }.sorted { $0.lastPathComponent.lowercased() < $1.lastPathComponent.lowercased() }

            for item in found {
                acquireSecurityAccess(for: item)
            }
            localDocumentsIPAs = found
            if found.isEmpty {
                errorMessage = "Không tìm thấy tệp .ipa / .tipa nào trong thư mục \(folderURL.lastPathComponent)."
            } else {
                errorMessage = nil
                appendLog("[Thư mục] Tìm thấy \(found.count) tệp IPA trong \(folderURL.lastPathComponent).")
                if found.count == 1 {
                    setIPAFile(found[0])
                }
            }
        } catch {
            errorMessage = "Không thể đọc thư mục: \(error.localizedDescription)"
        }
    }

    private func trackTempURL(_ url: URL) {
        let tmpPath = FileManager.default.temporaryDirectory.standardizedFileURL.path
        if url.standardizedFileURL.path.hasPrefix(tmpPath), !copiedTempURLs.contains(url) {
            copiedTempURLs.append(url)
        }
    }

    func handleIncomingFileURL(_ rawURL: URL) {
        let url = NativeDocumentPicker.preservePickedFileIfNeeded(rawURL)
        acquireSecurityAccess(for: url)
        trackTempURL(url)
        activeTab = 0
        let ext = url.pathExtension.lowercased()
        if ext == "ipa" || ext == "tipa" || ext == "zip" {
            inputMode = .ipa
            setIPAFile(url)
        } else if ext == "dat" || url.lastPathComponent.lowercased().contains("global-metadata") {
            if inputMode == .ipa {
                setOverrideMetadataFile(url)
            } else {
                selectedMetadataURL = url
                appendLog("[Input] Đã chọn metadata: \(url.lastPathComponent)")
            }
        } else {
            inputMode = .standalone
            selectedMachOURL = url
            appendLog("[Input] Đã chọn Mach-O: \(url.lastPathComponent)")
        }
    }

    func setIPAFile(_ rawURL: URL) {
        let url = NativeDocumentPicker.preservePickedFileIfNeeded(rawURL)
        acquireSecurityAccess(for: url)
        trackTempURL(url)
        cleanupOldCopiedTemps(keeping: url)

        inspectTask?.cancel()
        selectedIPAURL = url
        isInspecting = true
        errorMessage = nil
        inspectionInfo = nil
        statusMessage = "Đang đọc cấu trúc ZIP64 của \(url.lastPathComponent)..."
        appendLog("[IPA] Đang kiểm tra tệp: \(url.lastPathComponent)")

        inspectTask = Task.detached(priority: .userInitiated) {
            do {
                let info = try Self.withCoordinatedRead(url: url) { actualURL in
                    try DumpCoordinator.inspectIPA(at: actualURL)
                }
                await MainActor.run {
                    guard !Task.isCancelled, self.selectedIPAURL == url else { return }
                    self.isInspecting = false
                    if info.fileSizeBytes > 10 * 1024 * 1024 * 1024 {
                        self.errorMessage = "Tệp IPA vượt quá giới hạn tối đa 10 GB."
                        self.appendLog("[Cảnh báo] Tệp IPA > 10 GB.")
                    } else {
                        self.inspectionInfo = info
                        self.statusMessage = "Sẵn sàng phân tích: \(info.appName) (\(formatByteCount(info.fileSizeBytes)))"
                        self.appendLog("[IPA] App=\(info.appName) · Bundle=\(info.bundleId) · MachO=\(info.primaryMachOPath) · Meta=\(info.metadataPath ?? "Không có")")
                    }
                }
            } catch {
                await MainActor.run {
                    guard !Task.isCancelled, self.selectedIPAURL == url else { return }
                    self.isInspecting = false
                    self.errorMessage = error.localizedDescription
                    self.statusMessage = "Lỗi kiểm tra IPA"
                    self.appendLog("[Lỗi] \(error.localizedDescription)")
                }
            }
        }
    }

    func setOverrideMetadataFile(_ rawURL: URL) {
        let url = NativeDocumentPicker.preservePickedFileIfNeeded(rawURL)
        acquireSecurityAccess(for: url)
        trackTempURL(url)
        overrideMetadataURL = url
        errorMessage = nil
        appendLog("[IPA] Đã gắn global-metadata.dat rời: \(url.lastPathComponent)")
    }

    func clearOverrideMetadata() {
        overrideMetadataURL = nil
    }

    func setMachOFile(_ url: URL) {
        acquireSecurityAccess(for: url)
        selectedMachOURL = url
        errorMessage = nil
        appendLog("[Standalone] Mach-O: \(url.lastPathComponent)")
    }

    func setMetadataFile(_ url: URL) {
        acquireSecurityAccess(for: url)
        selectedMetadataURL = url
        errorMessage = nil
        appendLog("[Standalone] Metadata: \(url.lastPathComponent)")
    }

    func startAnalysis() {
        guard !isRunning else { return }
        errorMessage = nil
        logs.removeAll()
        progress = 0.02
        currentPhase = .inspecting
        isRunning = true

        cancellation.reset()
        let cancelToken = cancellation

        let mode = inputMode
        let ipaURL = selectedIPAURL
        let overrideMetaURL = overrideMetadataURL
        let machoURL = selectedMachOURL
        let metaURL = selectedMetadataURL
        let opts = options
        let rootURL = outputsRootURL

        Task.detached(priority: .userInitiated) {
            let coordinator = DumpCoordinator()
            let checkCancelled: () -> Bool = {
                cancelToken.isCancelled
            }
            let emitProgress: (DumpProgressUpdate) -> Void = { [weak self] upd in
                Task { @MainActor in
                    guard let self = self else { return }
                    self.currentPhase = upd.phase
                    self.progress = upd.progress
                    self.statusMessage = upd.message
                    self.appendLog(upd.message)
                }
            }

            do {
                let result: DumpCoordinatorResult
                if mode == .ipa {
                    guard let ipa = ipaURL else {
                        throw DumpCoordinatorError.noMachOFound
                    }
                    result = try Self.withCoordinatedRead(url: ipa) { coordIPA in
                        if let om = overrideMetaURL {
                            return try Self.withCoordinatedRead(url: om) { coordMeta in
                                try coordinator.runIPA(
                                    ipaURL: coordIPA,
                                    overrideMetadataURL: coordMeta,
                                    outputsRootURL: rootURL,
                                    options: opts,
                                    isCancelled: checkCancelled,
                                    onProgress: emitProgress
                                )
                            }
                        } else {
                            return try coordinator.runIPA(
                                ipaURL: coordIPA,
                                overrideMetadataURL: nil,
                                outputsRootURL: rootURL,
                                options: opts,
                                isCancelled: checkCancelled,
                                onProgress: emitProgress
                            )
                        }
                    }
                } else {
                    guard let m = machoURL else {
                        throw DumpCoordinatorError.noMachOFound
                    }
                    result = try Self.withCoordinatedRead(url: m) { coordMachO in
                        if let sm = metaURL {
                            return try Self.withCoordinatedRead(url: sm) { coordMeta in
                                try coordinator.runStandalone(
                                    machOURL: coordMachO,
                                    metadataURL: coordMeta,
                                    outputsRootURL: rootURL,
                                    options: opts,
                                    isCancelled: checkCancelled,
                                    onProgress: emitProgress
                                )
                            }
                        } else {
                            return try coordinator.runStandalone(
                                machOURL: coordMachO,
                                metadataURL: nil,
                                outputsRootURL: rootURL,
                                options: opts,
                                isCancelled: checkCancelled,
                                onProgress: emitProgress
                            )
                        }
                    }
                }

                await MainActor.run {
                    self.isRunning = false
                    self.currentPhase = .completed
                    self.progress = 1.0
                    self.currentSession = result.session
                    self.explorerRows = result.explorerRows
                    self.selectedAssemblyFilter = "Tất cả"
                    self.loadSavedSessions()
                }
            } catch {
                await MainActor.run {
                    self.isRunning = false
                    self.currentPhase = .failed
                    self.errorMessage = error.localizedDescription
                    self.statusMessage = error.localizedDescription
                    self.appendLog("[Lỗi] \(error.localizedDescription)")
                }
            }
        }
    }

    func cancelAnalysis() {
        cancellation.cancel()
        appendLog("[Hệ thống] Đang yêu cầu huỷ...")
    }

    func appendLog(_ msg: String) {
        let fmt = DateFormatter()
        fmt.dateFormat = "HH:mm:ss"
        let line = "[\(fmt.string(from: Date()))] \(msg)"
        logs.append(line)
        if logs.count > 400 {
            logs.removeFirst(logs.count - 400)
        }
    }

    func loadSavedSessions() {
        let fm = FileManager.default
        if !fm.fileExists(atPath: outputsRootURL.path) {
            try? fm.createDirectory(at: outputsRootURL, withIntermediateDirectories: true)
        }
        guard let dirs = try? fm.contentsOfDirectory(at: outputsRootURL, includingPropertiesForKeys: [.isDirectoryKey]) else {
            savedSessions = []
            return
        }
        var loaded: [DumpSessionRecord] = []
        for d in dirs {
            let sessURL = d.appendingPathComponent(".session.json")
            if let data = try? Data(contentsOf: sessURL),
               let rec = try? JSONDecoder().decode(DumpSessionRecord.self, from: data) {
                loaded.append(rec)
            }
        }
        loaded.sort { $0.createdAt > $1.createdAt }
        savedSessions = loaded
        if currentSession == nil, let first = loaded.first {
            selectSession(first)
        }
    }

    func selectSession(_ session: DumpSessionRecord) {
        currentSession = session
        let dir = outputsRootURL.appendingPathComponent(session.outputDirectoryName)
        let expURL = dir.appendingPathComponent(".explorer_index.json")
        Task.detached(priority: .userInitiated) {
            if let data = try? Data(contentsOf: expURL),
               let rows = try? JSONDecoder().decode([ExplorerRow].self, from: data) {
                await MainActor.run {
                    self.explorerRows = rows
                    self.selectedAssemblyFilter = "Tất cả"
                }
            }
        }
    }

    func deleteSession(_ session: DumpSessionRecord) {
        let dir = outputsRootURL.appendingPathComponent(session.outputDirectoryName)
        try? FileManager.default.removeItem(at: dir)
        if currentSession?.id == session.id {
            currentSession = nil
            explorerRows = []
        }
        loadSavedSessions()
    }

    func clearAllOutputs() {
        try? FileManager.default.removeItem(at: outputsRootURL)
        try? FileManager.default.createDirectory(at: outputsRootURL, withIntermediateDirectories: true)
        currentSession = nil
        savedSessions = []
        explorerRows = []
    }

    func exportSessionZip(_ session: DumpSessionRecord) -> URL? {
        let dir = outputsRootURL.appendingPathComponent(session.outputDirectoryName)
        let zipURL = FileManager.default.temporaryDirectory
            .appendingPathComponent("\(session.outputDirectoryName).zip")
        do {
            try DumpCoordinator().packageOutputDirectory(dir, toZip: zipURL)
            return zipURL
        } catch {
            errorMessage = "Không thể nén ZIP: \(error.localizedDescription)"
            return nil
        }
    }

    var availableAssemblies: [String] {
        var set = Set<String>()
        for row in explorerRows {
            if !row.assembly.isEmpty { set.insert(row.assembly) }
        }
        return ["Tất cả"] + set.sorted()
    }

    var filteredExplorerRows: [ExplorerRow] {
        let q = searchQuery.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return explorerRows.filter { row in
            if let kf = selectedKindFilter, row.kind != kf { return false }
            if selectedAssemblyFilter != "Tất cả" && row.assembly != selectedAssemblyFilter { return false }
            if q.isEmpty { return true }
            return row.name.lowercased().contains(q)
                || row.className.lowercased().contains(q)
                || row.namespace.lowercased().contains(q)
                || row.rvaHex.lowercased().contains(q)
                || row.fieldOffsetHex.lowercased().contains(q)
                || row.tokenHex.lowercased().contains(q)
        }
    }

    func totalOutputsDiskBytes() -> UInt64 {
        let fm = FileManager.default
        guard let enumerator = fm.enumerator(at: outputsRootURL, includingPropertiesForKeys: [.fileSizeKey]) else {
            return 0
        }
        var total: UInt64 = 0
        for case let url as URL in enumerator {
            if let sz = (try? url.resourceValues(forKeys: [.fileSizeKey]))?.fileSize {
                total += UInt64(sz)
            }
        }
        return total
    }
}
