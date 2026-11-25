import Foundation

public enum GameAnalysisState: String, Codable, Sendable {
    case imported = "Imported"
    case queued = "Queued"
    case analyzing = "Analyzing"
    case completed = "Completed"
    case failed = "Failed"
    case cancelled = "Cancelled"
}

public struct GameRecord: Identifiable, Codable, Equatable, Hashable, Sendable {
    public let id: UUID
    public var gameName: String
    public var directoryName: String
    public var bundleIdentifier: String
    public var bundleVersion: String
    public var executableName: String
    public var storedIPAFileName: String?
    public var ipaFileSize: Int64
    public var architectureSummary: String
    public var encryptionState: BinaryEncryptionState
    public var isUnityIL2CPP: Bool
    public var analysisState: GameAnalysisState
    public var totalOffsets: Int
    public var verifiedOffsets: Int
    public var probableOffsets: Int
    public var importedAt: Date
    public var lastAnalyzedAt: Date?

    public init(
        id: UUID = UUID(),
        gameName: String,
        directoryName: String,
        bundleIdentifier: String,
        bundleVersion: String,
        executableName: String,
        storedIPAFileName: String?,
        ipaFileSize: Int64,
        architectureSummary: String,
        encryptionState: BinaryEncryptionState,
        isUnityIL2CPP: Bool,
        analysisState: GameAnalysisState,
        totalOffsets: Int,
        verifiedOffsets: Int,
        probableOffsets: Int,
        importedAt: Date = Date(),
        lastAnalyzedAt: Date? = nil
    ) {
        self.id = id
        self.gameName = gameName
        self.directoryName = directoryName
        self.bundleIdentifier = bundleIdentifier
        self.bundleVersion = bundleVersion
        self.executableName = executableName
        self.storedIPAFileName = storedIPAFileName
        self.ipaFileSize = ipaFileSize
        self.architectureSummary = architectureSummary
        self.encryptionState = encryptionState
        self.isUnityIL2CPP = isUnityIL2CPP
        self.analysisState = analysisState
        self.totalOffsets = totalOffsets
        self.verifiedOffsets = verifiedOffsets
        self.probableOffsets = probableOffsets
        self.importedAt = importedAt
        self.lastAnalyzedAt = lastAnalyzedAt
    }

    public var hasStoredIPA: Bool {
        storedIPAFileName != nil
    }
}
