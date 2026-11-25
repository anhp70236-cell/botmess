import SwiftUI
import UIKit
import UniformTypeIdentifiers

public struct GameDetailRootView: View {
    @EnvironmentObject private var container: AppContainer

    public init() {}

    public var body: some View {
        NavigationStack {
            Group {
                if container.games.isEmpty {
                    ScrollView {
                        VStack(spacing: 16) {
                            EmptyStateCardView(
                                icon: "cylinder.split.1x2",
                                title: "Kho Offset Đang Trống",
                                message: "Sau khi chọn và phân tích tệp IPA, toàn bộ Offset (Máu, Thể lực, Vị trí, Di chuyển, Camera...) sẽ hiển thị tại đây và lưu trực tiếp trong ứng dụng Tệp (Files) của iPhone."
                            )
                            Button {
                                container.openIPAPicker()
                            } label: {
                                Label("Chọn Tệp IPA Để Dò Ngay", systemImage: "doc.badge.plus")
                                    .font(.system(size: 14, weight: .semibold))
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 10)
                            }
                            .buttonStyle(.borderedProminent)
                            .tint(AppTheme.accent)
                        }
                        .padding(16)
                    }
                } else if let selected = container.activeGameForDetails ?? container.games.first {
                    GameDetailContentView(record: selected)
                }
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle(container.activeGameForDetails?.gameName ?? "Kho Offset")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if container.games.count > 1 {
                    ToolbarItem(placement: .navigationBarLeading) {
                        Menu {
                            ForEach(container.games) { item in
                                Button {
                                    container.activeGameForDetails = item
                                } label: {
                                    Label(item.gameName, systemImage: item.id == container.activeGameForDetails?.id ? "checkmark.circle.fill" : "circle")
                                }
                            }
                        } label: {
                            HStack(spacing: 4) {
                                Image(systemName: "square.stack.3d.up")
                                Text("Đổi Game")
                                    .font(.system(size: 13, weight: .medium))
                            }
                            .foregroundStyle(AppTheme.accent)
                        }
                    }
                }
            }
        }
    }
}

struct GameDetailContentView: View {
    @EnvironmentObject private var container: AppContainer
    @EnvironmentObject private var pipeline: AnalysisPipelineCoordinator

    let record: GameRecord

    @State private var bundle: GameAnalysisBundle?
    @State private var loadError: String?

    @State private var searchText: String = ""
    @State private var selectedCategory: OffsetCategory?
    @State private var selectedStatus: OffsetVerificationStatus?

    @State private var inspectedOffset: DiscoveredOffset?
    @State private var isShowingExportChoiceSheet = false
    @State private var isShowingReportSheet = false
    @State private var isShowingRuntimeSheet = false
    @State private var isShowingTraceImporter = false
    @State private var confirmDeleteGame = false
    @State private var confirmDeleteIPA = false
    @State private var copiedAddressLabel: String?

