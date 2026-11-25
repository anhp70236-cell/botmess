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

/// Imports `.ipa` files from iOS Files (including large multi-GB archives via streaming chunked I/O)
/// and inspects `Payload/*.app/Info.plist` without unpacking unnecessary asset files.
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

        guard FileManager.default.fileExists(atPath: sourceURL.path) else {
            throw AnalysisError.fileNotFound(sourceURL.path)
        }

        let attrs = try FileManager.default.attributesOfItem(atPath: sourceURL.path)
        let fileSize = (attrs[.size] as? NSNumber)?.int64Value ?? 0
        guard fileSize >= 22 else {
            throw AnalysisError.invalidIPAArchive("Selected file is empty or not a valid IPA archive.")
        }

        try storageManager.verifyCapacityForImport(fileSizeBytes: fileSize)

        let rawBaseName = sourceURL.deletingPathExtension().lastPathComponent
        let cleanStem = HexFormatters.sanitizeDirectoryName(rawBaseName)
        let uniqueFileName = "\(cleanStem)_\(UUID().uuidString.prefix(8)).ipa"
        let destinationURL = storageManager.storedIPAURL(for: uniqueFileName)

        // Stream-copy in 1 MB chunks so 3 GB+ IPAs never spike memory
        try streamCopyFile(from: sourceURL, to: destinationURL, totalBytes: fileSize, progress: copyProgress)

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

        // Locate Payload/<App>.app/Info.plist
        guard let infoPlistEntry = entries.first(where: { entry in
            !entry.isDirectory
                && entry.path.hasPrefix("Payload/")
                && entry.path.hasSuffix(".app/Info.plist")
                && entry.path.components(separatedBy: "/").count == 3
        }) else {
            if entries.contains(where: { $0.path.hasPrefix("Payload/") && $0.path.contains(".app/") }) {
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
            throw AnalysisError.invalidInfoPlist("Root object is not a dictionary.")
        }

        let executableName = (plistObj["CFBundleExecutable"] as? String)
            ?? URL(fileURLWithPath: String(appBundlePrefix.dropLast())).deletingPathExtension().lastPathComponent

        let displayName = preferredGameName
            ?? (plistObj["CFBundleDisplayName"] as? String)
            ?? (plistObj["CFBundleName"] as? String)
            ?? fallbackName
            ?? executableName

        let bundleID = (plistObj["CFBundleIdentifier"] as? String) ?? "unknown.bundle.id"
        let shortVersion = (plistObj["CFBundleShortVersionString"] as? String) ?? "1.0"
        let buildVersion = (plistObj["CFBundleVersion"] as? String) ?? "1"

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

        let chunkSize = 1_048_576 // 1 MB
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
