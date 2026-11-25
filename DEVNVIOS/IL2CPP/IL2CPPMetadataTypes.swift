import Foundation

public enum IL2CPPMetadataAvailability: String, Codable, Sendable {
    case validStandard = "Hợp lệ (Chuẩn IL2CPP)"
    case encryptedOrObfuscated = "Đã mã hóa / Bảo vệ Header"
    case truncatedOrIncomplete = "Không đầy đủ / Cắt cụt"
    case notPresent = "Không dùng Unity IL2CPP"
}

public struct IL2CPPImageRecord: Sendable, Equatable {
    public let imageIndex: Int
    public let assemblyName: String
    public let typeStart: Int32
    public let typeCount: UInt32
    public let orderedMethodIndices: [Int]
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
    public let images: [IL2CPPImageRecord]
    public let types: [IL2CPPTypeRecord]
    public let methods: [IL2CPPMethodRecord]
    public let fields: [IL2CPPFieldRecord]
    public let relevantStringLiterals: [IL2CPPStringLiteralRecord]
    public let diagnostics: [String]

    public init(
        availability: IL2CPPMetadataAvailability,
        metadataVersion: Int?,
        images: [IL2CPPImageRecord] = [],
        types: [IL2CPPTypeRecord],
        methods: [IL2CPPMethodRecord],
        fields: [IL2CPPFieldRecord],
        relevantStringLiterals: [IL2CPPStringLiteralRecord],
        diagnostics: [String]
    ) {
        self.availability = availability
        self.metadataVersion = metadataVersion
        self.images = images
        self.types = types
        self.methods = methods
        self.fields = fields
        self.relevantStringLiterals = relevantStringLiterals
        self.diagnostics = diagnostics
    }
}
