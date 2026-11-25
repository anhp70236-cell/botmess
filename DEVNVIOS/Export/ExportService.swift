import Foundation

public struct ExportedPackagePayload: Codable, Sendable {
    public let exportedAt: Date
    public let generator: String
    public let metadata: BinaryMetadataModel
    public let report: AnalysisReport
    public let offsets: [DiscoveredOffset]
    public let runtime: RuntimeSnapshot
}

public final class ExportService: Sendable {
    private let storageManager: StorageManagerService

    public init(storageManager: StorageManagerService) {
        self.storageManager = storageManager
    }

    public func exportOffsetsJSON(
        gameName: String,
        offsets: [DiscoveredOffset]
    ) throws -> URL {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601

        let safeName = HexFormatters.sanitizeDirectoryName(gameName)
        let outURL = storageManager.exportsDirectoryURL.appendingPathComponent("\(safeName)_offsets.json")
        do {
            let data = try encoder.encode(offsets)
            try data.write(to: outURL, options: .atomic)
            return outURL
        } catch {
            throw AnalysisError.exportError(error.localizedDescription)
        }
    }

    public func exportFullAnalysisPackageJSON(bundle: GameAnalysisBundle) throws -> URL {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601

        let payload = ExportedPackagePayload(
            exportedAt: Date(),
            generator: "DEVNVIOS IPA ANALYZER v2.0",
            metadata: bundle.metadata,
            report: bundle.report,
            offsets: bundle.offsets,
            runtime: bundle.runtime
        )

        let safeName = HexFormatters.sanitizeDirectoryName(bundle.record.gameName)
        let outURL = storageManager.exportsDirectoryURL.appendingPathComponent("\(safeName)_full_analysis.json")
        do {
            let data = try encoder.encode(payload)
            try data.write(to: outURL, options: .atomic)
            return outURL
        } catch {
            throw AnalysisError.exportError(error.localizedDescription)
        }
    }

    public func exportMarkdownReport(bundle: GameAnalysisBundle) throws -> URL {
        let safeName = HexFormatters.sanitizeDirectoryName(bundle.record.gameName)
        let outURL = storageManager.exportsDirectoryURL.appendingPathComponent("\(safeName)_report.md")

        var md = """
        # DEVNVIOS IPA Analysis Report — \(bundle.metadata.gameName)

        - **Bundle ID**: `\(bundle.metadata.bundleIdentifier)`
        - **Version**: `\(bundle.metadata.bundleShortVersion)` (`\(bundle.metadata.bundleBuildVersion)`)
        - **Module**: `\(bundle.metadata.analysisModuleName)` (`\(bundle.metadata.cpuArchitecture)`)
        - **Encryption**: `\(bundle.metadata.encryptionState.rawValue)`
        - **IL2CPP**: `\(bundle.metadata.isUnityIL2CPP ? "Yes (\(bundle.metadata.il2cppMetadataStatus))" : "No")`
        - **Binary SHA-256**: `\(bundle.metadata.binarySHA256)`
        - **Total Offsets**: \(bundle.report.totalOffsets) (Verified: \(bundle.report.verifiedCount), Probable: \(bundle.report.probableCount), Unverified: \(bundle.report.unverifiedCount), Undetermined: \(bundle.report.undeterminedCount))

        ## Analysis Methods

        | Method | Status | Findings | Details |
        | --- | --- | --- | --- |
        """

        for m in bundle.report.methodSummaries {
            md += "\n| \(m.methodName) | \(m.status) | \(m.findingsCount) | \(m.details) |"
        }

        md += "\n\n## Top Discovered Offsets\n\n| Name | Category | Type | RVA | Static VA | File Offset | Status |\n| --- | --- | --- | --- | --- | --- | --- |"
        for off in bundle.offsets.prefix(150) {
            md += "\n| `\(off.name)` | \(off.category.rawValue) | \(off.dataType.rawValue) | `\(off.rvaFormatted)` | `\(off.vaFormatted)` | `\(off.fileOffsetFormatted)` | \(off.verificationStatus.rawValue) |"
        }
        md += "\n"

        do {
            try Data(md.utf8).write(to: outURL, options: .atomic)
            return outURL
        } catch {
            throw AnalysisError.exportError(error.localizedDescription)
        }
    }
}
