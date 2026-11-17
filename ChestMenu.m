// ChestHelper.m — iosmods Auto Click
// 3 nút số 1/2/3: kéo tới vị trí cần bấm, chạm (hoặc gạt công tắc) để bật -> nút chuyển xanh = đang auto click.
// Build thành .dylib (arm64, ARC) rồi inject vào IPA. Cần link: UIKit, Foundation.

#import <UIKit/UIKit.h>
#import <objc/runtime.h>
#import <dlfcn.h>
#import <mach/mach_time.h>

#pragma mark - Private API (giả lập chạm)

typedef void *CHHID;
typedef CHHID (*FnDigitizer)(CFAllocatorRef, uint64_t, uint32_t, uint32_t, uint32_t, uint32_t, uint32_t,
                             double, double, double, double, double, Boolean, Boolean, uint32_t);
typedef CHHID (*FnFinger)(CFAllocatorRef, uint64_t, uint32_t, uint32_t, uint32_t,
                          double, double, double, double, double, Boolean, Boolean, uint32_t);
typedef void (*FnAppend)(CHHID, CHHID, uint32_t);
typedef void (*FnSetInt)(CHHID, uint32_t, int32_t);

static FnDigitizer pDigitizer; static FnFinger pFinger; static FnAppend pAppend; static FnSetInt pSetInt;

@interface UITouch (CH)
- (void)setPhase:(UITouchPhase)phase;
- (void)setTapCount:(NSUInteger)c;
- (void)setIsTap:(BOOL)b;
- (void)setTimestamp:(NSTimeInterval)t;
- (void)setWindow:(UIWindow *)w;
- (void)setView:(UIView *)v;
- (void)setGestureView:(UIView *)v;
- (void)_setLocationInWindow:(CGPoint)p resetPrevious:(BOOL)r;
- (void)_setIsFirstTouchForView:(BOOL)b;
- (void)_setHidEvent:(CHHID)e;
@end

@interface UIEvent (CH)
- (void)_clearTouches;
- (void)_addTouch:(UITouch *)t forDelayedDelivery:(BOOL)d;
@end

@interface UIApplication (CH)
- (UIEvent *)_touchesEvent;
@end

static void CHLoadHID(void) {
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        dlopen("/System/Library/Frameworks/IOKit.framework/IOKit", RTLD_LAZY);
        pDigitizer = (FnDigitizer)dlsym(RTLD_DEFAULT, "IOHIDEventCreateDigitizerEvent");
        pFinger    = (FnFinger)dlsym(RTLD_DEFAULT, "IOHIDEventCreateDigitizerFingerEvent");
        pAppend    = (FnAppend)dlsym(RTLD_DEFAULT, "IOHIDEventAppendEvent");
        pSetInt    = (FnSetInt)dlsym(RTLD_DEFAULT, "IOHIDEventSetIntegerValue");
    });
}

static CHHID gLastHand = NULL; // giải phóng trễ 1 nhịp cho an toàn

static void CHAttachHID(UITouch *t, CGPoint p, UITouchPhase ph) {
    CHLoadHID();
    if (!pDigitizer || !pFinger || !pAppend) return;
    CGSize s = UIScreen.mainScreen.bounds.size;
    BOOL down = (ph != UITouchPhaseEnded);
    uint64_t ts = mach_absolute_time();
    uint32_t mask = 1 | 2; // range | touch
    CHHID hand = pDigitizer(kCFAllocatorDefault, ts, 3, 0, 0, 2, 0, 0, 0, 0, 0, 0, 0, 0, 0);
    if (pSetInt) pSetInt(hand, 0xb0019, 1);
    CHHID finger = pFinger(kCFAllocatorDefault, ts, 1, 2, mask, p.x / s.width, p.y / s.height, 0,
                           down ? 1.0 : 0.0, 0, down, down, 0);
    pAppend(hand, finger, 0);
    CFRelease(finger);
    if ([t respondsToSelector:@selector(_setHidEvent:)]) [t _setHidEvent:hand];
    if (gLastHand) CFRelease(gLastHand);
    gLastHand = hand;
}

#pragma mark - Overlay window

