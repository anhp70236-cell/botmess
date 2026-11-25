import Foundation

/// Pure-Swift demangler for Itanium C++ ABI (`_Z` / `__Z`) and Swift mangled symbols (`_$s` / `$s`),
/// enabling deep static offset discovery on C++ engines (Unreal, Cocos2d-x, custom C++) and native binaries.
public enum SymbolDemangler {
    public static func demangle(_ raw: String) -> String {
        if raw.hasPrefix("__Z") {
            let trimmed = String(raw.dropFirst(1)) // -> _Z...
            if let cpp = demangleItaniumCPP(trimmed) {
                return cpp
            }
        } else if raw.hasPrefix("_Z") {
            if let cpp = demangleItaniumCPP(raw) {
                return cpp
            }
        } else if raw.hasPrefix("_$s") || raw.hasPrefix("$s") {
            let clean = raw.hasPrefix("_") ? String(raw.dropFirst()) : raw
            if let swiftReadable = parseSimplifiedSwiftSymbol(clean) {
                return swiftReadable
            }
        }

        if raw.hasPrefix("_") {
            return String(raw.dropFirst())
        }
        return raw
    }

    private static func demangleItaniumCPP(_ mangled: String) -> String? {
        let chars = Array(mangled)
        guard chars.count >= 4, chars[0] == "_", chars[1] == "Z" else {
            return nil
        }

        var idx = 2
        var isNested = false
        var isConstMethod = false

        if idx < chars.count && chars[idx] == "N" {
            isNested = true
            idx += 1
            while idx < chars.count && (chars[idx] == "K" || chars[idx] == "V" || chars[idx] == "r") {
                if chars[idx] == "K" { isConstMethod = true }
                idx += 1
            }
        }

        var parts: [String] = []
        while idx < chars.count {
            if isNested && chars[idx] == "E" {
                idx += 1
                break
            }
            // Constructor / Destructor markers
            if chars[idx] == "C", idx + 1 < chars.count, chars[idx + 1].isNumber {
                if let cls = parts.last { parts.append(cls) }
                idx += 2
                if !isNested { break }
                continue
            }
            if chars[idx] == "D", idx + 1 < chars.count, chars[idx + 1].isNumber {
                if let cls = parts.last { parts.append("~\(cls)") }
                idx += 2
                if !isNested { break }
                continue
            }
            // Skip template argument block I...E
            if chars[idx] == "I" {
                var depth = 1
                idx += 1
                while idx < chars.count && depth > 0 {
                    if chars[idx] == "I" { depth += 1 }
                    else if chars[idx] == "E" { depth -= 1 }
                    idx += 1
                }
                continue
            }

            guard chars[idx].isNumber else {
                break
            }

            var len = 0
            while idx < chars.count, let digit = chars[idx].wholeNumberValue {
                len = len * 10 + digit
                idx += 1
                if len > 512 { return nil }
            }
            guard len > 0, idx + len <= chars.count else {
                return nil
            }
            let segment = String(chars[idx..<(idx + len)])
            parts.append(segment)
            idx += len

            if !isNested {
                break
            }
        }

        guard !parts.isEmpty else { return nil }
        let qualified = parts.joined(separator: "::")
        if idx < chars.count {
            if chars[idx] == "v" && idx + 1 == chars.count {
                return isConstMethod ? "\(qualified)() const" : "\(qualified)()"
            }
            return isConstMethod ? "\(qualified)(...) const" : "\(qualified)(...)"
        }
        return qualified
    }

    private static func parseSimplifiedSwiftSymbol(_ sym: String) -> String? {
        let chars = Array(sym.dropFirst(2)) // drop "$s"
        var idx = 0
        var tokens: [String] = []
        while idx < chars.count {
            guard chars[idx].isNumber else {
                idx += 1
                continue
            }
            var len = 0
            while idx < chars.count, let d = chars[idx].wholeNumberValue {
                len = len * 10 + d
                idx += 1
                if len > 256 { return nil }
            }
            guard len > 0, idx + len <= chars.count else { break }
            tokens.append(String(chars[idx..<(idx + len)]))
            idx += len
            if tokens.count >= 5 { break }
        }
        guard tokens.count >= 2 else { return nil }
        return "Swift::" + tokens.joined(separator: ".")
    }
}
