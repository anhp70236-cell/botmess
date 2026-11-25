<?php
/* DEVNVIOS - docs.php v27 (tai lieu ve website, giao dien giong shopdsgm.vn)
   - v26: header + thanh tab duoi giong het devnvios_theme.php: logo devnvios.io.vn (lap loa), nut EN, nut sang/toi, avatar + menu tai khoan,
          thanh tab Home / Keys / Tools / History / Me (hoac Log in). Logo + tai khoan: theme (trang chu) ghi vao localStorage 'ds-docs-acc', docs doc ra (du phong: lay tu trang chu).
   - /docs.php            => danh sach tai lieu (chi hien Privacy Policy + Terms of Service)
   - /docs.php?doc=privacy | terms  => trang day du
   - v25: tieu de co icon cung dong + mau rieng, "Last Updated", muc danh so chu dam, tu khoa in dam,
          khoi footer (ten site, Legal Disclaimer, Policies, copyright), lien he qua Telegram @iosnea (khong dung Gmail).
   - about / keys / disclaimer van con trong mang $docs (vao bang ?doc=about ...), muon hien trong danh sach: them ten vao $listed.
   File doc lap: khong can db.php. Dung chung giao dien sang/toi (localStorage 'ds-theme') voi trang chinh.
   Cu phap noi dung: **chu dam**, {tg} = link Telegram, dong bat dau bang "- " = gach dau dong.
   Luu file o dang UTF-8 (khong BOM). */
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$siteName = 'DEVNVIOS';
$siteHost = 'iosmods.xyz';
$siteUrl  = 'https://iosmods.xyz';
$homeUrl  = '/';
$updated  = 'October 21, 2026';
$tgUser   = 'iosnea';
$tgUrl    = 'https://t.me/' . $tgUser;

/* tai lieu duoc hien trong danh sach */
$listed = ['privacy', 'terms'];

$ico = [
    'site'  => '<rect x="2" y="6" width="20" height="12" rx="4"/><path d="M7 10v4M5 12h4M16 11h.01M18 13h.01"/>',
    'key'   => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2m-4 4 3 3m-6-1 2 2"/>',
    'scale' => '<path d="M12 3v18M7 21h10M5 7h14M5 7l-3 7a3.5 3.5 0 0 0 6 0zM19 7l-3 7a3.5 3.5 0 0 0 6 0z"/>',
    'shield' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/>',
    'doc'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
    'home'  => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
    'chev'  => '<path d="m9 5 7 7-7 7"/>',
    'key2'  => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2m-4 4 3 3m-6-1 2 2"/>',
    'tools' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    'bag'   => '<path d="M6.2 8 8 4h8l1.8 4"/><rect x="4" y="8" width="16" height="12.5" rx="2.4"/><path d="M9 12.2a3 3 0 0 0 6 0"/>',
    'login' => '<circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3"/><path d="M6.2 18.6a6.5 6.5 0 0 1 11.6 0"/>',
    'lock'  => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'out'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
];
$svg = function ($n, $cls = 'ico') use ($ico) { return '<svg class="' . $cls . '" viewBox="0 0 24 24" aria-hidden="true">' . $ico[$n] . '</svg>'; };

/* in dam + link telegram (escape truoc roi moi doi **x** va {tg}) */
$fmt = function ($t) use ($e, $tgUrl, $tgUser, $siteUrl, $siteHost) {
    $s = $e($t);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    $s = str_replace('{tg}', '<a class="lnk" href="' . $e($tgUrl) . '" target="_blank" rel="noopener">@' . $e($tgUser) . '</a>', $s);
    $s = str_replace('{web}', '<a class="lnk" href="' . $e($siteUrl) . '" target="_blank" rel="noopener">' . $e($siteUrl) . '</a>', $s);
    return $s;
};

