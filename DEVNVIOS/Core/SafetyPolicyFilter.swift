import Foundation

/// Enforces strict legal & compliance boundaries:
/// 1. Blocks analyzing banking, e-wallet, payment gateway, investment, and financial management `.ipa` apps outright.
/// 2. Excludes all identifiers related to real money, IAP, StoreKit, billing, wallets, OTPs, and financial transactions.
/// 3. Never bypasses FairPlay DRM, encryption, or third-party protections.
public enum SafetyPolicyFilter {
    private static let prohibitedFinancialAppTokens: [String] = [
        "vietcombank", "techcombank", "mbbank", "bidv", "vpbank", "vietinbank", "agribank",
        "sacombank", "hdbank", "tpbank", "acbbank", "shbbank", "vibbank", "msbbank", "ocbbank",
        "seabank", "lpbank", "namabank", "eximbank", "pvcombank", "baovietbank", "abbank",
        "cake.vn", "timo.vn", "momo", "zalopay", "vnpay", "viettelmoney", "viettelpay",
        "shopeepay", "smartbanking", "ibanking", "ebanking", "mobilebanking", "digibank",
        "paypal", "stripe", "venmo", "cashapp", "revolut", "wise.com", "binance",
        "coinbase", "metamask", "trustwallet", "okx", "bybit"
    ]

    private static let excludedFinancialTokens: [String] = [
        "storekit", "skpayment", "skproduct", "inapppurchase", "iapmanager", "iapservice",
        "iap_", "billing", "payment", "wallet", "ewallet", "creditcard", "debitcard",
        "bankaccount", "banking", "stripe", "paypal", "applepay", "vnpay", "momo", "zalopay",
        "receiptvalidation", "transactionobserver", "purchasereceipt", "realmoney",
        "cashout", "checkout", "financial", "topup", "napthe", "tienmat", "chuyentien",
        "sodu", "taikhoannganhang", "diamondpack", "gempack", "purchaseitem", "buyproduct",
        "restorepurchase", "subscription", "otpcode", "smartotp", "pincode"
    ]

    private static let systemNoisePrefixes: [String] = [
        "__mh_execute_header", "___swift_", "__swift_FORCE_LOAD", "OBJC_METACLASS_$",
        "_OBJC_METACLASS_$", "l_OBJC_", "GCC_except_table", "___clang_"
    ]

    public static func isProhibitedFinancialApp(bundleID: String, displayName: String) -> Bool {
        let combined = "\(bundleID) \(displayName)".lowercased()
        for token in prohibitedFinancialAppTokens {
            if combined.contains(token) {
                return true
            }
        }
        if combined.contains("ngân hàng") || combined.contains("ngan hang") || combined.contains("ví điện tử") {
            return true
        }
        return false
    }

    public static func isPermittedIdentifier(_ identifier: String) -> Bool {
        let trimmed = identifier.trimmingCharacters(in: .whitespacesAndNewlines)
        guard trimmed.count >= 2 else { return false }
        for noise in systemNoisePrefixes where trimmed.hasPrefix(noise) {
            return false
        }
        let normalized = trimmed.lowercased()
        for token in excludedFinancialTokens {
            if normalized.contains(token) {
                return false
            }
        }
        return true
    }
}
