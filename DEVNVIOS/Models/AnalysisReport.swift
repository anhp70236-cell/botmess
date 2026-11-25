import Foundation

public struct AnalysisMethodSummary: Codable, Equatable, Identifiable, Sendable {
    public var id: String { methodName }
    public let methodName: String
    public let executed: Bool
    public let status: String
    public let findingsCount: Int
    public let details: String

    public init(
        methodName: String,
        executed: Bool,
        status: String,
        findingsCount: Int,
        details: String
    ) {
        self.methodName = methodName
        self.executed = executed
        self.status = status
        self.findingsCount = findingsCount
        self.details = details
    }
}

public struct CrossVersionDelta: Codable, Equatable, Sendable {
    public let previousVersion: String
    public let currentVersion: String
    public let matchedCount: Int
    public let shiftedRVAEntries: [String]
    public let newlyAddedCount: Int
    public let missingCount: Int

    public init(
        previousVersion: String,
        currentVersion: String,
        matchedCount: Int,
        shiftedRVAEntries: [String],
        newlyAddedCount: Int,
        missingCount: Int
    ) {
        self.previousVersion = previousVersion
        self.currentVersion = currentVersion
        self.matchedCount = matchedCount
        self.shiftedRVAEntries = shiftedRVAEntries
        self.newlyAddedCount = newlyAddedCount
        self.missingCount = missingCount
    }
}

public struct AnalysisReport: Codable, Equatable, Sendable {
    public let reportID: UUID
    public let gameName: String
    public let bundleIdentifier: String
    public let bundleVersion: String
    public let generatedAt: Date
    public let durationSeconds: Double
    public let totalOffsets: Int
    public let verifiedCount: Int
    public let probableCount: Int
    public let unverifiedCount: Int
    public let undeterminedCount: Int
    public let categoryBreakdown: [String: Int]
    public let methodSummaries: [AnalysisMethodSummary]
    public let crossVersionDelta: CrossVersionDelta?
    public let warnings: [String]
    public let limitations: [String]

    public init(
        reportID: UUID = UUID(),
        gameName: String,
        bundleIdentifier: String,
        bundleVersion: String,
        generatedAt: Date = Date(),
        durationSeconds: Double,
        totalOffsets: Int,
        verifiedCount: Int,
        probableCount: Int,
        unverifiedCount: Int,
        undeterminedCount: Int,
        categoryBreakdown: [String: Int],
        methodSummaries: [AnalysisMethodSummary],
        crossVersionDelta: CrossVersionDelta?,
        warnings: [String],
        limitations: [String]
    ) {
        self.reportID = reportID
        self.gameName = gameName
        self.bundleIdentifier = bundleIdentifier
        self.bundleVersion = bundleVersion
        self.generatedAt = generatedAt
        self.durationSeconds = durationSeconds
        self.totalOffsets = totalOffsets
        self.verifiedCount = verifiedCount
        self.probableCount = probableCount
        self.unverifiedCount = unverifiedCount
        self.undeterminedCount = undeterminedCount
        self.categoryBreakdown = categoryBreakdown
        self.methodSummaries = methodSummaries
        self.crossVersionDelta = crossVersionDelta
        self.warnings = warnings
        self.limitations = limitations
    }
}

public struct AnalysisHistoryEntry: Identifiable, Codable, Equatable, Sendable {
    public let id: UUID
    public let gameName: String
    public let bundleIdentifier: String
    public let bundleVersion: String
    public let binarySHA256: String
    public let analyzedAt: Date
    public let durationSeconds: Double
    public let totalOffsets: Int
    public let verifiedCount: Int
    public let encryptionState: BinaryEncryptionState
    public let offsetSnapshotByName: [String: UInt64]

    public init(
        id: UUID = UUID(),
        gameName: String,
        bundleIdentifier: String,
        bundleVersion: String,
        binarySHA256: String,
        analyzedAt: Date = Date(),
        durationSeconds: Double,
        totalOffsets: Int,
        verifiedCount: Int,
        encryptionState: BinaryEncryptionState,
        offsetSnapshotByName: [String: UInt64]
    ) {
        self.id = id
        self.gameName = gameName
        self.bundleIdentifier = bundleIdentifier
        self.bundleVersion = bundleVersion
        self.binarySHA256 = binarySHA256
        self.analyzedAt = analyzedAt
        self.durationSeconds = durationSeconds
        self.totalOffsets = totalOffsets
        self.verifiedCount = verifiedCount
        self.encryptionState = encryptionState
        self.offsetSnapshotByName = offsetSnapshotByName
    }
}
