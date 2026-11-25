import SwiftUI
import UIKit

public struct AnalysisView: View {
    @EnvironmentObject private var container: AppContainer
    @EnvironmentObject private var pipeline: AnalysisPipelineCoordinator
    @State private var copiedLogsToast = false

    public init() {}

    private let pipelineChecklist: [(threshold: Double, title: String)] = [
        (0.10, "Kiểm tra tính hợp lệ & chính sách an toàn IPA"),
        (0.28, "Giải nén luồng PKZIP / ZIP64 dung lượng lớn"),
        (0.48, "Đọc cấu trúc Mach-O 64-bit & kiểm tra FairPlay"),
        (0.62, "Lõi C++17 quét đa luồng lệnh máy ARM64 & Swift5 Types"),
        (0.76, "Đối chiếu sâu IL2CPP CodeGenModule & Objective-C IMP"),
        (0.90, "Kiểm chứng chéo Prologue, Field [X0] & ánh xạ ASLR"),
        (0.99, "Tự động xuất tệp mang tên ứng dụng trên iPhone")
    ]

    public var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    // Main Progress Card
                    VStack(alignment: .leading, spacing: 11) {
                        HStack {
                            VStack(alignment: .leading, spacing: 3) {
                                Text(pipeline.currentGameName.isEmpty ? "Hệ Thống Dò C++17 & Swift" : pipeline.currentGameName)
                                    .font(.system(size: 16, weight: .bold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                    .lineLimit(1)
                                Text(pipeline.currentStageTitle)
                                    .font(.system(size: 12))
                                    .foregroundStyle(AppTheme.textSecondary)
                                    .lineLimit(2)
                            }
                            Spacer()
                            Text("\(Int(pipeline.progress * 100))%")
                                .font(.system(size: 20, weight: .bold, design: .monospaced))
                                .foregroundStyle(AppTheme.accent)
                        }

                        ProgressView(value: pipeline.progress)
                            .tint(AppTheme.accent)

                        // 7-Stage Deep Verification Checklist
                        VStack(alignment: .leading, spacing: 6) {
                            ForEach(Array(pipelineChecklist.enumerated()), id: \.offset) { _, step in
                                let done = pipeline.progress >= step.threshold
                                HStack(spacing: 8) {
                                    Image(systemName: done ? "checkmark.circle.fill" : "circle.dotted")
                                        .font(.system(size: 12))
                                        .foregroundStyle(done ? AppTheme.statusVerified : AppTheme.textMuted)
                                    Text(step.title)
                                        .font(.system(size: 11, weight: done ? .medium : .regular))
                                        .foregroundStyle(done ? AppTheme.textPrimary : AppTheme.textMuted)
                                        .lineLimit(1)
                                }
                            }
                        }
                        .padding(.top, 2)

                        HStack(spacing: 10) {
                            if pipeline.queuedCount > 1 {
                                Text("Hàng đợi còn: \(pipeline.queuedCount) IPA")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.textMuted)
                            }
                            Spacer()
                            if pipeline.isAnalyzing {
                                Button(role: .destructive) {
                                    pipeline.cancelCurrentAnalysis()
                                } label: {
                                    Label("Dừng Quét", systemImage: "xmark.circle")
                                        .font(.system(size: 12, weight: .semibold))
                                }
                                .buttonStyle(.bordered)
                                .tint(AppTheme.statusUndetermined)
                            } else {
                                Button {
                                    container.openIPAPicker()
                                } label: {
                                    Label("Chọn Tệp IPA", systemImage: "doc.badge.plus")
                                        .font(.system(size: 12, weight: .semibold))
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(AppTheme.accent)
                            }
                        }
                    }
                    .thinCard(padding: 15, cornerRadius: 14, elevated: true)

                    if let errorText = pipeline.lastErrorMessage {
                        HStack(alignment: .top, spacing: 10) {
                            Image(systemName: "exclamationmark.shield.fill")
                                .foregroundStyle(AppTheme.statusUndetermined)
                            Text(errorText)
                                .font(.system(size: 12))
                                .foregroundStyle(AppTheme.textPrimary)
                        }
                        .thinCard(padding: 12, cornerRadius: 12)
                    }

                    // Completed Output Summary
                    if let bundle = pipeline.latestCompletedBundle {
                        VStack(alignment: .leading, spacing: 10) {
                            HStack {
                                Text("KẾT QUẢ KIỂM CHỨNG THỰC TẾ")
                                    .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.textMuted)
                                Spacer()
                                Text("\(bundle.record.directoryName)_OFFSET.txt")
                                    .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.statusVerified)
                            }

                            HStack(spacing: 8) {
                                miniCountTile("Chuẩn 100%", count: bundle.report.verifiedCount, color: AppTheme.statusVerified)
                                miniCountTile("Chính xác cao", count: bundle.report.probableCount, color: AppTheme.statusProbable)
                                miniCountTile("Chưa rõ", count: bundle.report.unverifiedCount, color: AppTheme.statusUnverified)
                                miniCountTile("Token tĩnh", count: bundle.report.undeterminedCount, color: AppTheme.statusUndetermined)
                            }

                            HStack(spacing: 10) {
                                Button {
                                    pipeline.pendingExportPromptBundle = bundle
                                } label: {
                                    HStack(spacing: 5) {
                                        Image(systemName: "square.and.arrow.down.on.square.fill")
                                        Text("Chọn Lưu 1 Tệp / Tách Tệp")
                                    }
                                    .font(.system(size: 12, weight: .semibold))
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 9)
                                    .background(AppTheme.accent)
                                    .foregroundStyle(Color.white)
                                    .clipShape(RoundedRectangle(cornerRadius: 9, style: .continuous))
                                }
                                .buttonStyle(.plain)

                                Button {
                                    container.openGameDetails(bundle.record)
                                } label: {
                                    HStack(spacing: 5) {
                                        Text("Xem Chi Tiết (\(bundle.offsets.count))")
                                        Image(systemName: "arrow.right")
                                    }
                                    .font(.system(size: 12, weight: .semibold))
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 9)
                                    .background(AppTheme.surfaceElevated)
                                    .foregroundStyle(AppTheme.textPrimary)
                                    .clipShape(RoundedRectangle(cornerRadius: 9, style: .continuous))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .thinCard(padding: 14, cornerRadius: 14)
                    }

                    // Live Log Console
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text("NHẬT KÝ LÕI C++17 & MACH-O")
                                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                .foregroundStyle(AppTheme.textMuted)
                            Spacer()
                            if !pipeline.logEntries.isEmpty {
                                Button {
                                    let text = pipeline.logEntries.map(\.formattedLine).joined(separator: "\n")
                                    UIPasteboard.general.string = text
                                    copiedLogsToast = true
                                } label: {
                                    Text(copiedLogsToast ? "Đã chép Log" : "Sao chép Log")
                                        .font(.system(size: 11, weight: .medium))
                                        .foregroundStyle(AppTheme.accent)
                                }
                            }
                        }

