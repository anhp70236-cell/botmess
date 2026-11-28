# IPA Dumper Pro v2.0

Ứng dụng iOS native (SwiftUI + Pure-Swift IL2CPP & Mach-O Static Engine) build trực tiếp thành tệp `.ipa` thông qua GitHub Actions (`macos-15` + Xcode).

## Tính năng chính
- **Hỗ trợ IPA tới 10 GB**: Đọc trực tiếp bằng `pread` + ZIP64 Central Directory và `mmap`, giải nén streaming qua `zlib`, không nạp toàn bộ IPA vào RAM.
- **Đầu ra đầy đủ**:
  - `dump.cs`: Types, fields, properties, methods, attributes, field offsets, method RVA / VA / File Offset, TypeDef indexes.
  - Struct & JSON: `il2cpp.h`, `script.json`, `stringliteral.json`.
  - **Dummy DLLs**: Sinh tệp PE32 ECMA-335 hợp lệ mở được bằng **dnSpy / ILSpy**, khôi phục metadata tokens (`[Token]`, `[Address]`, `[FieldOffset]`).
- **Đa luồng Worker**: Mặc định **2 Workers** chạy song song (có thể chỉnh 1 / 2 / 4).
- **Chẩn đoán bảo vệ & Mach-O Fallback**: Phát hiện FairPlay `cryptid` và kiểm tra cấu trúc/entropy của `global-metadata.dat`; tự động fallback sang quét sâu Mach-O rõ (Obj-C, Swift 5, Exports Trie, `LC_SYMTAB`, `LC_FUNCTION_STARTS`, ARM64 XRefs) nếu metadata được bảo vệ.
- **Bộ lọc an toàn**: Tự động chặn ứng dụng tài chính/ngân hàng/ví điện tử và lọc bỏ toàn bộ symbol liên quan đến thanh toán tiền thật / StoreKit / In-App Purchase khỏi mọi đầu ra.

## Cài đặt `.ipa`
Tải `IPADumperPro.ipa` từ tab **Actions → Artifacts** hoặc mục **Releases** trên GitHub và cài qua TrollStore, AltStore, Sideloadly hoặc ESign (iOS 15.0+, arm64).
