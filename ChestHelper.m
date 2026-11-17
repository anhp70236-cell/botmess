// ChestHelper.m (v2) — dylib nhúng vào TikTok IPA
// Menu tím + tự nhận diện icon rương (theo màu, không bấm nhầm icon khác)
// + mở rương + đồng bộ đồng hồ theo từng nhịp giây + bấm đúng lúc về 0
// + đọc kết quả (nhặt được / rương rỗng) + sinh nhật ký thao tác trong menu.

#import <UIKit/UIKit.h>
#import <QuartzCore/QuartzCore.h>
#import <CoreGraphics/CoreGraphics.h>
#import <objc/runtime.h>

#pragma mark - Màu

static UIColor *CHC(CGFloat r, CGFloat g, CGFloat b, CGFloat a) {
    return [UIColor colorWithRed:r / 255.0 green:g / 255.0 blue:b / 255.0 alpha:a];
}
#define C_BG     CHC(26, 18, 48, 0.97)
#define C_LINE   CHC(78, 58, 140, 1)
#define C_ACCENT CHC(124, 77, 255, 1)
#define C_SOFT   CHC(124, 77, 255, 0.20)
#define C_TEXT   CHC(237, 233, 254, 1)
#define C_MUTED  CHC(167, 155, 205, 1)

#pragma mark - Cửa sổ nổi (cho chạm xuyên qua phần trống)

@interface CHPassWindow : UIWindow
@end
@implementation CHPassWindow
- (UIView *)hitTest:(CGPoint)point withEvent:(UIEvent *)event {
    UIView *v = [super hitTest:point withEvent:event];
    if (v == self || v == self.rootViewController.view) return nil;
    return v;
}
@end

static CHPassWindow *gWindow = nil;

#pragma mark - Tiện ích view

static NSArray<UIWindow *> *CHTargetWindows(void) {
    NSMutableArray *out = [NSMutableArray array];
    for (UIScene *s in [UIApplication sharedApplication].connectedScenes) {
        if (![s isKindOfClass:[UIWindowScene class]]) continue;
        for (UIWindow *w in ((UIWindowScene *)s).windows) {
            if (w == gWindow || w.hidden) continue;
            if (w.windowLevel >= UIWindowLevelAlert + 50) continue;
            [out addObject:w];
        }
    }
    return out;
}

static CGRect CHRect(UIView *v) {
    if (!v.window) return CGRectZero;
    return [v convertRect:v.bounds toView:nil];
}

// View có thật sự đang hiện trên màn hình không (không ẩn, không trong suốt, nằm trong màn hình)
static BOOL CHVisible(UIView *v) {
    if (!v || !v.window) return NO;
    for (UIView *p = v; p; p = p.superview) {
        if (p.hidden || p.alpha < 0.05) return NO;
    }
    CGSize sc = [UIScreen mainScreen].bounds.size;
    CGRect r = CHRect(v);
    CGRect inter = CGRectIntersection(r, CGRectMake(0, 0, sc.width, sc.height));
    if (CGRectIsNull(inter)) return NO;
    return inter.size.width * inter.size.height > 0.5 * r.size.width * r.size.height;
}

static void CHCollectLabels(UIView *v, NSMutableArray *out) {
    if (v.hidden || v.alpha < 0.05) return;
    if ([v isKindOfClass:[UILabel class]]) {
        UILabel *l = (UILabel *)v;
        if (l.text.length) [out addObject:l];
    }
    for (UIView *s in v.subviews) CHCollectLabels(s, out);
}

// "00:05" -> 5, "1:30" -> 90, không đúng dạng -> -1
static NSInteger CHSeconds(NSString *t) {
    NSString *s = [t stringByTrimmingCharactersInSet:[NSCharacterSet whitespaceCharacterSet]];
    NSArray *p = [s componentsSeparatedByString:@":"];
    if (p.count != 2) return -1;
    NSString *a = p[0], *b = p[1];
    if (a.length < 1 || a.length > 2 || b.length != 2) return -1;
    NSCharacterSet *nd = [[NSCharacterSet decimalDigitCharacterSet] invertedSet];
    if ([a rangeOfCharacterFromSet:nd].location != NSNotFound) return -1;
    if ([b rangeOfCharacterFromSet:nd].location != NSNotFound) return -1;
    return a.integerValue * 60 + b.integerValue;
}

static BOOL CHHasAny(NSString *s, NSArray<NSString *> *keys) {
    for (NSString *k in keys) if ([s containsString:k]) return YES;
    return NO;
}

// Số nguyên đầu tiên trong chuỗi (bỏ dấu . ,) — không có thì -1
static NSInteger CHFirstInt(NSString *t) {
    NSMutableString *num = [NSMutableString string];
    BOOL started = NO;
    for (NSUInteger i = 0; i < t.length; i++) {
        unichar c = [t characterAtIndex:i];
        if (c >= '0' && c <= '9') { [num appendFormat:@"%C", c]; started = YES; }
        else if (started && (c == '.' || c == ',')) continue;
        else if (started) break;
    }
    return num.length ? num.integerValue : -1;
}

