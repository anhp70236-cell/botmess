import Foundation

public struct ImportedIPAInspection: Sendable {
    public let storedIPAFileName: String
    public let storedIPAURL: URL
    public let ipaFileSize: Int64
    public let gameName: String
    public let directoryName: String
    public let bundleIdentifier: String
    public let bundleShortVersion: String
    public let bundleBuildVersion: String
    public let executableName: String
    public let appBundlePrefix: String
    public let hasUnityFramework: Bool
    public let hasGlobalMetadata: Bool
}

/// Imports `.ipa` / `.tipa` / `.zip` files from iOS Files (`UIDocumentPickerViewController` copy, `Documents/Inbox`,
/// or drop-in `Bo_File_IPA_Vao_Day/`), coordinating FileProvider reads safely on all sideloaded/signed iPhones.
public final class IPAImportService: Sendable {
    private let storageManager: StorageManagerService

    public init(storageManager: StorageManagerService) {
        self.storageManager = storageManager
    }

    public func importIPA(
        from sourceURL: URL,
        preferredGameName: String? = nil,
        copyProgress: ((Double) -> Void)? = nil
    ) throws -> ImportedIPAInspection {
        let didStartScope = sourceURL.startAccessingSecurityScopedResource()
        defer {
            if didStartScope {
                sourceURL.stopAccessingSecurityScopedResource()
            }
        }

        let rawBaseName = Self.cleanUploadedStem(sourceURL.deletingPathExtension().lastPathComponent)
        let cleanStem = HexFormatters.sanitizeDirectoryName(rawBaseName)
        let uniqueFileName = "\(cleanStem)_\(UUID().uuidString.prefix(8)).ipa"
        let destinationURL = storageManager.storedIPAURL(for: uniqueFileName)

        // Materialize & stream-copy using direct handle first, falling back to NSFileCoordinator for iCloud/FileProvider URLs
        var copiedSuccess = false
        if FileManager.default.fileExists(atPath: sourceURL.path) {
            let attrs = try? FileManager.default.attributesOfItem(atPath: sourceURL.path)
            let fileSize = (attrs?[.size] as? NSNumber)?.int64Value ?? 0
            if fileSize >= 22 {
                try storageManager.verifyCapacityForImport(fileSizeBytes: fileSize)
                try streamCopyFile(from: sourceURL, to: destinationURL, totalBytes: fileSize, progress: copyProgress)
                copiedSuccess = true
            }
        }

        if !copiedSuccess {
            var coordError: NSError?
            var innerThrow: Error?
            let coordinator = NSFileCoordinator()
            coordinator.coordinate(readingItemAt: sourceURL, options: .withoutChanges, error: &coordError) { coordinatedURL in
                do {
                    let attrs = try FileManager.default.attributesOfItem(atPath: coordinatedURL.path)
                    let fileSize = (attrs[.size] as? NSNumber)?.int64Value ?? 0
                    guard fileSize >= 22 else {
                        throw AnalysisError.invalidIPAArchive("Tệp rỗng hoặc nhỏ hơn kích thước tối thiểu 22 bytes.")
                    }
                    try storageManager.verifyCapacityForImport(fileSizeBytes: fileSize)
                    try streamCopyFile(from: coordinatedURL, to: destinationURL, totalBytes: fileSize, progress: copyProgress)
                    copiedSuccess = true
                } catch {
                    innerThrow = error
                }
            }
            if let innerThrow { throw innerThrow }
            if !copiedSuccess {
                throw AnalysisError.fileNotFound(sourceURL.lastPathComponent)
            }
        }

        // Clean up temporary picker staging copy if source came from Staging or Inbox
        let sourcePath = sourceURL.path
        if sourcePath.contains("DEVNVIOS_PickedStaging") || sourcePath.contains("/Inbox/") {
            try? FileManager.default.removeItem(at: sourceURL)
        }

        do {
            return try inspectStoredIPA(
                at: destinationURL,
                storedFileName: uniqueFileName,
                preferredGameName: preferredGameName,
                fallbackName: rawBaseName
            )
        } catch {
            try? FileManager.default.removeItem(at: destinationURL)
            throw error
        }
    }

