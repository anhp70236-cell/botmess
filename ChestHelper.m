// ChestHelper.m — dylib nhúng vào TikTok IPA
// Menu tím (không phát sáng) + tự mở rương + đếm ngược chuẩn + bấm cực nhanh khi về 0
// Build: clang -arch arm64 -fobjc-arc -dynamiclib ... (xem build-chest.yml)

#import <UIKit/UIKit.h>
#import <QuartzCore/QuartzCore.h>
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

static void CHCollectLabels(UIView *v, NSMutableArray *out) {
    if (v.hidden || v.alpha < 0.01) return;
    if ([v isKindOfClass:[UILabel class]]) {
        UILabel *l = (UILabel *)v;
        if (l.text.length) [out addObject:l];
    }
    for (UIView *s in v.subviews) CHCollectLabels(s, out);
}

static CGRect CHRectInWindow(UIView *v) {
    return [v convertRect:v.bounds toView:nil];
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

static BOOL CHIsKeyword(NSString *t) {
    NSString *l = t.lowercaseString;
    return [l containsString:@"rương báu"] || [l containsString:@"treasure"] ||
           [l containsString:@"rương"] || [l containsString:@"chest"];
}

// Tìm khung bottom-sheet chứa nhãn từ khoá (cao > 35% màn hình, rộng > 80%)
static UIView *CHFindContainer(UIView *kw) {
    CGSize sc = [UIScreen mainScreen].bounds.size;
    UIView *v = kw.superview;
    while (v) {
        CGRect r = CHRectInWindow(v);
        if (r.size.height > sc.height * 0.35 && r.size.width > sc.width * 0.8) return v;
        v = v.superview;
    }
    return nil;
}

// Tìm view bấm được từ nhãn đi lên
static UIView *CHTapTarget(UIView *from, UIView *stopAt) {
    for (UIView *v = from; v && v != stopAt.superview; v = v.superview) {
        if ([v isKindOfClass:[UIControl class]]) return v;
        for (UIGestureRecognizer *g in v.gestureRecognizers)
            if ([g isKindOfClass:[UITapGestureRecognizer class]] && g.enabled) return v;
    }
    return from;
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

static BOOL CHTap(UIView *v) {
    if (!v) return NO;
    BOOL done = NO;
    if ([v isKindOfClass:[UIControl class]]) {
        [(UIControl *)v sendActionsForControlEvents:UIControlEventTouchUpInside];
        done = YES;
    }
    for (UIGestureRecognizer *g in [v.gestureRecognizers copy]) {
        if ([g isKindOfClass:[UITapGestureRecognizer class]] && g.enabled) {
            CHFireGesture(g);
            done = YES;
        }
    }
    return done;
}

#pragma mark - Dump cây view (để gửi lại cho mình chỉnh theo bản TikTok của bạn)

static void CHDumpView(UIView *v, int depth, NSMutableString *out) {
    if (depth > 14) return;
    NSString *txt = @"";
    if ([v isKindOfClass:[UILabel class]]) txt = [NSString stringWithFormat:@" text=\"%@\"", ((UILabel *)v).text];
    NSString *acc = v.accessibilityLabel.length ? [NSString stringWithFormat:@" acc=\"%@\"", v.accessibilityLabel] : @"";
    CGRect r = CHRectInWindow(v);
    [out appendFormat:@"%*s%@ (%.0f,%.0f %.0fx%.0f)%@%@%@\n", depth * 2, "", NSStringFromClass([v class]),
        r.origin.x, r.origin.y, r.size.width, r.size.height, txt, acc, v.hidden ? @" HIDDEN" : @""];
    for (UIView *s in v.subviews) CHDumpView(s, depth + 1, out);
}

#pragma mark - Engine

@interface CHEngine : NSObject
@property (nonatomic) BOOL enabled;
@property (nonatomic) NSInteger coins;
@property (nonatomic) NSInteger chests;
@property (nonatomic, copy) void (^onChange)(NSString *status);
@property (nonatomic, strong) NSMutableArray<NSString *> *log;
+ (instancetype)shared;
- (void)start;
- (void)resetStats;
- (void)dumpToClipboard;
@end

@implementation CHEngine {
    CADisplayLink *_link;
    UIView *_container;          // bottom-sheet rương đang mở
    UIView *_target;             // nút bấm trong sheet
    NSInteger _lastSec;
    CFTimeInterval _lastChange;
    CFTimeInterval _lastScan, _lastIconTap, _burstStart;
    BOOL _bursting;
    NSInteger _pendingCoins;
    NSInteger _burstFrames;
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
        _coins = [[NSUserDefaults standardUserDefaults] integerForKey:@"ch_coins"];
        _chests = [[NSUserDefaults standardUserDefaults] integerForKey:@"ch_chests"];
        _lastSec = -1;
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

- (void)addLog:(NSString *)s {
    NSDateFormatter *f = [NSDateFormatter new]; f.dateFormat = @"HH:mm:ss";
    [self.log addObject:[NSString stringWithFormat:@"%@  %@", [f stringFromDate:[NSDate date]], s]];
    while (self.log.count > 3) [self.log removeObjectAtIndex:0];
}

- (void)save {
    [[NSUserDefaults standardUserDefaults] setInteger:_coins forKey:@"ch_coins"];
    [[NSUserDefaults standardUserDefaults] setInteger:_chests forKey:@"ch_chests"];
}

- (void)resetStats { _coins = 0; _chests = 0; [self save]; [self addLog:@"Đã reset số liệu"]; [self notify:@"Đã reset"]; }

- (void)notify:(NSString *)st { if (self.onChange) self.onChange(st); }

- (void)dumpToClipboard {
    NSMutableString *out = [NSMutableString string];
    if (_container && _container.window) {
        [out appendString:@"=== SHEET RUONG ===\n"];
        CHDumpView(_container, 0, out);
    } else {
        for (UIWindow *w in CHTargetWindows()) {
            [out appendFormat:@"=== WINDOW %@ ===\n", NSStringFromClass([w class])];
            CHDumpView(w, 0, out);
        }
    }
    [UIPasteboard generalPasteboard].string = out;
    [self addLog:[NSString stringWithFormat:@"Đã copy cây view (%lu ký tự)", (unsigned long)out.length]];
    [self notify:@"Đã copy dump"];
}

#pragma mark tick

- (void)tick:(CADisplayLink *)link {
    if (!_enabled) return;
    CFTimeInterval now = CACurrentMediaTime();

    // Sheet đang mở?
    if (_container && _container.window) { [self handlePopup:now]; return; }

    // Sheet vừa biến mất
    if (_container) {
        if (_bursting) {
            _coins += _pendingCoins; _chests += 1; [self save];
            [self addLog:[NSString stringWithFormat:@"Đã nhặt +%ld xu", (long)_pendingCoins]];
            [self notify:[NSString stringWithFormat:@"Đã nhặt +%ld xu", (long)_pendingCoins]];
        }
        _container = nil; _target = nil; _bursting = NO; _lastSec = -1; _pendingCoins = 0;
    }

    if (now - _lastScan < 0.06) return;
    _lastScan = now;

    NSMutableArray *labels = [NSMutableArray array];
    for (UIWindow *w in CHTargetWindows()) CHCollectLabels(w, labels);

    // 1) Có sheet rương chưa?
    CGSize sc = [UIScreen mainScreen].bounds.size;
    for (UILabel *l in labels) {
        if (!CHIsKeyword(l.text) || l.text.length < 8) continue;
        UIView *c = CHFindContainer(l);
        if (!c) continue;
        NSMutableArray *inside = [NSMutableArray array];
        CHCollectLabels(c, inside);
        BOOL hasTimer = NO;
        for (UILabel *x in inside) if (CHSeconds(x.text) >= 0) { hasTimer = YES; break; }
        if (hasTimer || inside.count >= 3) {
            _container = c; _lastSec = -1; _bursting = NO;
            [self addLog:@"Đã mở sheet rương"];
            [self handlePopup:now];
            return;
        }
    }

    // 2) Tìm icon rương (nhãn mm:ss ở nửa trên màn hình) rồi bấm
    UILabel *best = nil; NSInteger bestSec = NSIntegerMax; BOOL bestNamed = NO;
    for (UILabel *l in labels) {
        NSInteger s = CHSeconds(l.text);
        if (s < 0) continue;
        CGRect r = CHRectInWindow(l);
        if (r.origin.y > sc.height * 0.30) continue;
        BOOL named = NO;
        UIView *p = l;
        for (int i = 0; i < 5 && p; i++, p = p.superview) {
            NSString *cn = NSStringFromClass([p class]).lowercaseString;
            NSString *ac = (p.accessibilityLabel ?: @"").lowercaseString;
            if ([cn containsString:@"chest"] || [cn containsString:@"treasure"] || [cn containsString:@"coin"] ||
                [ac containsString:@"rương"] || [ac containsString:@"chest"] || [ac containsString:@"treasure"]) { named = YES; break; }
        }
        if ((named && !bestNamed) || (named == bestNamed && s < bestSec)) { best = l; bestSec = s; bestNamed = named; }
    }
    if (best) {
        [self notify:[NSString stringWithFormat:@"Thấy rương (%@)", best.text]];
        if (now - _lastIconTap > 0.22) {
            _lastIconTap = now;
            UIView *t = CHTapTarget(best, nil);
            BOOL ok = CHTap(t);
            if (!ok) { // thử các view cha
                for (UIView *p = best.superview; p && !ok; p = p.superview) ok = CHTap(p);
            }
            [self addLog:ok ? @"Bấm icon rương" : @"Không bấm được icon (dùng Dump)"];
        }
    } else {
        [self notify:@"Chờ rương…"];
    }
}

- (void)handlePopup:(CFTimeInterval)now {
    NSMutableArray *labels = [NSMutableArray array];
    CHCollectLabels(_container, labels);

    UILabel *timer = nil; CGFloat maxY = -1;
    UILabel *bottom = nil; CGFloat bottomY = -1;
    CGFloat bigFont = 0; NSInteger coins = 0;

    for (UILabel *l in labels) {
        CGRect r = CHRectInWindow(l);
        if (CHSeconds(l.text) >= 0 && r.origin.y > maxY) { maxY = r.origin.y; timer = l; }
        if (!CHIsKeyword(l.text) && r.origin.y > bottomY) { bottomY = r.origin.y; bottom = l; }
        NSString *t = [l.text stringByTrimmingCharactersInSet:[NSCharacterSet whitespaceCharacterSet]];
        NSCharacterSet *nd = [[NSCharacterSet decimalDigitCharacterSet] invertedSet];
        if (t.length && [t rangeOfCharacterFromSet:nd].location == NSNotFound && l.font.pointSize > bigFont) {
            bigFont = l.font.pointSize; coins = t.integerValue;
        }
    }
    if (coins > 0) _pendingCoins = coins;

    if (_bursting) {
        // Bấm liên tục, mỗi frame 3 lần
        if (now - _burstStart > 4.0) {
            _bursting = NO;
            [self addLog:@"Hết 4s chưa nhận được (dùng Dump)"];
            [self notify:@"Chưa nhận được"];
            return;
        }
        if (_burstFrames % 8 == 0) {
            UILabel *ref = timer ?: bottom;
            if (ref) _target = CHTapTarget(ref, _container);
        }
        _burstFrames++;
        for (int i = 0; i < 3; i++) CHTap(_target);
        [self notify:@"ĐANG BẤM NHẬN XU!"];
        return;
    }

    if (timer) {
        NSInteger sec = CHSeconds(timer.text);
        if (sec != _lastSec) { _lastSec = sec; _lastChange = now; }
        CFTimeInterval remain = (_lastChange + sec) - now;      // thời điểm về 0 dự đoán
        _target = CHTapTarget(timer, _container);
        [self notify:[NSString stringWithFormat:@"Đếm ngược %@  (còn %.2fs)", timer.text, MAX(remain, 0)]];
        if (sec == 0 || remain <= 0.03) [self startBurst:now];
    } else if (bottom) {
        // Không còn đồng hồ -> nút đã sẵn sàng
        _target = CHTapTarget(bottom, _container);
        [self startBurst:now];
    }
}

- (void)startBurst:(CFTimeInterval)now {
    _bursting = YES; _burstStart = now; _burstFrames = 0;
    [self addLog:@"Về 0 — bắt đầu bấm cực nhanh"];
}

@end

#pragma mark - Menu

@interface CHMenu : UIView
@property (nonatomic, strong) UIView *card;
@property (nonatomic, strong) UIButton *bubble;
@property (nonatomic, strong) UILabel *status, *coinVal, *chestVal, *logLabel;
@property (nonatomic) BOOL collapsed;
@end

@implementation CHMenu

static const CGFloat kW = 240, kH = 252, kB = 48;

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
        _status.adjustsFontSizeToFitWidth = YES; _status.minimumScaleFactor = 0.75;
        [pill addSubview:_status];

        [self labelText:@"XU ĐÃ NHẶT" font:[UIFont systemFontOfSize:10 weight:UIFontWeightSemibold] color:C_MUTED frame:CGRectMake(16, 86, 100, 14)];
        _coinVal = [self labelText:@"0" font:[UIFont systemFontOfSize:24 weight:UIFontWeightBold] color:C_TEXT frame:CGRectMake(16, 100, 110, 30)];
        [self labelText:@"RƯƠNG" font:[UIFont systemFontOfSize:10 weight:UIFontWeightSemibold] color:C_MUTED frame:CGRectMake(136, 86, 90, 14)];
        _chestVal = [self labelText:@"0" font:[UIFont systemFontOfSize:24 weight:UIFontWeightBold] color:C_TEXT frame:CGRectMake(136, 100, 90, 30)];

        _logLabel = [self labelText:@"" font:[UIFont systemFontOfSize:10.5] color:C_MUTED frame:CGRectMake(16, 136, kW - 32, 48)];
        _logLabel.numberOfLines = 3;

        UIView *line = [[UIView alloc] initWithFrame:CGRectMake(14, 192, kW - 28, 1)];
        line.backgroundColor = C_LINE; [_card addSubview:line];

        [self labelText:@"Tự động" font:[UIFont systemFontOfSize:13 weight:UIFontWeightSemibold] color:C_TEXT frame:CGRectMake(16, 208, 70, 24)];
        UISwitch *sw = [UISwitch new];
        sw.transform = CGAffineTransformMakeScale(0.8, 0.8);
        sw.onTintColor = C_ACCENT; sw.on = YES;
        sw.frame = CGRectMake(78, 205, sw.frame.size.width, sw.frame.size.height);
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
    b.frame = CGRectMake(x, 206, 48, 26);
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
    _chestVal.text = [NSString stringWithFormat:@"%ld", (long)e.chests];
    _logLabel.text = [e.log componentsJoinedByString:@"\n"];
    [_bubble setTitle:[NSString stringWithFormat:@"%ld", (long)e.coins] forState:UIControlStateNormal];
}

- (void)toggle:(UISwitch *)s { [CHEngine shared].enabled = s.on; [self refresh:s.on ? @"Chờ rương…" : @"Đã tắt"]; }
- (void)dump { [[CHEngine shared] dumpToClipboard]; [self refresh:@"Đã copy dump"]; }
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
