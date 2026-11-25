import Foundation

public enum OffsetExportMode: String, CaseIterable, Identifiable, Sendable {
    case singleCombinedFile = "single"
    case splitMultipleFiles = "split"

    public var id: String { rawValue }

    public var vietnameseTitle: String {
        switch self {
        case .singleCombinedFile:
            return "Gom toàn bộ vào 1 tệp duy nhất"
        case .splitMultipleFiles:
            return "Tách riêng ra từng tệp theo nhóm"
        }
    }

    public var vietnameseSubtitle: String {
        switch self {
        case .singleCombinedFile:
            return "Gộp tất cả thông tin nhị phân, RVA, VA, File Offset, Field Offset vào 1 tệp tổng hợp (.txt & .json) rất dễ lưu và xử lý."
        case .splitMultipleFiles:
            return "Xuất riêng từng tệp theo từng nhóm (Máu, Người chơi, Vị trí/Di chuyển, Camera, Thực thể...) vào thư mục riêng trên máy."
        }
    }
}

public struct ExportResultSummary: Identifiable, Sendable {
    public let id = UUID()
    public let mode: OffsetExportMode
    public let primaryShareURL: URL
    public let generatedFiles: [URL]
    public let relativeDisplayPath: String
}

public struct ExportedPackagePayload: Codable, Sendable {
    public let exportedAt: Date
    public let generator: String
    public let metadata: BinaryMetadataModel
    public let report: AnalysisReport
    public let offsets: [DiscoveredOffset]
    public let runtime: RuntimeSnapshot
}

/// Generates both All-in-One Single Master Files and Split Multi-File Category Bundles directly inside
/// `Documents/KetQuaOffset/` so they appear immediately in the iPhone **Tệp (Files)** app and can be shared via ShareSheet.
public final class ExportService: Sendable {
    private let storageManager: StorageManagerService

    public init(storageManager: StorageManagerService) {
        self.storageManager = storageManager
    }

    /// Exports according to the user's chosen post-scan mode: Single Combined File OR Split Multiple Files.
    public func exportByMode(
        mode: OffsetExportMode,
        bundle: GameAnalysisBundle
    ) throws -> ExportResultSummary {
        switch mode {
        case .singleCombinedFile:
            let txtURL = try exportAllInOneMasterText(bundle: bundle)
            let jsonURL = try exportFullAnalysisPackageJSON(bundle: bundle)
            return ExportResultSummary(
                mode: .singleCombinedFile,
                primaryShareURL: txtURL,
                generatedFiles: [txtURL, jsonURL],
                relativeDisplayPath: "Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER -> KetQuaOffset/\(txtURL.lastPathComponent)"
            )

        case .splitMultipleFiles:
            let files = try exportSplitCategoryFiles(bundle: bundle)
            let folderURL = files.first?.deletingLastPathComponent() ?? storageManager.exportsDirectoryURL
            return ExportResultSummary(
                mode: .splitMultipleFiles,
                primaryShareURL: folderURL,
                generatedFiles: files,
                relativeDisplayPath: "Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER -> KetQuaOffset/\(folderURL.lastPathComponent)/ (\(files.count) tệp)"
            )
        }
    }

