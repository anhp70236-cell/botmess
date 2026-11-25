import SwiftUI
import UIKit

public enum AppThemeMode: String, CaseIterable, Identifiable, Codable {
    case matteDark = "Pro Matte Dark"
    case slateContrast = "Slate Contrast"
    case system = "System"

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

/// Matte obsidian & slate design palette — zero neon, zero glow, hairline thin cards.
public enum AppTheme {
    public static let canvas = Color(red: 0.043, green: 0.051, blue: 0.067)          // #0B0D11
    public static let surface = Color(red: 0.071, green: 0.086, blue: 0.114)         // #12161D
    public static let surfaceElevated = Color(red: 0.098, green: 0.118, blue: 0.153) // #191E27
    public static let hairline = Color(red: 0.153, green: 0.180, blue: 0.227)        // #272E3A
    public static let accent = Color(red: 0.345, green: 0.608, blue: 0.878)          // #589BE0
    public static let accentSubtle = Color(red: 0.345, green: 0.608, blue: 0.878).opacity(0.14)

    public static let textPrimary = Color(red: 0.918, green: 0.937, blue: 0.965)     // #EAEFF6
    public static let textSecondary = Color(red: 0.565, green: 0.612, blue: 0.690)   // #909CB0
    public static let textMuted = Color(red: 0.380, green: 0.424, blue: 0.498)       // #616C7F

    public static let statusVerified = Color(red: 0.298, green: 0.659, blue: 0.486)     // Muted emerald
    public static let statusProbable = Color(red: 0.796, green: 0.620, blue: 0.306)     // Muted amber
    public static let statusUnverified = Color(red: 0.490, green: 0.541, blue: 0.624)   // Muted steel
    public static let statusUndetermined = Color(red: 0.745, green: 0.376, blue: 0.376) // Muted crimson

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
        navAppearance.backgroundColor = UIColor(red: 0.043, green: 0.051, blue: 0.067, alpha: 0.96)
        navAppearance.shadowColor = UIColor(red: 0.153, green: 0.180, blue: 0.227, alpha: 0.7)
        navAppearance.titleTextAttributes = [
            .foregroundColor: UIColor(red: 0.918, green: 0.937, blue: 0.965, alpha: 1.0)
        ]
        navAppearance.largeTitleTextAttributes = [
            .foregroundColor: UIColor(red: 0.918, green: 0.937, blue: 0.965, alpha: 1.0)
        ]
        UINavigationBar.appearance().standardAppearance = navAppearance
        UINavigationBar.appearance().scrollEdgeAppearance = navAppearance
        UINavigationBar.appearance().compactAppearance = navAppearance

        let tabAppearance = UITabBarAppearance()
        tabAppearance.configureWithOpaqueBackground()
        tabAppearance.backgroundColor = UIColor(red: 0.055, green: 0.067, blue: 0.088, alpha: 0.98)
        tabAppearance.shadowColor = UIColor(red: 0.153, green: 0.180, blue: 0.227, alpha: 0.8)
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
        cornerRadius: CGFloat = 12,
        elevated: Bool = false
    ) -> some View {
        modifier(ThinCardModifier(padding: padding, cornerRadius: cornerRadius, elevated: elevated))
    }
}