/* ===== Noi dung tai lieu ===== */
$docs = [
    'about' => [
        'title' => 'About DEVNVIOS', 'short' => 'What the website is and how it works', 'icon' => 'site', 'color' => '#3b9eff',
        'lead'  => 'DEVNVIOS is a key-based website where members open tools for iOS from one tidy place.',
        'sections' => [
            ['What DEVNVIOS is', [
                $siteName . ' (' . $siteHost . ') gives members access to a catalog of tools. You create an account, activate a VIP or Free key, and open the tools your key allows.',
                'Every tool has its own page with a description, its features, preview images, the upload date, and the iOS version it supports.',
            ]],
            ['Packages', [
                'Tools are grouped into packages. Package 1 holds VIP tools, Package 2 holds Free tools, and Package 3 holds iOS tools.',
                'Tap a package title on the Home tab to see every tool in that package and to search by name.',
            ]],
            ['How to get started', [
                '1. Sign up or log in.',
                '2. Open the Keys tab and activate your VIP or Free key.',
                '3. Choose a tool, read its page, then tap GET.',
                '4. Check the History tab to see the turns you received and the turns you used.',
            ]],
            ['Your account and security', [
                'The Me tab shows your plan and how many turns you have left. You can change your password from the Security section.',
                'Keep your password and your keys private. Anyone who has them can use your turns.',
            ]],
            ['Need help?', [
                'If a tool does not open, wait for the page to finish loading and tap GET again within the reopen window. For anything else, contact support on Telegram: {tg}.',
            ]],
        ],
    ],
    'keys' => [
        'title' => 'Keys & turns', 'short' => 'VIP and Free keys, turns, reopen window', 'icon' => 'key', 'color' => '#34c759',
        'lead'  => 'How VIP and Free keys work, what a turn is, and what happens when your turns run out.',
        'sections' => [
            ['Key types', [
                'A VIP key unlocks VIP tools. A Free key unlocks Free tools. Each tool page shows which key it needs (see Plan and Key needed).',
                'Use each key only on the tools it is meant for.',
            ]],
            ['Turns', [
                'Each key adds turns to your account. Opening a tool uses 1 turn. Your remaining turns are shown on the Me tab and in the account menu.',
            ]],
            ['Reopen window', [
                'If a tool does not open, tap GET again within 20 seconds. It reopens without using another turn.',
                'After that window you need to enter your key again before the tool can be opened.',
            ]],
            ['Turn history', [
                'The History tab lists turns received and turns used, grouped by day. A tool that was reopened inside the window is shown with 0 turns.',
                'On some accounts the history is kept on your device only. Clearing your browser data removes it.',
            ]],
            ['Removed keys', [
                'An administrator can remove a key that was misused. When that happens your turns go back to 0 and you will see a notice with the reason the next time you log in.',
            ]],
            ['Keep your keys private', [
                'A key is meant for one person. Sharing it can use up your turns and can lead to a block.',
            ]],
        ],
    ],
    'disclaimer' => [
        'title' => 'Legal disclaimer', 'short' => 'Using the tools and your responsibility', 'icon' => 'scale', 'color' => '#ff9f0a',
        'lead'  => 'Please read this before you use any tool on ' . $siteName . '.',
        'sections' => [
            ['Testing and entertainment', [
                'The tools are provided for testing and entertainment purposes only.',
            ]],
            ['Your responsibility', [
                'You are responsible for how you use any tool, including following the rules of the apps, games, and services you use it with. You use the tools at your own risk.',
            ]],
            ['No guarantee', [
                'Tools are provided as is. They can stop working when an app or service changes. We do not guarantee that a tool will always be available or that it will work on every device or iOS version.',
            ]],
            ['Independent website', [
                $siteName . ' is an independent website. It is not affiliated with, endorsed by, or sponsored by Apple, game publishers, or any other third party. Names, icons, and images are used only to identify tools.',
            ]],
            ['Other accounts', [
                'We are not responsible for any loss, restriction, or ban of accounts you hold in other apps or services.',
            ]],
        ],
    ],
    'privacy' => [
        'title' => 'Privacy Policy', 'short' => 'What we collect and why', 'icon' => 'shield', 'color' => '#ff8a65',
        'lead'  => '',
        'sections' => [
            ['Introduction & Application Identity', [
                'This Privacy Policy outlines how ' . $siteName . ' (operating at **{web}**, support on Telegram: **{tg}**) collects, utilizes, stores, and protects your information when you visit our website, register an account, or activate a key.',
            ]],
            ['Account Data Collection & Compliance Statement', [
                'When you register, we collect only the **username** and **password** you choose. Your password is used exclusively to sign you in and to protect your account.',
                '**Purpose of Account Data:** this data is used exclusively to verify your identity, securely run your member profile, and count the turns on your account.',
                'We do **not** request access to your email inbox, contacts, photos, files, or any other sensitive account or device permissions.',
            ]],
            ['Minimal Legitimate Data Collection', [
                'We keep the data we collect to the minimum needed to run the website:',
                '- **Account details:** the username and password you give us when you register.',
                '- **Key and turn activity:** which keys you activated and which tools you opened.',
                '- **Technical data:** your **IP address** and device type, used for security.',
                '- **Browser preferences:** the light or dark theme and your turn history, saved in your browser.',
            ]],
            ['How We Use Your Information', [
                'We use your information to run your account, count your turns, show your history, protect the website from abuse, and fix technical problems. We do not use it for advertising.',
            ]],
            ['Security, IP Checks & Abuse Prevention', [
                'We use IP checks and blocks to stop abuse. Administrators can block an **account**, a **key**, or an **IP address** that breaks the rules. Account data is available only to the website administrators.',
            ]],
            ['Data Stored on Your Device', [
                'Your theme, your turn history (when it is shown from your device), and short settings are kept in your browser storage. **Clearing your browser data removes them.**',
            ]],
            ['Data Sharing & Third Parties', [
                'We **never sell, rent, or trade** your personal information. We share data only when the law requires it or when it is needed to protect the website and its members.',
            ]],
            ['Your Choices & Data Rights', [
                'You can change your password at any time from the Me tab. If you have a question about your data, or you want your account reviewed, contact us using the details below. Continued use of the website constitutes consent to the latest version of this policy.',
            ]],
            ['Contact Information', [
                '- Support Telegram: {tg}',
                '- Website: {web}',
            ]],
        ],
    ],
    'terms' => [
        'title' => 'Terms of Service', 'short' => 'Rules for using the website', 'icon' => 'scale', 'color' => '#3b9eff',
        'lead'  => '',
        'sections' => [
            ['Operational Scope & Digital Access Intermediary', [
                $siteName . ' operates as an independent digital distribution gateway, facilitating authorized access through **VIP and Free keys** to software tools developed by third-party creators. Cataloged items are technically classified as **client-side utilities, touch interaction scripts, and local runtime customization tools** intended for iOS devices. The platform operates independently and does not host or distribute proprietary source code belonging to third parties. Application titles or graphics shown on the website serve strictly for indexing device compatibility.',
            ]],
            ['Accounts & Acceptable Use', [
                'You are responsible for your account and everything done with it. Keep your password private and give accurate information.',
                'You agree **not** to share, resell, or leak keys; **not** to bypass turns, key checks, or blocks; and **not** to attack, overload, or scrape the website or use it to harm others.',
            ]],
            ['Keys, Turns & Reopen Window', [
                'Each key adds turns to your account. A turn is used each time you open a tool, unless you reopen it inside the **20-second reopen window**. Keys and turns have **no cash value** and cannot be transferred.',
            ]],
            ['Absolute Discretionary Rights of the Platform', [
                'Administrators reserve the right to **remove keys, reset turns to 0, and block accounts or IP addresses** that break these terms, with or without prior notice. When a key is removed you will see a notice with the reason the next time you log in. The website and its tools can change, pause, or stop at any time, including for maintenance.',
            ]],
            ['Digital Goods Nature, Non-Refundable Policy & Anti-Chargeback', [
                'Keys represent intangible digital goods that deliver immediate access. Once a key is generated or delivered to a user account, the service is deemed fully rendered and **STRICTLY NON-REFUNDABLE AND NON-RETURNABLE** under any circumstances. We expressly reject all post-delivery refund claims or chargeback disputes through banking institutions, e-wallets, or merchant payment gateways. Fraudulent disputes will trigger permanent account termination and a permanent IP block.',
            ]],
            ['Third-Party Service Awareness & Complete Disclaimer', [
                'Apps, games, and online services employ their own automated systems and enforce independent publisher terms. Users fully acknowledge this and voluntarily assume **100% of all risks** regarding their third-party accounts, including suspension, restrictions, or bans. Use of the tools is covered by our Legal Disclaimer, and ' . $siteName . ' is not affiliated with, endorsed by, or sponsored by Apple, game publishers, or any other third party.',
            ]],
            ['Modifications & Consent', [
                'We may update these terms at any time. The date at the top shows the latest version. **Continued use constitutes full consent to the prevailing terms.** Questions can be sent to our support on Telegram: {tg}.',
            ]],
        ],
    ],
];

