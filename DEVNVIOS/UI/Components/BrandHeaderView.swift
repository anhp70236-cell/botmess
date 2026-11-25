import SwiftUI

public struct DEVNVIOSLogoMark: View {
    public var size: CGFloat = 38

    public init(size: CGFloat = 38) {
        self.size = size
    }

    public var body: some View {
        ZStack {
            RoundedRectangle(cornerRadius: size * 0.25, style: .continuous)
                .fill(AppTheme.surface)
                .overlay(
                    RoundedRectangle(cornerRadius: size * 0.25, style: .continuous)
                        .strokeBorder(AppTheme.hairline, lineWidth: 1)
                )

            Canvas { context, canvasSize in
                let center = CGPoint(x: canvasSize.width / 2, y: canvasSize.height / 2)
                let rOuter = min(canvasSize.width, canvasSize.height) * 0.34
                let rInner = min(canvasSize.width, canvasSize.height) * 0.18

                func hexPath(radius: CGFloat) -> Path {
                    var path = Path()
                    for i in 0..<6 {
                        let angle = CGFloat(Double(i) * (.pi / 3.0) - (.pi / 2.0))
                        let pt = CGPoint(
                            x: center.x + radius * cos(angle),
                            y: center.y + radius * sin(angle)
                        )
                        if i == 0 { path.move(to: pt) } else { path.addLine(to: pt) }
                    }
                    path.closeSubpath()
                    return path
                }

                context.stroke(hexPath(radius: rOuter), with: .color(AppTheme.accent), lineWidth: max(1.5, size * 0.05))
                context.stroke(hexPath(radius: rInner), with: .color(AppTheme.textPrimary), lineWidth: max(1.2, size * 0.04))

                let dotRect = CGRect(
                    x: center.x - size * 0.05,
                    y: center.y - size * 0.05,
                    width: size * 0.10,
                    height: size * 0.10
                )
                context.fill(Path(ellipseIn: dotRect), with: .color(AppTheme.accent))
            }
        }
        .frame(width: size, height: size)
    }
}

public struct BrandHeaderView: View {
    public init() {}

    public var body: some View {
        HStack(spacing: 12) {
            DEVNVIOSLogoMark(size: 42)

            VStack(alignment: .leading, spacing: 2) {
                Text("DEVNVIOS")
                    .font(.system(size: 18, weight: .bold))
                    .tracking(1.0)
                    .foregroundStyle(AppTheme.textPrimary)

                Text("PHÂN TÍCH OFFSET IPA CHUYÊN SÂU")
                    .font(.system(size: 10, weight: .semibold, design: .monospaced))
                    .foregroundStyle(AppTheme.textSecondary)
            }

            Spacer(minLength: 6)

            Text("ARM64 • IL2CPP")
                .font(.system(size: 10, weight: .semibold, design: .monospaced))
                .foregroundStyle(AppTheme.accent)
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(AppTheme.accentSubtle)
                .clipShape(Capsule())
        }
    }
}

public struct StatMetricTile: View {
    public let title: String
    public let value: String
    public let subtitle: String
    public let iconName: String

    public init(title: String, value: String, subtitle: String, iconName: String) {
        self.title = title
        self.value = value
        self.subtitle = subtitle
        self.iconName = iconName
    }

    public var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 5) {
                Image(systemName: iconName)
                    .font(.system(size: 11, weight: .semibold))
                    .foregroundStyle(AppTheme.accent)
                Text(title)
                    .font(.system(size: 11, weight: .medium))
                    .foregroundStyle(AppTheme.textSecondary)
                    .lineLimit(1)
            }

            Text(value)
                .font(.system(size: 17, weight: .bold, design: .rounded))
                .foregroundStyle(AppTheme.textPrimary)
                .lineLimit(1)
                .minimumScaleFactor(0.72)

            Text(subtitle)
                .font(.system(size: 10))
                .foregroundStyle(AppTheme.textMuted)
                .lineLimit(1)
        }
        .thinCard(padding: 11, cornerRadius: 12)
    }
}

public struct VerificationBadge: View {
    public let status: OffsetVerificationStatus

    public init(status: OffsetVerificationStatus) {
        self.status = status
    }

    public var body: some View {
        let color = AppTheme.color(for: status)
        Text(status.vietnameseLabel)
            .font(.system(size: 10, weight: .semibold))
            .foregroundStyle(color)
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(color.opacity(0.13))
            .clipShape(Capsule())
            .overlay(
                Capsule()
                    .strokeBorder(color.opacity(0.32), lineWidth: 0.75)
            )
    }
}

public struct EmptyStateCardView: View {
    public let icon: String
    public let title: String
    public let message: String

    public init(icon: String, title: String, message: String) {
        self.icon = icon
        self.title = title
        self.message = message
    }

    public var body: some View {
        VStack(spacing: 10) {
            Image(systemName: icon)
                .font(.system(size: 28, weight: .light))
                .foregroundStyle(AppTheme.accent.opacity(0.85))

            Text(title)
                .font(.system(size: 15, weight: .semibold))
                .foregroundStyle(AppTheme.textPrimary)
                .multilineTextAlignment(.center)

            Text(message)
                .font(.system(size: 12))
                .foregroundStyle(AppTheme.textSecondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 6)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 24)
        .thinCard()
    }
}
