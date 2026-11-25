import Foundation

/// Enforces research compliance boundaries:
/// 1. Never collect or index offsets related to real-money payments, billing, StoreKit transactions, wallets, or financial accounts.
/// 2. Never bypass encryption (FairPlay DRM), anti-cheat, or third-party application protections.
public enum SafetyPolicyFilter {
    private static let excludedFinancialTokens: [String] = [
        "storekit",
        "inapppurchase",
        "iapmanager",
        "iapservice",
        "iap_",
        "billing",
        "payment",
        "wallet",
        "creditcard",
        "debitcard",
        "bankaccount",
        "stripe",
        "paypal",
        "applepay",
        "receiptvalidation",
        "transactionobserver",
        "purchasereceipt",
        "realmoney",
        "cashout",
        "checkout",
        "financial"
    ]

    private static let thirdPartyProtectionIndicators: [String] = [
        "fairplay",
        "appattest",
        "devicecheck"
    ]

    public static func isPermittedIdentifier(_ identifier: String) -> Bool {
        let normalized = identifier.lowercased()
        for token in excludedFinancialTokens {
            if normalized.contains(token) {
                return false
            }
        }
        return true
    }

    public static func isProtectionIndicator(_ identifier: String) -> Bool {
        let normalized = identifier.lowercased()
        for indicator in thirdPartyProtectionIndicators {
            if normalized.contains(indicator) {
                return true
            }
        }
        return false
    }
}
