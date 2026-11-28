import SwiftUI

enum AppThemeStyle: String, CaseIterable, Codable, Identifiable {
    case obsidianDark = "Obsidian Dark"
    case midnightSlate = "Midnight Slate"
    case cleanLight = "Clean Light"

    var id: String { rawValue }

    var preferredColorScheme: ColorScheme {
        switch self {
        case .obsidianDark, .midnightSlate: return .dark
        case .cleanLight: return .light
        }
    }

    var background: Color {
        switch self {
        case .obsidianDark: return Color(red: 0.043, green: 0.055, blue: 0.086)
        case .midnightSlate: return Color(red: 0.067, green: 0.094, blue: 0.153)
        case .cleanLight: return Color(red: 0.949, green: 0.961, blue: 0.980)
        }
    }

    var cardBackground: Color {
        switch self {
        case .obsidianDark: return Color(red: 0.078, green: 0.098, blue: 0.145)
        case .midnightSlate: return Color(red: 0.118, green: 0.161, blue: 0.231)
        case .cleanLight: return Color.white
        }
    }

    var elevatedBackground: Color {
        switch self {
        case .obsidianDark: return Color(red: 0.118, green: 0.145, blue: 0.212)
        case .midnightSlate: return Color(red: 0.165, green: 0.220, blue: 0.310)
        case .cleanLight: return Color(red: 0.910, green: 0.933, blue: 0.965)
        }
    }

    var border: Color {
        switch self {
        case .obsidianDark: return Color.white.opacity(0.09)
        case .midnightSlate: return Color.white.opacity(0.12)
        case .cleanLight: return Color.black.opacity(0.08)
        }
    }

    var primaryText: Color {
        switch self {
        case .obsidianDark, .midnightSlate: return Color(red: 0.945, green: 0.961, blue: 0.984)
        case .cleanLight: return Color(red: 0.067, green: 0.094, blue: 0.153)
        }
    }

    var secondaryText: Color {
        switch self {
        case .obsidianDark, .midnightSlate: return Color(red: 0.580, green: 0.639, blue: 0.722)
        case .cleanLight: return Color(red: 0.392, green: 0.455, blue: 0.545)
        }
    }

    var accent: Color {
        switch self {
        case .obsidianDark: return Color(red: 0.231, green: 0.510, blue: 0.965)
        case .midnightSlate: return Color(red: 0.220, green: 0.741, blue: 0.973)
        case .cleanLight: return Color(red: 0.145, green: 0.388, blue: 0.922)
        }
    }

    var success: Color { Color(red: 0.133, green: 0.773, blue: 0.369) }
    var warning: Color { Color(red: 0.961, green: 0.620, blue: 0.043) }
    var danger: Color { Color(red: 0.937, green: 0.267, blue: 0.267) }
}

struct GlassCardModifier: ViewModifier {
    let theme: AppThemeStyle
    let cornerRadius: CGFloat

    func body(content: Content) -> some View {
        content
            .padding(14)
            .background(
                RoundedRectangle(cornerRadius: cornerRadius, style: .continuous)
                    .fill(theme.cardBackground)
            )
            .overlay(
                RoundedRectangle(cornerRadius: cornerRadius, style: .continuous)
                    .stroke(theme.border, lineWidth: 1)
            )
    }
}

extension View {
    func dipCard(_ theme: AppThemeStyle, radius: CGFloat = 16) -> some View {
        modifier(GlassCardModifier(theme: theme, cornerRadius: radius))
    }
}

struct BadgeChip: View {
    let text: String
    let color: Color

    var body: some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold, design: .monospaced))
            .foregroundColor(color)
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(color.opacity(0.14))
            .clipShape(Capsule())
    }
}

func formatByteCount(_ bytes: UInt64) -> String {
    let b = Double(bytes)
    if b >= 1024 * 1024 * 1024 {
        return String(format: "%.2f GB", b / (1024 * 1024 * 1024))
    } else if b >= 1024 * 1024 {
        return String(format: "%.2f MB", b / (1024 * 1024))
    } else if b >= 1024 {
        return String(format: "%.1f KB", b / 1024)
    }
    return "\(bytes) B"
}