@interface CHWindow : UIWindow
@end
@implementation CHWindow
- (BOOL)canBecomeKeyWindow { return NO; }
- (UIView *)hitTest:(CGPoint)p withEvent:(UIEvent *)e {
    UIView *v = [super hitTest:p withEvent:e];
    return (v == self || v == self.rootViewController.view) ? nil : v;
}
@end

static CHWindow *gWin;

static UIWindowScene *CHScene(void) {
    for (UIScene *s in UIApplication.sharedApplication.connectedScenes)
        if ([s isKindOfClass:UIWindowScene.class] && s.activationState == UISceneActivationStateForegroundActive)
            return (UIWindowScene *)s;
    return nil;
}

// Tìm view của TikTok nằm dưới điểm p (bỏ qua cửa sổ của menu)
static UIView *CHHit(CGPoint p, UIWindow **outWin) {
    UIWindowScene *sc = CHScene();
    if (!sc) return nil;
    NSArray *wins = [sc.windows sortedArrayUsingComparator:^NSComparisonResult(UIWindow *a, UIWindow *b) {
        return a.windowLevel < b.windowLevel ? NSOrderedDescending : (a.windowLevel > b.windowLevel ? NSOrderedAscending : NSOrderedSame);
    }];
    for (UIWindow *w in wins) {
        if (w == gWin || w.hidden || w.alpha < 0.01) continue;
        UIView *v = [w hitTest:[w convertPoint:p fromWindow:nil] withEvent:nil];
        if (v) { if (outWin) *outWin = w; return v; }
    }
    return nil;
}

static void CHTap(CGPoint p) {
    UIApplication *app = UIApplication.sharedApplication;
    UIWindow *w = nil;
    UIView *v = CHHit(p, &w);
    if (!v || !w) return;
    CGPoint lp = [w convertPoint:p fromWindow:nil];

    UITouch *t = [[UITouch alloc] init];
    [t setTapCount:1];
    [t setIsTap:YES];
    [t setPhase:UITouchPhaseBegan];
    [t setWindow:w];
    [t _setLocationInWindow:lp resetPrevious:YES];
    [t setView:v];
    [t _setIsFirstTouchForView:YES];
    [t setTimestamp:NSProcessInfo.processInfo.systemUptime];
    if ([t respondsToSelector:@selector(setGestureView:)]) [t setGestureView:v];
    CHAttachHID(t, p, UITouchPhaseBegan);

    UIEvent *e = [app _touchesEvent];
    [e _clearTouches];
    [e _addTouch:t forDelayedDelivery:NO];
    [app sendEvent:e];

    [t setPhase:UITouchPhaseEnded];
    [t setTimestamp:NSProcessInfo.processInfo.systemUptime];
    CHAttachHID(t, p, UITouchPhaseEnded);
    [e _clearTouches];
    [e _addTouch:t forDelayedDelivery:NO];
    [app sendEvent:e];
}

#pragma mark - UI

static UIColor *CHGreen(void) { return [UIColor colorWithRed:0.20 green:0.78 blue:0.35 alpha:1]; }

@interface CHMarker : UIView
@property (nonatomic) BOOL on;
@property (nonatomic, copy) void (^changed)(void);
@end

