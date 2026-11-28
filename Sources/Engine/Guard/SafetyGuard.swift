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
        pattern: #"(?:\b(?:buy|purchase|spend|topup|recharge|pay)\s+(?:[a-z0-9]+\s+)?(?:gem|gems|diamond|diamonds|gold|coin|coins|cash|money|currency|vip|premium|pack|bundle|pass|subscription|ads|noads|product|iap)\b|\b(?:add|set|get|grant|give|unlock|restore)\s+(?:gem|gems|diamond|diamonds|gold|coin|coins|cash|money|currency|vip|premium|noads|iap|product)\b)"#,
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
        var out: [UInt8] = []
        out.reserveCapacity(raw.utf8.count + 8)
        let bytes = Array(raw.utf8)

        @inline(__always) func isUpperASCII(_ b: UInt8) -> Bool { b >= 65 && b <= 90 }
        @inline(__always) func isLowerASCII(_ b: UInt8) -> Bool { b >= 97 && b <= 122 }
        @inline(__always) func isDigitASCII(_ b: UInt8) -> Bool { b >= 48 && b <= 57 }

        for i in 0..<bytes.count {
            let b = bytes[i]
            if b >= 0x80 {
                // Non-ASCII UTF-8 byte: keep in word stream if valid
                out.append(b)
                continue
            }
            let isUpper = isUpperASCII(b)
            let isAlnum = isUpper || isLowerASCII(b) || isDigitASCII(b)
            if !isAlnum {
                if out.last != 32 && !out.isEmpty { out.append(32) }
                continue
            }
            if isUpper && i > 0 {
                let prev = bytes[i - 1]
                let prevLowerOrDigit = isLowerASCII(prev) || isDigitASCII(prev)
                let nextLower = (i + 1 < bytes.count) && isLowerASCII(bytes[i + 1])
                if prevLowerOrDigit || (isUpperASCII(prev) && nextLower) {
                    if out.last != 32 && !out.isEmpty { out.append(32) }
                }
            }
            out.append(isUpper ? (b + 32) : b)
        }
        while out.last == 32 { out.removeLast() }
        while out.first == 32 { out.removeFirst() }
        return String(decoding: out, as: UTF8.self)
    }

    private static let triggerBytePatterns: [[UInt8]] = [
        "iap", "inapp", "in_app", "store", "skp", "skr", "bill", "checkout",
        "pay", "receipt", "creditcard", "money", "micro", "topup", "recharg", "vouch",
        "coup", "invoic", "entitle", "monetiz", "subscr", "buy", "purch",
        "spend", "gem", "diam", "gold", "coin", "cash", "curren", "vip",
        "prem", "noads", "prod", "shop", "transact", "consum"
    ].map { Array($0.utf8) }

    private static let patternsByFirstByte: [[[UInt8]]] = {
        var buckets = [[[UInt8]]](repeating: [], count: 26)
        for pat in triggerBytePatterns {
            if let first = pat.first, first >= 97 && first <= 122 {
                buckets[Int(first - 97)].append(pat)
            }
        }
        return buckets
    }()

    @inline(__always)
    private static func scanTriggerBuffer(_ ptr: UnsafeBufferPointer<UInt8>) -> Bool {
        let n = ptr.count
        guard n >= 3 else { return false }
        for i in 0..<(n - 2) {
            var b0 = ptr[i]
            if b0 >= 65 && b0 <= 90 { b0 &+= 32 }
            guard b0 >= 97 && b0 <= 122 else { continue }
            let bucket = patternsByFirstByte[Int(b0 - 97)]
            if bucket.isEmpty { continue }
            for pat in bucket {
                let m = pat.count
                if i + m > n { continue }
                var matched = true
                var j = 1
                while j < m {
                    var bj = ptr[i + j]
                    if bj >= 65 && bj <= 90 { bj &+= 32 }
                    if bj != pat[j] {
                        matched = false
                        break
                    }
                    j += 1
                }
                if matched { return true }
            }
        }
        return false
    }

    private static func hasRealMoneyTrigger(_ raw: String) -> Bool {
        if let res = raw.utf8.withContiguousStorageIfAvailable({ scanTriggerBuffer($0) }) {
            return res
        }
        return ContiguousArray(raw.utf8).withUnsafeBufferPointer { scanTriggerBuffer($0) }
    }

    /// Strict real-money / In-App Purchase / StoreKit / payment symbol filter.
    public static func isRealMoneyBlocked(_ name: String) -> Bool {
        if name.isEmpty || !hasRealMoneyTrigger(name) { return false }
        return autoreleasepool {
            if matches(iapSubstrRE, name) { return true }
            let norm = normalizeTokens(name)
            if norm.isEmpty { return false }
            return matches(iapTokenRE, norm) || matches(iapPairRE, norm) || matches(iapShopPairRE, norm)
        }
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