static BOOL CHIsPureInt(NSString *t) {
    NSString *s = [t stringByTrimmingCharactersInSet:[NSCharacterSet whitespaceCharacterSet]];
    if (!s.length) return NO;
    NSCharacterSet *nd = [[NSCharacterSet decimalDigitCharacterSet] invertedSet];
    return [s rangeOfCharacterFromSet:nd].location == NSNotFound;
}

// Chữ đặc trưng của sheet rương
static BOOL CHIsSheetKeyword(NSString *t) {
    NSString *l = t.lowercaseString;
    return [l containsString:@"rương báu"] || [l containsString:@"treasure"] || [l containsString:@"mở rương"];
}

// Tìm khung bottom-sheet chứa nhãn từ khoá (cao > 35% màn hình, rộng > 80%)
static UIView *CHFindContainer(UIView *kw) {
    CGSize sc = [UIScreen mainScreen].bounds.size;
    UIView *v = kw.superview;
    while (v) {
        CGRect r = CHRect(v);
        if (r.size.height > sc.height * 0.35 && r.size.width > sc.width * 0.8) return v;
        v = v.superview;
    }
    return nil;
}

#pragma mark - Giả lập bấm

static void CHFireGesture(UIGestureRecognizer *g) {
    @try {
        Ivar tv = class_getInstanceVariable([g class], "_targets");
        if (!tv) tv = class_getInstanceVariable([UIGestureRecognizer class], "_targets");
        NSArray *targets = tv ? object_getIvar(g, tv) : nil;
        for (id t in targets) {
            Ivar ia = class_getInstanceVariable([t class], "_action");
            Ivar it = class_getInstanceVariable([t class], "_target");
            if (!ia || !it) continue;
            SEL action = *(SEL *)((uint8_t *)(__bridge void *)t + ivar_getOffset(ia));
            id target = object_getIvar(t, it);
            if (!target || !action || ![target respondsToSelector:action]) continue;
            @try { [g setValue:@(UIGestureRecognizerStateEnded) forKey:@"state"]; } @catch (...) {}
            IMP imp = [target methodForSelector:action];
            ((void (*)(id, SEL, id))imp)(target, action, g);
        }
    } @catch (...) {}
}

// Bấm view thật sự nhận chạm (hitTest) rồi đi lên từng cấp cho tới khi có thứ bấm được.
// Không bao giờ bấm view quá to (nền / lớp phủ) để tránh bấm nhầm.
static BOOL CHActivate(UIView *start, UIView *stop, NSString **desc) {
    if (!start) return NO;
    CGSize sc = [UIScreen mainScreen].bounds.size;
    CGFloat big = sc.width * sc.height * 0.4;
    for (UIView *v = start; v; v = v.superview) {
        if (v == stop) break;
        CGRect r = CHRect(v);
        if (r.size.width * r.size.height > big) break;
        BOOL did = NO;
        if ([v isKindOfClass:[UIControl class]] && ((UIControl *)v).enabled) {
            [(UIControl *)v sendActionsForControlEvents:UIControlEventTouchUpInside];
            did = YES;
        }
        if (v.userInteractionEnabled) {
            for (UIGestureRecognizer *g in [v.gestureRecognizers copy]) {
                if ([g isKindOfClass:[UITapGestureRecognizer class]] && g.enabled) {
                    CHFireGesture(g);
                    did = YES;
                }
            }
        }
        if (!did && v.isAccessibilityElement && [v respondsToSelector:@selector(accessibilityActivate)]) {
            did = [v accessibilityActivate];
        }
        if (did) {
            if (desc) *desc = [NSString stringWithFormat:@"%@ %.0fx%.0f", NSStringFromClass([v class]), r.size.width, r.size.height];
            return YES;
        }
    }
    return NO;
}

#pragma mark - Nhận diện icon rương (theo tên lớp hoặc theo màu hồng-đỏ + vàng)

static void CHCollectIconCandidates(UIView *v, CGRect zone, NSMutableArray *out) {
    if (v.hidden || v.alpha < 0.05) return;
    CGRect r = CHRect(v);
    BOOL fits = r.size.width >= 34 && r.size.width <= 110 && r.size.height >= 34 && r.size.height <= 120;
    if (fits && ![v isKindOfClass:[UILabel class]]) {
        CGPoint c = CGPointMake(CGRectGetMidX(r), CGRectGetMidY(r));
        if (CGRectContainsPoint(zone, c)) { [out addObject:v]; return; }
    }
    for (UIView *s in v.subviews) CHCollectIconCandidates(s, zone, out);
}

static BOOL CHNamedChest(UIView *v) {
    NSMutableArray *vs = [NSMutableArray arrayWithObject:v];
    [vs addObjectsFromArray:v.subviews];
    for (UIView *x in vs) {
        NSString *s = [NSString stringWithFormat:@"%@ %@ %@", NSStringFromClass([x class]),
                       x.accessibilityLabel ?: @"", x.accessibilityIdentifier ?: @""].lowercaseString;
        if ([s containsString:@"chest"] || [s containsString:@"treasure"] || [s containsString:@"rương"]) return YES;
    }
    return NO;
}

