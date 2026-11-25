import Foundation

public enum AnalysisError: LocalizedError, Equatable, Sendable {
    case fileNotFound(String)
    case securityScopeDenied(String)
    case invalidIPAArchive(String)
    case corruptedArchive(String)
    case missingAppBundle
    case missingInfoPlist
    case invalidInfoPlist(String)
    case missingExecutable(String)
    case invalidMachOBinary(String)
    case unsupportedArchitecture(String)
    case metadataUnavailable(String)
    case insufficientStorage(requiredBytes: Int64, availableBytes: Int64)
    case cancelled
    case prohibitedFinancialApp(String)
    case databaseError(String)
    case exportError(String)
    case runtimeSnapshotInvalid(String)

    public var errorDescription: String? {
        switch self {
        case .fileNotFound(let path):
            return "Không tìm thấy tệp IPA tại đường dẫn: \(path)"
        case .securityScopeDenied(let name):
            return "iOS từ chối quyền đọc tệp đã chọn: \(name). Hãy thử chép tệp vào thư mục 'Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER'."
        case .invalidIPAArchive(let reason):
            return "Tệp IPA không hợp lệ: \(reason)"
        case .corruptedArchive(let reason):
            return "Dữ liệu nén ZIP/IPA bị lỗi: \(reason)"
        case .missingAppBundle:
            return "Không tìm thấy thư mục Payload/*.app bên trong tệp IPA."
        case .missingInfoPlist:
            return "Thiếu tệp Info.plist bên trong gói ứng dụng."
        case .invalidInfoPlist(let reason):
            return "Không thể đọc cấu trúc Info.plist: \(reason)"
        case .missingExecutable(let name):
            return "Không tìm thấy tệp thực thi nhị phân '\(name)' trong gói IPA."
        case .invalidMachOBinary(let reason):
            return "Định dạng nhị phân Mach-O không hợp lệ: \(reason)"
        case .unsupportedArchitecture(let arch):
            return "Kiến trúc CPU không được hỗ trợ: \(arch) (Yêu cầu ARM64 64-bit)."
        case .metadataUnavailable(let reason):
            return "Metadata IL2CPP không khả dụng: \(reason)"
        case .insufficientStorage(let requiredBytes, let availableBytes):
            return "Không đủ dung lượng bộ nhớ. Cần ít nhất \(HexFormatters.formatBytes(requiredBytes)), còn trống \(HexFormatters.formatBytes(availableBytes))."
        case .cancelled:
            return "Đã hủy quá trình phân tích theo yêu cầu."
        case .prohibitedFinancialApp(let appName):
            return "Từ chối phân tích '\(appName)': Hệ thống nghiêm cấm dò Offset đối với ứng dụng Ngân hàng, Ví điện tử, Thanh toán và Tài chính theo quy định pháp luật."
        case .databaseError(let reason):
            return "Lỗi cơ sở dữ liệu Offset: \(reason)"
        case .exportError(let reason):
            return "Lỗi xuất tệp kết quả: \(reason)"
        case .runtimeSnapshotInvalid(let reason):
            return "Dữ liệu Runtime JSON không hợp lệ: \(reason)"
        }
    }
}