    private var filteredOffsets: [DiscoveredOffset] {
        guard let bundle else { return [] }
        return container.databaseService.filterOffsets(
            bundle.offsets,
            searchQuery: searchText,
            category: selectedCategory,
            verificationStatus: selectedStatus
        )
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                if let bundle {
                    binarySummaryCard(bundle: bundle)
                    actionToolbarCard(bundle: bundle)
                    searchAndFilterSection(bundle: bundle)

                    if filteredOffsets.isEmpty {
                        EmptyStateCardView(
                            icon: "magnifyingglass",
                            title: "Không Có Offset Phù Hợp Bộ Lọc",
                            message: "Hãy xóa từ khóa tìm kiếm hoặc chọn nhóm danh mục khác."
                        )
                    } else {
                        LazyVStack(spacing: 10) {
                            ForEach(filteredOffsets) { offset in
                                OffsetRowCard(
                                    offset: offset,
                                    isCopied: copiedAddressLabel == offset.id.uuidString,
                                    onCopy: {
                                        let copyTarget = offset.rva > 0 ? offset.rvaFormatted : offset.value
                                        UIPasteboard.general.string = copyTarget
                                        withAnimation(.easeInOut(duration: 0.18)) {
                                            copiedAddressLabel = offset.id.uuidString
                                        }
                                    },
                                    onInspect: {
                                        inspectedOffset = offset
                                    }
                                )
                            }
                        }
                    }
                } else if let loadError {
                    EmptyStateCardView(icon: "exclamationmark.octagon", title: "Lỗi Đọc Cơ Sở Dữ Liệu", message: loadError)
                } else {
                    ProgressView("Đang tải dữ liệu \(record.directoryName)/...")
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 40)
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
        }
        .onAppear { reloadBundle() }
        .onChange(of: record.id) { _ in reloadBundle() }
        .onChange(of: record.lastAnalyzedAt) { _ in reloadBundle() }
        .sheet(item: $inspectedOffset) { item in
            OffsetInspectorSheet(offset: item, defaultBase: bundle?.metadata.defaultBaseAddress ?? 0x1_0000_0000)
        }
        .sheet(isPresented: $isShowingExportChoiceSheet) {
            if let bundle {
                ExportModePromptSheet(bundle: bundle) {}
                    .environmentObject(container)
            }
        }
        .sheet(isPresented: $isShowingReportSheet) {
            if let bundle {
                AnalysisReportSheet(bundle: bundle)
            }
        }
        .sheet(isPresented: $isShowingRuntimeSheet) {
            if let bundle {
                RuntimeInspectorSheet(
                    bundle: bundle,
                    onImportTraceTapped: {
                        isShowingRuntimeSheet = false
                        DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                            isShowingTraceImporter = true
                        }
                    }
                )
            }
        }
        .sheet(isPresented: $isShowingTraceImporter) {
            IPADocumentPicker(
                onPickURLs: { urls in
                    isShowingTraceImporter = false
                    if let first = urls.first {
                        applyAuthorizedRuntimeTrace(from: first)
                    }
                },
                onCancel: {
                    isShowingTraceImporter = false
                }
            )
        }
        .confirmationDialog("Xóa Tệp IPA Gốc?", isPresented: $confirmDeleteIPA, titleVisibility: .visible) {
            Button("Xóa IPA • Giữ Nguyên Offset", role: .destructive) {
                container.deleteStoredIPAOnly(for: record)
                reloadBundle()
            }
            Button("Hủy", role: .cancel) {}
        } message: {
            Text("Xóa tệp IPA dung lượng lớn để nhẹ máy nhưng giữ nguyên toàn bộ Offset trong \(record.directoryName)/.")
        }
        .confirmationDialog("Xóa Game \(record.gameName)?", isPresented: $confirmDeleteGame, titleVisibility: .visible) {
            Button("Xóa Hoàn Toàn", role: .destructive) {
                container.deleteGameCompletely(record)
            }
            Button("Hủy", role: .cancel) {}
        } message: {
            Text("Xóa vĩnh viễn thư mục \(record.directoryName)/ và toàn bộ kết quả đã lưu.")
        }
    }

    private func reloadBundle() {
        do {
            self.bundle = try container.databaseService.loadGameBundle(for: record)
            self.loadError = nil
        } catch {
            self.bundle = nil
            self.loadError = error.localizedDescription
        }
    }

    private func applyAuthorizedRuntimeTrace(from url: URL) {
        do {
            let data = try Data(contentsOf: url)
            let trace = try container.runtimeInspector.parseAuthorizedTraceJSON(data)
            container.selectedTab = .analysis
            pipeline.reanalyze(record: record, authorizedTrace: trace)
        } catch {
            container.alertMessage = error.localizedDescription
        }
    }

    @ViewBuilder
    private func binarySummaryCard(bundle: GameAnalysisBundle) -> some View {
        let meta = bundle.metadata
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                VStack(alignment: .leading, spacing: 3) {
                    Text(meta.gameName)
                        .font(.system(size: 17, weight: .bold))
                        .foregroundStyle(AppTheme.textPrimary)
                    Text("\(meta.bundleIdentifier) • v\(meta.bundleShortVersion) (\(meta.bundleBuildVersion))")
                        .font(.system(size: 11, design: .monospaced))
                        .foregroundStyle(AppTheme.textSecondary)
                }
                Spacer()
                Text("Games/\(record.directoryName)/")
                    .font(.system(size: 10, weight: .semibold, design: .monospaced))
                    .foregroundStyle(AppTheme.accent)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(AppTheme.accentSubtle)
                    .clipShape(RoundedRectangle(cornerRadius: 6, style: .continuous))
            }

            Rectangle().fill(AppTheme.hairline).frame(height: 0.8)

            LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], alignment: .leading, spacing: 8) {
                metaKeyValue("Module chính", meta.analysisModuleName)
                metaKeyValue("Kiến trúc CPU", meta.cpuArchitecture.uppercased())
                metaKeyValue("Mã hóa FairPlay", meta.encryptionState == .unencrypted ? "Không (cryptid=0)" : "Có (cryptid=\(meta.cryptID))")
                metaKeyValue("Ký hiệu Symbol", "\(meta.symbolStripStatus.rawValue) (\(meta.totalSymbolsCount))")
                metaKeyValue("Unity IL2CPP", meta.isUnityIL2CPP ? (meta.il2cppMetadataVersion.map { "Chuẩn v\($0)" } ?? meta.il2cppMetadataStatus) : "Không")
                metaKeyValue("ASLR Base", HexFormatters.vaHex(meta.defaultBaseAddress))
            }

            Text("Đã lưu trên iPhone: Tệp (Files) -> Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER -> Games/\(record.directoryName)/")
                .font(.system(size: 10, design: .monospaced))
                .foregroundStyle(AppTheme.statusVerified)
                .lineLimit(2)
        }
        .thinCard(padding: 14, cornerRadius: 13, elevated: true)
    }

    private func metaKeyValue(_ key: String, _ val: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(key.uppercased())
                .font(.system(size: 9, weight: .semibold, design: .monospaced))
                .foregroundStyle(AppTheme.textMuted)
            Text(val)
                .font(.system(size: 12, weight: .medium, design: .monospaced))
                .foregroundStyle(AppTheme.textPrimary)
                .lineLimit(1)
        }
    }

    @ViewBuilder
    private func actionToolbarCard(bundle: GameAnalysisBundle) -> some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                Button {
                    isShowingExportChoiceSheet = true
                } label: {
                    HStack(spacing: 5) {
                        Image(systemName: "square.and.arrow.down.on.square.fill")
                        Text("Xuất 1 Tệp / Tách Từng Tệp")
                    }
                    .font(.system(size: 12, weight: .bold))
                    .foregroundStyle(Color.white)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                    .background(AppTheme.accent)
                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                }

                Button {
                    isShowingReportSheet = true
                } label: {
                    actionChipLabel("Báo cáo & Lịch sử", icon: "doc.text.magnifyingglass")
                }

                Button {
                    isShowingRuntimeSheet = true
                } label: {
                    actionChipLabel("Runtime & ASLR", icon: "memorychip")
                }

                if record.hasStoredIPA {
                    Button {
                        container.selectedTab = .analysis
                        pipeline.reanalyze(record: record)
                    } label: {
                        actionChipLabel("Dò lại", icon: "arrow.clockwise")
                    }

                    Button {
                        confirmDeleteIPA = true
                    } label: {
                        actionChipLabel("Xóa IPA gốc", icon: "archivebox")
                    }
                }

                Button {
                    confirmDeleteGame = true
                } label: {
                    HStack(spacing: 5) {
                        Image(systemName: "trash")
                        Text("Xóa Game")
                    }
                    .font(.system(size: 12, weight: .medium))
                    .foregroundStyle(AppTheme.statusUndetermined)
                    .padding(.horizontal, 11)
                    .padding(.vertical, 8)
                    .background(AppTheme.surface)
                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                    .overlay(
                        RoundedRectangle(cornerRadius: 8, style: .continuous)
                            .strokeBorder(AppTheme.statusUndetermined.opacity(0.35), lineWidth: 0.8)
                    )
                }
            }
        }
        .buttonStyle(.plain)
    }

    private func actionChipLabel(_ title: String, icon: String) -> some View {
        HStack(spacing: 5) {
            Image(systemName: icon)
                .foregroundStyle(AppTheme.accent)
            Text(title)
                .foregroundStyle(AppTheme.textPrimary)
        }
        .font(.system(size: 12, weight: .medium))
        .padding(.horizontal, 11)
        .padding(.vertical, 8)
        .background(AppTheme.surface)
        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: 8, style: .continuous)
                .strokeBorder(AppTheme.hairline, lineWidth: 0.8)
        )
    }

    @ViewBuilder
    private func searchAndFilterSection(bundle: GameAnalysisBundle) -> some View {
        let totalCount = bundle.offsets.count
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass")
                    .foregroundStyle(AppTheme.textMuted)
                TextField("Tìm tên hàm, máu, vị trí, camera, RVA (0x...)", text: $searchText)
                    .font(.system(size: 13, design: .monospaced))
                    .foregroundStyle(AppTheme.textPrimary)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                if !searchText.isEmpty {
                    Button {
                        searchText = ""
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .foregroundStyle(AppTheme.textMuted)
                    }
                }
            }
            .thinCard(padding: 10, cornerRadius: 10)

            // Status Filter Chips
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    filterChip(title: "Tất cả (\(totalCount))", isSelected: selectedStatus == nil && selectedCategory == nil) {
                        selectedStatus = nil
                        selectedCategory = nil
                    }
                    ForEach(OffsetVerificationStatus.allCases) { st in
                        filterChip(title: st.vietnameseLabel, isSelected: selectedStatus == st) {
                            selectedStatus = (selectedStatus == st) ? nil : st
                        }
                    }
                }
            }

            // Vietnamese Category Filter Chips with live counts
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(OffsetCategory.allCases) { cat in
                        let cCount = bundle.report.categoryBreakdown[cat.rawValue] ?? 0
                        filterChip(
                            title: "\(cat.vietnameseTitle) (\(cCount))",
                            isSelected: selectedCategory == cat
                        ) {
                            selectedCategory = (selectedCategory == cat) ? nil : cat
                        }
                    }
                }
            }

            Text("Đang hiển thị \(filteredOffsets.count) / \(totalCount) offset")
                .font(.system(size: 11, design: .monospaced))
                .foregroundStyle(AppTheme.textMuted)
        }
    }

    private func filterChip(title: String, isSelected: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(title)
                .font(.system(size: 11, weight: isSelected ? .semibold : .regular))
                .foregroundStyle(isSelected ? AppTheme.accent : AppTheme.textSecondary)
                .padding(.horizontal, 10)
                .padding(.vertical, 6)
                .background(isSelected ? AppTheme.accentSubtle : AppTheme.surface)
                .clipShape(Capsule())
                .overlay(
                    Capsule()
                        .strokeBorder(isSelected ? AppTheme.accent.opacity(0.65) : AppTheme.hairline, lineWidth: 0.8)
                )
        }
        .buttonStyle(.plain)
    }
}