// chest = tỉ lệ pixel đỏ-hồng + vàng; purple = tỉ lệ pixel tím (hộp quà); opq = số pixel đọc được
static void CHScore(UIView *v, CGFloat *chest, CGFloat *purple, int *opq) {
    *chest = 0; *purple = 0; *opq = 0;
    CGSize s = v.bounds.size;
    if (s.width < 4 || s.height < 4) return;
    @try {
        UIGraphicsImageRendererFormat *fmt = [UIGraphicsImageRendererFormat defaultFormat];
        fmt.scale = 1; fmt.opaque = NO;
        UIGraphicsImageRenderer *rd = [[UIGraphicsImageRenderer alloc] initWithSize:s format:fmt];
        UIImage *img = [rd imageWithActions:^(UIGraphicsImageRendererContext *ctx) {
            [v drawViewHierarchyInRect:v.bounds afterScreenUpdates:NO];
        }];
        if (!img.CGImage) return;
        const int N = 24;
        uint8_t buf[N * N * 4];
        memset(buf, 0, sizeof(buf));
        CGColorSpaceRef cs = CGColorSpaceCreateDeviceRGB();
        CGContextRef cg = CGBitmapContextCreate(buf, N, N, 8, N * 4, cs, kCGImageAlphaPremultipliedLast | kCGBitmapByteOrder32Big);
        CGColorSpaceRelease(cs);
        if (!cg) return;
        CGContextDrawImage(cg, CGRectMake(0, 0, N, N), img.CGImage);
        CGContextRelease(cg);
        int op = 0, red = 0, gold = 0, pur = 0;
        for (int i = 0; i < N * N; i++) {
            int a = buf[i * 4 + 3];
            if (a < 150) continue;
            int r = MIN(255, buf[i * 4] * 255 / a);
            int g = MIN(255, buf[i * 4 + 1] * 255 / a);
            int b = MIN(255, buf[i * 4 + 2] * 255 / a);
            op++;
            if (r > 190 && r - g > 90 && b < 170) red++;
            else if (r > 200 && g > 150 && b < 160 && r - b > 70) gold++;
            else if (b > 140 && r > 80 && r < 200 && g < 140 && b - g > 50) pur++;
        }
        *opq = op;
        if (op >= 10) { *chest = (CGFloat)(red + gold) / op; *purple = (CGFloat)pur / op; }
    } @catch (...) {}
}

#pragma mark - Dump cây view

static void CHDumpView(UIView *v, int depth, NSMutableString *out) {
    if (depth > 14) return;
    NSString *txt = @"";
    if ([v isKindOfClass:[UILabel class]]) txt = [NSString stringWithFormat:@" text=\"%@\"", ((UILabel *)v).text];
    NSString *acc = v.accessibilityLabel.length ? [NSString stringWithFormat:@" acc=\"%@\"", v.accessibilityLabel] : @"";
    CGRect r = CHRect(v);
    [out appendFormat:@"%*s%@ (%.0f,%.0f %.0fx%.0f)%@%@%@\n", depth * 2, "", NSStringFromClass([v class]),
        r.origin.x, r.origin.y, r.size.width, r.size.height, txt, acc, v.hidden ? @" HIDDEN" : @""];
    for (UIView *s in v.subviews) CHDumpView(s, depth + 1, out);
}

#pragma mark - Engine

@interface CHEngine : NSObject
@property (nonatomic) BOOL enabled;
@property (nonatomic) NSInteger coins;
@property (nonatomic) NSInteger okCount;
@property (nonatomic) NSInteger emptyCount;
@property (nonatomic, copy) void (^onChange)(NSString *status);
@property (nonatomic, strong) NSMutableArray<NSString *> *log;
+ (instancetype)shared;
- (void)start;
- (void)resetStats;
- (void)setEnabledAndReset:(BOOL)on;
- (void)dumpToClipboard;
@end

static const CFTimeInterval kLead = 0.0;   // bấm sớm hơn mốc 0 bao nhiêu giây (0 = đúng lúc đồng hồ về 0)

@implementation CHEngine {
    CADisplayLink *_link;

    // phiên sheet rương đang mở
    UIView *_sheet;
    CFTimeInterval _sheetOpenedAt;
    BOOL _sawTimer, _haveEdge, _fired, _resolved;
    NSInteger _sec;
    CFTimeInterval _zeroAt, _firedAt, _lastTap;
    NSInteger _attempts;
    CGPoint _btnPt;
    BOOL _haveBtn;
    __weak UILabel *_btnLabel;

    // chờ kết quả
    BOOL _awaitingResult;
    CFTimeInterval _lastResultScan;

    // icon rương
    CFTimeInterval _lastScan, _lastIconScan, _lastIconTap, _iconCooldownUntil, _lastNoIconLog;
    NSInteger _iconAttempts;

    // phục vụ Dump / chẩn đoán
    NSString *_lastTapDesc;
    NSString *_lastCandInfo;
    NSMutableArray<NSString *> *_lastTexts;
    CFTimeInterval _lastNotify;
    NSString *_lastStatus;
}

+ (instancetype)shared {
    static CHEngine *e; static dispatch_once_t o;
    dispatch_once(&o, ^{ e = [CHEngine new]; });
    return e;
}

