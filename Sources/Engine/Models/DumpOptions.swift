import Foundation

public struct DumpOptions: Codable, Equatable, Sendable {
    public var generateDumpCS: Bool
    public var generateStructAndJSON: Bool
    public var generateDummyDLLs: Bool
    public var includeMethods: Bool
    public var includeFields: Bool
    public var includeProperties: Bool
    public var restoreAttributes: Bool
    public var writeFieldOffsets: Bool
    public var writeMethodOffsets: Bool
    public var writeTypeDefIndexes: Bool
    public var restoreDummyDllTokens: Bool
    public var workerThreads: Int
    public var deepMachOScan: Bool
    public var autoExportZip: Bool

    public init(
        generateDumpCS: Bool = true,
        generateStructAndJSON: Bool = true,
        generateDummyDLLs: Bool = true,
        includeMethods: Bool = true,
        includeFields: Bool = true,
        includeProperties: Bool = true,
        restoreAttributes: Bool = true,
        writeFieldOffsets: Bool = true,
        writeMethodOffsets: Bool = true,
        writeTypeDefIndexes: Bool = true,
        restoreDummyDllTokens: Bool = true,
        workerThreads: Int = 2,
        deepMachOScan: Bool = true,
        autoExportZip: Bool = false
    ) {
        self.generateDumpCS = generateDumpCS
        self.generateStructAndJSON = generateStructAndJSON
        self.generateDummyDLLs = generateDummyDLLs
        self.includeMethods = includeMethods
        self.includeFields = includeFields
        self.includeProperties = includeProperties
        self.restoreAttributes = restoreAttributes
        self.writeFieldOffsets = writeFieldOffsets
        self.writeMethodOffsets = writeMethodOffsets
        self.writeTypeDefIndexes = writeTypeDefIndexes
        self.restoreDummyDllTokens = restoreDummyDllTokens
        self.workerThreads = [1, 2, 4].contains(workerThreads) ? workerThreads : 2
        self.deepMachOScan = deepMachOScan
        self.autoExportZip = autoExportZip
    }

    public static let `default` = DumpOptions()
}
