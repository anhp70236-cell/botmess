import Foundation

public enum IL2CPPMetadataAvailability: String, Codable, Sendable {
    case validStandard = "Valid IL2CPP Metadata"
    case encryptedOrObfuscated = "Encrypted / Non-Standard Header"
    case truncatedOrIncomplete = "Incomplete / Truncated Metadata"
    case notPresent = "Not Unity IL2CPP"
}

public struct IL2CPPTypeRecord: Sendable, Equatable {
    public let typeIndex: Int
    public let namespaceName: String
    public let className: String
    public let token: UInt32
    public let methodStart: Int32
    public let methodCount: UInt16
    public let fieldStart: Int32
    public let fieldCount: UInt16

    public var fullName: String {
        namespaceName.isEmpty ? className : "\(namespaceName).\(className)"
    }
}

public struct IL2CPPMethodRecord: Sendable, Equatable {
    public let methodIndex: Int
    public let declaringTypeIndex: Int
    public let declaringClassFullName: String
    public let methodName: String
    public let token: UInt32
    public let parameterCount: UInt16
}

public struct IL2CPPFieldRecord: Sendable, Equatable {
    public let fieldIndex: Int
    public let declaringClassFullName: String
    public let fieldName: String
    public let fieldOrdinalInClass: Int
    public let estimatedInstanceOffset: UInt32
    public let token: UInt32
}

public struct IL2CPPStringLiteralRecord: Sendable, Equatable {
    public let index: Int
    public let value: String
}

public struct IL2CPPAnalysisResult: Sendable {
    public let availability: IL2CPPMetadataAvailability
    public let metadataVersion: Int?
    public let types: [IL2CPPTypeRecord]
    public let methods: [IL2CPPMethodRecord]
    public let fields: [IL2CPPFieldRecord]
    public let relevantStringLiterals: [IL2CPPStringLiteralRecord]
    public let diagnostics: [String]
}