struct OffsetRowCard: View {
    let offset: DiscoveredOffset
    let isCopied: Bool
    let onCopy: () -> Void
    let onInspect: () -> Void

    var body: some View {
        Button(action: onInspect) {
            VStack(alignment: .leading, spacing: 8) {
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: offset.category.safeSymbolName)
                        .font(.system(size: 13))
                        .foregroundStyle(AppTheme.accent)
                        .frame(width: 20, height: 20)

                    VStack(alignment: .leading, spacing: 3) {
                        Text(offset.name)
                            .font(.system(size: 13, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textPrimary)
                            .lineLimit(2)
                            .multilineTextAlignment(.leading)

                        Text("\(offset.category.vietnameseTitle) • \(offset.dataType.vietnameseTitle) • \(offset.discoverySource.rawValue)")
                            .font(.system(size: 10))
                            .foregroundStyle(AppTheme.textSecondary)
                            .lineLimit(1)
                    }

                    Spacer(minLength: 4)
                    VerificationBadge(status: offset.verificationStatus)
                }

                HStack(spacing: 10) {
                    addressPair(offset.dataType == .field ? "FIELD" : "RVA", offset.rvaFormatted)
                    addressPair("GIÁ TRỊ / VA", offset.value)
                    addressPair("FILE OFF", offset.fileOffsetFormatted)
                    Spacer(minLength: 4)

                    Button(action: onCopy) {
                        HStack(spacing: 4) {
                            Image(systemName: isCopied ? "checkmark" : "doc.on.doc")
                            Text(isCopied ? "Đã chép" : "Chép")
                        }
                        .font(.system(size: 10, weight: .semibold))
                        .foregroundStyle(isCopied ? AppTheme.statusVerified : AppTheme.accent)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(AppTheme.surfaceElevated)
                        .clipShape(RoundedRectangle(cornerRadius: 6, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
            }
            .thinCard(padding: 12, cornerRadius: 12)
        }
        .buttonStyle(.plain)
    }

    private func addressPair(_ label: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(label)
                .font(.system(size: 8, weight: .semibold, design: .monospaced))
                .foregroundStyle(AppTheme.textMuted)
            Text(value)
                .font(.system(size: 11, weight: .medium, design: .monospaced))
                .foregroundStyle(AppTheme.textPrimary)
                .lineLimit(1)
        }
    }
}

struct OffsetInspectorSheet: View {
    @Environment(\.dismiss) private var dismiss
    let offset: DiscoveredOffset
    let defaultBase: UInt64
    @State private var customSlideHex: String = "0x04200000"

    private var computedRuntimeHex: String {
        guard let slide = HexFormatters.parseHex(customSlideHex),
              let rt = offset.runtimeAddress(withASLRSlide: slide) else {
            return "N/A"
        }
        return HexFormatters.vaHex(rt)
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    VStack(alignment: .leading, spacing: 8) {
                        VerificationBadge(status: offset.verificationStatus)
                        Text(offset.name)
                            .font(.system(size: 15, weight: .bold, design: .monospaced))
                            .foregroundStyle(AppTheme.textPrimary)
                        Text(offset.notes)
                            .font(.system(size: 12))
                            .foregroundStyle(AppTheme.textSecondary)
                    }
                    .thinCard()

                    VStack(alignment: .leading, spacing: 8) {
                        detailRow("Module thực thi", offset.moduleName)
                        detailRow("Nhóm chức năng", offset.category.vietnameseTitle)
                        detailRow("Loại dữ liệu", offset.dataType.vietnameseTitle)
                        detailRow("Giá trị / Địa chỉ ảo (Static VA)", offset.value)
                        detailRow("Địa chỉ RVA / Field Offset", offset.rvaFormatted)
                        detailRow("Vị trí trong tệp (File Offset)", offset.fileOffsetFormatted)
                        detailRow("Nguồn phát hiện", offset.discoverySource.rawValue)
                        detailRow("Độ tin cậy", String(format: "%.0f%%", offset.confidenceScore * 100))
                        if let sig = offset.signatureHint {
                            detailRow("Chữ ký lệnh ARM64 / Metadata Token", sig)
                        }
                        detailRow("Thời điểm phát hiện", ISO8601DateFormatter().string(from: offset.detectedAt))
                    }
                    .thinCard()

                    VStack(alignment: .leading, spacing: 8) {
                        Text("BỘ TÍNH ĐỊA CHỈ ĐỘNG ASLR")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        Text("Công thức: RuntimeVA = StaticVA (\(HexFormatters.vaHex(defaultBase)) + RVA) + ASLRSlide")
                            .font(.system(size: 11, design: .monospaced))
                            .foregroundStyle(AppTheme.textSecondary)

                        HStack {
                            Text("ASLR Slide:")
                                .font(.system(size: 12, design: .monospaced))
                                .foregroundStyle(AppTheme.textSecondary)
                            TextField("0x04200000", text: $customSlideHex)
                                .font(.system(size: 13, design: .monospaced))
                                .foregroundStyle(AppTheme.accent)
                                .textInputAutocapitalization(.never)
                                .autocorrectionDisabled()
                        }

                        HStack {
                            Text("Địa chỉ Runtime:")
                                .font(.system(size: 12, design: .monospaced))
                                .foregroundStyle(AppTheme.textSecondary)
                            Text(computedRuntimeHex)
                                .font(.system(size: 14, weight: .bold, design: .monospaced))
                                .foregroundStyle(AppTheme.statusVerified)
                            Spacer()
                            Button("Sao chép") {
                                UIPasteboard.general.string = computedRuntimeHex
                            }
                            .font(.system(size: 12, weight: .semibold))
                            .foregroundStyle(AppTheme.accent)
                        }
                    }
                    .thinCard()
                }
                .padding(16)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Chi Tiết Offset")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Đóng") { dismiss() }
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private func detailRow(_ title: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title)
                .font(.system(size: 10, weight: .semibold))
                .foregroundStyle(AppTheme.textMuted)
            Text(value)
                .font(.system(size: 12, design: .monospaced))
                .foregroundStyle(AppTheme.textPrimary)
                .textSelection(.enabled)
        }
    }
}

