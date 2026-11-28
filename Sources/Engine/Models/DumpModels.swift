import Foundation

public enum MetadataProtectionStatus: Codable, Equatable, Sendable {
    case valid(version: Int32, containerOffset: UInt64)
    case encryptedOrProtected(magicHex: String, entropy: Double, reason: String)
    case missing

    public var isValid: Bool {
        if case .valid = self { return true }
        return false
    }

    public var summaryText: String {
        switch self {
        case let .valid(version, offset):
            if offset == 0 {
                return "Plaintext IL2CPP v\(version) (0xFAB11BAF)"
            }
            return "Plaintext IL2CPP v\(version) @ 0x\(String(offset, radix: 16, uppercase: true))"
        case let .encryptedOrProtected(magicHex, entropy, reason):
            let ent = String(format: "%.2f", entropy)
            return "Protected/Encrypted [\(magicHex)] H=\(ent) — \(reason)"
        case .missing:
            return "Không tìm thấy global-metadata.dat"
        }
    }
}

public struct ProtectionDiagnostics: Codable, Equatable, Sendable {
    public var fairPlayEncrypted: Bool
    public var cryptId: UInt32
    public var cryptOffset: UInt32
    public var cryptSize: UInt32
    public var metadataStatus: MetadataProtectionStatus

    public init(
        fairPlayEncrypted: Bool = false,
        cryptId: UInt32 = 0,
        cryptOffset: UInt32 = 0,
        cryptSize: UInt32 = 0,
        metadataStatus: MetadataProtectionStatus = .missing
    ) {
        self.fairPlayEncrypted = fairPlayEncrypted
        self.cryptId = cryptId
        self.cryptOffset = cryptOffset
        self.cryptSize = cryptSize
        self.metadataStatus = metadataStatus
    }
}

public struct IPAInspectionInfo: Codable, Equatable, Sendable {
    public var fileName: String
    public var fileSizeBytes: UInt64
    public var appName: String
    public var bundleId: String
    public var bundleVersion: String
    public var executableName: String
    public var primaryMachOPath: String
    public var secondaryMachOPath: String?
    public var unityFrameworkPath: String?
    public var metadataPath: String?
    public var totalZipEntries: Int
    public var isStandaloneMode: Bool

    public init(
        fileName: String,
        fileSizeBytes: UInt64,
        appName: String,
        bundleId: String,
        bundleVersion: String,
        executableName: String,
        primaryMachOPath: String,
        secondaryMachOPath: String? = nil,
        unityFrameworkPath: String? = nil,
        metadataPath: String? = nil,
        totalZipEntries: Int = 0,
        isStandaloneMode: Bool = false
    ) {
        self.fileName = fileName
        self.fileSizeBytes = fileSizeBytes
        self.appName = appName
        self.bundleId = bundleId
        self.bundleVersion = bundleVersion
        self.executableName = executableName
        self.primaryMachOPath = primaryMachOPath
        self.secondaryMachOPath = secondaryMachOPath
        self.unityFrameworkPath = unityFrameworkPath
        self.metadataPath = metadataPath
        self.totalZipEntries = totalZipEntries
        self.isStandaloneMode = isStandaloneMode
    }
}

public struct SegmentInfo: Codable, Equatable, Sendable {
    public var name: String
    public var vmAddr: UInt64
    public var vmSize: UInt64
    public var fileOffset: UInt64
    public var fileSize: UInt64
    public var initProt: UInt32
    public var maxProt: UInt32
}

public struct SectionInfo: Codable, Equatable, Sendable {
    public var segName: String
    public var sectName: String
    public var addr: UInt64
    public var size: UInt64
    public var offset: UInt64
    public var flags: UInt32
}

public struct ObjCMethodInfo: Codable, Equatable, Sendable {
    public var name: String
    public var types: String
    public var rva: UInt64
    public var va: UInt64
    public var fileOffset: UInt64
    public var isClassMethod: Bool
}

public struct ObjCIvarInfo: Codable, Equatable, Sendable {
    public var name: String
    public var typeEncoding: String
    public var offset: Int32
}

public struct ObjCPropertyInfo: Codable, Equatable, Sendable {
    public var name: String
    public var attributes: String
}

public struct ObjCClassInfo: Codable, Equatable, Sendable {
    public var name: String
    public var superName: String
    public var methods: [ObjCMethodInfo]
    public var ivars: [ObjCIvarInfo]
    public var properties: [ObjCPropertyInfo]
}

public struct SwiftTypeInfo: Codable, Equatable, Sendable {
    public var kind: String
    public var name: String
    public var fields: [String]
    public var descriptorRVA: UInt64
}

public struct NativeSymbolEntry: Codable, Equatable, Sendable {
    public var name: String
    public var rva: UInt64
    public var va: UInt64
    public var fileOffset: UInt64
    public var source: String
}

