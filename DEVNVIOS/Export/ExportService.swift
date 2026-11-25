import Foundation

public enum OffsetExportMode: String, CaseIterable, Identifiable, Sendable {
    case singleCombinedFile = "single"
    case splitMultipleFiles = "split"

    public var id: String { rawValue }

    public var vietnameseTitle: String {
        switch self {
        case .singleCombinedFile:
            return "Gom Toàn Bộ Vào 1 Tệp Duy Nhất"
        case .splitMultipleFiles:
            return "Tách Riêng Ra Từng Tệp Theo Nhóm"
        }
    }

    public var vietnameseSubtitle: String {
        switch self {
        case .singleCombinedFile:
            return "Lưu ngay tệp mang tên Game/Ứng dụng (ví dụ: TenGame_OFFSET.txt & .json) chứa đầy đủ RVA, VA, File Offset, Field Offset trong 1 tệp duy nhất."
        case .splitMultipleFiles:
            return "Tạo thư mục mang tên Game chứa riêng từng tệp cho từng nhóm chức năng (Máu, Người chơi, Vị trí, Camera, Hệ thống...)."
        }
    }
}

public struct SavedOffsetFileItem: Identifiable, Equatable, Sendable {
    public var id: String { fileURL.path }
    public let displayTitle: String
    public let subtitle: String
    public let fileURL: URL
    public let fileSize: Int64
    public let modifiedAt: Date
    public let isDirectory: Bool
    public let childFiles: [URL]
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
    public let websites: [String]
    public let metadata: BinaryMetadataModel
    public let report: AnalysisReport
    public let offsets: [DiscoveredOffset]
    public let runtime: RuntimeSnapshot
}

/// Automatically saves clearly named per-game offset files (`<TenGame>_OFFSET.txt` & `<TenGame>_TachTungFile/`)
/// directly into the iPhone's `Documents/` & `Documents/KetQuaOffset/` directories so users can tap them
/// inside the app OR open the iOS **Tệp (Files)** app to view/copy/share every offset immediately.
public final class ExportService: Sendable {
    private let storageManager: StorageManagerService

    public init(storageManager: StorageManagerService) {
        self.storageManager = storageManager
    }

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
                relativeDisplayPath: "Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER -> \(txtURL.lastPathComponent)"
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

    /// Lists all saved game offset files & split folders on the iPhone so the user can tap directly on a game file to inspect its offsets.
    public func listSavedOffsetFiles() -> [SavedOffsetFileItem] {
        var results: [SavedOffsetFileItem] = []

        // Check both KetQuaOffset/ and top-level Documents/ *_OFFSET.txt files
        let exportItems = (try? FileManager.default.contentsOfDirectory(
            at: storageManager.exportsDirectoryURL,
            includingPropertiesForKeys: [.isDirectoryKey, .fileSizeKey, .contentModificationDateKey],
            options: [.skipsHiddenFiles]
        )) ?? []

        for url in exportItems {
            guard let vals = try? url.resourceValues(forKeys: [.isDirectoryKey, .fileSizeKey, .contentModificationDateKey]) else {
                continue
            }
            let modDate = vals.contentModificationDate ?? Date()
            if vals.isDirectory == true {
                let children = ((try? FileManager.default.contentsOfDirectory(
                    at: url,
                    includingPropertiesForKeys: [.fileSizeKey],
                    options: [.skipsHiddenFiles]
                )) ?? []).sorted { $0.lastPathComponent < $1.lastPathComponent }
                let totalBytes = children.reduce(Int64(0)) { acc, child in
                    let sz = (try? child.resourceValues(forKeys: [.fileSizeKey]).fileSize) ?? 0
                    return acc + Int64(sz)
                }
                results.append(
                    SavedOffsetFileItem(
                        displayTitle: url.lastPathComponent,
                        subtitle: "Thư mục tách riêng \(children.count) tệp • \(HexFormatters.formatBytes(totalBytes))",
                        fileURL: url,
                        fileSize: totalBytes,
                        modifiedAt: modDate,
                        isDirectory: true,
                        childFiles: children
                    )
                )
            } else {
                let ext = url.pathExtension.lowercased()
                guard ext == "txt" || ext == "json" || ext == "md" else { continue }
                let size = Int64(vals.fileSize ?? 0)
                results.append(
                    SavedOffsetFileItem(
                        displayTitle: url.lastPathComponent,
                        subtitle: "Tệp Offset tổng hợp • \(HexFormatters.formatBytes(size))",
                        fileURL: url,
                        fileSize: size,
                        modifiedAt: modDate,
                        isDirectory: false,
                        childFiles: []
                    )
                )
            }
        }

        results.sort { $0.modifiedAt > $1.modifiedAt }
        return results
    }