struct AnalysisReportSheet: View {
    @Environment(\.dismiss) private var dismiss
    let bundle: GameAnalysisBundle

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    VStack(alignment: .leading, spacing: 6) {
                        Text("BÁO CÁO 9 PHƯƠNG PHÁP PHÂN TÍCH")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        Text("Thời gian xử lý: \(String(format: "%.2f", bundle.report.durationSeconds))s • SHA-256: \(bundle.metadata.binarySHA256.prefix(16))...")
                            .font(.system(size: 11, design: .monospaced))
                            .foregroundStyle(AppTheme.textSecondary)
                    }
                    .thinCard()

                    if !bundle.report.warnings.isEmpty {
                        VStack(alignment: .leading, spacing: 6) {
                            Text("GHI CHÚ KỸ THUẬT & TRẠNG THÁI BẢO VỆ")
                                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                .foregroundStyle(AppTheme.statusProbable)
                            ForEach(bundle.report.warnings + bundle.report.limitations, id: \.self) { w in
                                Text("• \(w)")
                                    .font(.system(size: 12))
                                    .foregroundStyle(AppTheme.textPrimary)
                            }
                        }
                        .thinCard()
                    }

                    ForEach(bundle.report.methodSummaries) { method in
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text(method.methodName)
                                    .font(.system(size: 13, weight: .semibold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Spacer()
                                Text(method.status)
                                    .font(.system(size: 10, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.accent)
                            }
                            Text(method.details)
                                .font(.system(size: 11))
                                .foregroundStyle(AppTheme.textSecondary)
                        }
                        .thinCard(padding: 12)
                    }

                    if let cross = bundle.report.crossVersionDelta {
                        VStack(alignment: .leading, spacing: 6) {
                            Text("SO SÁNH XUYÊN PHIÊN BẢN (v\(cross.previousVersion) -> v\(cross.currentVersion))")
                                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                .foregroundStyle(AppTheme.accent)
                            Text("Khớp tên: \(cross.matchedCount) • Đổi địa chỉ RVA: \(cross.shiftedRVAEntries.count) • Mới: \(cross.newlyAddedCount) • Bị gỡ: \(cross.missingCount)")
                                .font(.system(size: 12))
                                .foregroundStyle(AppTheme.textPrimary)
                            ForEach(cross.shiftedRVAEntries.prefix(15), id: \.self) { line in
                                Text(line)
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.statusProbable)
                            }
                        }
                        .thinCard()
                    }

