import SwiftUI
import UIKit

public enum AppThemeMode: String, CaseIterable, Identifiable, Codable {
    case matteDark = "Titanium Đen Nhám (Mặc định)"
    case slateContrast = "Xám Đá Tương Phản Cao"
    case system = "Theo Hệ Thống iOS"

    public var id: String { rawValue }

    public var preferredColorScheme: ColorScheme? {
        switch self {
        case .matteDark, .slateContrast:
            return .dark
        case .system:
            return nil
        }
    }
}

/// High-precision Titanium & Obsidian Dark Mode palette — zero neon, zero glow, razor-thin borders.
public enum AppTheme {
    public static let canvas = Color(red: 0.035, green: 0.043, blue: 0.059)          // #090B0F
    public static let surface = Color(red: 0.067, green: 0.082, blue: 0.114)         // #11151D
    public static let surfaceElevated = Color(red: 0.094, green: 0.118, blue: 0.161) // #181E29
    public static let hairline = Color(red: 0.157, green: 0.192, blue: 0.251)        // #283140
    public static let accent = Color(red: 0.310, green: 0.561, blue: 0.902)          // #4F8FE6
    public static let accentSubtle = Color(red: 0.310, green: 0.561, blue: 0.902).opacity(0.15)

    public static let textPrimary = Color(red: 0.929, green: 0.945, blue: 0.973)     // #EDF1F8
    public static let textSecondary = Color(red: 0.576, green: 0.627, blue: 0.710)   // #93A0B5
    public static let textMuted = Color(red: 0.392, green: 0.439, blue: 0.518)       // #647084

    public static let statusVerified = Color(red: 0.267, green: 0.690, blue: 0.494)     // Sage Emerald
    public static let statusProbable = Color(red: 0.824, green: 0.635, blue: 0.298)     // Warm Gold-Amber
    public static let statusUnverified = Color(red: 0.502, green: 0.557, blue: 0.643)   // Muted Steel
    public static let statusUndetermined = Color(red: 0.769, green: 0.380, blue: 0.380) // Muted Terracotta

    public static func color(for status: OffsetVerificationStatus) -> Color {
        switch status {
        case .verified: return statusVerified
        case .probable: return statusProbable
        case .unverified: return statusUnverified
        case .undetermined: return statusUndetermined
        }
    }

    public static func configureGlobalAppearance() {
        let navAppearance = UINavigationBarAppearance()
        navAppearance.configureWithOpaqueBackground()
        navAppearance.backgroundColor = UIColor(red: 0.035, green: 0.043, blue: 0.059, alpha: 0.98)
        navAppearance.shadowColor = UIColor(red: 0.157, green: 0.192, blue: 0.251, alpha: 0.75)
        navAppearance.titleTextAttributes = [
            .foregroundColor: UIColor(red: 0.929, green: 0.945, blue: 0.973, alpha: 1.0)
        ]
        navAppearance.largeTitleTextAttributes = [
            .foregroundColor: UIColor(red: 0.929, green: 0.945, blue: 0.973, alpha: 1.0)
        ]
        UINavigationBar.appearance().standardAppearance = navAppearance
        UINavigationBar.appearance().scrollEdgeAppearance = navAppearance
        UINavigationBar.appearance().compactAppearance = navAppearance

        let tabAppearance = UITabBarAppearance()
        tabAppearance.configureWithOpaqueBackground()
        tabAppearance.backgroundColor = UIColor(red: 0.047, green: 0.059, blue: 0.082, alpha: 0.98)
        tabAppearance.shadowColor = UIColor(red: 0.157, green: 0.192, blue: 0.251, alpha: 0.85)
        UITabBar.appearance().standardAppearance = tabAppearance
        UITabBar.appearance().scrollEdgeAppearance = tabAppearance
    }
}

public struct ThinCardModifier: ViewModifier {
    let padding: CGFloat
    let cornerRadius: CGFloat
    let elevated: Bool

    public func body(content: Content) -> some View {
        content
            .padding(padding)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(elevated ? AppTheme.surfaceElevated : AppTheme.surface)
            .clipShape(RoundedRectangle(cornerRadius: cornerRadius, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: cornerRadius, style: .continuous)
                    .strokeBorder(AppTheme.hairline, lineWidth: 0.85)
            )
    }
}

extension View {
    public func thinCard(
        padding: CGFloat = 14,
        cornerRadius: CGFloat = 13,
        elevated: Bool = false
    ) -> some View {
        modifier(ThinCardModifier(padding: padding, cornerRadius: cornerRadius, elevated: elevated))
    }
}
