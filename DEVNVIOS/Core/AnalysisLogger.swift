import Foundation

public enum AnalysisLogLevel: String, Codable, CaseIterable, Sendable {
    case info = "INFO"
    case stage = "STAGE"
    case success = "OK"
    case warning = "WARN"
    case error = "ERROR"
}

public struct AnalysisLogEntry: Identifiable, Codable, Equatable, Sendable {
    public let id: UUID
    public let timestamp: Date
    public let level: AnalysisLogLevel
    public let stage: String
    public let message: String

    public init(
        id: UUID = UUID(),
        timestamp: Date = Date(),
        level: AnalysisLogLevel,
        stage: String,
        message: String
    ) {
        self.id = id
        self.timestamp = timestamp
        self.level = level
        self.stage = stage
        self.message = message
    }

    public var formattedTime: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm:ss.SSS"
        return formatter.string(from: timestamp)
    }

    public var formattedLine: String {
        "[\(formattedTime)] [\(level.rawValue)] [\(stage)] \(message)"
    }
}

public final class AnalysisLogger: @unchecked Sendable {
    private let lock = NSLock()
    private var entries: [AnalysisLogEntry] = []
    private let maxEntries: Int
    private let onUpdate: (@Sendable ([AnalysisLogEntry]) -> Void)?

    public init(
        maxEntries: Int = 1500,
        onUpdate: (@Sendable ([AnalysisLogEntry]) -> Void)? = nil
    ) {
        self.maxEntries = maxEntries
        self.onUpdate = onUpdate
    }

    public func log(_ level: AnalysisLogLevel, stage: String, _ message: String) {
        let entry = AnalysisLogEntry(level: level, stage: stage, message: message)
        let snapshot: [AnalysisLogEntry]
        lock.lock()
        entries.append(entry)
        if entries.count > maxEntries {
            entries.removeFirst(entries.count - maxEntries)
        }
        snapshot = entries
        lock.unlock()
        onUpdate?(snapshot)
    }

    public func info(stage: String, _ message: String) {
        log(.info, stage: stage, message)
    }

    public func stage(_ stageName: String, _ message: String) {
        log(.stage, stage: stageName, message)
    }

    public func success(stage: String, _ message: String) {
        log(.success, stage: stage, message)
    }

    public func warning(stage: String, _ message: String) {
        log(.warning, stage: stage, message)
    }

    public func error(stage: String, _ message: String) {
        log(.error, stage: stage, message)
    }

    public func allEntries() -> [AnalysisLogEntry] {
        lock.lock()
        defer { lock.unlock() }
        return entries
    }

    public func clear() {
        lock.lock()
        entries.removeAll()
        lock.unlock()
        onUpdate?([])
    }
}