$key = (isset($_GET['doc']) && is_string($_GET['doc'])) ? strtolower(trim($_GET['doc'])) : '';
$doc = isset($docs[$key]) ? $docs[$key] : null;
$self = strtok(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/docs.php', '?');
if ($self === false || $self === '') { $self = '/docs.php'; }
$pageTitle = ($doc ? $doc['title'] : 'Documents') . ' - ' . $siteName;
$activeNav = $doc ? ($key === 'privacy' ? 'privacy' : ($key === 'terms' ? 'terms' : 'docs')) : 'docs';

$renderList = function () use ($docs, $listed, $self, $e, $svg) {
    $out = '';
    foreach ($docs as $k => $d) {
        if (!in_array($k, $listed, true)) { continue; }
        $out .= '<a class="doc" href="' . $e($self . '?doc=' . $k) . '"><span class="doc__ic">' . $svg($d['icon']) . '</span>'
            . '<span class="doc__t"><strong>' . $e($d['title']) . '</strong><span>' . $e($d['short']) . '</span></span>' . $svg('chev', 'ico ico--chev') . '</a>' . "\n";
    }
    return $out;
};

/* in cac doan van cua 1 muc: dong "- " thanh gach dau dong */
$renderParas = function ($paras) use ($fmt) {
    $out = ''; $inList = false;
    foreach ($paras as $p) {
        if (strncmp($p, '- ', 2) === 0) {
            if (!$inList) { $out .= "        <ul>\n"; $inList = true; }
            $out .= '            <li>' . $fmt(substr($p, 2)) . "</li>\n";
        } else {
            if ($inList) { $out .= "        </ul>\n"; $inList = false; }
            $out .= '        <p>' . $fmt($p) . "</p>\n";
        }
    }
    if ($inList) { $out .= "        </ul>\n"; }
    return $out;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($pageTitle) ?></title>
<meta name="theme-color" content="#000000">
<script>try{var __t=localStorage.getItem('ds-theme');if(__t==='light'||(__t==='auto'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches))document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<style>
    :root { color-scheme: dark; --bg: #000; --card: #0b0b0d; --field: #141416; --line: #1f1f23; --line2: #2a2a30; --h: #f5f5f7; --p: #a1a1aa; --link: #3b9eff; --soft: #141416; --nav: #0e0e10; --pill: rgba(59, 158, 255, .2); --hd: 54px; --logo-h: 47px; --hbtn: 32px; --nav-h: 56px; --red: #ff6961; --nav-glass: rgba(22, 22, 26, .46); --nav-glass-line: rgba(255, 255, 255, .07);
        --font: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif; }
    :root[data-theme="light"] { color-scheme: light; --bg: #f5f7fa; --card: #fff; --field: #f3f4f6; --line: #e5e7eb; --line2: #d1d5db; --h: #111827; --p: #4b5563; --link: #0a6cdc; --soft: #f3f4f6; --nav: #fff; --pill: rgba(10, 108, 220, .13); --red: #dc2626; --nav-glass: rgba(255, 255, 255, .52); --nav-glass-line: rgba(0, 0, 0, .06); }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html { overscroll-behavior-y: none; -webkit-text-size-adjust: 100%; background: var(--bg); }
    body { margin: 0; padding: calc(var(--hd) + 8px) 16px calc(var(--nav-h) + env(safe-area-inset-bottom, 0px) + 20px); font: 16px/1.6 var(--font); color: var(--h); background: var(--bg); -webkit-font-smoothing: antialiased; }
    a { color: inherit; text-decoration: none; }
    button { font: inherit; border: 0; cursor: pointer; }
    .wrap { max-width: 640px; margin: 0 auto; animation: soft-in .4s ease both; }
    @keyframes soft-in { from { opacity: 0; transform: translate3d(0, 6px, 0); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { .wrap { animation: none; } }

    .hd { position: fixed; top: 0; left: 0; right: 0; z-index: 900; display: flex; align-items: center; justify-content: space-between; height: var(--hd); padding: 0 14px; background: var(--bg); border-bottom: 1px solid var(--line); -webkit-transform: translate3d(0, 0, 0); transform: translate3d(0, 0, 0); }
    .brand { display: flex; align-items: center; height: 100%; min-width: 0; margin: 0; padding: 0; }
    .brand__txt { font-size: 20px; font-weight: 800; letter-spacing: -.02em; color: var(--h); }
    [hidden] { display: none !important; }

    .ds-logo { position: relative; display: block; flex: 0 1 auto; min-width: 0; max-width: 70vw; height: var(--logo-h); line-height: 0; overflow: hidden; contain: paint; -webkit-transform: translateZ(0) skewX(10deg); transform: translateZ(0) skewX(10deg); transform-origin: 50% 50%; }
    .brand__logo { display: block; width: auto; height: var(--logo-h); max-width: 70vw; margin: 0; padding: 0; object-fit: contain; }
    .ds-sw { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; transform-origin: 50% 50%; -webkit-mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .4) 28%, rgba(0, 0, 0, .72) 50%, rgba(0, 0, 0, .4) 72%, rgba(0, 0, 0, 0) 92%); mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .4) 28%, rgba(0, 0, 0, .72) 50%, rgba(0, 0, 0, .4) 72%, rgba(0, 0, 0, 0) 92%); will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden; -webkit-transition: opacity .6s ease; transition: opacity .6s ease; -webkit-animation: ds-sw35 1.6s linear infinite; animation: ds-sw35 1.6s linear infinite; -webkit-animation-delay: var(--ds-ph, 0s); animation-delay: var(--ds-ph, 0s); }
    .ds-swi { position: absolute; top: 0; left: 0; display: block; width: 100%; height: 100%; max-width: none; object-fit: contain; -webkit-filter: brightness(0) invert(1); filter: brightness(0) invert(1); opacity: 1; transform-origin: 50% 50%; will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden; -webkit-animation: ds-swi35 1.6s linear infinite; animation: ds-swi35 1.6s linear infinite; -webkit-animation-delay: var(--ds-ph, 0s); animation-delay: var(--ds-ph, 0s); }
    .ds-sw2, .ds-sw2 .ds-swi { -webkit-animation-delay: var(--ds-ph2, -.8s); animation-delay: var(--ds-ph2, -.8s); }
    @keyframes ds-sw35 { from { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } to { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } }
    @keyframes ds-swi35 { from { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } to { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } }
    html.ds-lg-off .ds-logo .ds-sw { opacity: 0; -webkit-transition-duration: .3s; transition-duration: .3s; }
    html.ds-lg-off .ds-logo .ds-sw, html.ds-lg-off .ds-logo .ds-swi { -webkit-animation-play-state: paused; animation-play-state: paused; }
    @media (prefers-reduced-motion: reduce) { .ds-sw { display: none; } }

    .acc { position: relative; }
    .av { position: relative; overflow: hidden; box-sizing: border-box; display: flex; align-items: center; justify-content: center; width: var(--hbtn); height: var(--hbtn); padding: 0; font-size: 14px; font-weight: 800; color: var(--h); background: var(--field); border: 0; border-radius: 50%; -webkit-clip-path: circle(50% at 50% 50%); clip-path: circle(50% at 50% 50%); }
    .av:active { transform: scale(.96); }
    .av__img { position: absolute; top: 0; left: 0; display: block; width: 100%; height: 100%; object-fit: cover; object-position: center; background: none; }
    .has-img > span { visibility: hidden; }
    .um-ov { position: fixed; left: 0; right: 0; top: 0; bottom: 0; z-index: 850; background: transparent; }
    .um { position: absolute; top: calc(100% + 9px); right: 0; z-index: 2000; box-sizing: border-box; width: min(220px, calc(100vw - 28px)); padding: 8px; text-align: left; line-height: 1.35; background: var(--nav); border: 1px solid var(--line2); border-radius: 16px; box-shadow: 0 8px 24px rgba(0, 0, 0, .35); transform-origin: 100% 0; animation: um-in .16s ease both; }
    :root[data-theme="light"] .um { box-shadow: 0 8px 24px rgba(0, 0, 0, .12); }
    @keyframes um-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { .um { animation: none; } }
    .um__top { display: flex; align-items: center; gap: 9px; padding: 2px 3px 8px; }
    .um__av { position: relative; overflow: hidden; display: flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 36px; height: 36px; font-size: 15px; font-weight: 800; color: var(--h); background: var(--field); border-radius: 12px; }
    .um__who { flex: 1 1 0; min-width: 0; }
    .um__hello { font-size: 10.5px; line-height: 1.2; font-weight: 600; color: #fff; }
    .um__name { margin: 0 0 3px; overflow: hidden; font-size: 14px; font-weight: 800; line-height: 1.25; color: #fff; text-overflow: ellipsis; white-space: nowrap; }
    .badge { display: inline-block; box-sizing: border-box; height: 17px; padding: 0 7px; font-size: 10px; font-weight: 700; line-height: 17px; white-space: nowrap; color: #fff; background: var(--soft); border-radius: 999px; }
    .badge.is-vip { background: rgba(255, 105, 97, .14); }
    .um__row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 0 0 2px; padding: 6px 10px; font-size: 12px; color: var(--p); background: var(--field); border-radius: 10px; }
    .um__row b { font-size: 14px; font-weight: 800; color: #fff; }
    .um__sep { height: 1px; margin: 4px 2px; background: var(--line); }
    .um__item { box-sizing: border-box; display: flex; align-items: center; gap: 9px; width: 100%; padding: 3px; font-size: 13px; font-weight: 600; text-align: left; color: #d1d5db; background: transparent; border: 0; border-radius: 10px; }
    .um__item:active { background: var(--field); }
    .um__item .ico { box-sizing: content-box; flex: 0 0 auto; width: 14px; height: 14px; padding: 5px; background: var(--field); border-radius: 9px; }
    .um__item--out { color: var(--red); }
    .um__item--out .ico { background: rgba(255, 105, 97, .14); }
    :root[data-theme="light"] .um__item { color: #374151; }
    :root[data-theme="light"] .um__item--out { color: var(--red); }
    :root[data-theme="light"] .um__hello, :root[data-theme="light"] .um__name, :root[data-theme="light"] .um__row b, :root[data-theme="light"] .badge { color: #000; }
    :root[data-theme="light"] .badge.is-vip { background: rgba(220, 38, 38, .1); }
    .hbtn { display: flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 0 8px; font-size: 12px; font-weight: 800; color: var(--h); background: var(--card); border: 1px solid var(--line2); border-radius: 10px; }
    .hbtn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .hd__r { display: flex; gap: 6px; }

    .ico { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .ico--chev { flex: 0 0 auto; width: 16px; height: 16px; stroke: var(--p); stroke-width: 2.2; }

    /* v28: giao dien phang, trung tinh (khong phat sang, khong mau me): the (card) + vien mong */
    .title { margin: 26px 0 20px; text-align: center; }
    .title h1 { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 0; font-size: 26px; font-weight: 800; line-height: 1.25; letter-spacing: -.01em; color: var(--h); }
    .title h1 .ico { flex: 0 0 auto; width: 26px; height: 26px; color: var(--p); }
    .title small { display: block; margin-top: 8px; font-size: 13px; color: var(--p); }
    .lead { margin: 0 0 18px; font-size: 15px; line-height: 1.65; color: var(--p); text-align: center; }
    .sec { margin: 0 0 12px; padding: 18px 18px 14px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; }
    .sec h2 { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 10px; font-size: 17px; font-weight: 700; line-height: 1.4; color: var(--h); }
    .sec__n { display: inline-flex; flex: 0 0 auto; align-items: center; justify-content: center; min-width: 24px; height: 24px; margin-top: 1px; padding: 0 6px; font-size: 12px; font-weight: 700; color: var(--p); background: var(--field); border: 1px solid var(--line2); border-radius: 8px; }
    .sec p { margin: 0 0 8px; font-size: 15px; line-height: 1.7; color: var(--p); }
    .sec p strong, .sec li strong { color: var(--h); font-weight: 700; }
    .sec ul { margin: 6px 0 4px; padding-left: 20px; color: var(--p); }
    .sec li { margin: 0 0 6px; font-size: 15px; line-height: 1.7; }
    .sec li::marker { color: var(--p); }
    .lnk { color: var(--link); text-decoration: underline; text-underline-offset: 2px; }
    .doc { display: flex; align-items: center; gap: 14px; margin: 0 0 10px; padding: 14px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; transition: background-color .2s ease; }
    .doc:active { background: var(--field); }
    .doc__ic { display: flex; flex: 0 0 44px; align-items: center; justify-content: center; width: 44px; height: 44px; color: var(--h); background: var(--field); border-radius: 12px; }
    .doc__t { flex: 1 1 0; min-width: 0; }
    .doc__t strong { display: block; font-size: 16px; font-weight: 700; }
    .doc__t span { display: block; font-size: 13.5px; color: var(--p); }

    .ft { margin: 32px 0 0; padding-top: 24px; border-top: 1px solid var(--line); }
    .ft__brand { display: flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 800; color: var(--h); }
    .ft__brand .ico { width: 20px; height: 20px; color: var(--p); }
    .ft__tag { margin: 6px 0 22px; max-width: 280px; font-size: 14px; line-height: 1.55; color: var(--p); }
    .ft__h { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 13px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--h); }
    .ft__h .ico { width: 18px; height: 18px; color: var(--p); }
    .ft__txt { margin: 0 0 22px; font-size: 13px; line-height: 1.6; color: var(--p); }
    .ft__lnk { display: block; padding: 7px 0; font-size: 14px; color: var(--p); transition: color .2s ease; }
    .ft__lnk.is-on { color: var(--h); font-weight: 700; }
    .ft__copy { margin: 22px 0 0; padding-top: 22px; font-size: 13px; text-align: center; color: var(--p); border-top: 1px solid var(--line); }
    /* v30: khoi cuoi trang giong theme (DEVNVIOS / Legal disclaimer / Documents) + dong "Made with ..." lay tu trang chu */
    .ds-docs { margin: 32px 0 26px; padding-top: 26px; border-top: 1px solid var(--line); }
    .ds-docs__blk { margin: 0 0 22px; }
    .ds-docs__h { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 14.5px; font-weight: 700; letter-spacing: .03em; line-height: 1.25; text-transform: uppercase; color: var(--h); }
    .ds-docs__h svg { flex: 0 0 auto; width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .ds-docs__h--brand { margin-bottom: 8px; font-size: 18px; letter-spacing: -.01em; text-transform: none; }
    .ds-docs__p { margin: 0; font-size: 14px; font-weight: 400; line-height: 1.45; color: var(--p); }
    .ds-docs > .ds-docs__h { margin: 0 0 6px; }
    .ds-docs a { color: inherit; text-decoration: none; }
    .ds-doclink { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 6px 0; font-size: 14px; font-weight: 400; line-height: 1.3; color: var(--p); }
    .ds-doclink:last-child { padding-bottom: 0; }
    .ds-doclink svg { display: none; }
    :root:not([data-theme="light"]) .ds-docs__p, :root:not([data-theme="light"]) .ds-doclink { color: #8e8e93; }
    .site-footer { margin: 0; padding: 22px 0 0; font-size: 11.5px; font-weight: 600; line-height: 1.3; text-align: center; color: var(--p); border-top: 1px solid var(--line); }
    .site-footer p { margin: 0; padding: 0; font-size: 11.5px; line-height: 1.35; }
    .site-footer p + p { margin-top: 2px; }
    .site-footer a { color: var(--link); }
    .site-footer { text-transform: none; }
    .site-footer:not(.is-ready) { visibility: hidden; }
    .site-footer svg { max-width: 24px; max-height: 24px; }
    .site-footer__heart { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; color: #ff3b30; -webkit-animation: beat .6s infinite; animation: beat .6s infinite; }
    .site-footer__year { display: block; margin-top: 3px; font-weight: 400; color: var(--p); }
    @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.2); } }
    @media (prefers-reduced-motion: reduce) { .site-footer__heart { -webkit-animation: none; animation: none; } }

    .nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1000; isolation: isolate; padding: 3px 10px calc(4px + env(safe-area-inset-bottom, 0px)); background: transparent; border: 0; -webkit-transform: translate3d(0, 0, 0); transform: translate3d(0, 0, 0); -webkit-user-select: none; user-select: none; }
    .nav::before { content: ""; position: absolute; z-index: -1; left: 0; right: 0; top: 0; bottom: -160px; pointer-events: none; background: var(--nav-glass); border-top: 1px solid var(--nav-glass-line); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); }
    @supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) { .nav::before { background: var(--nav); } }
    .nav__in { position: relative; display: flex; gap: 6px; max-width: 36rem; margin: 0 auto; }
    .nav__it { box-sizing: border-box; display: flex; flex: 1 1 0; flex-direction: column; align-items: center; gap: 1px; min-width: 0; padding: 5px 2px 4px; font-size: 10.5px; font-weight: 600; color: var(--p); background: transparent; border: 0; border-radius: 12px; transition: background-color .18s ease, color .18s ease; }
    .nav__it:active { background: var(--pill); }
    .nav__it.is-active { color: var(--link); background: var(--pill); }
    .nav__it .ico { width: 20px; height: 20px; }
    .nav__it span { display: block; max-width: 100%; overflow: hidden; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; }
</style>
</head>
<body>
<header class="hd">
    <a class="brand" id="dsBrand" href="<?= $e($homeUrl) ?>" data-txt="<?= $e(ucfirst(strtolower($siteHost))) ?>"></a>
    <div class="hd__r">
        <span class="hbtn">EN</span>
        <button class="hbtn" id="themeBtn" type="button" aria-label="Theme"></button>
        <div class="acc" id="dsAcc" hidden>
            <button class="av" id="dsAv" type="button" aria-label="Account" aria-haspopup="true" aria-expanded="false"><span id="dsAvL">U</span></button>
            <div class="um" id="dsUm" hidden>
                <div class="um__top">
                    <div class="um__av" id="dsUmAv"><span id="dsUmAvL">U</span></div>
                    <div class="um__who">
                        <div class="um__hello">Hello,</div>
                        <div class="um__name" id="dsUmName">Member</div>
                        <span class="badge" id="dsUmPlan">Member</span>
                    </div>
                </div>
                <div class="um__row"><span>Turns left</span><b id="dsUmTurns">0</b></div>
                <div class="um__sep"></div>
                <button class="um__item" type="button" data-view="me"><?= $svg('login') ?><span>Profile</span></button>
                <a class="um__item" href="/security.php"><?= $svg('lock') ?><span>Security</span></a>
                <button class="um__item" type="button" data-view="hist"><?= $svg('bag') ?><span>Turn history</span></button>
                <button class="um__item" type="button" data-view="keys"><?= $svg('key2') ?><span>Activate a key</span></button>
                <div class="um__sep"></div>
                <a class="um__item um__item--out" href="/logout"><?= $svg('out') ?><span>Log out</span></a>
            </div>
        </div>
    </div>
</header>
<div class="um-ov" id="dsUmOv" hidden></div>

<main class="wrap">
<?php if ($doc): ?>
    <div class="title">
        <h1><?= $svg($doc['icon']) ?><span><?= $e($doc['title']) ?></span></h1>
        <small>Last Updated: <?= $e($updated) ?></small>
    </div>
<?php if ($doc['lead'] !== ''): ?>
    <p class="lead"><?= $fmt($doc['lead']) ?></p>
<?php endif; ?>
<?php foreach ($doc['sections'] as $i => $sec): ?>
    <section class="sec">
        <h2><span class="sec__n"><?= (int) ($i + 1) ?></span><span><?= $e($sec[0]) ?></span></h2>
<?= $renderParas($sec[1]) ?>
    </section>
<?php endforeach; ?>
<?php else: ?>
    <div class="title">
        <h1><?= $svg('doc') ?><span>Documents</span></h1>
        <small>Everything about <?= $e($siteName) ?> in one place</small>
    </div>
    <p class="lead">Tap a document to read the full version.</p>
    <div style="margin-top: 10px"><?= $renderList() ?></div>
<?php endif; ?>

    <section class="ds-docs" id="dsDocs">
        <div class="ds-docs__blk">
            <h3 class="ds-docs__h ds-docs__h--brand"><?= $svg('site') ?><span><?= $e($siteName) ?></span></h3>
            <p class="ds-docs__p">Keys and tools for iOS in one place. Pick a package, activate a key, open a tool.</p>
        </div>
        <div class="ds-docs__blk">
            <h3 class="ds-docs__h"><?= $svg('scale') ?><span>Legal disclaimer</span></h3>
            <p class="ds-docs__p">Tools are provided for testing and entertainment purposes only. You are responsible for how you use them.</p>
        </div>
        <h3 class="ds-docs__h"><?= $svg('doc') ?><span>Documents</span></h3>
        <a class="ds-doclink" href="<?= $e($self . '?doc=privacy') ?>"><span>Privacy Policy</span></a>
        <a class="ds-doclink" href="<?= $e($self . '?doc=terms') ?>"><span>Terms of Service</span></a>
    </section>
    <footer class="site-footer" id="dsSiteFt">
        Made with
        <svg class="site-footer__heart" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
        by <a href="<?= $e($homeUrl) ?>"><?= $e($siteName) ?></a>
        <span class="site-footer__year">@2024</span>
    </footer>
</main>

<nav class="nav" aria-label="Main">
    <div class="nav__in">
        <a class="nav__it is-active" href="<?= $e($homeUrl) ?>" data-view="home"><?= $svg('home') ?><span>Home</span></a>
        <a class="nav__it" href="<?= $e($homeUrl) ?>" data-view="keys"><?= $svg('key2') ?><span>Keys</span></a>
        <a class="nav__it" href="<?= $e($homeUrl) ?>" data-view="tools"><?= $svg('tools') ?><span>Tools</span></a>
        <a class="nav__it" href="<?= $e($homeUrl) ?>" data-view="hist"><?= $svg('bag') ?><span>History</span></a>
        <a class="nav__it" id="dsLast" href="/dang-nhap" style="visibility: hidden"><?= $svg('login') ?><span>Log in</span></a>
    </div>
</nav>

<script>
(function () {
    var root = document.documentElement, btn = document.getElementById('themeBtn');
    var MOON = '<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
    var SUN = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4"/></svg>';
    var meta = document.querySelector('meta[name="theme-color"]');
    function paint() {
        var light = root.getAttribute('data-theme') === 'light';
        btn.innerHTML = light ? SUN : MOON;
        if (meta) meta.setAttribute('content', light ? '#f5f7fa' : '#000000');
    }
    btn.addEventListener('click', function () {
        var light = root.getAttribute('data-theme') === 'light';
        if (light) root.removeAttribute('data-theme'); else root.setAttribute('data-theme', 'light');
        try { localStorage.setItem('ds-theme', light ? 'dark' : 'light'); } catch (e) {}
        paint();
    });
    paint();
})();
</script>

<script>
/* v26: logo + tai khoan + thanh tab giong theme. Lay logo / dang nhap tu trang chu, nho lai de lan sau hien ngay. */
(function () {
    var HOME = <?= json_encode($homeUrl) ?>, KEY = 'ds-docs-acc', done = false, curLogo = null;
    var $ = function (i) { return document.getElementById(i); };
    function absUrl(u) { try { return new URL(u, window.location.origin).href; } catch (e) { return u; } }
    function load() { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }
    function save(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }
    function clean(t) { return String(t == null ? '' : t).replace(/\s+/g, ' ').trim(); }

    function paintLogo(src) {
        var b = $('dsBrand');
        if (!b || curLogo === src) return;
        curLogo = src;
        b.textContent = '';
        function txt() {
            b.textContent = '';
            var t = document.createElement('span');
            t.className = 'brand__txt';
            t.textContent = b.getAttribute('data-txt') || '';
            b.appendChild(t);
        }
        if (!src) { txt(); return; }
        var w = document.createElement('span'), im = new Image();
        w.className = 'ds-logo';
        im.className = 'brand__logo'; im.alt = 'DEVNVIOS'; im.decoding = 'async'; im.src = src;
        im.onerror = function () { curLogo = ''; txt(); };
        w.appendChild(im);
        for (var i = 0; i < 2; i++) {
            var sw = document.createElement('i'), c = new Image();
            sw.className = 'ds-sw' + (i ? ' ds-sw2' : '');
            sw.setAttribute('aria-hidden', 'true');
            c.className = 'ds-swi'; c.alt = ''; c.src = src;
            sw.appendChild(c);
            w.appendChild(sw);
        }
        b.appendChild(w);
    }

    function setAv(box, letterId, url) {
        var old = box.querySelector('.av__img');
        if (old) old.parentNode.removeChild(old);
        box.classList.remove('has-img');
        if (!url) return;
        var im = new Image();
        im.className = 'av__img'; im.alt = ''; im.decoding = 'async';
        im.referrerPolicy = 'no-referrer';
        im.onload = function () { box.classList.add('has-img'); };
        im.onerror = function () { if (im.parentNode) im.parentNode.removeChild(im); box.classList.remove('has-img'); };
        im.src = url;
        box.appendChild(im);
        if (im.complete && im.naturalWidth > 0) box.classList.add('has-img');
    }

    function apply(s) {
        done = true;
        paintLogo(s.logo || '');
        var acc = $('dsAcc'), last = $('dsLast');
        if (s.logged) {
            var nm = s.name || 'Member', ch = (Array.from(nm)[0] || 'U').toUpperCase();
            $('dsAvL').textContent = ch; $('dsUmAvL').textContent = ch;
            $('dsUmName').textContent = nm;
            var pl = $('dsUmPlan');
            pl.textContent = s.plan || 'Member';
            pl.className = 'badge' + (s.plan === 'VIP' ? ' is-vip' : '');
            $('dsUmTurns').textContent = (s.turns === null || s.turns === undefined) ? '\u2014' : String(s.turns);
            setAv($('dsAv'), 'dsAvL', s.avatar || '');
            setAv($('dsUmAv'), 'dsUmAvL', s.avatar || '');
            acc.hidden = false;
            last.setAttribute('href', HOME);
            last.setAttribute('data-view', 'me');
            last.querySelector('span').textContent = 'Me';
        } else {
            acc.hidden = true;
            last.setAttribute('href', '/dang-nhap');
            last.removeAttribute('data-view');
            last.querySelector('span').textContent = 'Log in';
        }
        last.style.visibility = 'visible';
    }

    function parse(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html'), out = { valid: false, logo: '', logged: false, name: '', plan: 'Member', turns: null, avatar: '' }, c = null;
        var lg = doc.querySelector('.brand__logo');
        if (lg && lg.getAttribute('src')) out.logo = absUrl(lg.getAttribute('src'));
        var cfg = doc.getElementById('app-config');
        out.valid = !!(doc.querySelector('.site-header') || cfg);
        if (cfg) { try { c = JSON.parse(cfg.textContent); } catch (e) { c = null; } }
        var av = doc.getElementById('ds-avatar');
        out.logged = !!((c && c.loggedIn) || av);
        var nEl = doc.querySelector('.user-bar__name');
        out.name = clean(nEl ? nEl.textContent : '').replace(/^(hello|hi)[\s,!:]+/i, '');
        var pEl = doc.querySelector('.user-bar__plan'), pt = clean(pEl ? pEl.textContent : '') + ' ' + (c && c.keyType ? c.keyType : '');
        if (/vip/i.test(pt) || (pEl && pEl.classList.contains('is-vip'))) out.plan = 'VIP';
        else if (/free/i.test(pt) || (pEl && pEl.classList.contains('is-free'))) out.plan = 'Free';
        if (c && c.turns !== undefined && c.turns !== null && !isNaN(parseInt(c.turns, 10))) out.turns = parseInt(c.turns, 10);
        else {
            var tc = doc.getElementById('turn-counter'), m = tc ? /\d+/.exec(tc.textContent || '') : null;
            if (m) out.turns = parseInt(m[0], 10);
        }
        var ai = av ? av.querySelector('.ds-av__img') : null;
        if (ai && ai.getAttribute('src')) out.avatar = ai.getAttribute('src');
        return out;
    }

    var cached = load();
    if (cached) apply(cached);
    try {
        fetch(HOME, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (h) {
                if (window.__dsFootSync) { window.__dsFootSync(h); }
                var s = parse(h);
                if (!s.valid) return; /* khong phai trang chu that (bi chan / loi): giu nguyen du lieu da nho */
                var c = load() || {};
                if (!s.logo && c.logo) s.logo = c.logo;
                if (s.logged && c.logged) {
                    if (!s.name && c.name) s.name = c.name;
                    if (!s.avatar && c.avatar) s.avatar = c.avatar;
                    if (s.turns === null && c.turns !== undefined) s.turns = c.turns;
                }
                delete s.valid;
                save(s);
                apply(s);
            })
            .catch(function () { if (!done) apply({ logged: false, logo: '' }); });
    } catch (e) { apply({ logged: false, logo: '' }); }
    setTimeout(function () { if (!done) apply({ logged: false, logo: '' }); }, 1800);

    /* menu tai khoan */
    var av = $('dsAv'), um = $('dsUm'), ov = $('dsUmOv');
    function umOpen(on) {
        um.hidden = !on; ov.hidden = !on;
        av.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    av.addEventListener('click', function (e) { e.stopPropagation(); umOpen(um.hidden); });
    ov.addEventListener('click', function () { umOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') umOpen(false); });

    /* Home / Keys / Tools / History / Me: mo trang chu dung man do (theme doc 'ds-view' trong sessionStorage) */
    function goView(v) {
        try {
            sessionStorage.setItem('ds-view', v);
            sessionStorage.removeItem('ds-st');
        } catch (e) {}
    }
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-view]') : null;
        if (!t) return;
        var v = t.getAttribute('data-view');
        goView(v);
        if (t.tagName === 'BUTTON') { e.preventDefault(); window.location.href = HOME; }
    });
    /* bam logo: ve thang trang chu, nhu theme */
    $('dsBrand').addEventListener('click', function () {
        try { sessionStorage.removeItem('ds-st'); sessionStorage.removeItem('ds-view'); sessionStorage.removeItem('ds-quiet'); } catch (e) {}
    });
})();
</script>
<script>
/* v29: lap loa logo giong theme - nhip khoa theo dong ho, chua tai xong thi an, thoat web thi an + tam dung, quay lai (hoac iOS dong bang lau) thi khoi dong lai MOT lan */
(function () {
    try {
        var root = document.documentElement, last = 0, tmA = 0, tmB = 0, hid = false, beat = Date.now(), t0 = Date.now(), ready = false;
        function phase() {
            var p = Date.now() % 1600;
            root.style.setProperty('--ds-ph', (-p / 1000) + 's');
            root.style.setProperty('--ds-ph2', (-((p + 800) % 1600) / 1000) + 's');
        }
        function off() { root.classList.add('ds-lg-off'); }
        function on() { root.classList.remove('ds-lg-off'); }
        function restart() {
            phase();
            var list = document.querySelectorAll('.ds-logo .ds-sw, .ds-logo .ds-swi');
            Array.prototype.forEach.call(list, function (el) {
                el.style.setProperty('animation', 'none', 'important');
                el.style.setProperty('-webkit-animation', 'none', 'important');
            });
            void root.offsetWidth;
            Array.prototype.forEach.call(list, function (el) {
                el.style.removeProperty('animation');
                el.style.removeProperty('-webkit-animation');
            });
        }
        function comeback() {
            var n = Date.now();
            if (n - last < 1200) return;
            last = n;
            clearTimeout(tmA); clearTimeout(tmB);
            off();
            tmA = setTimeout(function () { restart(); tmB = setTimeout(on, 140); }, 260);
        }
        function logoReady() {
            var l = document.querySelectorAll('.ds-logo img');
            if (!l.length) return false;
            for (var i = 0; i < l.length; i++) { if (!l[i].complete) return false; }
            return true;
        }
        function step() {
            if (ready) return;
            if (document.visibilityState === 'hidden') { setTimeout(step, 300); return; }
            if (logoReady() || Date.now() - t0 > 1800) { ready = true; phase(); on(); return; }
            setTimeout(step, 40);
        }
        phase();
        off();
        step();
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') { hid = true; off(); }
            else if (hid) { hid = false; comeback(); }
        });
        window.addEventListener('pagehide', function () { hid = true; off(); });
        window.addEventListener('pageshow', function (e) { if (e.persisted || hid) { hid = false; comeback(); } });
        setInterval(function () {
            var n = Date.now();
            if (n - beat > 9000 && document.visibilityState === 'visible') { comeback(); }
            beat = n;
        }, 3000);
    } catch (e) {}
})();
</script>
<script>
/* v30: khoi DEVNVIOS / Legal / Documents + dong "Made with ..." giong het trang chu (chu do admin chinh cung duoc lay theo). Nho lai trong localStorage 'ds-docs-foot'. */
(function () {
    var KEY = 'ds-docs-foot', sec = document.getElementById('dsDocs'), ft = document.getElementById('dsSiteFt');
    function clean(el) {
        var x = el.querySelectorAll('script,style,iframe,object,embed,link,meta'), i, n, j, a;
        for (i = 0; i < x.length; i++) { if (x[i].parentNode) x[i].parentNode.removeChild(x[i]); }
        x = el.querySelectorAll('*');
        for (i = 0; i < x.length; i++) {
            n = x[i];
            for (j = n.attributes.length - 1; j >= 0; j--) {
                a = n.attributes[j];
                if (/^on/i.test(a.name) || ((a.name === 'href' || a.name === 'src' || a.name === 'xlink:href') && /^\s*(javascript|data):/i.test(a.value))) n.removeAttribute(a.name);
            }
        }
        return el;
    }
    function apply(o) {
        if (!o) return;
        if (o.docs && sec) sec.innerHTML = o.docs;
        if (o.foot && ft) { ft.innerHTML = o.foot; }
        if (ft) { ft.classList.add('is-ready'); }
    }
    try { apply(JSON.parse(localStorage.getItem(KEY) || 'null')); } catch (e) {}
    setTimeout(function () { if (ft) ft.classList.add('is-ready'); }, 2500);
    window.__dsFootSync = function (html) {
        try {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            if (!(doc.querySelector('.site-header') || doc.getElementById('app-config'))) return;
            var d1 = doc.getElementById('ds-docs'), f1 = doc.querySelector('.site-footer'), o = {};
            if (d1) o.docs = clean(d1.cloneNode(true)).innerHTML;
            if (f1) o.foot = clean(f1.cloneNode(true)).innerHTML;
            if (!o.docs && !o.foot) return;
            try { localStorage.setItem(KEY, JSON.stringify(o)); } catch (e) {}
            apply(o);
        } catch (e) {}
    };
})();
</script>
<script id="ds-shine-keep">(function(){var d=document.documentElement,t;function on(){d.classList.remove('ds-lg-off');}
window.addEventListener('load',function(){setTimeout(on,300);});
window.addEventListener('pageshow',function(){setTimeout(on,300);});
document.addEventListener('visibilitychange',function(){if(!document.hidden)setTimeout(on,300);});
window.addEventListener('scroll',function(){clearTimeout(t);t=setTimeout(on,600);},{passive:true});
setTimeout(on,3500);})();</script>
<style id="ds-shine-soft">html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:not(.ds-lg-off) .ds-logo .ds-sw{opacity:.65!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-swi{-webkit-animation-duration:0.9s!important;animation-duration:0.9s!important;-webkit-animation-timing-function:linear!important;animation-timing-function:linear!important;-webkit-animation-iteration-count:infinite!important;animation-iteration-count:infinite!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw2,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw2 .ds-swi{-webkit-animation-delay:-0.45s!important;animation-delay:-0.45s!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-si{-webkit-animation-duration:0.9s!important;animation-duration:0.9s!important;-webkit-animation-timing-function:linear!important;animation-timing-function:linear!important;-webkit-animation-iteration-count:infinite!important;animation-iteration-count:infinite!important;-webkit-animation-delay:0s!important;animation-delay:0s!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s2,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s2 .dsx-si{-webkit-animation-delay:-0.45s!important;animation-delay:-0.45s!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-si{opacity:.6!important}</style>
</body>
</html>