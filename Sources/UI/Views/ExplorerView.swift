import SwiftUI
import UIKit

struct ExplorerView: View {
    @EnvironmentObject var vm: DumpViewModel
    @State private var selectedRow: ExplorerRow?

    var body: some View {
        let theme = vm.selectedTheme
        let filtered = vm.filteredExplorerRows
        let displayed = Array(filtered.prefix(600))

        NavigationView {
            ZStack {
                theme.background.ignoresSafeArea()

                VStack(spacing: 10) {
                    // Search + Assembly Filter
                    VStack(spacing: 8) {
                        HStack(spacing: 8) {
                            Image(systemName: "magnifyingglass")
                                .foregroundColor(theme.secondaryText)
                            TextField("Tìm class, method, field, 0xRVA, token...", text: $vm.searchQuery)
                                .textInputAutocapitalization(.never)
                                .disableAutocorrection(true)
                                .foregroundColor(theme.primaryText)
                            if !vm.searchQuery.isEmpty {
                                Button {
                                    vm.searchQuery = ""
                                } label: {
                                    Image(systemName: "xmark.circle.fill")
                                        .foregroundColor(theme.secondaryText)
                                }
                            }
                        }
                        .padding(10)
                        .background(theme.cardBackground)
                        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                        .overlay(
                            RoundedRectangle(cornerRadius: 12, style: .continuous)
                                .stroke(theme.border, lineWidth: 1)
                        )

                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                kindChip("Tất cả", kind: nil, theme: theme)
                                ForEach(ExplorerItemKind.allCases, id: \.self) { kind in
                                    kindChip(kind.rawValue, kind: kind, theme: theme)
                                }
                            }
                        }

                        if vm.availableAssemblies.count > 1 {
                            HStack {
                                Text("Assembly:")
                                    .font(.caption)
                                    .foregroundColor(theme.secondaryText)
                                Picker("Assembly", selection: $vm.selectedAssemblyFilter) {
                                    ForEach(vm.availableAssemblies, id: \.self) { asm in
                                        Text(asm).tag(asm)
                                    }
                                }
                                .pickerStyle(.menu)
                                Spacer()
                                Text("\(filtered.count) mục")
                                    .font(.caption.monospacedDigit())
                                    .foregroundColor(theme.secondaryText)
                            }
                        }
                    }
                    .padding(.horizontal, 16)
                    .padding(.top, 8)

                    if displayed.isEmpty {
                        Spacer()
                        VStack(spacing: 10) {
                            Image(systemName: "tray")
                                .font(.system(size: 40))
                                .foregroundColor(theme.secondaryText)
                            Text(vm.explorerRows.isEmpty ? "Chưa có dữ liệu. Hãy chạy Dump ở tab Phân tích." : "Không tìm thấy kết quả khớp từ khoá.")
                                .font(.subheadline)
                                .foregroundColor(theme.secondaryText)
                                .multilineTextAlignment(.center)
                                .padding(.horizontal, 32)
                        }
                        Spacer()
                    } else {
                        ScrollView {
                            LazyVStack(spacing: 8) {
                                ForEach(displayed) { row in
                                    Button {
                                        selectedRow = row
                                    } label: {
                                        explorerRowCard(row, theme: theme)
                                    }
                                }
                                if filtered.count > 600 {
                                    Text("Đang hiển thị 600/\(filtered.count) kết quả đầu. Hãy nhập từ khoá để lọc chính xác hơn.")
                                        .font(.caption)
                                        .foregroundColor(theme.secondaryText)
                                        .padding(.vertical, 8)
                                }
                            }
                            .padding(.horizontal, 16)
                            .padding(.bottom, 16)
                        }
                    }
                }
            }
            .navigationTitle("Trình duyệt Offset")
            .navigationBarTitleDisplayMode(.inline)
            .sheet(item: $selectedRow) { row in
                ExplorerDetailSheet(row: row, theme: theme)
            }
        }
        .navigationViewStyle(.stack)
    }

    private func kindChip(_ title: String, kind: ExplorerItemKind?, theme: AppThemeStyle) -> some View {
        let isActive = vm.selectedKindFilter == kind
        return Button {
            vm.selectedKindFilter = kind
        } label: {
            Text(title)
                .font(.caption.weight(.semibold))
                .foregroundColor(isActive ? .white : theme.secondaryText)
                .padding(.horizontal, 12)
                .padding(.vertical, 6)
                .background(isActive ? theme.accent : theme.cardBackground)
                .clipShape(Capsule())
                .overlay(Capsule().stroke(theme.border, lineWidth: isActive ? 0 : 1))
        }
    }

    private func explorerRowCard(_ row: ExplorerRow, theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                BadgeChip(text: row.kind.rawValue.uppercased(), color: colorForKind(row.kind, theme: theme))
                Text(row.className)
                    .font(.caption)
                    .foregroundColor(theme.secondaryText)
                    .lineLimit(1)
                Spacer()
                if row.rvaHex != "—" && row.rvaHex != "0x0" {
                    BadgeChip(text: "RVA \(row.rvaHex)", color: theme.accent)
                } else if row.fieldOffsetHex != "—" {
                    BadgeChip(text: "OFF \(row.fieldOffsetHex)", color: theme.warning)
                }
            }
            Text(row.signature)
                .font(.system(size: 13, weight: .semibold, design: .monospaced))
                .foregroundColor(theme.primaryText)
                .lineLimit(2)
                .multilineTextAlignment(.leading)

            HStack(spacing: 10) {
                Text(row.assembly)
                    .font(.caption2)
                    .foregroundColor(theme.secondaryText)
                if row.tokenHex != "—" {
                    Text("Token: \(row.tokenHex)")
                        .font(.system(size: 11, design: .monospaced))
                        .foregroundColor(theme.secondaryText)
                }
                if !row.extraNote.isEmpty {
                    Text(row.extraNote)
                        .font(.caption2)
                        .foregroundColor(theme.success)
                        .lineLimit(1)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .dipCard(theme, radius: 13)
    }

    private func colorForKind(_ kind: ExplorerItemKind, theme: AppThemeStyle) -> Color {
        switch kind {
        case .method: return theme.accent
        case .field: return theme.warning
        case .property: return Color.purple
        case .type: return theme.success
        case .native: return Color.orange
        }
    }
}

