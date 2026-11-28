import Foundation

public struct RestrictedCheckResult: Equatable, Sendable {
    public let isBlocked: Bool
    public let reason: String
}

public enum SafetyGuard {
    private static let bankExactRE = try! NSRegularExpression(
        pattern: #"\b(?:bank|banking|mbanking|ebanking|ibanking|digibank|mobilebanking|smartbanking|smartotp|softotp|etoken|vietcombank|vcbdigibank|bidv|vietinbank|vietinbankipay|techcombank|mbbank|mbmobile|acbone|vpbank|vpbankneo|tpbank|agribank|sacombank|sacombankpay|hdbank|myvib|seabank|eximbank|namabank|lpbank|lienviet|lienvietpostbank|abbank|bacabank|baovietbank|vietabank|vietbank|kienlongbank|pgbank|pvcombank|shinhan|shinhanbank|wooribank|timoplus|cakebyvpbank|standardchartered|publicbank|hongleong|momo|mservice|zalopay|vnpay|viettelpay|viettelmoney|shopeepay|airpay|payoo|onepay|foxpay|appotapay|napas|vneid|etax|vssid|bhxh|baohiemxahoi|paypal|venmo|cashapp|revolut|wise|stripe|alipay|wechatpay|chase|bankofamerica|wellsfargo|citibank|hsbc|barclays|monzo|starling|klarna|binance|coinbase|metamask|trustwallet|okx|bybit|kraken|phantom|ledgerlive|exodus|bitget|kucoin|fintech)\b"#,
        options: [.caseInsensitive]
    )

    private static let bankBundleRE = try! NSRegularExpression(
        pattern: #"(?:^|\.)(?:vcb|vietcombank|bidv|vietinbank|techcombank|tcb|mbbank|acb|vpbank|tpbank|agribank|sacombank|hdbank|vib|shb|msb|ocb|seabank|eximbank|namabank|lpbank|lienviet|abbank|bacabank|baoviet|vietabank|vietbank|kienlong|pgbank|ncb|pvcombank|shinhan|woori|timo|tnex|momo|mservice|zalopay|vnpay|viettelpay|viettelmoney|shopeepay|napas|vneid|vssid|etax|bank|banking|mbanking|ebanking|ibanking|digibank|smartotp|softotp|etoken|hsbc|citi|citibank|uob|cimb|scb|paypal|revolut|binance|coinbase|metamask|trustwallet)(?:\.|$)"#,
        options: [.caseInsensitive]
    )

    private static let bankFrameworkRE = try! NSRegularExpression(
        pattern: #"(?:smartotp|softotp|vnptekyc|fptekyc|vnpaysdk|zalopaysdk|momosdk|mbanking|ebanking|vietcombank|techcombank|bidv|vietinbank|vpbank|sacombank|agribank|mbbank)"#,
        options: [.caseInsensitive]
    )

    private static let iapSubstrRE = try! NSRegularExpression(
        pattern: #"(?:inapppurchase|in_app_purchase|storekit|skpayment|skproduct|skreceipt|skstoreproduct|purchaseproduct|purchaseitem|purchasepack|purchasebundle|purchasegem|purchasecoin|purchasediamond|purchasevip|purchasecurrency|buygems|buycoins|buydiamonds|buypack|buybundle|buyvip|buypremium|buycurrency|billingclient|billingmanager|billingservice|iapmanager|iapcontroller|iaphelper|iapservice|iapproduct|applepay|googlepay|verifyreceipt|restorecompletedtransactions|restorepurchases|paymenttransaction|paymentqueue|paywall|creditcard|realmoney|real_money|microtransaction|subscriptionpurchase|topuppack|checkoutflow|consumableproduct|nonconsumable|appstorereceipt|transactionreceipt)"#,
        options: [.caseInsensitive]
    )

    private static let iapTokenRE = try! NSRegularExpression(
        pattern: #"\b(?:iap|inapp|storekit|skpayment|skproduct|skreceipt|billing|checkout|payment|paywall|receipt|applepay|googlepay|creditcard|realmoney|microtrans|topup|recharge|voucher|coupon|invoice|entitlement|monetization|monetize|(?:iap|store|vip|premium|paid|autorenew|auto\s+renew)\s*subscription|subscription\s*(?:sku|receipt|price|tier|purchase|billing))\b"#,
        options: [.caseInsensitive]
    )

