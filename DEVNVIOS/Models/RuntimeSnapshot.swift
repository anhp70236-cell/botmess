import Foundation

public struct RuntimeLoadedModule: Identifiable, Codable, Equatable, Sendable {
    public let id: UUID
    public let imageName: String
    public let fullPath: String
    public let runtimeBaseAddress: UInt64
    public let aslrSlide: Int64
    public let staticBaseAddress: UInt64
    public let isARM64: Bool

    public init(
        id: UUID = UUID(),
        imageName: String,
        fullPath: String,
        runtimeBaseAddress: UInt64,
        aslrSlide: Int64,
        staticBaseAddress: UInt64,
        isARM64: Bool
    ) {
        self.id = id
        self.imageName = imageName
        self.fullPath = fullPath
        self.runtimeBaseAddress = runtimeBaseAddress
        self.aslrSlide = aslrSlide
        self.staticBaseAddress = staticBaseAddress
        self.isARM64 = isARM64
    }
}

public struct RuntimeMappedSymbol: Identifiable, Codable, Equatable, Sendable {
    public let id: UUID
    public let symbolName: String
    public let moduleName: String
    public let runtimeAddress: UInt64
    public let aslrSlide: UInt64
    public let resolvedStaticVA: UInt64
    public let resolvedRVA: UInt64
    public let resolvedFileOffset: UInt64?
    public let category: OffsetCategory

    public init(
        id: UUID = UUID(),
        symbolName: String,
        moduleName: String,
        runtimeAddress: UInt64,
        aslrSlide: UInt64,
        resolvedStaticVA: UInt64,
        resolvedRVA: UInt64,
        resolvedFileOffset: UInt64?,
        category: OffsetCategory
    ) {
        self.id = id
        self.symbolName = symbolName
        self.moduleName = moduleName
        self.runtimeAddress = runtimeAddress
        self.aslrSlide = aslrSlide
        self.resolvedStaticVA = resolvedStaticVA
        self.resolvedRVA = resolvedRVA
        self.resolvedFileOffset = resolvedFileOffset
        self.category = category
    }
}

public struct RuntimeSnapshot: Codable, Equatable, Sendable {
    public let targetModuleName: String
    public let observationMode: String
    public let requiresAuthorizedRuntimeHarness: Bool
    public let sampleASLRSlide: UInt64
    public let mappedRuntimeSymbols: [RuntimeMappedSymbol]
    public let hostDyldModulesSample: [RuntimeLoadedModule]
    public let notes: [String]
    public let capturedAt: Date

    public init(
        targetModuleName: String,
        observationMode: String,
        requiresAuthorizedRuntimeHarness: Bool,
        sampleASLRSlide: UInt64,
        mappedRuntimeSymbols: [RuntimeMappedSymbol],
        hostDyldModulesSample: [RuntimeLoadedModule],
        notes: [String],
        capturedAt: Date = Date()
    ) {
        self.targetModuleName = targetModuleName
        self.observationMode = observationMode
        self.requiresAuthorizedRuntimeHarness = requiresAuthorizedRuntimeHarness
        self.sampleASLRSlide = sampleASLRSlide
        self.mappedRuntimeSymbols = mappedRuntimeSymbols
        self.hostDyldModulesSample = hostDyldModulesSample
        self.notes = notes
        self.capturedAt = capturedAt
    }
}
