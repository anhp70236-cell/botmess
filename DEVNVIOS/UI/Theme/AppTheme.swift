import SwiftUI
import UIKit

public enum AppThemeMode: String, CaseIterable, Identifiable, Codable {
    case matteDark = "Titanium Đen Nhám (Cao cấp)"
    case slateContrast = "Graphite Tương Phản Sắc Nét"
    case system = "Theo Giao Diện iOS"

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

/// Refined Apple-Pro Matte Graphite & Titanium design system — zero neon, zero glow, precision hairlines.
public enum AppTheme {
    public static let canvas = Color(red: 0.039, green: 0.043, blue: 0.055)          // #0A0B0E
    public static let surface = Color(red: 0.075, green: 0.086, blue: 0.110)         // #13161C
    public static let surfaceElevated = Color(red: 0.106, green: 0.122, blue: 0.157) // #1B1F28
    public static let hairline = Color(red: 0.176, green: 0.204, blue: 0.255)        // #2D3441
    public static let accent = Color(red: 0.337, green: 0.576, blue: 0.898)          // Muted Cobalt-Steel #5693E5
    public static let accentSubtle = Color(red: 0.337, green: 0.576, blue: 0.898).opacity(0.14)

    public static let textPrimary = Color(red: 0.941, green: 0.953, blue: 0.976)     // #F0F3F9
    public static let textSecondary = Color(red: 0.600, green: 0.647, blue: 0.725)   // #99A5B9
    public static let textMuted = Color(red: 0.416, green: 0.463, blue: 0.541)       // #6A768A

    public static let statusVerified = Color(red: 0.278, green: 0.698, blue: 0.506)     // Matte Sage Emerald
    public static let statusProbable = Color(red: 0.831, green: 0.647, blue: 0.318)     // Matte Warm Ochre
    public static let statusUnverified = Color(red: 0.514, green: 0.569, blue: 0.651)   // Matte Slate
    public static let statusUndetermined = Color(red: 0.776, green: 0.392, blue: 0.392) // Matte Terracotta

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
        navAppearance.backgroundColor = UIColor(red: 0.039, green: 0.043, blue: 0.055, alpha: 0.98)
        navAppearance.shadowColor = UIColor(red: 0.176, green: 0.204, blue: 0.255, alpha: 0.65)
        navAppearance.titleTextAttributes = [
            .foregroundColor: UIColor(red: 0.941, green: 0.953, blue: 0.976, alpha: 1.0)
        ]
        navAppearance.largeTitleTextAttributes = [
            .foregroundColor: UIColor(red: 0.941, green: 0.953, blue: 0.976, alpha: 1.0)
        ]
        UINavigationBar.appearance().standardAppearance = navAppearance
        UINavigationBar.appearance().scrollEdgeAppearance = navAppearance
        UINavigationBar.appearance().compactAppearance = navAppearance

        let tabAppearance = UITabBarAppearance()
        tabAppearance.configureWithOpaqueBackground()
        tabAppearance.backgroundColor = UIColor(red: 0.051, green: 0.059, blue: 0.078, alpha: 0.99)
        tabAppearance.shadowColor = UIColor(red: 0.176, green: 0.204, blue: 0.255, alpha: 0.75)
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
                    .strokeBorder(
                        LinearGradient(
                            colors: [
                                Color.white.opacity(elevated ? 0.11 : 0.07),
                                AppTheme.hairline.opacity(0.85)
                            ],
                            startPoint: .topLeading,
                            endPoint: .bottomTrailing
                        ),
                        lineWidth: 0.85
                    )
            )
    }
}

extension View {
    public func thinCard(
        padding: CGFloat = 14,
        cornerRadius: CGFloat = 14,
        elevated: Bool = false
    ) -> some View {
        modifier(ThinCardModifier(padding: padding, cornerRadius: cornerRadius, elevated: elevated))
    }
}