public struct NativeBinarySnapshot: Sendable {
    public var sourceName: String
    public var architecture: String
    public var baseVM: UInt64
    public var fairPlayEncrypted: Bool
    public var cryptId: UInt32
    public var objcClasses: [ObjCClassInfo]
    public var swiftTypes: [SwiftTypeInfo]
    public var exportedSymbols: [NativeSymbolEntry]
    public var symtabSymbols: [NativeSymbolEntry]
    public var functionStartsRVA: [UInt64]
    public var xrefFunctions: [NativeSymbolEntry]
    public var filteredRealMoneyCount: Int
}

public struct Il2CppImageInfo: Codable, Equatable, Sendable {
    public var index: Int
    public var name: String
    public var typeStart: Int
    public var typeCount: Int
    public var entryPointIndex: Int32
    public var token: UInt32
    public var assemblyName: String
}

public struct Il2CppParameterInfo: Codable, Equatable, Sendable {
    public var name: String
    public var token: UInt32
    public var typeIndex: Int32
    public var typeName: String
    public var defaultValue: String?
    public var isOut: Bool
    public var isIn: Bool
    public var isRef: Bool
}

public struct Il2CppAttributeInfo: Codable, Equatable, Sendable {
    public var name: String
    public var typeIndex: Int32
    public var ctorMethodIndex: Int32
}

public struct Il2CppFieldInfo: Codable, Equatable, Sendable {
    public var index: Int
    public var name: String
    public var typeIndex: Int32
    public var typeName: String
    public var typeAttrs: UInt16
    public var token: UInt32
    public var offset: Int32
    public var isStatic: Bool
    public var isConst: Bool
    public var defaultValue: String?
    public var ctorInitValue: String?
    public var attributes: [Il2CppAttributeInfo]
}

public struct Il2CppMethodInfo: Codable, Equatable, Sendable {
    public var index: Int
    public var name: String
    public var returnTypeIndex: Int32
    public var returnTypeName: String
    public var genericParamNames: [String]
    public var parameters: [Il2CppParameterInfo]
    public var token: UInt32
    public var flags: UInt16
    public var iflags: UInt16
    public var slot: UInt16
    public var rva: UInt64
    public var va: UInt64
    public var fileOffset: UInt64
    public var arm64Semantics: String
    public var attributes: [Il2CppAttributeInfo]
    public var genericInstRVAs: [UInt64]

    public init(
        index: Int,
        name: String,
        returnTypeIndex: Int32,
        returnTypeName: String,
        genericParamNames: [String],
        parameters: [Il2CppParameterInfo],
        token: UInt32,
        flags: UInt16,
        iflags: UInt16,
        slot: UInt16,
        rva: UInt64,
        va: UInt64,
        fileOffset: UInt64,
        arm64Semantics: String,
        attributes: [Il2CppAttributeInfo],
        genericInstRVAs: [UInt64] = []
    ) {
        self.index = index
        self.name = name
        self.returnTypeIndex = returnTypeIndex
        self.returnTypeName = returnTypeName
        self.genericParamNames = genericParamNames
        self.parameters = parameters
        self.token = token
        self.flags = flags
        self.iflags = iflags
        self.slot = slot
        self.rva = rva
        self.va = va
        self.fileOffset = fileOffset
        self.arm64Semantics = arm64Semantics
        self.attributes = attributes
        self.genericInstRVAs = genericInstRVAs
    }
}

public struct Il2CppPropertyInfo: Codable, Equatable, Sendable {
    public var index: Int
    public var name: String
    public var getMethodIndex: Int32
    public var setMethodIndex: Int32
    public var getRVA: UInt64
    public var setRVA: UInt64
    public var typeName: String
    public var attrs: UInt32
    public var token: UInt32
    public var attributes: [Il2CppAttributeInfo]
}

public struct Il2CppEventInfo: Codable, Equatable, Sendable {
    public var index: Int
    public var name: String
    public var typeName: String
    public var addMethodIndex: Int32
    public var removeMethodIndex: Int32
    public var raiseMethodIndex: Int32
    public var token: UInt32
    public var attributes: [Il2CppAttributeInfo]
}

public struct Il2CppTypeInfo: Codable, Equatable, Sendable {
    public var typeDefIndex: Int
    public var imageIndex: Int
    public var assemblyName: String
    public var namespace: String
    public var name: String
    public var fullName: String
    public var genericParamNames: [String]
    public var flags: UInt32
    public var token: UInt32
    public var parentTypeIndex: Int32
    public var parentName: String?
    public var declaringTypeIndex: Int32
    public var declaringTypeName: String?
    public var nestedTypeNames: [String]
    public var interfaceTypeIndices: [Int32]
    public var interfaceNames: [String]
    public var isValueType: Bool
    public var isEnum: Bool
    public var isInterface: Bool
    public var isAbstract: Bool
    public var isSealed: Bool
    public var fields: [Il2CppFieldInfo]
    public var methods: [Il2CppMethodInfo]
    public var properties: [Il2CppPropertyInfo]
    public var events: [Il2CppEventInfo]
    public var attributes: [Il2CppAttributeInfo]
}