- (instancetype)init {
    if ((self = [super init])) {
        _enabled = YES;
        _log = [NSMutableArray array];
        _lastTexts = [NSMutableArray array];
        NSUserDefaults *d = [NSUserDefaults standardUserDefaults];
        _coins = [d integerForKey:@"ch_coins"];
        _okCount = [d integerForKey:@"ch_ok"];
        _emptyCount = [d integerForKey:@"ch_empty"];
        _sec = -1;
    }
    return self;
}

- (void)start {
    if (_link) return;
    _link = [CADisplayLink displayLinkWithTarget:self selector:@selector(tick:)];
    if (@available(iOS 15.0, *)) {
        _link.preferredFrameRateRange = CAFrameRateRangeMake(60, 120, 120);
    } else {
        _link.preferredFramesPerSecond = 60;
    }
    [_link addToRunLoop:[NSRunLoop mainRunLoop] forMode:NSRunLoopCommonModes];
}

- (void)save {
    NSUserDefaults *d = [NSUserDefaults standardUserDefaults];
    [d setInteger:_coins forKey:@"ch_coins"];
    [d setInteger:_okCount forKey:@"ch_ok"];
    [d setInteger:_emptyCount forKey:@"ch_empty"];
}

- (void)addLog:(NSString *)s {
    NSDateFormatter *f = [NSDateFormatter new]; f.dateFormat = @"HH:mm:ss";
    [self.log addObject:[NSString stringWithFormat:@"%@ %@", [f stringFromDate:[NSDate date]], s]];
    while (self.log.count > 4) [self.log removeObjectAtIndex:0];
    [self notify:s force:YES];
}

- (void)notify:(NSString *)st force:(BOOL)force {
    CFTimeInterval now = CACurrentMediaTime();
    if (!force && now - _lastNotify < 0.1) return;
    if (!force && [st isEqualToString:_lastStatus]) return;
    _lastNotify = now; _lastStatus = st;
    if (self.onChange) self.onChange(st);
}

- (void)resetStats {
    _coins = 0; _okCount = 0; _emptyCount = 0; [self save];
    [self addLog:@"Đã reset số liệu"];
}

- (void)resetSession {
    _sawTimer = NO; _haveEdge = NO; _fired = NO; _resolved = NO;
    _sec = -1; _attempts = 0; _haveBtn = NO; _btnLabel = nil; _lastTap = 0;
}

- (void)setEnabledAndReset:(BOOL)on {
    _enabled = on;
    _sheet = nil; _awaitingResult = NO; _iconAttempts = 0; _iconCooldownUntil = 0;
    [self resetSession];
    [self notify:on ? @"Chờ rương…" : @"Đã tắt" force:YES];
}

#pragma mark Dump

- (void)dumpToClipboard {
    NSMutableString *out = [NSMutableString string];
    [out appendString:@"=== NHẬT KÝ ===\n"];
    for (NSString *l in self.log) [out appendFormat:@"%@\n", l];
    [out appendFormat:@"Lần bấm gần nhất: %@\n", _lastTapDesc ?: @"(chưa có)"];
    [out appendFormat:@"Icon ứng viên: %@\n", _lastCandInfo ?: @"(chưa quét)"];
    [out appendFormat:@"Chữ trên màn hình sau khi bấm: %@\n", [_lastTexts componentsJoinedByString:@" | "]];
    if (_sheet && _sheet.window) {
        [out appendString:@"=== SHEET RUONG ===\n"];
        CHDumpView(_sheet, 0, out);
    } else {
        for (UIWindow *w in CHTargetWindows()) {
            [out appendFormat:@"=== WINDOW %@ ===\n", NSStringFromClass([w class])];
            CHDumpView(w, 0, out);
        }
    }
    [UIPasteboard generalPasteboard].string = out;
    [self addLog:[NSString stringWithFormat:@"Đã copy dump (%lu ký tự)", (unsigned long)out.length]];
}

#pragma mark tick

- (void)tick:(CADisplayLink *)link {
    if (!_enabled) return;
    CFTimeInterval now = CACurrentMediaTime();

    if (_awaitingResult) [self watchResult:now];

    if (_sheet) {
        if (CHVisible(_sheet)) { [self handleSheet:now]; return; }
        [self sheetGone];
    }

    if (now - _lastScan < 0.06) return;
    _lastScan = now;
    [self scanForSheet:now];
    if (_sheet) { [self handleSheet:now]; return; }
    [self idleIcon:now];
}

- (void)sheetGone {
    _sheet = nil;
    [self resetSession];
}

- (void)scanForSheet:(CFTimeInterval)now {
    NSMutableArray *labels = [NSMutableArray array];
    for (UIWindow *w in CHTargetWindows()) CHCollectLabels(w, labels);
    for (UILabel *l in labels) {
        if (!CHIsSheetKeyword(l.text)) continue;
        UIView *c = CHFindContainer(l);
        if (!c || !CHVisible(c)) continue;
        _sheet = c;
        [self resetSession];
        _sheetOpenedAt = now;
        _iconAttempts = 0;
        [self addLog:@"Đã mở rương, chờ đồng hồ"];
        return;
    }
}

#pragma mark Icon rương

