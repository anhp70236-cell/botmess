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
                        .thinCard(padding: 14, cornerRadius: 13, elevated: true)

                    // Theme Settings
                    VStack(alignment: .leading, spacing: 10) {
                        sectionHeader("GIAO DIỆN HIỂN THỊ")

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
                        sectionHeader("HẠN MỨC LƯU TRỮ (TỐI THIỂU 3.0 GB)")

                        HStack {
                            Text("Hạn mức cấp phát")
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

                        Text("Hỗ trợ xử lý mượt các tệp IPA dung lượng lớn trên 3 GB (tối đa 32 GB tùy dung lượng trống thực tế của iPhone).")
                            .font(.system(size: 11))
                            .foregroundStyle(AppTheme.textSecondary)

                        Rectangle().fill(AppTheme.hairline).frame(height: 0.8)

                        Button {
                            container.rebuildDatabaseIndex()
                            indexRebuiltFeedback = true
                        } label: {
                            HStack {
                                Image(systemName: "arrow.triangle.2.circlepath")
                                    .foregroundStyle(AppTheme.accent)
                                Text(indexRebuiltFeedback ? "Đã Khôi Phục Chỉ Mục Từ Ổ Đĩa!" : "Khôi Phục Dữ Liệu Offset Từ Thư Mục Máy")
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
                        sectionHeader("THÔNG TIN HỆ THỐNG")
                        infoRow("Tên ứng dụng", "DEVNVIOS IPA ANALYZER")
                        infoRow("Định danh", "com.devnvios.ipaanalyzer")
                        infoRow("Phiên bản", "2.1.0 (Bản Tiếng Việt Chuyên Sâu)")
                        infoRow("Lõi phân tích", "Mach-O 64-bit • ARM64 A64 • IL2CPP CodeGen")
                        infoRow("Đường dẫn Tệp", "Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER")
                        infoRow("Phạm vi dò", "Máu, Vị trí, Tốc độ, Camera, Thực thể...")
                        infoRow("Chính sách", "Không dò tiền thật / thanh toán / ví")
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