                        if pipeline.logEntries.isEmpty {
                            Text("Chờ nạp tệp IPA... Lõi C++17 sẽ tự động quét đa luồng toàn bộ phân vùng __TEXT,__text, Swift5 Nominal Types, ObjC IMP và bảng Il2CppCodeGenModule để xuất kết quả chuẩn xác thực tế.")
                                .font(.system(size: 12, design: .monospaced))
                                .foregroundStyle(AppTheme.textMuted)
                                .padding(.vertical, 14)
                        } else {
                            ScrollView(.vertical) {
                                LazyVStack(alignment: .leading, spacing: 5) {
                                    ForEach(pipeline.logEntries) { item in
                                        HStack(alignment: .top, spacing: 6) {
                                            Text(item.formattedTime)
                                                .foregroundStyle(AppTheme.textMuted)
                                            Text(item.level.rawValue)
                                                .foregroundStyle(color(for: item.level))
                                                .frame(width: 44, alignment: .leading)
                                            Text("[\(item.stage)]")
                                                .foregroundStyle(AppTheme.accent)
                                            Text(item.message)
                                                .foregroundStyle(AppTheme.textPrimary)
                                                .fixedSize(horizontal: false, vertical: true)
                                        }
                                        .font(.system(size: 11, design: .monospaced))
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                    }
                                }
                            }
                            .frame(minHeight: 200, maxHeight: 320)
                        }
                    }
                    .thinCard(padding: 13, cornerRadius: 14)
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Phân tích")
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    private func miniCountTile(_ label: String, count: Int, color: Color) -> some View {
        VStack(spacing: 3) {
            Text("\(count)")
                .font(.system(size: 15, weight: .bold, design: .monospaced))
                .foregroundStyle(color)
            Text(label)
                .font(.system(size: 9, weight: .medium))
                .foregroundStyle(AppTheme.textSecondary)
                .lineLimit(1)
                .minimumScaleFactor(0.75)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 8)
        .background(AppTheme.surfaceElevated)
        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
    }

    private func color(for level: AnalysisLogLevel) -> Color {
        switch level {
        case .info: return AppTheme.textSecondary
        case .stage: return AppTheme.accent
        case .success: return AppTheme.statusVerified
        case .warning: return AppTheme.statusProbable
        case .error: return AppTheme.statusUndetermined
        }
    }
}
