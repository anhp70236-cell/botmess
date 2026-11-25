import Foundation

/// Enforces strict research compliance boundaries:
/// 1. Blocks all identifiers related to real-money payments, IAP, StoreKit, billing, top-ups, wallets, or financial accounts.
/// 2. Never bypasses DRM, FairPlay, anti-cheat, or third-party application protections.
public enum SafetyPolicyFilter {
    private static let excludedFinancialTokens: [String] = [
        "storekit",
        "skpayment",
        "skproduct",
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
        "financial",
        "topup",
        "napthe",
        "tienmat",
        "diamondpack",
        "gempack",
        "purchaseitem",
        "buyproduct",
        "restorepurchase",
        "subscription"
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
}
