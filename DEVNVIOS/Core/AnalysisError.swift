import Foundation

public enum AnalysisError: LocalizedError, Equatable, Sendable {
    case fileNotFound(String)
    case securityScopeDenied(String)
    case invalidIPAArchive(String)
    case corruptedArchive(String)
    case missingAppBundle
    case missingInfoPlist
    case invalidInfoPlist(String)
    case missingExecutable(String)
    case invalidMachOBinary(String)
    case unsupportedArchitecture(String)
    case metadataUnavailable(String)
    case insufficientStorage(requiredBytes: Int64, availableBytes: Int64)
    case cancelled
    case databaseError(String)
    case exportError(String)
    case runtimeSnapshotInvalid(String)

    public var errorDescription: String? {
        switch self {
        case .fileNotFound(let path):
            return "File not found: \(path)"
        case .securityScopeDenied(let name):
            return "iOS denied read access to selected file: \(name)"
        case .invalidIPAArchive(let reason):
            return "Invalid IPA archive: \(reason)"
        case .corruptedArchive(let reason):
            return "Corrupted ZIP/IPA data stream: \(reason)"
        case .missingAppBundle:
            return "No Payload/*.app bundle found inside IPA archive."
        case .missingInfoPlist:
            return "Info.plist is missing from application bundle."
        case .invalidInfoPlist(let reason):
            return "Unable to parse Info.plist: \(reason)"
        case .missingExecutable(let name):
            return "Main binary executable '\(name)' was not found inside bundle."
        case .invalidMachOBinary(let reason):
            return "Invalid Mach-O binary format: \(reason)"
        case .unsupportedArchitecture(let arch):
            return "Unsupported CPU architecture: \(arch)"
        case .metadataUnavailable(let reason):
            return "IL2CPP metadata unavailable: \(reason)"
        case .insufficientStorage(let requiredBytes, let availableBytes):
            return "Insufficient storage space. Required: \(HexFormatters.formatBytes(requiredBytes)), available: \(HexFormatters.formatBytes(availableBytes))."
        case .cancelled:
            return "Analysis operation was cancelled by user."
        case .databaseError(let reason):
            return "Offset database error: \(reason)"
        case .exportError(let reason):
            return "Export failed: \(reason)"
        case .runtimeSnapshotInvalid(let reason):
            return "Invalid runtime inspection snapshot: \(reason)"
        }
    }
}