- (UIView *)findChestIcon {
    CGSize sc = [UIScreen mainScreen].bounds.size;
    CGRect zone = CGRectMake(0, sc.height * 0.11, sc.width * 0.55, sc.height * 0.22);
    NSMutableArray *cands = [NSMutableArray array];
    for (UIWindow *w in CHTargetWindows()) CHCollectIconCandidates(w, zone, cands);

    NSMutableString *info = [NSMutableString string];
    UIView *best = nil; CGFloat bestScore = 0; UIView *named = nil;
    UIView *unreadable = nil; int unreadableCount = 0;

    for (UIView *v in cands) {
        CGFloat ch, pu; int op;
        CHScore(v, &ch, &pu, &op);
        CGRect r = CHRect(v);
        BOOL nm = CHNamedChest(v);
        [info appendFormat:@"[%@ %.0fx%.0f chest=%.2f purple=%.2f px=%d%@] ", NSStringFromClass([v class]), r.size.width, r.size.height, ch, pu, op, nm ? @" NAMED" : @""];
        if (nm && !named) named = v;
        if (op < 10) { unreadable = v; unreadableCount++; continue; }
        if (ch >= 0.18 && ch > pu * 1.2 && ch > bestScore) { best = v; bestScore = ch; }
    }
    _lastCandInfo = info.length ? info : @"(không có ứng viên)";

    if (named) return named;
    if (best) return best;
    if (cands.count == 1 && unreadableCount == 1) return unreadable;   // chỉ 1 icon và không đọc được màu
    return nil;
}

- (void)idleIcon:(CFTimeInterval)now {
    if (now - _lastIconScan < 0.15) return;
    _lastIconScan = now;
    if (now < _iconCooldownUntil) return;

    UIView *icon = [self findChestIcon];
    if (!icon) {
        _iconAttempts = 0;
        [self notify:@"Chờ rương…" force:NO];
        if (_lastCandInfo.length > 24 && ![_lastCandInfo hasPrefix:@"(không"] && now - _lastNoIconLog > 8) {
            _lastNoIconLog = now;
            [self addLog:@"Có icon nhưng không phải rương"];
        }
        return;
    }
    [self notify:@"Thấy rương — đang mở" force:NO];
    if (now - _lastIconTap < 0.4) return;
    _lastIconTap = now;
    _iconAttempts++;
    if (_iconAttempts > 6) {
        _iconCooldownUntil = now + 4;
        _iconAttempts = 0;
        [self addLog:@"Icon rương không mở được, nghỉ 4s"];
        return;
    }
    CGRect r = CHRect(icon);
    CGPoint pt = CGPointMake(CGRectGetMidX(r), CGRectGetMidY(r));
    UIView *hit = [icon.window hitTest:pt withEvent:nil];
    NSString *d = nil;
    BOOL ok = CHActivate(hit, nil, &d);
    if (!ok) ok = CHActivate(icon, nil, &d);
    if (_iconAttempts == 1) [self addLog:ok ? [NSString stringWithFormat:@"Bấm icon rương: %@", d] : @"Không bấm được icon rương"];
    _lastTapDesc = d ?: @"(icon: không bấm được)";
}

#pragma mark Sheet rương

- (void)handleSheet:(CFTimeInterval)now {
    NSMutableArray *labels = [NSMutableArray array];
    CHCollectLabels(_sheet, labels);
    CGRect sr = CHRect(_sheet);

    UILabel *timer = nil; CGFloat timerY = -1;
    UILabel *bottom = nil; CGFloat bottomY = -1;
    for (UILabel *l in labels) {
        CGRect r = CHRect(l);
        if (CHSeconds(l.text) >= 0) {
            if (r.origin.y > timerY) { timerY = r.origin.y; timer = l; }
        } else if (!CHIsSheetKeyword(l.text) && l.text.length <= 30 &&
                   r.origin.y > sr.origin.y + sr.size.height * 0.55 && r.origin.y > bottomY) {
            bottomY = r.origin.y; bottom = l;
        }
    }

    UILabel *ref = timer ?: bottom;
    if (ref) {
        _btnLabel = ref;
        CGRect br = CHRect(ref);
        _btnPt = CGPointMake(CGRectGetMidX(br), CGRectGetMidY(br));
        _haveBtn = YES;
    }

    // Phiên cũ đã xong mà sheet vẫn còn và có đồng hồ mới -> coi như rương mới
    if (_fired && _resolved && timer && CHSeconds(timer.text) >= 1 && now - _firedAt > 2.0) {
        [self resetSession];
        _sheetOpenedAt = now;
        [self addLog:@"Rương mới, chờ đồng hồ"];
    }

    if (_fired) {
        [self continueFiring:now];
        return;
    }

    if (timer) {
        _sawTimer = YES;
        NSInteger sec = CHSeconds(timer.text);
        if (_sec >= 0 && sec != _sec) {          // vừa nhảy sang giây mới -> mốc chính xác
            _haveEdge = YES;
            _zeroAt = now + sec;
        }
        _sec = sec;
        if (sec == 0) { [self fire:now]; return; }
        if (_haveEdge && now >= _zeroAt - kLead) { [self fire:now]; return; }
        if (_haveEdge) {
            [self notify:[NSString stringWithFormat:@"Đếm ngược %@ · còn %.1fs", timer.text, MAX(_zeroAt - now, 0)] force:NO];
        } else {
            [self notify:[NSString stringWithFormat:@"Đồng bộ đồng hồ %@…", timer.text] force:NO];
        }
    } else if (bottom) {
        // Không còn đồng hồ nhưng có nút -> sẵn sàng
        if (_sawTimer || now - _sheetOpenedAt > 0.8) { [self fire:now]; return; }
        [self notify:@"Đang tải rương…" force:NO];
    } else {
        [self notify:@"Đang tải rương…" force:NO];
    }
}