@implementation CHMarker
- (instancetype)initWithIndex:(NSInteger)i {
    self = [super initWithFrame:CGRectMake(0, 0, 46, 46)];
    self.layer.cornerRadius = 23;
    self.layer.borderWidth = 2;
    self.layer.borderColor = [UIColor colorWithWhite:1 alpha:0.9].CGColor;
    UILabel *l = [[UILabel alloc] initWithFrame:self.bounds];
    l.text = [NSString stringWithFormat:@"%ld", (long)i];
    l.textAlignment = NSTextAlignmentCenter;
    l.textColor = UIColor.whiteColor;
    l.font = [UIFont systemFontOfSize:20 weight:UIFontWeightBold];
    [self addSubview:l];
    [self addGestureRecognizer:[[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(pan:)]];
    [self addGestureRecognizer:[[UITapGestureRecognizer alloc] initWithTarget:self action:@selector(tap)]];
    [self refresh];
    return self;
}
- (void)refresh {
    self.backgroundColor = self.on ? [CHGreen() colorWithAlphaComponent:0.85] : [UIColor colorWithWhite:0.12 alpha:0.6];
}
- (void)setOn:(BOOL)on { _on = on; [self refresh]; }
- (void)tap { self.on = !self.on; if (self.changed) self.changed(); }
- (void)pan:(UIPanGestureRecognizer *)g {
    CGPoint d = [g translationInView:self.superview];
    CGRect b = self.superview.bounds;
    self.center = CGPointMake(MIN(MAX(self.center.x + d.x, 23), b.size.width - 23),
                              MIN(MAX(self.center.y + d.y, 23), b.size.height - 23));
    [g setTranslation:CGPointZero inView:self.superview];
    if (g.state == UIGestureRecognizerStateEnded && self.changed) self.changed();
}
@end

@interface CHUI : NSObject
@property (nonatomic, strong) UIView *panel, *bubble;
@property (nonatomic, strong) NSMutableArray<CHMarker *> *markers;
@property (nonatomic, strong) NSMutableArray<UISwitch *> *switches;
@property (nonatomic, strong) UISlider *slider;
@property (nonatomic, strong) UILabel *cpsLabel, *infoLabel;
@property (nonatomic, strong) dispatch_source_t timer;
+ (instancetype)shared;
- (void)build;
@end

@implementation CHUI
+ (instancetype)shared { static CHUI *u; static dispatch_once_t o; dispatch_once(&o, ^{ u = [CHUI new]; }); return u; }

- (void)build {
    if (gWin) return;
    UIWindowScene *sc = CHScene();
    if (!sc) return;
    gWin = [[CHWindow alloc] initWithWindowScene:sc];
    gWin.frame = UIScreen.mainScreen.bounds;
    gWin.windowLevel = UIWindowLevelAlert + 100;
    gWin.backgroundColor = UIColor.clearColor;
    UIViewController *vc = [UIViewController new];
    vc.view.backgroundColor = UIColor.clearColor;
    gWin.rootViewController = vc;
    gWin.hidden = NO;
    UIView *root = vc.view;
    CGSize s = root.bounds.size;

    // --- 3 nút đánh dấu ---
    self.markers = [NSMutableArray array];
    self.switches = [NSMutableArray array];
    __weak CHUI *ws = self;
    for (NSInteger i = 1; i <= 3; i++) {
        CHMarker *m = [[CHMarker alloc] initWithIndex:i];
        m.center = CGPointMake(s.width / 2 + (i - 2) * 70, s.height * 0.55);
        m.changed = ^{ [ws sync]; };
        [root addSubview:m];
        [self.markers addObject:m];
    }

    // --- Bong bóng thu nhỏ ---
    self.bubble = [[UIView alloc] initWithFrame:CGRectMake(12, 120, 46, 46)];
    self.bubble.backgroundColor = [UIColor colorWithWhite:0.1 alpha:0.92];
    self.bubble.layer.cornerRadius = 23;
    UILabel *bl = [[UILabel alloc] initWithFrame:self.bubble.bounds];
    bl.text = @"io"; bl.textAlignment = NSTextAlignmentCenter; bl.textColor = UIColor.whiteColor;
    bl.font = [UIFont systemFontOfSize:16 weight:UIFontWeightHeavy];
    [self.bubble addSubview:bl];
    [self.bubble addGestureRecognizer:[[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(dragView:)]];
    [self.bubble addGestureRecognizer:[[UITapGestureRecognizer alloc] initWithTarget:self action:@selector(openPanel)]];
    [root addSubview:self.bubble];

    // --- Bảng điều khiển ---
    CGFloat W = 250;
    self.panel = [[UIView alloc] initWithFrame:CGRectMake(12, 120, W, 322)];
    self.panel.backgroundColor = [UIColor colorWithWhite:0.09 alpha:0.96];
    self.panel.layer.cornerRadius = 18;
    self.panel.hidden = YES;

    UIView *head = [[UIView alloc] initWithFrame:CGRectMake(0, 0, W, 56)];
    [head addGestureRecognizer:[[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(dragPanel:)]];
    [self.panel addSubview:head];
    UILabel *t1 = [[UILabel alloc] initWithFrame:CGRectMake(16, 10, 180, 22)];
    t1.text = @"iosmods.xyz"; t1.textColor = UIColor.whiteColor;
    t1.font = [UIFont systemFontOfSize:17 weight:UIFontWeightSemibold];
    [head addSubview:t1];
    UILabel *t2 = [[UILabel alloc] initWithFrame:CGRectMake(16, 32, 200, 16)];
    t2.text = @"Auto Click"; t2.textColor = [UIColor colorWithWhite:0.6 alpha:1];
    t2.font = [UIFont systemFontOfSize:12];
    [head addSubview:t2];
    UIButton *min = [UIButton buttonWithType:UIButtonTypeSystem];
    min.frame = CGRectMake(W - 48, 10, 36, 36);
    [min setTitle:@"–" forState:UIControlStateNormal];
    min.titleLabel.font = [UIFont systemFontOfSize:26 weight:UIFontWeightMedium];
    [min setTitleColor:UIColor.whiteColor forState:UIControlStateNormal];
    [min addTarget:self action:@selector(closePanel) forControlEvents:UIControlEventTouchUpInside];
    [self.panel addSubview:min];

    for (NSInteger i = 0; i < 3; i++) {
        UILabel *l = [[UILabel alloc] initWithFrame:CGRectMake(16, 66 + i * 42, 120, 31)];
        l.text = [NSString stringWithFormat:@"Nút %ld", (long)i + 1];
        l.textColor = UIColor.whiteColor; l.font = [UIFont systemFontOfSize:16];
        [self.panel addSubview:l];
        UISwitch *sw = [[UISwitch alloc] initWithFrame:CGRectMake(W - 16 - 51, 66 + i * 42, 51, 31)];
        sw.onTintColor = CHGreen();
        sw.tag = i;
        [sw addTarget:self action:@selector(switched:) forControlEvents:UIControlEventValueChanged];
        [self.panel addSubview:sw];
        [self.switches addObject:sw];
    }

    UILabel *sp = [[UILabel alloc] initWithFrame:CGRectMake(16, 198, 100, 20)];
    sp.text = @"Tốc độ"; sp.textColor = UIColor.whiteColor; sp.font = [UIFont systemFontOfSize:16];
    [self.panel addSubview:sp];
    self.cpsLabel = [[UILabel alloc] initWithFrame:CGRectMake(W - 130, 198, 114, 20)];
    self.cpsLabel.textAlignment = NSTextAlignmentRight;
    self.cpsLabel.textColor = [UIColor colorWithWhite:0.7 alpha:1]; self.cpsLabel.font = [UIFont systemFontOfSize:14];
    [self.panel addSubview:self.cpsLabel];
    self.slider = [[UISlider alloc] initWithFrame:CGRectMake(16, 222, W - 32, 30)];
    self.slider.minimumValue = 1; self.slider.maximumValue = 100; self.slider.value = 20;
    self.slider.minimumTrackTintColor = CHGreen();
    [self.slider addTarget:self action:@selector(speedChanged) forControlEvents:UIControlEventValueChanged];
    [self.panel addSubview:self.slider];

    self.infoLabel = [[UILabel alloc] initWithFrame:CGRectMake(16, 258, W - 32, 16)];
    self.infoLabel.textColor = [UIColor colorWithWhite:0.5 alpha:1]; self.infoLabel.font = [UIFont systemFontOfSize:11];
    self.infoLabel.text = @"Kéo nút số tới vị trí cần bấm";
    [self.panel addSubview:self.infoLabel];

    UIButton *off = [UIButton buttonWithType:UIButtonTypeSystem];
    off.frame = CGRectMake(16, 278, W - 32, 34);
    off.backgroundColor = [UIColor colorWithWhite:0.2 alpha:1]; off.layer.cornerRadius = 10;
    [off setTitle:@"Tắt tất cả" forState:UIControlStateNormal];
    [off setTitleColor:UIColor.whiteColor forState:UIControlStateNormal];
    [off addTarget:self action:@selector(allOff) forControlEvents:UIControlEventTouchUpInside];
    [self.panel addSubview:off];
    [root addSubview:self.panel];

    [self speedChanged];
}

#pragma mark panel behaviour

- (void)openPanel {
    CGSize s = gWin.bounds.size;
    CGRect f = self.panel.frame;
    f.origin.x = MIN(MAX(self.bubble.frame.origin.x, 8), s.width - f.size.width - 8);
    f.origin.y = MIN(MAX(self.bubble.frame.origin.y, 50), s.height - f.size.height - 20);
    self.panel.frame = f;
    self.panel.hidden = NO; self.panel.alpha = 0;
    self.panel.transform = CGAffineTransformMakeScale(0.85, 0.85);
    [UIView animateWithDuration:0.38 delay:0 usingSpringWithDamping:0.78 initialSpringVelocity:0.6 options:0 animations:^{
        self.panel.alpha = 1; self.panel.transform = CGAffineTransformIdentity; self.bubble.alpha = 0;
    } completion:^(BOOL f2) { self.bubble.hidden = YES; }];
}
- (void)closePanel {
    self.bubble.center = CGPointMake(self.panel.frame.origin.x + 23, self.panel.frame.origin.y + 23);
    self.bubble.hidden = NO;
    [UIView animateWithDuration:0.22 animations:^{
        self.panel.alpha = 0; self.panel.transform = CGAffineTransformMakeScale(0.85, 0.85); self.bubble.alpha = 1;
    } completion:^(BOOL f) { self.panel.hidden = YES; self.panel.transform = CGAffineTransformIdentity; }];
}
- (void)dragView:(UIPanGestureRecognizer *)g {
    UIView *v = g.view; CGPoint d = [g translationInView:v.superview];
    v.center = CGPointMake(v.center.x + d.x, v.center.y + d.y);
    [g setTranslation:CGPointZero inView:v.superview];
}
- (void)dragPanel:(UIPanGestureRecognizer *)g {
    CGPoint d = [g translationInView:self.panel.superview];
    self.panel.center = CGPointMake(self.panel.center.x + d.x, self.panel.center.y + d.y);
    [g setTranslation:CGPointZero inView:self.panel.superview];
}

#pragma mark logic

- (void)switched:(UISwitch *)sw { self.markers[sw.tag].on = sw.on; [self sync]; }
- (void)allOff { for (CHMarker *m in self.markers) m.on = NO; [self sync]; }
- (void)speedChanged {
    self.cpsLabel.text = [NSString stringWithFormat:@"%d lần/giây", (int)self.slider.value];
    [self stopTimer]; [self sync];
}
- (void)sync {
    BOOL any = NO;
    for (NSInteger i = 0; i < 3; i++) {
        self.switches[i].on = self.markers[i].on;
        if (self.markers[i].on) any = YES;
    }
    UIWindow *w; UIView *v = CHHit(self.markers[0].center, &w);
    self.infoLabel.text = [NSString stringWithFormat:@"Dưới nút 1: %@", v ? NSStringFromClass(v.class) : @"—"];
    if (any && !self.timer) [self startTimer];
    if (!any) [self stopTimer];
}
- (void)startTimer {
    double ns = 1e9 / MAX(1.0, (double)self.slider.value);
    self.timer = dispatch_source_create(DISPATCH_SOURCE_TYPE_TIMER, 0, 0, dispatch_get_main_queue());
    dispatch_source_set_timer(self.timer, DISPATCH_TIME_NOW, (uint64_t)ns, 0);
    __weak CHUI *ws = self;
    dispatch_source_set_event_handler(self.timer, ^{
        for (CHMarker *m in ws.markers) if (m.on) CHTap(m.center);
    });
    dispatch_resume(self.timer);
}
- (void)stopTimer { if (self.timer) { dispatch_source_cancel(self.timer); self.timer = nil; } }
@end

#pragma mark - Entry

__attribute__((constructor)) static void CHInit(void) {
    [NSNotificationCenter.defaultCenter addObserverForName:UIApplicationDidBecomeActiveNotification
                                                    object:nil queue:NSOperationQueue.mainQueue
                                                usingBlock:^(NSNotification *n) {
        dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(1.5 * NSEC_PER_SEC)), dispatch_get_main_queue(), ^{
            [[CHUI shared] build];
        });
    }];
    [NSNotificationCenter.defaultCenter addObserverForName:UIApplicationWillResignActiveNotification
                                                    object:nil queue:NSOperationQueue.mainQueue
                                                usingBlock:^(NSNotification *n) {
        if (gWin) [[CHUI shared] allOff];
    }];
}
