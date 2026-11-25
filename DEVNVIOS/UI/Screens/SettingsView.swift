import SwiftUI

public struct SettingsView: View {
    @EnvironmentObject private var container: AppContainer
    @State private var quotaGB: Double = 8.0
    @State private var indexRebuiltFeedback = false

    public init() {}

    public var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    BrandHeaderView()
                        .thinCard(padding: 15, cornerRadius: 15, elevated: true)

                    // Official Websites Introduction Card (devnvios.io.vn & iosmods.xyz)
                    VStack(alignment: .leading, spacing: 10) {
                        sectionHeader("GIỚI THIỆU HỆ SINH THÁI & WEBSITE CHÍNH THỨC")

                        Text("DEVNVIOS IPA ANALYZER v3.0 được phát triển chuyên biệt cho cộng đồng nghiên cứu kỹ thuật iOS với kiến trúc C++17 & Swift hiệu năng cao.")
                            .font(.system(size: 12))
                            .foregroundStyle(AppTheme.textSecondary)

                        if let devURL = URL(string: "https://devnvios.io.vn") {
                            Link(destination: devURL) {
                                websiteRow(
                                    title: "devnvios.io.vn",
                                    subtitle: "Cổng công cụ & tài nguyên nghiên cứu kỹ thuật DEVNVIOS chính thức",
                                    icon: "globe.asia.australia.fill"
                                )
                            }
                            .buttonStyle(.plain)
                        }

                        if let modsURL = URL(string: "https://iosmods.xyz") {
                            Link(destination: modsURL) {
                                websiteRow(
                                    title: "iosmods.xyz",
                                    subtitle: "Cộng đồng chia sẻ kiến thức & tiện ích iOS chuyên sâu",
                                    icon: "safari.fill"
                                )
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .thinCard()

                    // Theme Settings
                    VStack(alignment: .leading, spacing: 10) {
                        sectionHeader("GIAO DIỆN HIỂN THỊ (KHÔNG PHÁT SÁNG)")

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
                        sectionHeader("HẠN MỨC BỘ NHỚ LÀM VIỆC (TỐI THIỂU 3.0 GB)")

                        HStack {
                            Text("Hạn mức lưu trữ")
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

                        Rectangle().fill(AppTheme.hairline).frame(height: 0.75)

                        Button {
                            container.rebuildDatabaseIndex()
                            indexRebuiltFeedback = true
                        } label: {
                            HStack {
                                Image(systemName: "arrow.triangle.2.circlepath")
                                    .foregroundStyle(AppTheme.accent)
                                Text(indexRebuiltFeedback ? "Đã Khôi Phục Toàn Bộ Chỉ Mục!" : "Quét & Khôi Phục Dữ Liệu Offset Trên Máy")
                                    .font(.system(size: 13, weight: .medium))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Spacer()
                            }
                        }
                        .buttonStyle(.plain)
                    }
                    .thinCard()

                    // App Information & Legal Compliance
                    VStack(alignment: .leading, spacing: 8) {
                        sectionHeader("THÔNG TIN HỆ THỐNG & TUÂN THỦ PHÁP LÝ")
                        infoRow("Tên ứng dụng", "DEVNVIOS IPA ANALYZER")
                        infoRow("Phiên bản", "3.0.0 (C++17 & Swift Ultimate)")
                        infoRow("Lõi phân tích", "C++17 ARM64 • Swift5 • ObjC • IL2CPP")
                        infoRow("Hỗ trợ IPA", "Mọi Game & Ứng dụng hợp lệ")
                        infoRow("Chặn nghiêm ngặt", "Ngân hàng, Ví điện tử, Thanh toán & Tiền thật")
                    }
                    .thinCard()
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Cài đặt")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                let currentGB = Double(container.storageManager.configuredQuotaBytes) / 1_073_741_824.0
                quotaGB = max(3.0, round(currentGB))
            }
        }
    }

    private func websiteRow(title: String, subtitle: String, icon: String) -> some View {
        HStack(spacing: 12) {
            ZStack {
                RoundedRectangle(cornerRadius: 9, style: .continuous)
                    .fill(AppTheme.surfaceElevated)
                    .frame(width: 36, height: 36)
                Image(systemName: icon)
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundStyle(AppTheme.accent)
            }
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.system(size: 14, weight: .bold, design: .monospaced))
                    .foregroundStyle(AppTheme.textPrimary)
                Text(subtitle)
                    .font(.system(size: 11))
                    .foregroundStyle(AppTheme.textSecondary)
                    .lineLimit(2)
            }
            Spacer()
            Image(systemName: "arrow.up.right")
                .font(.system(size: 12, weight: .semibold))
                .foregroundStyle(AppTheme.accent)
        }
        .padding(10)
        .background(AppTheme.canvas.opacity(0.6))
        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: 10, style: .continuous)
                .strokeBorder(AppTheme.hairline, lineWidth: 0.75)
        )
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