- (void)fire:(CFTimeInterval)now {
    if (!_haveBtn) { [self notify:@"Không thấy nút nhận" force:NO]; return; }
    _fired = YES; _resolved = NO; _firedAt = now; _attempts = 0; _lastTap = 0;
    _awaitingResult = YES; _lastResultScan = 0;
    [_lastTexts removeAllObjects];
    [self addLog:@"Về 0 — bấm nhận"];
    [self continueFiring:now];
}

- (void)continueFiring:(CFTimeInterval)now {
    if (_resolved) return;
    if (_attempts >= 16 || now - _firedAt > 1.6) {
        [self notify:@"Đã bấm xong, chờ kết quả…" force:NO];
        return;
    }
    CFTimeInterval gap = _attempts < 6 ? 0.006 : 0.10;
    if (now - _lastTap < gap) return;
    _lastTap = now;
    _attempts++;
    [self notify:[NSString stringWithFormat:@"ĐANG BẤM NHẬN (%ld)", (long)_attempts] force:NO];

    UIWindow *w = _sheet.window;
    if (!w) return;
    UIView *hit = [w hitTest:_btnPt withEvent:nil];
    NSString *d = nil;
    BOOL ok = CHActivate(hit, _sheet, &d);
    if (!ok && _btnLabel) ok = CHActivate(_btnLabel, _sheet, &d);
    if (_attempts == 1) {
        [self addLog:ok ? [NSString stringWithFormat:@"Bấm: %@", d] : @"Không bấm được nút (dùng Dump)"];
    }
    _lastTapDesc = d ?: @"(nút: không bấm được)";
}

#pragma mark Kết quả

- (void)watchResult:(CFTimeInterval)now {
    if (now - _lastResultScan < 0.05) return;
    _lastResultScan = now;

    NSArray *emptyKeys = @[@"rỗng", @"trống", @"empty", @"may mắn lần sau", @"better luck", @"đã hết", @"hết xu", @"hết rồi"];
    NSArray *okKeys = @[@"nhận được", @"đã nhận", @"bạn đã nhận", @"you got", @"you earned", @"you received", @"congrat", @"chúc mừng", @"claimed"];

    NSMutableArray *labels = [NSMutableArray array];
    for (UIWindow *w in CHTargetWindows()) CHCollectLabels(w, labels);

    for (UILabel *l in labels) {
        NSString *t = l.text.lowercaseString;
        if (CHHasAny(t, emptyKeys)) {
            _emptyCount++; [self save];
            _awaitingResult = NO; _resolved = YES;
            [self addLog:@"✗ Rương rỗng (không nhận được xu)"];
            return;
        }
        if (CHHasAny(t, okKeys)) {
            NSInteger n = CHFirstInt(l.text);
            if (n < 0) {
                UIView *root = l.superview.superview ?: l.superview;
                NSMutableArray *near = [NSMutableArray array];
                if (root) CHCollectLabels(root, near);
                CGFloat bigFont = 0;
                for (UILabel *x in near) {
                    if (CHIsPureInt(x.text) && x.font.pointSize > bigFont) { bigFont = x.font.pointSize; n = CHFirstInt(x.text); }
                }
            }
            _okCount++;
            if (n > 0) _coins += n;
            [self save];
            _awaitingResult = NO; _resolved = YES;
            [self addLog:n > 0 ? [NSString stringWithFormat:@"✓ Nhặt +%ld xu", (long)n] : @"✓ Nhận xu (không đọc được số)"];
            return;
        }
    }

    if (now - _firedAt > 7.0) {
        _awaitingResult = NO;
        CGSize sc = [UIScreen mainScreen].bounds.size;
        [_lastTexts removeAllObjects];
        for (UILabel *l in labels) {
            CGRect r = CHRect(l);
            if (r.origin.y < sc.height * 0.35) continue;
            NSString *t = l.text;
            if (t.length > 22) t = [[t substringToIndex:22] stringByAppendingString:@"…"];
            if (![_lastTexts containsObject:t] && _lastTexts.count < 6) [_lastTexts addObject:t];
        }
        [self addLog:@"? Chưa rõ kết quả (bấm Dump gửi mình)"];
        if (_lastTexts.count) [self addLog:[NSString stringWithFormat:@"Màn: %@", [_lastTexts componentsJoinedByString:@" | "]]];
    }
}

@end

#pragma mark - Menu

@interface CHMenu : UIView
@property (nonatomic, strong) UIView *card;
@property (nonatomic, strong) UIButton *bubble;
@property (nonatomic, strong) UILabel *status, *coinVal, *okVal, *emptyVal, *logLabel;
@property (nonatomic) BOOL collapsed;
@end

