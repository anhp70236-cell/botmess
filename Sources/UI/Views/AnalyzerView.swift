import SwiftUI
import UniformTypeIdentifiers

struct AnalyzerView: View {
    @EnvironmentObject var vm: DumpViewModel
    @State private var showIPAPicker = false
    @State private var showMachOPicker = false
    @State private var showMetaPicker = false
    @State private var showOptionsSheet = false

    private var ipaContentTypes: [UTType] {
        var types: [UTType] = [.zip, .data]
        if let ipa = UTType(filenameExtension: "ipa") {
            types.insert(ipa, at: 0)
        }
        return types
    }

    var body: some View {
        let theme = vm.selectedTheme
        NavigationView {
            ZStack {
                theme.background.ignoresSafeArea()

                ScrollView(showsIndicators: false) {
                    VStack(spacing: 14) {
                        headerHero(theme)
                        inputSelectorCard(theme)
                        if let info = vm.inspectionInfo {
                            ipaInfoCard(info, theme: theme)
                        }
                        optionsSummaryCard(theme)
                        actionAndProgressCard(theme)
                        if let err = vm.errorMessage {
                            errorBanner(err, theme: theme)
                        }
                        if let session = vm.currentSession, !vm.isRunning {
                            completionSummaryCard(session, theme: theme)
                        }
                        if !vm.logs.isEmpty {
                            consoleLogCard(theme)
                        }
                    }
                    .padding(.horizontal, 16)
                    .padding(.vertical, 12)
                }
            }
            .navigationTitle("IPA Dumper Pro")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        showOptionsSheet = true
                    } label: {
                        Image(systemName: "checklist")
                            .foregroundColor(theme.accent)
                    }
                }
            }
            .sheet(isPresented: $showOptionsSheet) {
                OptionsSheetView()
                    .environmentObject(vm)
            }
            .fileImporter(isPresented: $showIPAPicker, allowedContentTypes: ipaContentTypes) { res in
                if case let .success(url) = res { vm.setIPAFile(url) }
            }
            .fileImporter(isPresented: $showMachOPicker, allowedContentTypes: [.data, .item]) { res in
                if case let .success(url) = res { vm.setMachOFile(url) }
            }
            .fileImporter(isPresented: $showMetaPicker, allowedContentTypes: [.data, .item]) { res in
                if case let .success(url) = res { vm.setMetadataFile(url) }
            }
        }
        .navigationViewStyle(.stack)
    }

    private func headerHero(_ theme: AppThemeStyle) -> some View {
        HStack(spacing: 12) {
            ZStack {
                RoundedRectangle(cornerRadius: 14, style: .continuous)
                    .fill(theme.accent.opacity(0.18))
                    .frame(width: 50, height: 50)
                Image(systemName: "cube.transparent.fill")
                    .font(.system(size: 24, weight: .semibold))
                    .foregroundColor(theme.accent)
            }
            VStack(alignment: .leading, spacing: 4) {
                Text("Streaming IL2CPP & Mach-O Engine")
                    .font(.headline)
                    .foregroundColor(theme.primaryText)
                HStack(spacing: 6) {
                    BadgeChip(text: "MAX 10 GB", color: theme.accent)
                    BadgeChip(text: "\(vm.options.workerThreads) WORKERS", color: theme.success)
                    BadgeChip(text: "ARM64", color: theme.warning)
                }
            }
            Spacer()
        }
        .dipCard(theme)
    }

    private func inputSelectorCard(_ theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Picker("Chế độ nhập", selection: $vm.inputMode) {
                ForEach(DumpViewModel.InputMode.allCases) { mode in
                    Text(mode.rawValue).tag(mode)
                }
            }
            .pickerStyle(.segmented)

            if vm.inputMode == .ipa {
                Button {
                    showIPAPicker = true
                } label: {
                    HStack(spacing: 12) {
                        Image(systemName: "doc.zipper")
                            .font(.title2)
                            .foregroundColor(theme.accent)
                        VStack(alignment: .leading, spacing: 3) {
                            Text(vm.selectedIPAURL?.lastPathComponent ?? "Chọn tệp .IPA (tối đa 10 GB)")
                                .font(.subheadline.weight(.semibold))
                                .foregroundColor(theme.primaryText)
                                .lineLimit(1)
                            Text("Đọc trực tiếp qua ZIP64 Central Directory, không tốn RAM")
                                .font(.caption)
                                .foregroundColor(theme.secondaryText)
                        }
                        Spacer()
                        Image(systemName: "chevron.right")
                            .foregroundColor(theme.secondaryText)
                    }
                    .padding(12)
                    .background(theme.elevatedBackground)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
            } else {
                VStack(spacing: 8) {
                    Button {
                        showMachOPicker = true
                    } label: {
                        fileRowLabel(
                            icon: "terminal.fill",
                            title: vm.selectedMachOURL?.lastPathComponent ?? "Chọn Mach-O (UnityFramework / Binary)",
                            subtitle: "Bắt buộc · ARM64 Mach-O đã giải mã FairPlay",
                            theme: theme
                        )
                    }
                    Button {
                        showMetaPicker = true
                    } label: {
                        fileRowLabel(
                            icon: "doc.text.magnifyingglass",
                            title: vm.selectedMetadataURL?.lastPathComponent ?? "Chọn global-metadata.dat (tuỳ chọn)",
                            subtitle: "Hỗ trợ Unity IL2CPP v20–v31",
                            theme: theme
                        )
                    }
                }
            }
        }
        .dipCard(theme)
    }

    private func fileRowLabel(icon: String, title: String, subtitle: String, theme: AppThemeStyle) -> some View {
        HStack(spacing: 10) {
            Image(systemName: icon)
                .font(.title3)
                .foregroundColor(theme.accent)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.subheadline.weight(.medium))
                    .foregroundColor(theme.primaryText)
                    .lineLimit(1)
                Text(subtitle)
                    .font(.caption)
                    .foregroundColor(theme.secondaryText)
            }
            Spacer()
            Image(systemName: "folder")
                .foregroundColor(theme.secondaryText)
        }
        .padding(10)
        .background(theme.elevatedBackground)
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
    }

    private func ipaInfoCard(_ info: IPAInspectionInfo, theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text("Thông tin IPA phát hiện")
                    .font(.subheadline.weight(.bold))
                    .foregroundColor(theme.primaryText)
                Spacer()
                Text(formatByteCount(info.fileSizeBytes))
                    .font(.caption.monospacedDigit())
                    .foregroundColor(theme.accent)
            }
            Divider().opacity(0.25)
            infoLine("Ứng dụng", "\(info.appName) (v\(info.bundleVersion))", theme: theme)
            infoLine("Bundle ID", info.bundleId.isEmpty ? "—" : info.bundleId, theme: theme)
            infoLine("Mach-O", URL(fileURLWithPath: info.primaryMachOPath).lastPathComponent, theme: theme)
            infoLine("Metadata", info.metadataPath != nil ? "Có global-metadata.dat" : "Không có (quét Mach-O)", theme: theme)
        }
        .dipCard(theme)
    }

    private func infoLine(_ k: String, _ v: String, theme: AppThemeStyle) -> some View {
        HStack {
            Text(k)
                .font(.caption)
                .foregroundColor(theme.secondaryText)
            Spacer()
            Text(v)
                .font(.caption.weight(.medium))
                .foregroundColor(theme.primaryText)
                .lineLimit(1)
        }
    }

    private func optionsSummaryCard(_ theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text("Cấu hình trích xuất")
                    .font(.subheadline.weight(.bold))
                    .foregroundColor(theme.primaryText)
                Spacer()
                Button("Tuỳ chỉnh chi tiết") {
                    showOptionsSheet = true
                }
                .font(.caption.weight(.semibold))
                .foregroundColor(theme.accent)
            }

            LazyVGrid(columns: [GridItem(.adaptive(minimum: 110), spacing: 6)], spacing: 6) {
                optionPill("dump.cs", active: vm.options.generateDumpCS, theme: theme)
                optionPill("il2cpp.h & JSON", active: vm.options.generateStructAndJSON, theme: theme)
                optionPill("Dummy DLLs", active: vm.options.generateDummyDLLs, theme: theme)
                optionPill("Methods/Fields", active: vm.options.includeMethods && vm.options.includeFields, theme: theme)
                optionPill("Properties", active: vm.options.includeProperties, theme: theme)
                optionPill("Attributes", active: vm.options.restoreAttributes, theme: theme)
                optionPill("Field Offsets", active: vm.options.writeFieldOffsets, theme: theme)
                optionPill("RVA/VA/Offset", active: vm.options.writeMethodOffsets, theme: theme)
                optionPill("TypeDef Index", active: vm.options.writeTypeDefIndexes, theme: theme)
                optionPill("DLL Tokens", active: vm.options.restoreDummyDllTokens, theme: theme)
            }
        }
        .dipCard(theme)
    }

    private func optionPill(_ label: String, active: Bool, theme: AppThemeStyle) -> some View {
        HStack(spacing: 4) {
            Image(systemName: active ? "checkmark.circle.fill" : "circle")
                .font(.system(size: 11))
                .foregroundColor(active ? theme.success : theme.secondaryText)
            Text(label)
                .font(.system(size: 11, weight: .medium))
                .foregroundColor(active ? theme.primaryText : theme.secondaryText)
                .lineLimit(1)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 5)
        .background(theme.elevatedBackground)
        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
    }

    private func actionAndProgressCard(_ theme: AppThemeStyle) -> some View {
        let canStart = !vm.isRunning && ((vm.inputMode == .ipa && vm.selectedIPAURL != nil) || (vm.inputMode == .standalone && vm.selectedMachOURL != nil))
        return VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Button {
                    if vm.isRunning { vm.cancelAnalysis() }
                    else { vm.startAnalysis() }
                } label: {
                    HStack {
                        Image(systemName: vm.isRunning ? "stop.circle.fill" : "play.fill")
                        Text(vm.isRunning ? "Huỷ phân tích" : "Bắt đầu Dump chuyên sâu")
                            .fontWeight(.semibold)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 13)
                    .foregroundColor(.white)
                    .background(vm.isRunning ? theme.danger : (canStart ? theme.accent : theme.secondaryText.opacity(0.4)))
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .disabled(!vm.isRunning && !canStart)
            }

            if vm.isRunning || vm.progress > 0 {
                VStack(alignment: .leading, spacing: 6) {
                    HStack {
                        Text(vm.currentPhase.rawValue)
                            .font(.caption.weight(.semibold))
                            .foregroundColor(theme.accent)
                        Spacer()
                        Text("\(Int(vm.progress * 100))%")
                            .font(.caption.monospacedDigit())
                            .foregroundColor(theme.primaryText)
                    }
                    ProgressView(value: vm.progress)
                        .tint(theme.accent)
                    Text(vm.statusMessage)
                        .font(.caption2)
                        .foregroundColor(theme.secondaryText)
                        .lineLimit(2)
                }
            }
        }
        .dipCard(theme)
    }

    private func errorBanner(_ msg: String, theme: AppThemeStyle) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundColor(theme.danger)
            Text(msg)
                .font(.caption)
                .foregroundColor(theme.primaryText)
            Spacer()
        }
        .padding(12)
        .background(theme.danger.opacity(0.14))
        .overlay(
            RoundedRectangle(cornerRadius: 12, style: .continuous)
                .stroke(theme.danger.opacity(0.4), lineWidth: 1)
        )
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
    }

    private func completionSummaryCard(_ s: DumpSessionRecord, theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Label("Kết quả trích xuất", systemImage: "checkmark.seal.fill")
                    .font(.subheadline.weight(.bold))
                    .foregroundColor(theme.success)
                Spacer()
                Text(String(format: "%.2fs", s.stats.elapsedSeconds))
                    .font(.caption.monospacedDigit())
                    .foregroundColor(theme.secondaryText)
            }

            Text(s.diagnostics.metadataStatus.summaryText)
                .font(.caption)
                .foregroundColor(s.diagnostics.metadataStatus.isValid ? theme.primaryText : theme.warning)

            LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())], spacing: 8) {
                statBox("Assemblies", "\(s.stats.assembliesCount)", theme: theme)
                statBox("Types", "\(s.stats.typesCount)", theme: theme)
                statBox("Methods", "\(s.stats.methodsCount)", theme: theme)
                statBox("Fields", "\(s.stats.fieldsCount)", theme: theme)
                statBox("Properties", "\(s.stats.propertiesCount)", theme: theme)
                statBox("Dummy DLLs", "\(s.stats.dummyDllsCount)", theme: theme)
            }

            if s.stats.filteredRealMoneyCount > 0 {
                Text("Đã lọc an toàn \(s.stats.filteredRealMoneyCount) symbol liên quan đến thanh toán / tiền thật.")
                    .font(.caption2)
                    .foregroundColor(theme.secondaryText)
            }
        }
        .dipCard(theme)
    }

    private func statBox(_ label: String, _ val: String, theme: AppThemeStyle) -> some View {
        VStack(spacing: 2) {
            Text(val)
                .font(.system(size: 16, weight: .bold, design: .monospaced))
                .foregroundColor(theme.accent)
            Text(label)
                .font(.caption2)
                .foregroundColor(theme.secondaryText)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 8)
        .background(theme.elevatedBackground)
        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
    }

    private func consoleLogCard(_ theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text("Nhật ký Worker")
                    .font(.caption.weight(.bold))
                    .foregroundColor(theme.primaryText)
                Spacer()
                Text("\(vm.logs.count) dòng")
                    .font(.caption2)
                    .foregroundColor(theme.secondaryText)
            }
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 4) {
                    ForEach(Array(vm.logs.enumerated()), id: \.offset) { _, line in
                        Text(line)
                            .font(.system(size: 11, design: .monospaced))
                            .foregroundColor(theme.secondaryText)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
            }
            .frame(maxHeight: 160)
        }
        .dipCard(theme)
    }
}
