import Foundation

public struct SymbolEntry: Identifiable, Codable, Equatable, Hashable, Sendable {
    public let id: UUID
    public let name: String
    public let cleanName: String
    public let moduleName: String
    public let sectionIndex: UInt8
    public let isExternal: Bool
    public let isFunctionSymbol: Bool
    public let virtualAddress: UInt64
    public let rva: UInt64
    public let fileOffset: UInt64?

    public init(
        id: UUID = UUID(),
        name: String,
        cleanName: String,
        moduleName: String,
        sectionIndex: UInt8,
        isExternal: Bool,
        isFunctionSymbol: Bool,
        virtualAddress: UInt64,
        rva: UInt64,
        fileOffset: UInt64?
    ) {
        self.id = id
        self.name = name
        self.cleanName = cleanName
        self.moduleName = moduleName
        self.sectionIndex = sectionIndex
        self.isExternal = isExternal
        self.isFunctionSymbol = isFunctionSymbol
        self.virtualAddress = virtualAddress
        self.rva = rva
        self.fileOffset = fileOffset
    }
}
