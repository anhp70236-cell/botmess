import Foundation

public enum SymbolStripStatus: String, Codable, Sendable {
    case fullSymbols = "Full Symbols"
    case partiallyStripped = "Partially Stripped"
    case stripped = "Stripped"
}

public enum BinaryEncryptionState: String, Codable, Sendable {
    case unencrypted = "Unencrypted (cryptid=0)"
    case fairPlayEncrypted = "Encrypted (FairPlay cryptid!=0)"
    case partiallyProtected = "Partially Encrypted / Obfuscated Metadata"
}

public struct MachOSegmentSummary: Codable, Equatable, Hashable, Sendable {
    public let name: String
    public let vmAddress: UInt64
    public let vmSize: UInt64
    public let fileOffset: UInt64
    public let fileSize: UInt64
    public let maxProt: Int32
    public let initProt: Int32
    public let sectionNames: [String]

    public init(
        name: String,
        vmAddress: UInt64,
        vmSize: UInt64,
        fileOffset: UInt64,
        fileSize: UInt64,
        maxProt: Int32,
        initProt: Int32,
        sectionNames: [String]
    ) {
        self.name = name
        self.vmAddress = vmAddress
        self.vmSize = vmSize
        self.fileOffset = fileOffset
        self.fileSize = fileSize
        self.maxProt = maxProt
        self.initProt = initProt
        self.sectionNames = sectionNames
    }
}

public struct BinaryMetadataModel: Codable, Equatable, Sendable {
    public let gameName: String
    public let bundleIdentifier: String
    public let bundleShortVersion: String
    public let bundleBuildVersion: String
    public let primaryModuleName: String
    public let analysisModuleName: String
    public let isUnityIL2CPP: Bool
    public let il2cppMetadataVersion: Int?
    public let il2cppMetadataStatus: String
    public let cpuArchitecture: String
    public let isARM64: Bool
    public let isUniversalFatBinary: Bool
    public let machOUUID: String?
    public let minimumOSVersion: String?
    public let defaultBaseAddress: UInt64
    public let entryPointRVA: UInt64?
    public let encryptionState: BinaryEncryptionState
    public let cryptID: UInt32
    public let encryptedOffset: UInt32
    public let encryptedSize: UInt32
    public let symbolStripStatus: SymbolStripStatus
    public let totalSymbolsCount: Int
    public let functionStartsCount: Int
    public let segments: [MachOSegmentSummary]
    public let binaryFileSize: Int64
    public let ipaFileSize: Int64
    public let binarySHA256: String
    public let analyzedAt: Date

    public init(
        gameName: String,
        bundleIdentifier: String,
        bundleShortVersion: String,
        bundleBuildVersion: String,
        primaryModuleName: String,
        analysisModuleName: String,
        isUnityIL2CPP: Bool,
        il2cppMetadataVersion: Int?,
        il2cppMetadataStatus: String,
        cpuArchitecture: String,
        isARM64: Bool,
        isUniversalFatBinary: Bool,
        machOUUID: String?,
        minimumOSVersion: String?,
        defaultBaseAddress: UInt64,
        entryPointRVA: UInt64?,
        encryptionState: BinaryEncryptionState,
        cryptID: UInt32,
        encryptedOffset: UInt32,
        encryptedSize: UInt32,
        symbolStripStatus: SymbolStripStatus,
        totalSymbolsCount: Int,
        functionStartsCount: Int,
        segments: [MachOSegmentSummary],
        binaryFileSize: Int64,
        ipaFileSize: Int64,
        binarySHA256: String,
        analyzedAt: Date = Date()
    ) {
        self.gameName = gameName
        self.bundleIdentifier = bundleIdentifier
        self.bundleShortVersion = bundleShortVersion
        self.bundleBuildVersion = bundleBuildVersion
        self.primaryModuleName = primaryModuleName
        self.analysisModuleName = analysisModuleName
        self.isUnityIL2CPP = isUnityIL2CPP
        self.il2cppMetadataVersion = il2cppMetadataVersion
        self.il2cppMetadataStatus = il2cppMetadataStatus
        self.cpuArchitecture = cpuArchitecture
        self.isARM64 = isARM64
        self.isUniversalFatBinary = isUniversalFatBinary
        self.machOUUID = machOUUID
        self.minimumOSVersion = minimumOSVersion
        self.defaultBaseAddress = defaultBaseAddress
        self.entryPointRVA = entryPointRVA
        self.encryptionState = encryptionState
        self.cryptID = cryptID
        self.encryptedOffset = encryptedOffset
        self.encryptedSize = encryptedSize
        self.symbolStripStatus = symbolStripStatus
        self.totalSymbolsCount = totalSymbolsCount
        self.functionStartsCount = functionStartsCount
        self.segments = segments
        self.binaryFileSize = binaryFileSize
        self.ipaFileSize = ipaFileSize
        self.binarySHA256 = binarySHA256
        self.analyzedAt = analyzedAt
    }
}