private struct ExplorerDetailSheet: View {
    let row: ExplorerRow
    let theme: AppThemeStyle
    @Environment(\.dismiss) private var dismiss
    @State private var copiedLabel: String?

    var body: some View {
        NavigationView {
            List {
                Section(header: Text("Chữ ký")) {
                    Text(row.signature)
                        .font(.system(size: 13, design: .monospaced))
                        .textSelection(.enabled)
                    copyButton("Copy Chữ ký", value: row.signature)
                }

                Section(header: Text("Địa chỉ & Metadata")) {
                    detailItem("Assembly", row.assembly)
                    detailItem("Namespace", row.namespace.isEmpty ? "—" : row.namespace)
                    detailItem("Class / Struct", row.className)
                    detailItem("Kiểu dữ liệu", row.dataType)
                    if row.typeDefIndex >= 0 {
                        detailItem("TypeDefIndex", "\(row.typeDefIndex)")
                    }
                    detailItem("Token", row.tokenHex)
                    detailItem("RVA", row.rvaHex)
                    detailItem("VA", row.vaHex)
                    detailItem("File Offset", row.fileOffsetHex)
                    detailItem("Field Offset", row.fieldOffsetHex)
                    if !row.extraNote.isEmpty {
                        detailItem("Ghi chú / Giá trị", row.extraNote)
                    }
                }

                Section(header: Text("Sao chép nhanh")) {
                    if row.rvaHex != "—" { copyButton("Copy RVA (\(row.rvaHex))", value: row.rvaHex) }
                    if row.vaHex != "—" { copyButton("Copy VA (\(row.vaHex))", value: row.vaHex) }
                    if row.fieldOffsetHex != "—" { copyButton("Copy Field Offset (\(row.fieldOffsetHex))", value: row.fieldOffsetHex) }
                    if row.tokenHex != "—" { copyButton("Copy Token (\(row.tokenHex))", value: row.tokenHex) }
                }

                if let copied = copiedLabel {
                    Section {
                        Text("Đã sao chép: \(copied)")
                            .font(.caption.weight(.semibold))
                            .foregroundColor(theme.success)
                    }
                }
            }
            .navigationTitle(row.name)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Đóng") { dismiss() }
                }
            }
        }
    }

    private func detailItem(_ key: String, _ val: String) -> some View {
        HStack {
            Text(key).font(.caption).foregroundColor(.secondary)
            Spacer()
            Text(val).font(.system(size: 12, design: .monospaced))
        }
    }

    private func copyButton(_ title: String, value: String) -> some View {
        Button {
            UIPasteboard.general.string = value
            copiedLabel = value
        } label: {
            Label(title, systemImage: "doc.on.doc")
        }
    }
}