@implementation CHMenu

static const CGFloat kW = 240, kH = 272, kB = 48;

- (UILabel *)labelText:(NSString *)t font:(UIFont *)f color:(UIColor *)c frame:(CGRect)r {
    UILabel *l = [[UILabel alloc] initWithFrame:r];
    l.text = t; l.font = f; l.textColor = c;
    [self.card addSubview:l];
    return l;
}

- (instancetype)init {
    if ((self = [super initWithFrame:CGRectMake(14, 120, kW, kH)])) {
        self.clipsToBounds = NO;

        _card = [[UIView alloc] initWithFrame:CGRectMake(0, 0, kW, kH)];
        _card.backgroundColor = C_BG;
        _card.layer.cornerRadius = 20;
        _card.layer.borderWidth = 1;
        _card.layer.borderColor = C_LINE.CGColor;
        [self addSubview:_card];

        [self labelText:@"Chest Helper" font:[UIFont systemFontOfSize:15 weight:UIFontWeightBold] color:C_TEXT frame:CGRectMake(16, 12, 150, 22)];

        UIButton *min = [UIButton buttonWithType:UIButtonTypeSystem];
        min.frame = CGRectMake(kW - 48, 8, 36, 30);
        [min setTitle:@"—" forState:UIControlStateNormal];
        min.titleLabel.font = [UIFont systemFontOfSize:18 weight:UIFontWeightBold];
        [min setTitleColor:C_MUTED forState:UIControlStateNormal];
        [min addTarget:self action:@selector(collapse) forControlEvents:UIControlEventTouchUpInside];
        [_card addSubview:min];

        UIView *pill = [[UIView alloc] initWithFrame:CGRectMake(14, 44, kW - 28, 32)];
        pill.backgroundColor = C_SOFT; pill.layer.cornerRadius = 10;
        [_card addSubview:pill];
        _status = [[UILabel alloc] initWithFrame:CGRectMake(10, 0, pill.bounds.size.width - 20, 32)];
        _status.font = [UIFont systemFontOfSize:12.5 weight:UIFontWeightSemibold];
        _status.textColor = C_TEXT; _status.text = @"Chờ rương…";
        _status.adjustsFontSizeToFitWidth = YES; _status.minimumScaleFactor = 0.7;
        [pill addSubview:_status];

        [self labelText:@"XU NHẶT" font:[UIFont systemFontOfSize:10 weight:UIFontWeightSemibold] color:C_MUTED frame:CGRectMake(16, 86, 70, 14)];
        _coinVal = [self labelText:@"0" font:[UIFont systemFontOfSize:22 weight:UIFontWeightBold] color:C_TEXT frame:CGRectMake(16, 100, 70, 28)];
        [self labelText:@"NHẬN" font:[UIFont systemFontOfSize:10 weight:UIFontWeightSemibold] color:C_MUTED frame:CGRectMake(94, 86, 60, 14)];
        _okVal = [self labelText:@"0" font:[UIFont systemFontOfSize:22 weight:UIFontWeightBold] color:C_TEXT frame:CGRectMake(94, 100, 60, 28)];
        [self labelText:@"RỖNG" font:[UIFont systemFontOfSize:10 weight:UIFontWeightSemibold] color:C_MUTED frame:CGRectMake(164, 86, 60, 14)];
        _emptyVal = [self labelText:@"0" font:[UIFont systemFontOfSize:22 weight:UIFontWeightBold] color:C_TEXT frame:CGRectMake(164, 100, 60, 28)];

        _logLabel = [self labelText:@"" font:[UIFont systemFontOfSize:10.5] color:C_MUTED frame:CGRectMake(16, 134, kW - 32, 70)];
        _logLabel.numberOfLines = 5;
        _logLabel.lineBreakMode = NSLineBreakByTruncatingTail;

        UIView *line = [[UIView alloc] initWithFrame:CGRectMake(14, 212, kW - 28, 1)];
        line.backgroundColor = C_LINE; [_card addSubview:line];

        [self labelText:@"Tự động" font:[UIFont systemFontOfSize:13 weight:UIFontWeightSemibold] color:C_TEXT frame:CGRectMake(16, 228, 70, 24)];
        UISwitch *sw = [UISwitch new];
        sw.transform = CGAffineTransformMakeScale(0.8, 0.8);
        sw.onTintColor = C_ACCENT; sw.on = YES;
        sw.frame = CGRectMake(78, 225, sw.frame.size.width, sw.frame.size.height);
        [sw addTarget:self action:@selector(toggle:) forControlEvents:UIControlEventValueChanged];
        [_card addSubview:sw];

        UIButton *dump = [self smallBtn:@"Dump" x:kW - 112 sel:@selector(dump)];
        UIButton *reset = [self smallBtn:@"Reset" x:kW - 60 sel:@selector(reset)];
        [_card addSubview:dump]; [_card addSubview:reset];

        // Bong bóng thu nhỏ
        _bubble = [UIButton buttonWithType:UIButtonTypeCustom];
        _bubble.frame = CGRectMake(0, 0, kB, kB);
        _bubble.backgroundColor = C_ACCENT;
        _bubble.layer.cornerRadius = kB / 2;
        _bubble.titleLabel.font = [UIFont systemFontOfSize:12 weight:UIFontWeightBold];
        [_bubble setTitle:@"0" forState:UIControlStateNormal];
        [_bubble setTitleColor:UIColor.whiteColor forState:UIControlStateNormal];
        [_bubble addTarget:self action:@selector(expand) forControlEvents:UIControlEventTouchUpInside];
        _bubble.hidden = YES;
        [self addSubview:_bubble];

        [self addGestureRecognizer:[[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(pan:)]];
        [self addGestureRecognizer:[[UIPinchGestureRecognizer alloc] initWithTarget:self action:@selector(pinch:)]];

        __weak CHMenu *ws = self;
        [CHEngine shared].onChange = ^(NSString *st) { [ws refresh:st]; };
        [self refresh:@"Chờ rương…"];
    }
    return self;
}

- (UIButton *)smallBtn:(NSString *)t x:(CGFloat)x sel:(SEL)s {
    UIButton *b = [UIButton buttonWithType:UIButtonTypeCustom];
    b.frame = CGRectMake(x, 226, 48, 26);
    b.backgroundColor = C_SOFT; b.layer.cornerRadius = 8;
    [b setTitle:t forState:UIControlStateNormal];
    b.titleLabel.font = [UIFont systemFontOfSize:11.5 weight:UIFontWeightSemibold];
    [b setTitleColor:C_TEXT forState:UIControlStateNormal];
    [b addTarget:self action:s forControlEvents:UIControlEventTouchUpInside];
    return b;
}

- (void)refresh:(NSString *)st {
    CHEngine *e = [CHEngine shared];
    _status.text = st;
    _coinVal.text = [NSString stringWithFormat:@"%ld", (long)e.coins];
    _okVal.text = [NSString stringWithFormat:@"%ld", (long)e.okCount];
    _emptyVal.text = [NSString stringWithFormat:@"%ld", (long)e.emptyCount];
    _logLabel.text = [e.log componentsJoinedByString:@"\n"];
    [_bubble setTitle:[NSString stringWithFormat:@"%ld", (long)e.coins] forState:UIControlStateNormal];
}

- (void)toggle:(UISwitch *)s { [[CHEngine shared] setEnabledAndReset:s.on]; }
- (void)dump { [[CHEngine shared] dumpToClipboard]; }
- (void)reset { [[CHEngine shared] resetStats]; }

- (void)collapse {
    self.collapsed = YES;
    [UIView animateWithDuration:0.2 animations:^{
        self.card.alpha = 0;
        self.bounds = CGRectMake(0, 0, kB, kB);
    } completion:^(BOOL f) { self.card.hidden = YES; self.bubble.hidden = NO; }];
}

- (void)expand {
    self.collapsed = NO;
    self.card.hidden = NO; self.bubble.hidden = YES;
    [UIView animateWithDuration:0.2 animations:^{
        self.card.alpha = 1;
        self.bounds = CGRectMake(0, 0, kW, kH);
    }];
}

- (void)pan:(UIPanGestureRecognizer *)g {
    CGPoint d = [g translationInView:self.superview];
    self.center = CGPointMake(self.center.x + d.x, self.center.y + d.y);
    [g setTranslation:CGPointZero inView:self.superview];
}

- (void)pinch:(UIPinchGestureRecognizer *)g {
    CGFloat cur = sqrt(self.transform.a * self.transform.a + self.transform.c * self.transform.c);
    CGFloat ns = MIN(1.4, MAX(0.65, cur * g.scale));
    self.transform = CGAffineTransformMakeScale(ns, ns);
    g.scale = 1;
}

@end

#pragma mark - Khởi tạo

static BOOL gReady = NO;

static void CHSetup(void) {
    if (gReady) return;
    UIWindowScene *scene = nil;
    for (UIScene *s in [UIApplication sharedApplication].connectedScenes) {
        if ([s isKindOfClass:[UIWindowScene class]] && s.activationState == UISceneActivationStateForegroundActive) {
            scene = (UIWindowScene *)s; break;
        }
    }
    if (!scene) { dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(0.5 * NSEC_PER_SEC)), dispatch_get_main_queue(), ^{ CHSetup(); }); return; }

    gWindow = [[CHPassWindow alloc] initWithWindowScene:scene];
    gWindow.windowLevel = UIWindowLevelAlert + 100;
    gWindow.backgroundColor = UIColor.clearColor;
    UIViewController *vc = [UIViewController new];
    vc.view.backgroundColor = UIColor.clearColor;
    gWindow.rootViewController = vc;
    gWindow.hidden = NO;
    [vc.view addSubview:[CHMenu new]];

    [[CHEngine shared] start];
    gReady = YES;
}

__attribute__((constructor)) static void CHInit(void) {
    [[NSNotificationCenter defaultCenter] addObserverForName:UIApplicationDidBecomeActiveNotification
                                                      object:nil queue:[NSOperationQueue mainQueue]
                                                  usingBlock:^(NSNotification *n) { CHSetup(); }];
    dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(2.0 * NSEC_PER_SEC)), dispatch_get_main_queue(), ^{ CHSetup(); });
}
