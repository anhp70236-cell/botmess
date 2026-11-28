import SwiftUI

struct SettingsView: View {
    @EnvironmentObject var vm: DumpViewModel
    @State private var showClearConfirm = false
    @State private var diskSizeBytes: UInt64 = 0

    var body: some View {
        NavigationView {
            Form {
                Section(header: Text("Giao diện")) {
                    Picker("Chủ đề hiển thị", selection: $vm.selectedTheme) {
                        ForEach(AppThemeStyle.allCases) { theme in
                            Text(theme.rawValue).tag(theme)
                        }
                    }
                }

                Section(header: Text("Mặc định Worker & Dung lượng")) {
                    Picker("Worker threads", selection: $vm.options.workerThreads) {
                        Text("1 Worker").tag(1)
                        Text("2 Workers").tag(2)
                        Text("4 Workers").tag(4)
                    }
                    HStack {
                        Text("Giới hạn IPA tối đa")
                        Spacer()
                        Text("10 GB (Streaming ZIP64)")
                            .foregroundColor(.secondary)
                    }
                }

                Section(header: Text("Bộ nhớ tệp xuất")) {
                    HStack {
                        Text("Dung lượng đã dùng")
                        Spacer()
                        Text(formatByteCount(diskSizeBytes))
                            .foregroundColor(.secondary)
                    }
                    Button(role: .destructive) {
                        showClearConfirm = true
                    } label: {
                        Label("Xoá toàn bộ tệp đã xuất", systemImage: "trash")
                    }
                }

                Section(header: Text("Nguyên tắc an toàn & Phân tích tĩnh")) {
                    Text("• Ứng dụng thực hiện phân tích tĩnh hoàn toàn trên thiết bị (offline), hỗ trợ tệp .ipa lên tới 10 GB nhờ streaming ZIP64 + mmap.\n• Tự động lọc bỏ toàn bộ symbol/class liên quan đến giao dịch tiền thật, StoreKit, In-App Purchase, ngân hàng và ví điện tử.\n• Không bẻ khoá FairPlay DRM hay giải mã file global-metadata.dat bị mã hoá tuỳ chỉnh; khi gặp tệp được bảo vệ, app sẽ báo rõ trạng thái và tự động phân tích phần Mach-O rõ (Obj-C, Swift 5, Exports, ARM64).")
                        .font(.caption)
                        .foregroundColor(.secondary)
                }
            }
            .navigationTitle("Cài đặt")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                diskSizeBytes = vm.totalOutputsDiskBytes()
            }
            .alert("Xoá toàn bộ tệp xuất?", isPresented: $showClearConfirm) {
                Button("Huỷ", role: .cancel) {}
                Button("Xoá", role: .destructive) {
                    vm.clearAllOutputs()
                    diskSizeBytes = vm.totalOutputsDiskBytes()
                }
            } message: {
                Text("Thao tác này sẽ xoá tất cả thư mục dump.cs, JSON, DummyDll đã lưu trong ứng dụng.")
            }
        }
        .navigationViewStyle(.stack)
    }
}