    public func inspectStoredIPA(
        at ipaURL: URL,
        storedFileName: String,
        preferredGameName: String? = nil,
        fallbackName: String? = nil
    ) throws -> ImportedIPAInspection {
        let reader = try IPAZipArchiveReader(archiveURL: ipaURL)
        let entries = try reader.listEntries()

        // Locate Payload/<App>.app/Info.plist (case-insensitive check for Payload/)
        guard let infoPlistEntry = entries.first(where: { entry in
            guard !entry.isDirectory else { return false }
            let parts = entry.path.components(separatedBy: "/")
            return parts.count == 3
                && parts[0].lowercased() == "payload"
                && parts[1].lowercased().hasSuffix(".app")
                && parts[2] == "Info.plist"
        }) else {
            if entries.contains(where: { $0.path.lowercased().hasPrefix("payload/") && $0.path.lowercased().contains(".app/") }) {
                throw AnalysisError.missingInfoPlist
            }
            throw AnalysisError.missingAppBundle
        }

        let appBundlePrefix = String(infoPlistEntry.path.dropLast("Info.plist".count))
        let infoData = try reader.extractEntryToData(infoPlistEntry)

        guard let plistObj = try PropertyListSerialization.propertyList(
            from: infoData,
            options: [],
            format: nil
        ) as? [String: Any] else {
            throw AnalysisError.invalidInfoPlist("Cấu trúc gốc Info.plist không phải kiểu từ điển.")
        }

        let executableName = (plistObj["CFBundleExecutable"] as? String)
            ?? URL(fileURLWithPath: String(appBundlePrefix.dropLast())).deletingPathExtension().lastPathComponent

        let rawDisplay = preferredGameName
            ?? (plistObj["CFBundleDisplayName"] as? String)
            ?? (plistObj["CFBundleName"] as? String)
            ?? fallbackName
            ?? executableName
        let displayName = rawDisplay.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? executableName : rawDisplay

        let bundleID = (plistObj["CFBundleIdentifier"] as? String) ?? "unknown.bundle.id"
        let shortVersion = (plistObj["CFBundleShortVersionString"] as? String) ?? "1.0"
        let buildVersion = (plistObj["CFBundleVersion"] as? String) ?? "1"

        if SafetyPolicyFilter.isProhibitedFinancialApp(bundleID: bundleID, displayName: displayName) {
            throw AnalysisError.prohibitedFinancialApp(displayName)
        }

        let mainExecPath = "\(appBundlePrefix)\(executableName)"
        guard entries.contains(where: { $0.path == mainExecPath && !$0.isDirectory }) else {
            throw AnalysisError.missingExecutable(executableName)
        }

        let unityPath = "\(appBundlePrefix)Frameworks/UnityFramework.framework/UnityFramework"
        let metadataPath = "\(appBundlePrefix)Data/Managed/Metadata/global-metadata.dat"

        let hasUnity = entries.contains { $0.path == unityPath && !$0.isDirectory }
        let hasMetadata = entries.contains { $0.path == metadataPath && !$0.isDirectory }

        return ImportedIPAInspection(
            storedIPAFileName: storedFileName,
            storedIPAURL: ipaURL,
            ipaFileSize: Int64(reader.archiveFileSize),
            gameName: displayName,
            directoryName: HexFormatters.sanitizeDirectoryName(displayName),
            bundleIdentifier: bundleID,
            bundleShortVersion: shortVersion,
            bundleBuildVersion: buildVersion,
            executableName: executableName,
            appBundlePrefix: appBundlePrefix,
            hasUnityFramework: hasUnity,
            hasGlobalMetadata: hasMetadata
        )
    }

    private static func cleanUploadedStem(_ stem: String) -> String {
        // Strip staging UUID prefix if present (e.g. "A1B2C3_MyGame" -> "MyGame")
        let parts = stem.split(separator: "_", maxSplits: 1)
        if parts.count == 2 && parts[0].count == 6 {
            return String(parts[1])
        }
        return stem
    }

    private func streamCopyFile(
        from sourceURL: URL,
        to destinationURL: URL,
        totalBytes: Int64,
        progress: ((Double) -> Void)?
    ) throws {
        if sourceURL.standardizedFileURL == destinationURL.standardizedFileURL {
            progress?(1.0)
            return
        }
        if FileManager.default.fileExists(atPath: destinationURL.path) {
            try FileManager.default.removeItem(at: destinationURL)
        }
        FileManager.default.createFile(atPath: destinationURL.path, contents: nil)

        let inHandle = try FileHandle(forReadingFrom: sourceURL)
        defer { try? inHandle.close() }
        let outHandle = try FileHandle(forWritingTo: destinationURL)
        defer { try? outHandle.close() }

        let chunkSize = 1_048_576
        var copied: Int64 = 0
        let denom = Double(max(totalBytes, 1))

        while true {
            if Task.isCancelled {
                try? outHandle.close()
                try? FileManager.default.removeItem(at: destinationURL)
                throw AnalysisError.cancelled
            }
            guard let chunk = try inHandle.read(upToCount: chunkSize), !chunk.isEmpty else {
                break
            }
            try outHandle.write(contentsOf: chunk)
            copied += Int64(chunk.count)
            progress?(min(1.0, Double(copied) / denom))
        }
    }
}
