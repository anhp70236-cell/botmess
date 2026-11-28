import SwiftUI

struct OptionsSheetView: View {
    @EnvironmentObject var vm: DumpViewModel
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationView {
            Form {
                Section(header: Text("Đầu ra chính")) {
                    Toggle(isOn: $vm.options.generateDumpCS) {
                        optionLabel("dump.cs", "Types, fields, properties and methods")
                    }
                    Toggle(isOn: $vm.options.generateStructAndJSON) {
                        optionLabel("Struct and JSON files", "il2cpp.h, script.json, stringliteral.json")
                    }
                    Toggle(isOn: $vm.options.generateDummyDLLs) {
                        optionLabel("Dummy DLLs", "Managed assemblies for dnSpy/ILSpy")
                    }
                }

                Section(header: Text("Thành phần trong dump.cs")) {
                    Toggle(isOn: $vm.options.includeMethods) {
                        optionLabel("Methods", "Include methods in dump.cs")
                    }
                    Toggle(isOn: $vm.options.includeFields) {
                        optionLabel("Fields", "Include fields in dump.cs")
                    }
                    Toggle(isOn: $vm.options.includeProperties) {
                        optionLabel("Properties", "Include properties in dump.cs")
                    }
                    Toggle(isOn: $vm.options.restoreAttributes) {
                        optionLabel("Attributes", "Restore available attributes")
                    }
                }

                Section(header: Text("Offsets, Indexes & Tokens")) {
                    Toggle(isOn: $vm.options.writeFieldOffsets) {
                        optionLabel("Field offsets", "Write field offsets")
                    }
                    Toggle(isOn: $vm.options.writeMethodOffsets) {
                        optionLabel("Method offsets", "Write RVA/VA/offset")
                    }
                    Toggle(isOn: $vm.options.writeTypeDefIndexes) {
                        optionLabel("TypeDef indexes", "Write type definition indexes")
                    }
                    Toggle(isOn: $vm.options.restoreDummyDllTokens) {
                        optionLabel("Dummy DLL tokens", "Restore metadata tokens")
                    }
                }

                Section(header: Text("Hiệu năng & Phân tích sâu")) {
                    Picker("Worker threads", selection: $vm.options.workerThreads) {
                        Text("1 Worker").tag(1)
                        Text("2 Workers (Khuyên dùng)").tag(2)
                        Text("4 Workers").tag(4)
                    }
                    Toggle(isOn: $vm.options.deepMachOScan) {
                        optionLabel("Deep Mach-O & ARM64 Scan", "Obj-C, Swift 5, .ctor init values & Fallback")
                    }
                    Toggle(isOn: $vm.options.autoExportZip) {
                        optionLabel("Tự động nén .ZIP", "Đóng gói sẵn toàn bộ kết quả sau khi dump")
                    }
                }
            }
            .navigationTitle("Tuỳ chọn Dump")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Mặc định") {
                        vm.options = .default
                    }
                }
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Xong") { dismiss() }
                        .font(.body.weight(.semibold))
                }
            }
        }
    }

    private func optionLabel(_ title: String, _ subtitle: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title).font(.subheadline.weight(.semibold))
            Text(subtitle).font(.caption).foregroundColor(.secondary)
        }
    }
}