    public func exportAllInOneMasterText(bundle: GameAnalysisBundle) throws -> URL {
        let safeName = HexFormatters.sanitizeDirectoryName(bundle.record.gameName)
        let fileName = "\(safeName)_OFFSET.txt"
        let outURL = storageManager.exportsDirectoryURL.appendingPathComponent(fileName)
        let rootMirrorURL = storageManager.rootDirectoryURL.appendingPathComponent(fileName)

        let meta = bundle.metadata
        var lines: [String] = []
        lines.append("================================================================================")
        lines.append("DEVNVIOS IPA ANALYZER v3.0 — HỆ THỐNG DÒ OFFSET C++17 & SWIFT CHUYÊN SÂU")
        lines.append("Website chính thức  : https://devnvios.io.vn  |  https://iosmods.xyz")
        lines.append("================================================================================")
        lines.append("Tên Game / Ứng dụng : \(meta.gameName)")
        lines.append("Bundle Identifier   : \(meta.bundleIdentifier)")
        lines.append("Phiên bản           : \(meta.bundleShortVersion) (Build \(meta.bundleBuildVersion))")
        lines.append("Module Thực thi     : \(meta.analysisModuleName) (\(meta.cpuArchitecture.uppercased()))")
        lines.append("Trạng thái Mã hóa   : \(meta.encryptionState.rawValue) (cryptid=\(meta.cryptID))")
        lines.append("Unity IL2CPP        : \(meta.isUnityIL2CPP ? "Có (\(meta.il2cppMetadataStatus))" : "Không (Native Swift/ObjC/C++)")")
        lines.append("ASLR Base Mặc định  : \(HexFormatters.vaHex(meta.defaultBaseAddress))")
        lines.append("SHA-256 Binary      : \(meta.binarySHA256)")
        lines.append("Tổng số Offset Thực : \(bundle.offsets.count) (Chuẩn 100%: \(bundle.report.verifiedCount) | Độ chính xác cao: \(bundle.report.probableCount))")
        lines.append("Chính sách Pháp lý  : Không dò ứng dụng Ngân hàng, Ví điện tử và giá trị tiền thật.")
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
                    lines.append("    Chi tiết     : \(item.notes)")
                }
            }
            lines.append("")
        }

        let content = lines.joined(separator: "\n")
        let utf8Data = Data(content.utf8)
        do {
            try utf8Data.write(to: outURL, options: .atomic)
            // Also write directly at root of Documents/ (`Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER -> <TenGame>_OFFSET.txt`)
            try? utf8Data.write(to: rootMirrorURL, options: .atomic)
            let gameFolderCopy = storageManager.gamesDirectoryURL
                .appendingPathComponent(bundle.record.directoryName, isDirectory: true)
                .appendingPathComponent(fileName)
            try? utf8Data.write(to: gameFolderCopy, options: .atomic)
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

        let summaryURL = folderURL.appendingPathComponent("00_\(safeName)_ThongTinChung.txt")
        let summaryText = """
        DEVNVIOS IPA ANALYZER v3.0 (devnvios.io.vn | iosmods.xyz)
        =========================================================
        TÊN GAME / APP : \(bundle.metadata.gameName)
        BUNDLE ID      : \(bundle.metadata.bundleIdentifier) (v\(bundle.metadata.bundleShortVersion))
        MODULE         : \(bundle.metadata.analysisModuleName) (\(bundle.metadata.cpuArchitecture))
        MÃ HÓA         : \(bundle.metadata.encryptionState.rawValue)
        IL2CPP         : \(bundle.metadata.il2cppMetadataStatus)
        TỔNG OFFSET    : \(bundle.offsets.count) (Chuẩn 100%: \(bundle.report.verifiedCount))
        """
        try Data(summaryText.utf8).write(to: summaryURL, options: .atomic)
        writtenURLs.append(summaryURL)

        for (idx, category) in OffsetCategory.allCases.enumerated() {
            let group = bundle.offsets.filter { $0.category == category }
            guard !group.isEmpty else { continue }

            let fileSlug = HexFormatters.sanitizeDirectoryName(category.rawValue).replacingOccurrences(of: " ", with: "_")
            let fileName = String(format: "%02d_%@_%@.txt", idx + 1, safeName, fileSlug)
            let fileURL = folderURL.appendingPathComponent(fileName)

            var text = "=== \(bundle.metadata.gameName.uppercased()) — \(category.vietnameseTitle.uppercased()) (\(group.count) OFFSET) ===\n\n"
            for off in group {
                text += "\(off.name)\n"
                text += "  RVA/Offset: \(off.rvaFormatted) | VA: \(off.vaFormatted) | FileOff: \(off.fileOffsetFormatted) | [\(off.verificationStatus.vietnameseLabel)]\n"
                if let sig = off.signatureHint {
                    text += "  Chữ ký    : \(sig)\n"
                }
                text += "\n"
            }
            try Data(text.utf8).write(to: fileURL, options: .atomic)
            writtenURLs.append(fileURL)
        }

        let offsetsJSON = folderURL.appendingPathComponent("\(safeName)_offsets.json")
        let enc = JSONEncoder()
        enc.outputFormatting = [.prettyPrinted, .sortedKeys]
        enc.dateEncodingStrategy = .iso8601
        try enc.encode(bundle.offsets).write(to: offsetsJSON, options: .atomic)
        writtenURLs.append(offsetsJSON)

        let metaJSON = folderURL.appendingPathComponent("\(safeName)_metadata.json")
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
            generator: "DEVNVIOS IPA ANALYZER v3.0 (C++17 & Swift Core)",
            websites: ["https://devnvios.io.vn", "https://iosmods.xyz"],
            metadata: bundle.metadata,
            report: bundle.report,
            offsets: bundle.offsets,
            runtime: bundle.runtime
        )

        let safeName = HexFormatters.sanitizeDirectoryName(bundle.record.gameName)
        let outURL = storageManager.exportsDirectoryURL.appendingPathComponent("\(safeName)_OFFSET.json")
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
        # Báo Cáo Phân Tích DEVNVIOS v3.0 — \(bundle.metadata.gameName)

        - **Website**: https://devnvios.io.vn | https://iosmods.xyz
        - **Bundle ID**: `\(bundle.metadata.bundleIdentifier)`
        - **Phiên bản**: `\(bundle.metadata.bundleShortVersion)` (`\(bundle.metadata.bundleBuildVersion)`)
        - **Module**: `\(bundle.metadata.analysisModuleName)` (`\(bundle.metadata.cpuArchitecture)`)
        - **Mã hóa**: `\(bundle.metadata.encryptionState.rawValue)`
        - **IL2CPP**: `\(bundle.metadata.isUnityIL2CPP ? "Có (\(bundle.metadata.il2cppMetadataStatus))" : "Không")`
        - **SHA-256**: `\(bundle.metadata.binarySHA256)`
        - **Tổng Offset**: \(bundle.report.totalOffsets) (Chuẩn 100%: \(bundle.report.verifiedCount), Độ chính xác cao: \(bundle.report.probableCount))

        ## Kết Quả 9 Phương Pháp

        | Phương pháp | Trạng thái | Phát hiện | Chi tiết |
        | --- | --- | --- | --- |
        """

        for m in bundle.report.methodSummaries {
            md += "\n| \(m.methodName) | \(m.status) | \(m.findingsCount) | \(m.details) |"
        }

        md += "\n\n## Danh Sách Offset Thực Tế\n\n| Tên | Nhóm | Loại | RVA/Offset | Static VA | File Offset | Trạng thái |\n| --- | --- | --- | --- | --- | --- | --- |"
        for off in bundle.offsets.prefix(300) {
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