    public func exportAllInOneMasterText(bundle: GameAnalysisBundle) throws -> URL {
        let safeName = HexFormatters.sanitizeDirectoryName(bundle.record.gameName)
        let outURL = storageManager.exportsDirectoryURL.appendingPathComponent("\(safeName)_TONG_HOP_TAT_CA_OFFSET.txt")

        let meta = bundle.metadata
        var lines: [String] = []
        lines.append("================================================================================")
        lines.append("DEVNVIOS IPA ANALYZER v2.1 — TỆP TỔNG HỢP TOÀN BỘ OFFSET (ALL-IN-ONE)")
        lines.append("================================================================================")
        lines.append("Tên Game / Ứng dụng : \(meta.gameName)")
        lines.append("Bundle Identifier   : \(meta.bundleIdentifier)")
        lines.append("Phiên bản           : \(meta.bundleShortVersion) (Build \(meta.bundleBuildVersion))")
        lines.append("Module Phân tích    : \(meta.analysisModuleName) (\(meta.cpuArchitecture.uppercased()))")
        lines.append("Trạng thái Mã hóa   : \(meta.encryptionState.rawValue) (cryptid=\(meta.cryptID))")
        lines.append("Unity IL2CPP        : \(meta.isUnityIL2CPP ? "Có (\(meta.il2cppMetadataStatus))" : "Không (Native Mach-O)")")
        lines.append("ASLR Base Mặc định  : \(HexFormatters.vaHex(meta.defaultBaseAddress))")
        lines.append("SHA-256 Binary      : \(meta.binarySHA256)")
        lines.append("Tổng số Offset      : \(bundle.offsets.count) (Đã xác minh: \(bundle.report.verifiedCount) | Khả năng cao: \(bundle.report.probableCount))")
        lines.append("Chính sách Tuân thủ : Chỉ dò thông số Gameplay (Máu, Vị trí, Tốc độ, Camera...), KHÔNG dò tiền thật/thanh toán.")
        lines.append("================================================================================")
        lines.append("")

        for category in OffsetCategory.allCases {
            let group = bundle.offsets.filter { $0.category == category }
            guard !group.isEmpty else { continue }

            lines.append("--------------------------------------------------------------------------------")
            lines.append("[\(category.vietnameseTitle.uppercased())] — Số lượng: \(group.count)")
            lines.append("--------------------------------------------------------------------------------")

            for item in group {
                let statusTag = "[\(item.verificationStatus.vietnameseLabel)]"
                lines.append("• \(item.name)  \(statusTag)")
                lines.append("    Loại: \(item.dataType.vietnameseTitle) | Module: \(item.moduleName) | Nguồn: \(item.discoverySource.rawValue)")
                lines.append("    RVA / Offset : \(item.rvaFormatted)  |  Static VA : \(item.vaFormatted)  |  File Offset : \(item.fileOffsetFormatted)")
                if let sig = item.signatureHint, !sig.isEmpty {
                    lines.append("    Chữ ký / Hint: \(sig)")
                }
                if !item.notes.isEmpty {
                    lines.append("    Ghi chú      : \(item.notes)")
                }
            }
            lines.append("")
        }

        let content = lines.joined(separator: "\n")
        do {
            try Data(content.utf8).write(to: outURL, options: .atomic)
            // Also save a mirror copy inside Games/<GameName>/ so both directories have it
            let gameFolderCopy = storageManager.gamesDirectoryURL
                .appendingPathComponent(bundle.record.directoryName, isDirectory: true)
                .appendingPathComponent("\(safeName)_TONG_HOP_TAT_CA_OFFSET.txt")
            try? Data(content.utf8).write(to: gameFolderCopy, options: .atomic)
            return outURL
        } catch {
            throw AnalysisError.exportError(error.localizedDescription)
        }
    }