                    VStack(alignment: .leading, spacing: 6) {
                        Text("LỊCH SỬ CÁC LẦN QUÉT (\(bundle.history.count))")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        ForEach(bundle.history) { h in
                            HStack {
                                Text("v\(h.bundleVersion)")
                                    .font(.system(size: 12, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Spacer()
                                Text("\(h.totalOffsets) offset (\(h.verifiedCount) đã xác minh)")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.textSecondary)
                            }
                        }
                    }
                    .thinCard()
                }
                .padding(16)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Báo Cáo Chi Tiết")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Đóng") { dismiss() }
                }
            }
        }
        .preferredColorScheme(.dark)
    }
}

struct RuntimeInspectorSheet: View {
    @Environment(\.dismiss) private var dismiss
    let bundle: GameAnalysisBundle
    let onImportTraceTapped: () -> Void

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    VStack(alignment: .leading, spacing: 8) {
                        Text("ÁNH XẠ ĐỊA CHỈ ĐỘNG RUNTIME & ASLR")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        Text("Chế độ: \(bundle.runtime.observationMode)")
                            .font(.system(size: 13, weight: .semibold))
                            .foregroundStyle(AppTheme.textPrimary)
                        ForEach(bundle.runtime.notes, id: \.self) { note in
                            Text("• \(note)")
                                .font(.system(size: 12))
                                .foregroundStyle(AppTheme.textSecondary)
                        }
                        Button(action: onImportTraceTapped) {
                            Label("Nhập Tệp Runtime Trace (.json)", systemImage: "tray.and.arrow.down")
                                .font(.system(size: 12, weight: .semibold))
                        }
                        .buttonStyle(.borderedProminent)
                        .tint(AppTheme.accent)
                        .padding(.top, 4)
                    }
                    .thinCard()

                    VStack(alignment: .leading, spacing: 8) {
                        Text("BẢNG ÁNH XẠ ASLR (SLIDE \(HexFormatters.hex(bundle.runtime.sampleASLRSlide)))")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        ForEach(bundle.runtime.mappedRuntimeSymbols.prefix(25)) { sym in
                            VStack(alignment: .leading, spacing: 2) {
                                Text(sym.symbolName)
                                    .font(.system(size: 12, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Text("Runtime: \(HexFormatters.vaHex(sym.runtimeAddress)) -> Static VA: \(HexFormatters.vaHex(sym.resolvedStaticVA)) (RVA \(HexFormatters.rvaHex(sym.resolvedRVA)))")
                                    .font(.system(size: 10, design: .monospaced))
                                    .foregroundStyle(AppTheme.accent)
                            }
                            Rectangle().fill(AppTheme.hairline).frame(height: 0.8)
                        }
                    }
                    .thinCard()
                }
                .padding(16)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Runtime & ASLR")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Đóng") { dismiss() }
                }
            }
        }
        .preferredColorScheme(.dark)
    }
}