public struct Il2CppStringLiteral: Codable, Equatable, Sendable {
    public var index: Int
    public var value: String
    public var address: UInt64
}

public struct Il2CppTypeMetaEntry: Sendable {
    public var typeEnum: UInt8
    public var attrs: UInt16
    public var dataIndex: Int32
    public var elementEnum: UInt8
    public var elementDataIndex: Int32
    public var arrayRank: UInt8
    public var genericBaseTypeIndex: Int32
    public var genericArgTypeIndices: [Int32]
    public var isByRef: Bool
}

public struct CodeGenModuleInfo: Sendable {
    public var imageName: String
    public var methodRVAs: [UInt64]
    public var methodTokens: [UInt32]
    public var codeGenModuleRVA: UInt64
}

public struct Il2CppRegistrationResult: Sendable {
    public var codeRegistrationRVA: UInt64
    public var metadataRegistrationRVA: UInt64
    public var strategyUsed: String
    public var codeGenModules: [String: CodeGenModuleInfo]
    public var legacyMethodRVAs: [UInt64]
    public var fieldOffsetsByType: [[Int32]]
    public var il2cppTypes: [Il2CppTypeMetaEntry]
    public var stringLiteralAddresses: [UInt64]
    public var ctorFieldInits: [Int: [Int32: String]]
    public var genericMethodRVAsByMethodDef: [Int: [UInt64]]

    public init(
        codeRegistrationRVA: UInt64,
        metadataRegistrationRVA: UInt64,
        strategyUsed: String,
        codeGenModules: [String: CodeGenModuleInfo],
        legacyMethodRVAs: [UInt64],
        fieldOffsetsByType: [[Int32]],
        il2cppTypes: [Il2CppTypeMetaEntry],
        stringLiteralAddresses: [UInt64],
        ctorFieldInits: [Int: [Int32: String]],
        genericMethodRVAsByMethodDef: [Int: [UInt64]] = [:]
    ) {
        self.codeRegistrationRVA = codeRegistrationRVA
        self.metadataRegistrationRVA = metadataRegistrationRVA
        self.strategyUsed = strategyUsed
        self.codeGenModules = codeGenModules
        self.legacyMethodRVAs = legacyMethodRVAs
        self.fieldOffsetsByType = fieldOffsetsByType
        self.il2cppTypes = il2cppTypes
        self.stringLiteralAddresses = stringLiteralAddresses
        self.ctorFieldInits = ctorFieldInits
        self.genericMethodRVAsByMethodDef = genericMethodRVAsByMethodDef
    }
}

public enum ExplorerItemKind: String, Codable, CaseIterable, Sendable {
    case method = "Method"
    case field = "Field"
    case property = "Property"
    case event = "Event"
    case type = "Type"
    case native = "Obj-C/Swift"
}

public struct ExplorerRow: Codable, Identifiable, Equatable, Sendable {
    public var id: Int
    public var kind: ExplorerItemKind
    public var assembly: String
    public var namespace: String
    public var className: String
    public var name: String
    public var signature: String
    public var dataType: String
    public var rvaHex: String
    public var vaHex: String
    public var fileOffsetHex: String
    public var fieldOffsetHex: String
    public var tokenHex: String
    public var typeDefIndex: Int
    public var extraNote: String
}

public struct DumpStats: Codable, Equatable, Sendable {
    public var assembliesCount: Int
    public var typesCount: Int
    public var methodsCount: Int
    public var fieldsCount: Int
    public var propertiesCount: Int
    public var eventsCount: Int
    public var stringLiteralsCount: Int
    public var dummyDllsCount: Int
    public var nativeSymbolsCount: Int
    public var filteredRealMoneyCount: Int
    public var elapsedSeconds: Double
}

public struct OutputFileItem: Codable, Identifiable, Equatable, Sendable {
    public var id: String { relativePath }
    public var name: String
    public var relativePath: String
    public var sizeBytes: UInt64
    public var isDirectory: Bool
}

public struct DumpSessionRecord: Codable, Identifiable, Equatable, Sendable {
    public var id: String
    public var title: String
    public var bundleId: String
    public var createdAt: Date
    public var outputDirectoryName: String
    public var diagnostics: ProtectionDiagnostics
    public var stats: DumpStats
    public var files: [OutputFileItem]
}

public enum DumpPhase: String, Codable, Sendable {
    case idle = "Sẵn sàng"
    case inspecting = "Phase 1 · Quét IPA & Kiểm tra an toàn"
    case analyzing = "Phase 2 · 2 Workers Phân tích Metadata & Mach-O"
    case generating = "Phase 3 · Xuất dump.cs, JSON, il2cpp.h & Dummy DLLs"
    case packaging = "Phase 4 · Hoàn tất & Đóng gói ZIP"
    case completed = "Hoàn tất"
    case failed = "Thất bại"
}

public struct DumpProgressUpdate: Sendable {
    public var phase: DumpPhase
    public var progress: Double
    public var message: String
}
