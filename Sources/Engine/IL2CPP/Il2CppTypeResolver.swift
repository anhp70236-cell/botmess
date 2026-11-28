import Foundation

public struct Il2CppTypeResolver: Sendable {
    public let il2cppTypes: [Il2CppTypeMetaEntry]
    public let typeNames: [String]
    public let typeNamespaces: [String]

    public init(il2cppTypes: [Il2CppTypeMetaEntry], typeNames: [String], typeNamespaces: [String]) {
        self.il2cppTypes = il2cppTypes
        self.typeNames = typeNames
        self.typeNamespaces = typeNamespaces
    }

    public func resolveTypeName(typeIndex: Int32) -> String {
        let idx = Int(typeIndex)
        guard idx >= 0 && idx < il2cppTypes.count else {
            return "object"
        }
        let t = il2cppTypes[idx]
        let base = resolveEnum(t.typeEnum, dataIndex: t.dataIndex, elemEnum: t.elementEnum, elemDataIndex: t.elementDataIndex, rank: t.arrayRank)
        return t.isByRef ? "ref \(base)" : base
    }

    private func resolveTypeDefName(_ tdIdx: Int32) -> String {
        let idx = Int(tdIdx)
        guard idx >= 0 && idx < typeNames.count else { return "Il2CppObject" }
        let nm = typeNames[idx]
        let ns = idx < typeNamespaces.count ? typeNamespaces[idx] : ""
        if ns == "System" {
            switch nm {
            case "Void": return "void"
            case "Boolean": return "bool"
            case "Char": return "char"
            case "SByte": return "sbyte"
            case "Byte": return "byte"
            case "Int16": return "short"
            case "UInt16": return "ushort"
            case "Int32": return "int"
            case "UInt32": return "uint"
            case "Int64": return "long"
            case "UInt64": return "ulong"
            case "Single": return "float"
            case "Double": return "double"
            case "String": return "string"
            case "Object": return "object"
            default: break
            }
        }
        return ns.isEmpty ? nm : "\(ns).\(nm)"
    }

    private func resolveEnum(_ tEnum: UInt8, dataIndex: Int32, elemEnum: UInt8, elemDataIndex: Int32, rank: UInt8) -> String {
        switch tEnum {
        case 0x01: return "void"
        case 0x02: return "bool"
        case 0x03: return "char"
        case 0x04: return "sbyte"
        case 0x05: return "byte"
        case 0x06: return "short"
        case 0x07: return "ushort"
        case 0x08: return "int"
        case 0x09: return "uint"
        case 0x0A: return "long"
        case 0x0B: return "ulong"
        case 0x0C: return "float"
        case 0x0D: return "double"
        case 0x0E: return "string"
        case 0x0F:
            let elem = resolveEnum(elemEnum, dataIndex: elemDataIndex, elemEnum: 0, elemDataIndex: -1, rank: 1)
            return "\(elem)*"
        case 0x11, 0x12, 0x15:
            return dataIndex >= 0 ? resolveTypeDefName(dataIndex) : "object"
        case 0x13:
            return "T\(max(0, Int(dataIndex)))"
        case 0x14:
            let elem = resolveEnum(elemEnum, dataIndex: elemDataIndex, elemEnum: 0, elemDataIndex: -1, rank: 1)
            let commas = String(repeating: ",", count: max(0, Int(rank) - 1))
            return "\(elem)[\(commas)]"
        case 0x16: return "System.TypedReference"
        case 0x18: return "System.IntPtr"
        case 0x19: return "System.UIntPtr"
        case 0x1B: return "System.IntPtr"
        case 0x1C: return "object"
        case 0x1D:
            let elem = resolveEnum(elemEnum, dataIndex: elemDataIndex, elemEnum: 0, elemDataIndex: -1, rank: 1)
            return "\(elem)[]"
        case 0x1E:
            return "M\(max(0, Int(dataIndex)))"
        default:
            return "object"
        }
    }

    public static func toCTypeName(_ csType: String) -> String {
        switch csType {
        case "void": return "void"
        case "bool": return "bool"
        case "char": return "uint16_t"
        case "sbyte": return "int8_t"
        case "byte": return "uint8_t"
        case "short": return "int16_t"
        case "ushort": return "uint16_t"
        case "int": return "int32_t"
        case "uint": return "uint32_t"
        case "long": return "int64_t"
        case "ulong": return "uint64_t"
        case "float": return "float"
        case "double": return "double"
        case "System.IntPtr", "IntPtr": return "intptr_t"
        case "System.UIntPtr", "UIntPtr": return "uintptr_t"
        case "string", "System.String": return "System_String_o*"
        case "object", "System.Object": return "Il2CppObject*"
        default:
            if csType.hasSuffix("[]") || csType.hasSuffix("*") {
                return "void*"
            }
            return "void*"
        }
    }

    public static func sanitizeCIdentifier(_ raw: String) -> String {
        var out = ""
        out.reserveCapacity(raw.count)
        for u in raw.unicodeScalars {
            if CharacterSet.alphanumerics.contains(u) || u == "_" {
                out.append(Character(u))
            } else {
                out.append("_")
            }
        }
        if out.isEmpty { return "UnknownIdent" }
        if let first = out.unicodeScalars.first, CharacterSet.decimalDigits.contains(first) {
            return "_\(out)"
        }
        return out
    }
}