    private static let iapPairRE = try! NSRegularExpression(
        pattern: #"(?:\b(?:buy|purchase|spend|topup|recharge|pay)\s+(?:gem|gems|diamond|diamonds|gold|coin|coins|cash|money|currency|vip|premium|pack|bundle|pass|subscription|ads|noads|product|iap)\b|\b(?:add|set|get|grant|give|unlock|restore)\s+(?:gem|gems|diamond|diamonds|gold|coin|coins|cash|money|currency|vip|premium|noads|iap|product)\b)"#,
        options: [.caseInsensitive]
    )

    private static let iapShopPairRE = try! NSRegularExpression(
        pattern: #"\b(?:shop|store)\s+(?:purchase|buy|payment|billing|price|pack|vip|gem|diamond|gold|coin|money)\b"#,
        options: [.caseInsensitive]
    )

    private static func matches(_ regex: NSRegularExpression, _ text: String) -> Bool {
        let range = NSRange(text.startIndex..<text.endIndex, in: text)
        return regex.firstMatch(in: text, options: [], range: range) != nil
    }

    public static func normalizeTokens(_ raw: String) -> String {
        if raw.isEmpty { return "" }
        var out = ""
        out.reserveCapacity(raw.count + 8)
        let scalars = Array(raw.unicodeScalars)
        for i in 0..<scalars.count {
            let c = scalars[i]
            let isUpper = CharacterSet.uppercaseLetters.contains(c)
            let isAlnum = CharacterSet.alphanumerics.contains(c)
            if !isAlnum {
                if !out.hasSuffix(" ") { out.append(" ") }
                continue
            }
            if isUpper && i > 0 {
                let prev = scalars[i - 1]
                let prevLowerOrDigit = CharacterSet.lowercaseLetters.contains(prev) || CharacterSet.decimalDigits.contains(prev)
                let nextLower = (i + 1 < scalars.count) && CharacterSet.lowercaseLetters.contains(scalars[i + 1])
                if prevLowerOrDigit || (CharacterSet.uppercaseLetters.contains(prev) && nextLower) {
                    if !out.hasSuffix(" ") { out.append(" ") }
                }
            }
            out.append(Character(c).lowercased())
        }
        return out.trimmingCharacters(in: .whitespaces)
    }

    /// Strict real-money / In-App Purchase / StoreKit / payment symbol filter.
    public static func isRealMoneyBlocked(_ name: String) -> Bool {
        if name.isEmpty { return false }
        if matches(iapSubstrRE, name) { return true }
        let norm = normalizeTokens(name)
        if norm.isEmpty { return false }
        return matches(iapTokenRE, norm) || matches(iapPairRE, norm) || matches(iapShopPairRE, norm)
    }

    /// Blocks banking, finance, e-wallet, crypto wallet, and government ID apps.
    public static func detectRestrictedApp(
        fileName: String,
        bundleId: String,
        appName: String,
        executableName: String,
        urlSchemes: [String],
        entryPaths: [String]
    ) -> RestrictedCheckResult {
        if !bundleId.isEmpty && (matches(bankBundleRE, bundleId) || matches(bankExactRE, normalizeTokens(bundleId))) {
            return RestrictedCheckResult(
                isBlocked: true,
                reason: "Ứng dụng \"\(bundleId)\" thuộc nhóm tài chính/ngân hàng/nhạy cảm nên bị từ chối phân tích."
            )
        }
        let candidates = [appName, executableName, fileName, urlSchemes.joined(separator: " ")]
        for val in candidates where !val.isEmpty {
            if matches(bankExactRE, normalizeTokens(val)) || matches(bankExactRE, val) {
                return RestrictedCheckResult(
                    isBlocked: true,
                    reason: "Tệp \"\(String(val.prefix(80)))\" thuộc nhóm ứng dụng hạn chế, không được hỗ trợ."
                )
            }
        }
        for path in entryPaths {
            if path.contains("/Frameworks/") && matches(bankFrameworkRE, path) {
                return RestrictedCheckResult(
                    isBlocked: true,
                    reason: "IPA chứa framework nhạy cảm bị hạn chế, không được phép phân tích."
                )
            }
        }
        return RestrictedCheckResult(isBlocked: false, reason: "")
    }
}