    public func exportSplitCategoryFiles(bundle: GameAnalysisBundle) throws -> [URL] {
        let safeName = HexFormatters.sanitizeDirectoryName(bundle.record.gameName)
        let folderURL = storageManager.exportsDirectoryURL.appendingPathComponent("\(safeName)_TachTungFile", isDirectory: true)
        try FileManager.default.createDirectory(at: folderURL, withIntermediateDirectories: true)

        var writtenURLs: [URL] = []

        // 00: Summary header file
        let summaryURL = folderURL.appendingPathComponent("00_ThongTin_NhiPhan_BaoCao.txt")
        let summaryText = """
        GAME: \(bundle.metadata.gameName) (\(bundle.metadata.bundleIdentifier) v\(bundle.metadata.bundleShortVersion))
        MODULE: \(bundle.metadata.analysisModuleName) (\(bundle.metadata.cpuArchitecture))
        MÃ HÓA: \(bundle.metadata.encryptionState.rawValue)
        IL2CPP: \(bundle.metadata.il2cppMetadataStatus)
        TỔNG OFFSET: \(bundle.offsets.count) (Đã xác minh: \(bundle.report.verifiedCount))
        """
        try Data(summaryText.utf8).write(to: summaryURL, options: .atomic)
        writtenURLs.append(summaryURL)

        // Separate file per non-empty OffsetCategory
        for (idx, category) in OffsetCategory.allCases.enumerated() {
            let group = bundle.offsets.filter { $0.category == category }
            guard !group.isEmpty else { continue }

            let fileSlug = HexFormatters.sanitizeDirectoryName(category.rawValue).replacingOccurrences(of: " ", with: "_")
            let fileName = String(format: "%02d_Offset_%@.txt", idx + 1, fileSlug)
            let fileURL = folderURL.appendingPathComponent(fileName)

            var text = "=== \(category.vietnameseTitle.uppercased()) (\(group.count) OFFSET) ===\n\n"
            for off in group {
                text += "\(off.name)\n"
                text += "  RVA/Offset: \(off.rvaFormatted) | VA: \(off.vaFormatted) | FileOff: \(off.fileOffsetFormatted) | [\(off.verificationStatus.vietnameseLabel)]\n"
                if let sig = off.signatureHint {
                    text += "  Signature : \(sig)\n"
                }
                text += "\n"
            }
            try Data(text.utf8).write(to: fileURL, options: .atomic)
            writtenURLs.append(fileURL)
        }

        // Also include structured JSON files in the split folder
        let offsetsJSON = folderURL.appendingPathComponent("offsets.json")
        let enc = JSONEncoder()
        enc.outputFormatting = [.prettyPrinted, .sortedKeys]
        enc.dateEncodingStrategy = .iso8601
        try enc.encode(bundle.offsets).write(to: offsetsJSON, options: .atomic)
        writtenURLs.append(offsetsJSON)

        let metaJSON = folderURL.appendingPathComponent("metadata.json")
        try enc.encode(bundle.metadata).write(to: metaJSON, options: .atomic)
        writtenURLs.append(metaJSON)

        return writtenURLs
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
            generator: "DEVNVIOS IPA ANALYZER v2.1",
            metadata: bundle.metadata,
            report: bundle.report,
            offsets: bundle.offsets,
            runtime: bundle.runtime
        )

        let safeName = HexFormatters.sanitizeDirectoryName(bundle.record.gameName)
        let outURL = storageManager.exportsDirectoryURL.appendingPathComponent("\(safeName)_TONG_HOP_OFFSET.json")
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
        # Báo Cáo Phân Tích DEVNVIOS — \(bundle.metadata.gameName)

        - **Bundle ID**: `\(bundle.metadata.bundleIdentifier)`
        - **Phiên bản**: `\(bundle.metadata.bundleShortVersion)` (`\(bundle.metadata.bundleBuildVersion)`)
        - **Module**: `\(bundle.metadata.analysisModuleName)` (`\(bundle.metadata.cpuArchitecture)`)
        - **Mã hóa**: `\(bundle.metadata.encryptionState.rawValue)`
        - **IL2CPP**: `\(bundle.metadata.isUnityIL2CPP ? "Có (\(bundle.metadata.il2cppMetadataStatus))" : "Không")`
        - **SHA-256**: `\(bundle.metadata.binarySHA256)`
        - **Tổng Offset**: \(bundle.report.totalOffsets) (Đã xác minh: \(bundle.report.verifiedCount), Khả năng cao: \(bundle.report.probableCount))

        ## Kết Quả Từng Phương Pháp

        | Phương pháp | Trạng thái | Phát hiện | Chi tiết |
        | --- | --- | --- | --- |
        """

        for m in bundle.report.methodSummaries {
            md += "\n| \(m.methodName) | \(m.status) | \(m.findingsCount) | \(m.details) |"
        }

        md += "\n\n## Danh Sách Offset Tiêu Biểu\n\n| Tên | Nhóm | Loại | RVA/Offset | Static VA | File Offset | Trạng thái |\n| --- | --- | --- | --- | --- | --- | --- |"
        for off in bundle.offsets.prefix(250) {
            md += "\n| `\(off.name)` | \(off.category.vietnameseTitle) | \(off.dataType.vietnameseTitle) | `\(off.rvaFormatted)` | `\(off.vaFormatted)` | `\(off.fileOffsetFormatted)` | \(off.verificationStatus.vietnameseLabel) |"
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
