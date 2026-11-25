import SwiftUI

public struct SettingsView: View {
    @EnvironmentObject private var container: AppContainer
    @State private var quotaGB: Double = 6.0
    @State private var indexRebuiltFeedback = false

    public init() {}

    public var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    BrandHeaderView()
                        .thinCard(padding: 14, cornerRadius: 13, elevated: true)

                    // Theme Settings
                    VStack(alignment: .leading, spacing: 10) {
                        sectionHeader("INTERFACE THEME")

                        ForEach(AppThemeMode.allCases) { mode in
                            Button {
                                container.themeMode = mode
                            } label: {
                                HStack {
                                    Text(mode.rawValue)
                                        .font(.system(size: 14, weight: .medium))
                                        .foregroundStyle(AppTheme.textPrimary)
                                    Spacer()
                                    Image(systemName: container.themeMode == mode ? "checkmark.circle.fill" : "circle")
                                        .foregroundStyle(container.themeMode == mode ? AppTheme.accent : AppTheme.textMuted)
                                }
                                .padding(.vertical, 4)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .thinCard()

                    // Storage Settings (>= 3 GB minimum)
                    VStack(alignment: .leading, spacing: 10) {
                        sectionHeader("STORAGE QUOTA SETTINGS (MIN 3.0 GB)")

                        HStack {
                            Text("Workspace Allocation")
                                .font(.system(size: 14, weight: .medium))
                                .foregroundStyle(AppTheme.textPrimary)
                            Spacer()
                            Text(String(format: "%.0f GB", quotaGB))
                                .font(.system(size: 14, weight: .bold, design: .monospaced))
                                .foregroundStyle(AppTheme.accent)
                        }

                        Slider(value: $quotaGB, in: 3.0...32.0, step: 1.0)
                            .tint(AppTheme.accent)
                            .onChange(of: quotaGB) { newValue in
                                container.updateStorageQuotaGB(newValue)
                            }

                        Text("Supports importing and managing multi-gigabyte IPA files (minimum 3 GB workspace floor, scaling up to 32 GB when iOS device storage permits).")
                            .font(.system(size: 11))
                            .foregroundStyle(AppTheme.textSecondary)

                        Divider().overlay(AppTheme.hairline)

                        Button {
                            container.rebuildDatabaseIndex()
                            indexRebuiltFeedback = true
                        } label: {
                            HStack {
                                Image(systemName: "arrow.triangle.2.circlepath")
                                    .foregroundStyle(AppTheme.accent)
                                Text(indexRebuiltFeedback ? "Database Index Verified & Rebuilt" : "Recover / Rebuild Offset Index from Disk")
                                    .font(.system(size: 13, weight: .medium))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Spacer()
                            }
                        }
                        .buttonStyle(.plain)
                    }
                    .thinCard()

                    // App Information
                    VStack(alignment: .leading, spacing: 8) {
                        sectionHeader("APPLICATION INFORMATION")
                        infoRow("Application", "DEVNVIOS IPA ANALYZER")
                        infoRow("Bundle ID", "com.devnvios.ipaanalyzer")
                        infoRow("Version", "2.0.0 (Build 1)")
                        infoRow("Binary Engine", "Mach-O 64-bit • ARM64 A64 • IL2CPP v24–v31+")
                        infoRow("ZIP Engine", "Streaming PKZIP / ZIP64 + zlib")
                        infoRow("Research Scope", "Authorized Binary & Metadata Analysis Only")
                        infoRow("Compliance", "No DRM/Anti-Cheat Bypass • No Financial Data")
                    }
                    .thinCard()
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Settings")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                let currentGB = Double(container.storageManager.configuredQuotaBytes) / 1_073_741_824.0
                quotaGB = max(3.0, round(currentGB))
            }
        }
    }

    private func sectionHeader(_ title: String) -> some View {
        Text(title)
            .font(.system(size: 11, weight: .semibold, design: .monospaced))
            .foregroundStyle(AppTheme.textMuted)
    }

    private func infoRow(_ label: String, _ value: String) -> some View {
        HStack(alignment: .top) {
            Text(label)
                .font(.system(size: 12))
                .foregroundStyle(AppTheme.textSecondary)
            Spacer(minLength: 12)
            Text(value)
                .font(.system(size: 12, weight: .medium, design: .monospaced))
                .foregroundStyle(AppTheme.textPrimary)
                .multilineTextAlignment(.trailing)
        }
    }
}
