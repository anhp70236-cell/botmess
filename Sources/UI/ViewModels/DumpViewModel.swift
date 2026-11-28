import Foundation
import SwiftUI

@MainActor
final class DumpViewModel: ObservableObject {
    enum InputMode: String, CaseIterable, Identifiable {
        case ipa = "Tệp .IPA (≤ 10 GB)"
        case standalone = "Mach-O + Metadata"
        var id: String { rawValue }
    }

    @Published var inputMode: InputMode = .ipa
    @Published var selectedIPAURL: URL?
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
    private var cancelFlag = false
    private let cancelLock = NSLock()
    private let optionsKey = "dip_options_v2"

    var outputsRootURL: URL {
        let docs = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask).first
            ?? FileManager.default.temporaryDirectory
        return docs.appendingPathComponent("IPADumperOutputs", isDirectory: true)
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
        loadSavedSessions()
    }

    deinit {
        for url in activeSecurityURLs {
            url.stopAccessingSecurityScopedResource()
        }
    }

    private func saveOptions() {
        if let data = try? JSONEncoder().encode(options) {
            UserDefaults.standard.set(data, forKey: optionsKey)
        }
    }

    private func acquireSecurityAccess(for url: URL) {
        if url.startAccessingSecurityScopedResource() {
            activeSecurityURLs.append(url)
        }
    }

    func handleIncomingFileURL(_ url: URL) {
        acquireSecurityAccess(for: url)
        let ext = url.pathExtension.lowercased()
        if ext == "ipa" || ext == "zip" {
            inputMode = .ipa
            setIPAFile(url)
        } else if ext == "dat" {
            inputMode = .standalone
            selectedMetadataURL = url
            appendLog("[Input] Đã chọn metadata: \(url.lastPathComponent)")
        } else {
            inputMode = .standalone
            selectedMachOURL = url
            appendLog("[Input] Đã chọn Mach-O: \(url.lastPathComponent)")
        }
    }

    func setIPAFile(_ url: URL) {
        acquireSecurityAccess(for: url)
        selectedIPAURL = url
        errorMessage = nil
        inspectionInfo = nil
        statusMessage = "Đang đọc cấu trúc ZIP64 của \(url.lastPathComponent)..."
        appendLog("[IPA] Đang kiểm tra tệp: \(url.lastPathComponent)")

        Task.detached(priority: .userInitiated) {
            do {
                let info = try DumpCoordinator.inspectIPA(at: url)
                await MainActor.run {
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
                    self.errorMessage = error.localizedDescription
                    self.statusMessage = "Lỗi kiểm tra IPA"
                    self.appendLog("[Lỗi] \(error.localizedDescription)")
                }
            }
        }
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

        cancelLock.lock()
        cancelFlag = false
        cancelLock.unlock()

        let mode = inputMode
        let ipaURL = selectedIPAURL
        let machoURL = selectedMachOURL
        let metaURL = selectedMetadataURL
        let opts = options
        let rootURL = outputsRootURL

        Task.detached(priority: .userInitiated) {
            let coordinator = DumpCoordinator()
            let checkCancelled: () -> Bool = { [weak self] in
                guard let self = self else { return true }
                self.cancelLock.lock()
                let c = self.cancelFlag
                self.cancelLock.unlock()
                return c
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
                    result = try coordinator.runIPA(
                        ipaURL: ipa,
                        outputsRootURL: rootURL,
                        options: opts,
                        isCancelled: checkCancelled,
                        onProgress: emitProgress
                    )
                } else {
                    guard let m = machoURL else {
                        throw DumpCoordinatorError.noMachOFound
                    }
                    result = try coordinator.runStandalone(
                        machOURL: m,
                        metadataURL: metaURL,
                        outputsRootURL: rootURL,
                        options: opts,
                        isCancelled: checkCancelled,
                        onProgress: emitProgress
                    )
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
        cancelLock.lock()
        cancelFlag = true
        cancelLock.unlock()
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
