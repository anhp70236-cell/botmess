import Foundation

public enum OffsetCategory: String, Codable, CaseIterable, Identifiable, Sendable {
    case player = "Player"
    case playerHealth = "Player Health"
    case playerState = "Player State"
    case position = "Position"
    case movement = "Movement"
    case rotation = "Rotation"
    case camera = "Camera"
    case character = "Character"
    case animation = "Animation"
    case gameObjects = "Game Objects"
    case gameState = "Game State"
    case gameplayVariables = "Gameplay Variables"

    public var id: String { rawValue }

    public var sfSymbolName: String {
        switch self {
        case .player: return "person.crop.circle"
        case .playerHealth: return "heart.text.square"
        case .playerState: return "waveform.path.ecg"
        case .position: return "location.north.line"
        case .movement: return "figure.walk.motion"
        case .rotation: return "gyroscope"
        case .camera: return "video.badge.Checkmark"
        case .character: return "person.text.rectangle"
        case .animation: return "sparkles.rectangle.stack"
        case .gameObjects: return "cube.transparent"
        case .gameState: return "flag.checkered.2.crossed"
        case .gameplayVariables: return "slider.horizontal.3"
        }
    }

    public var safeSymbolName: String {
        switch self {
        case .player: return "person.crop.circle"
        case .playerHealth: return "heart.text.square"
        case .playerState: return "waveform.path.ecg"
        case .position: return "location"
        case .movement: return "figure.run"
        case .rotation: return "rotate.3d"
        case .camera: return "camera.viewfinder"
        case .character: return "person.text.rectangle"
        case .animation: return "film.stack"
        case .gameObjects: return "cube"
        case .gameState: return "cpu"
        case .gameplayVariables: return "slider.horizontal.3"
        }
    }
}

public enum OffsetDataType: String, Codable, CaseIterable, Identifiable, Sendable {
    case classType = "Class"
    case method = "Method"
    case field = "Field"
    case staticData = "Static Data"
    case runtimeData = "Runtime Data"
    case symbolFunction = "Symbol Function"
    case objcSelector = "ObjC Selector"

    public var id: String { rawValue }
}

public enum DiscoverySource: String, Codable, CaseIterable, Identifiable, Sendable {
    case machOSymbolTable = "Mach-O Symbol Table"
    case il2cppMetadata = "IL2CPP Metadata"
    case objcRuntimeMetadata = "ObjC Metadata"
    case arm64PatternAnalysis = "ARM64 XRef / Prologue"
    case stringCrossReference = "String Cross-Reference"
    case runtimeInspection = "Runtime Inspection"
    case crossVersionMapping = "Cross-Version Mapping"

    public var id: String { rawValue }
}

public enum OffsetVerificationStatus: String, Codable, CaseIterable, Identifiable, Sendable {
    case verified = "Verified"
    case probable = "Probable"
    case unverified = "Unverified"
    case undetermined = "Undetermined"

    public var id: String { rawValue }

    public var vietnameseLabel: String {
        switch self {
        case .verified: return "Đã xác minh"
        case .probable: return "Có khả năng đúng"
        case .unverified: return "Chưa xác minh"
        case .undetermined: return "Không thể xác định"
        }
    }
}

public struct DiscoveredOffset: Identifiable, Codable, Equatable, Hashable, Sendable {
    public let id: UUID
    public let name: String
    public let value: String
    public let moduleName: String
    public let rva: UInt64
    public let virtualAddress: UInt64
    public let fileOffset: UInt64?
    public let category: OffsetCategory
    public let dataType: OffsetDataType
    public let discoverySource: DiscoverySource
    public var verificationStatus: OffsetVerificationStatus
    public let confidenceScore: Double
    public let signatureHint: String?
    public let notes: String
    public let detectedAt: Date

    public init(
        id: UUID = UUID(),
        name: String,
        value: String,
        moduleName: String,
        rva: UInt64,
        virtualAddress: UInt64,
        fileOffset: UInt64?,
        category: OffsetCategory,
        dataType: OffsetDataType,
        discoverySource: DiscoverySource,
        verificationStatus: OffsetVerificationStatus,
        confidenceScore: Double,
        signatureHint: String? = nil,
        notes: String = "",
        detectedAt: Date = Date()
    ) {
        self.id = id
        self.name = name
        self.value = value
        self.moduleName = moduleName
        self.rva = rva
        self.virtualAddress = virtualAddress
        self.fileOffset = fileOffset
        self.category = category
        self.dataType = dataType
        self.discoverySource = discoverySource
        self.verificationStatus = verificationStatus
        self.confidenceScore = confidenceScore
        self.signatureHint = signatureHint
        self.notes = notes
        self.detectedAt = detectedAt
    }

    public var rvaFormatted: String {
        rva > 0 ? HexFormatters.rvaHex(rva) : "N/A"
    }

    public var vaFormatted: String {
        virtualAddress > 0 ? HexFormatters.vaHex(virtualAddress) : "N/A"
    }

    public var fileOffsetFormatted: String {
        if let fileOffset {
            return HexFormatters.rvaHex(fileOffset)
        }
        return "Undetermined"
    }

    public func runtimeAddress(withASLRSlide slide: UInt64) -> UInt64? {
        guard virtualAddress > 0 else { return nil }
        return virtualAddress &+ slide
    }
}
