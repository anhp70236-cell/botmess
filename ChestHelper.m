// ChestMenu.m - dylib nhúng vào TikTok
// Menu tím: bật/tắt auto click, chọn số giây spam, tốc độ, kích thước nút tròn.
// Nút tròn đặt ở đâu thì click ở đó (kéo để di chuyển, chụm 2 ngón để phóng to/nhỏ, chạm 1 lần để bật/tắt).

#import <UIKit/UIKit.h>
#import <objc/runtime.h>
#import <mach/mach_time.h>
#import <dlfcn.h>

#pragma mark - Private API (giả lập cảm ứng trong chính app)

typedef void *CHHIDEvent;

static CHHIDEvent (*pCreateDigitizer)(CFAllocatorRef, uint64_t, uint32_t, uint32_t, uint32_t, uint32_t, uint32_t,
                                      double, double, double, double, double, Boolean, Boolean, uint32_t);
static CHHIDEvent (*pCreateFinger)(CFAllocatorRef, uint64_t, uint32_t, uint32_t, uint32_t,
                                   double, double, double, double, double, Boolean, Boolean, uint32_t);
static void (*pAppend)(CHHIDEvent, CHHIDEvent);
static void (*pSetInt)(CHHIDEvent, uint32_t, int64_t);

@interface UITouch (CH)
- (void)setTapCount:(NSUInteger)c;
- (void)setIsTap:(BOOL)b;
- (void)setPhase:(UITouchPhase)p;
- (void)_setLocationInWindow:(CGPoint)l resetPrevious:(BOOL)r;
- (void)setView:(UIView *)v;
- (void)setWindow:(UIWindow *)w;
- (void)_setIsFirstTouchForView:(BOOL)b;
- (void)setTimestamp:(NSTimeInterval)t;
- (void)_setHidEvent:(void *)e;
@end

@interface UIEvent (CH)
- (void)_setHIDEvent:(void *)e;
- (void)_clearTouches;
- (void)_addTouch:(UITouch *)t forDelayedDelivery:(BOOL)d;
@end

@interface UIApplication (CH)
- (UIEvent *)_touchesEvent;
@end

static BOOL CHLoadIOKit(void) {
    static BOOL ok;
    static dispatch_once_t once;
    dispatch_once(&once, ^{
        void *h = dlopen("/System/Library/Frameworks/IOKit.framework/IOKit", RTLD_NOW);
        if (!h) return;
        pCreateDigitizer = (typeof(pCreateDigitizer))dlsym(h, "IOHIDEventCreateDigitizerEvent");
        pCreateFinger    = (typeof(pCreateFinger))dlsym(h, "IOHIDEventCreateDigitizerFingerEvent");
        pAppend          = (typeof(pAppend))dlsym(h, "IOHIDEventAppendEvent");
        pSetInt          = (typeof(pSetInt))dlsym(h, "IOHIDEventSetIntegerValue");
        ok = pCreateDigitizer && pCreateFinger && pAppend && pSetInt;
    });
    return ok;
}

static CHHIDEvent CHMakeHID(CGPoint p, BOOL down) {
    if (!CHLoadIOKit()) return NULL;
    CGSize s = UIScreen.mainScreen.bounds.size;
    uint64_t t = mach_absolute_time();
    uint32_t mask = down ? 7 : 3; // range | touch | position
    CHHIDEvent hand = pCreateDigitizer(kCFAllocatorDefault, t, 3, 0, 0, mask, 0,
                                       0, 0, 0, 0, 0, down, down, 0);
    if (!hand) return NULL;
    pSetInt(hand, 0xb0019, 1); // IsDisplayIntegrated
    CHHIDEvent finger = pCreateFinger(kCFAllocatorDefault, t, 1, 2, mask,
                                      p.x / s.width, p.y / s.height, 0, down ? 0.5 : 0, 0, down, down, 0);
    if (finger) {
        pAppend(hand, finger);
        CFRelease(finger);
    }
    return hand;
}

// 1 cú chạm (xuống + lên) tại điểm p của cửa sổ w
static void CHTap(UIWindow *w, CGPoint p) {
    @try {
        UIApplication *app = UIApplication.sharedApplication;
        UIView *hit = [w hitTest:p withEvent:nil];
        if (!hit) return;
        UITouch *t = [[UITouch alloc] init];
        [t setTapCount:1];
        [t setIsTap:YES];
        [t setPhase:UITouchPhaseBegan];
        [t _setLocationInWindow:p resetPrevious:YES];
        [t setView:hit];
        [t setWindow:w];
        [t _setIsFirstTouchForView:YES];
        [t setTimestamp:NSProcessInfo.processInfo.systemUptime];
        for (int i = 0; i < 2; i++) {
            BOOL down = (i == 0);
            if (!down) {
                [t setPhase:UITouchPhaseEnded];
                [t setTimestamp:NSProcessInfo.processInfo.systemUptime];
            }
            CHHIDEvent hid = CHMakeHID(p, down);
            if (hid && [t respondsToSelector:@selector(_setHidEvent:)]) [t _setHidEvent:hid];
            UIEvent *ev = [app _touchesEvent];
            [ev _clearTouches];
            if (hid && [ev respondsToSelector:@selector(_setHIDEvent:)]) [ev _setHIDEvent:hid];
            [ev _addTouch:t forDelayedDelivery:NO];
            [app sendEvent:ev];
            if (hid) CFRelease(hid);
        }
    } @catch (NSException *e) {
    }
}

#pragma mark - Cửa sổ overlay (chỉ nhận chạm ở menu và nút tròn)

@interface CHWindow : UIWindow
@end
@implementation CHWindow
- (UIView *)hitTest:(CGPoint)p withEvent:(UIEvent *)e {
    UIView *v = [super hitTest:p withEvent:e];
    if (v == self || v == self.rootViewController.view) return nil;
    return v;
}
@end

@interface CHRootVC : UIViewController
@end
@implementation CHRootVC
- (void)loadView {
    UIView *v = [[UIView alloc] initWithFrame:UIScreen.mainScreen.bounds];
    v.backgroundColor = UIColor.clearColor;
    v.autoresizingMask = UIViewAutoresizingFlexibleWidth | UIViewAutoresizingFlexibleHeight;
    self.view = v;
}
@end

#pragma mark - Controller

static UIColor *CHPurple(CGFloat a) { return [UIColor colorWithRed:0.55 green:0.25 blue:0.95 alpha:a]; }

static UILabel *CHLabel(NSString *text, CGRect f, CGFloat size, BOOL bold) {
    UILabel *l = [[UILabel alloc] initWithFrame:f];
    l.text = text;
    l.textColor = UIColor.whiteColor;
    l.font = bold ? [UIFont boldSystemFontOfSize:size] : [UIFont systemFontOfSize:size];
    return l;
}

@interface CHController : NSObject
@property (nonatomic, strong) CHWindow *win;
@property (nonatomic, strong) UIView *root;
@property (nonatomic, strong) UIView *panel;
@property (nonatomic, strong) UIButton *mini;
@property (nonatomic, strong) UIView *circle;
@property (nonatomic, strong) UIView *dot;
@property (nonatomic, strong) UILabel *circleLabel;
@property (nonatomic, strong) UISwitch *sw;
@property (nonatomic, strong) UISegmentedControl *seg;
@property (nonatomic, strong) UISlider *cpsSlider;
@property (nonatomic, strong) UISlider *sizeSlider;
@property (nonatomic, strong) UILabel *cpsLabel;
@property (nonatomic, strong) UILabel *sizeLabel;
@property (nonatomic, strong) UILabel *stat;
@property (nonatomic, strong) UIButton *showBtn;
@property (nonatomic, strong) dispatch_source_t timer;
@property (nonatomic, assign) CGFloat size;
@property (nonatomic, assign) NSInteger taps;
@property (nonatomic, assign) CFTimeInterval endTime;
@property (nonatomic, assign) NSInteger lastShown;
@property (nonatomic, strong) NSArray<NSNumber *> *secs;
+ (instancetype)shared;
- (void)setup;
@end

@implementation CHController

+ (instancetype)shared {
    static CHController *c;
    static dispatch_once_t once;
    dispatch_once(&once, ^{ c = [CHController new]; });
    return c;
}

- (UIWindow *)targetWindow {
    UIWindow *best = nil;
    for (UIScene *sc in UIApplication.sharedApplication.connectedScenes) {
        if (![sc isKindOfClass:UIWindowScene.class]) continue;
        for (UIWindow *w in ((UIWindowScene *)sc).windows) {
            if (w == self.win || w.hidden || w.windowLevel > UIWindowLevelNormal) continue;
            if (w.isKeyWindow) return w;
            if (!best) best = w;
        }
    }
    return best;
}

- (void)setup {
    if (self.win) return;
    self.size = 80;
    self.secs = @[@3, @5, @10, @20, @30, @0];

    UIWindowScene *scene = nil;
    for (UIScene *sc in UIApplication.sharedApplication.connectedScenes) {
        if ([sc isKindOfClass:UIWindowScene.class] && sc.activationState == UISceneActivationStateForegroundActive) {
            scene = (UIWindowScene *)sc;
            break;
        }
    }
    CHWindow *w = scene ? [[CHWindow alloc] initWithWindowScene:scene]
                        : [[CHWindow alloc] initWithFrame:UIScreen.mainScreen.bounds];
    w.windowLevel = UIWindowLevelAlert + 1000;
    w.backgroundColor = UIColor.clearColor;
    w.rootViewController = [CHRootVC new];
    w.hidden = NO;
    self.win = w;
    self.root = w.rootViewController.view;

    [self buildPanel];
    [self buildMini];
    [self buildCircle];
}

#pragma mark UI

- (void)buildPanel {
    UIView *p = [[UIView alloc] initWithFrame:CGRectMake(16, 90, 260, 352)];
    p.backgroundColor = [UIColor colorWithRed:0.12 green:0.06 blue:0.22 alpha:0.94];
    p.layer.cornerRadius = 18;
    p.layer.borderWidth = 1;
    p.layer.borderColor = CHPurple(0.9).CGColor;
    p.clipsToBounds = YES;
    [self.root addSubview:p];
    self.panel = p;

    UIView *header = [[UIView alloc] initWithFrame:CGRectMake(0, 0, 260, 42)];
    header.backgroundColor = CHPurple(0.25);
    [p addSubview:header];
    [header addGestureRecognizer:[[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(panPanel:)]];
    [header addSubview:CHLabel(@"Chest Menu", CGRectMake(14, 8, 170, 26), 17, YES)];

    UIButton *min = [UIButton buttonWithType:UIButtonTypeSystem];
    min.frame = CGRectMake(212, 6, 36, 30);
    [min setTitle:@"–" forState:UIControlStateNormal];
    [min setTitleColor:UIColor.whiteColor forState:UIControlStateNormal];
    min.titleLabel.font = [UIFont boldSystemFontOfSize:22];
    [min addTarget:self action:@selector(onMinimize) forControlEvents:UIControlEventTouchUpInside];
    [header addSubview:min];

    [p addSubview:CHLabel(@"Auto click", CGRectMake(14, 52, 150, 30), 15, YES)];
    self.sw = [[UISwitch alloc] initWithFrame:CGRectMake(194, 51, 51, 31)];
    self.sw.onTintColor = CHPurple(1);
    [self.sw addTarget:self action:@selector(onSwitch:) forControlEvents:UIControlEventValueChanged];
    [p addSubview:self.sw];

    [p addSubview:CHLabel(@"Số giây spam (∞ = liên tục)", CGRectMake(14, 92, 232, 20), 13, NO)];
    self.seg = [[UISegmentedControl alloc] initWithItems:@[@"3", @"5", @"10", @"20", @"30", @"∞"]];
    self.seg.frame = CGRectMake(14, 116, 232, 30);
    self.seg.selectedSegmentIndex = 1;
    self.seg.selectedSegmentTintColor = CHPurple(1);
    [self.seg setTitleTextAttributes:@{NSForegroundColorAttributeName: UIColor.whiteColor} forState:UIControlStateNormal];
    [self.seg setTitleTextAttributes:@{NSForegroundColorAttributeName: UIColor.whiteColor} forState:UIControlStateSelected];
    [p addSubview:self.seg];

    self.cpsLabel = CHLabel(@"Tốc độ: 60 lần/giây", CGRectMake(14, 158, 232, 20), 13, NO);
    [p addSubview:self.cpsLabel];
    self.cpsSlider = [[UISlider alloc] initWithFrame:CGRectMake(14, 180, 232, 30)];
    self.cpsSlider.minimumValue = 10;
    self.cpsSlider.maximumValue = 120;
    self.cpsSlider.value = 60;
    self.cpsSlider.minimumTrackTintColor = CHPurple(1);
    [self.cpsSlider addTarget:self action:@selector(onCps:) forControlEvents:UIControlEventValueChanged];
    [p addSubview:self.cpsSlider];

    self.sizeLabel = CHLabel(@"Kích thước nút: 80", CGRectMake(14, 218, 232, 20), 13, NO);
    [p addSubview:self.sizeLabel];
    self.sizeSlider = [[UISlider alloc] initWithFrame:CGRectMake(14, 240, 232, 30)];
    self.sizeSlider.minimumValue = 40;
    self.sizeSlider.maximumValue = 160;
    self.sizeSlider.value = 80;
    self.sizeSlider.minimumTrackTintColor = CHPurple(1);
    [self.sizeSlider addTarget:self action:@selector(onSize:) forControlEvents:UIControlEventValueChanged];
    [p addSubview:self.sizeSlider];

    self.showBtn = [UIButton buttonWithType:UIButtonTypeSystem];
    self.showBtn.frame = CGRectMake(14, 282, 232, 38);
    self.showBtn.backgroundColor = CHPurple(1);
    self.showBtn.layer.cornerRadius = 12;
    [self.showBtn setTitle:@"Hiện nút tròn" forState:UIControlStateNormal];
    [self.showBtn setTitleColor:UIColor.whiteColor forState:UIControlStateNormal];
    self.showBtn.titleLabel.font = [UIFont boldSystemFontOfSize:16];
    [self.showBtn addTarget:self action:@selector(onToggleCircle) forControlEvents:UIControlEventTouchUpInside];
    [p addSubview:self.showBtn];

    self.stat = CHLabel(@"Đã click: 0", CGRectMake(14, 326, 232, 18), 12, NO);
    self.stat.textColor = [UIColor colorWithWhite:1 alpha:0.7];
    [p addSubview:self.stat];
}

- (void)buildMini {
    UIButton *m = [UIButton buttonWithType:UIButtonTypeSystem];
    m.frame = CGRectMake(16, 90, 46, 46);
    m.backgroundColor = CHPurple(0.95);
    m.layer.cornerRadius = 23;
    [m setTitle:@"M" forState:UIControlStateNormal];
    [m setTitleColor:UIColor.whiteColor forState:UIControlStateNormal];
    m.titleLabel.font = [UIFont boldSystemFontOfSize:20];
    m.hidden = YES;
    [m addTarget:self action:@selector(onRestore) forControlEvents:UIControlEventTouchUpInside];
    [m addGestureRecognizer:[[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(panFree:)]];
    [self.root addSubview:m];
    self.mini = m;
}

- (void)buildCircle {
    UIView *c = [[UIView alloc] initWithFrame:CGRectMake(0, 0, self.size, self.size)];
    c.center = CGPointMake(CGRectGetMidX(self.root.bounds), CGRectGetMidY(self.root.bounds));
    c.hidden = YES;
    c.layer.borderWidth = 2.5;
    c.layer.borderColor = UIColor.whiteColor.CGColor;
    self.circle = c;

    self.dot = [[UIView alloc] initWithFrame:CGRectMake(0, 0, 8, 8)];
    self.dot.backgroundColor = UIColor.whiteColor;
    self.dot.layer.cornerRadius = 4;
    [c addSubview:self.dot];

    self.circleLabel = CHLabel(@"", CGRectZero, 16, YES);
    self.circleLabel.textAlignment = NSTextAlignmentCenter;
    [c addSubview:self.circleLabel];

    [c addGestureRecognizer:[[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(panFree:)]];
    [c addGestureRecognizer:[[UITapGestureRecognizer alloc] initWithTarget:self action:@selector(tapCircle)]];
    [c addGestureRecognizer:[[UIPinchGestureRecognizer alloc] initWithTarget:self action:@selector(pinchCircle:)]];
    [self.root addSubview:c];
    [self layoutCircle];
}

- (void)layoutCircle {
    CGPoint ctr = self.circle.center;
    self.circle.bounds = CGRectMake(0, 0, self.size, self.size);
    self.circle.center = ctr;
    self.circle.layer.cornerRadius = self.size / 2;
    self.dot.center = CGPointMake(self.size / 2, self.size / 2);
    self.circleLabel.frame = CGRectMake(0, self.size * 0.18, self.size, 22);
    self.sizeLabel.text = [NSString stringWithFormat:@"Kích thước nút: %.0f", self.size];
    [self refreshCircleColor];
}

- (void)refreshCircleColor {
    BOOL on = self.timer != nil;
    self.circle.backgroundColor = on ? [UIColor colorWithRed:1 green:0.2 blue:0.3 alpha:0.35] : CHPurple(0.35);
}

#pragma mark Hành động

- (void)onMinimize {
    self.mini.center = CGPointMake(CGRectGetMinX(self.panel.frame) + 23, CGRectGetMinY(self.panel.frame) + 23);
    self.panel.hidden = YES;
    self.mini.hidden = NO;
}

- (void)onRestore {
    self.panel.frame = CGRectMake(CGRectGetMinX(self.mini.frame), CGRectGetMinY(self.mini.frame),
                                  self.panel.bounds.size.width, self.panel.bounds.size.height);
    [self clamp:self.panel];
    self.mini.hidden = YES;
    self.panel.hidden = NO;
}

- (void)onToggleCircle {
    BOOL show = self.circle.hidden;
    if (!show) [self stop];
    self.circle.hidden = !show;
    [self.showBtn setTitle:show ? @"Ẩn nút tròn" : @"Hiện nút tròn" forState:UIControlStateNormal];
}

- (void)onSwitch:(UISwitch *)s {
    if (s.on) [self start]; else [self stop];
}

- (void)onCps:(UISlider *)s {
    self.cpsLabel.text = [NSString stringWithFormat:@"Tốc độ: %.0f lần/giây", s.value];
    if (self.timer) [self restartTimer];
}

- (void)onSize:(UISlider *)s {
    self.size = round(s.value);
    [self layoutCircle];
}

- (void)tapCircle {
    if (self.timer) [self stop]; else [self start];
}

- (void)pinchCircle:(UIPinchGestureRecognizer *)g {
    self.size = MIN(160, MAX(40, self.size * g.scale));
    g.scale = 1;
    self.sizeSlider.value = self.size;
    [self layoutCircle];
}

- (void)panPanel:(UIPanGestureRecognizer *)g {
    UIView *v = g.view.superview;
    CGPoint t = [g translationInView:self.root];
    v.center = CGPointMake(v.center.x + t.x, v.center.y + t.y);
    [g setTranslation:CGPointZero inView:self.root];
    [self clamp:v];
}

- (void)panFree:(UIPanGestureRecognizer *)g {
    UIView *v = g.view;
    CGPoint t = [g translationInView:self.root];
    v.center = CGPointMake(v.center.x + t.x, v.center.y + t.y);
    [g setTranslation:CGPointZero inView:self.root];
    [self clamp:v];
}

- (void)clamp:(UIView *)v {
    CGRect b = self.root.bounds;
    CGFloat hw = v.bounds.size.width / 2, hh = v.bounds.size.height / 2;
    CGFloat x = MIN(MAX(v.center.x, hw), b.size.width - hw);
    CGFloat y = MIN(MAX(v.center.y, hh), b.size.height - hh);
    v.center = CGPointMake(x, y);
}

#pragma mark Auto click

- (void)start {
    if (self.circle.hidden) [self onToggleCircle];
    NSInteger sec = self.secs[self.seg.selectedSegmentIndex].integerValue;
    self.endTime = sec > 0 ? CACurrentMediaTime() + sec : 0;
    [self restartTimer];
    self.sw.on = YES;
}

- (void)restartTimer {
    if (self.timer) {
        dispatch_source_cancel(self.timer);
        self.timer = nil;
    }
    self.lastShown = -1;
    double cps = MAX(10, self.cpsSlider.value);
    dispatch_source_t t = dispatch_source_create(DISPATCH_SOURCE_TYPE_TIMER, 0, 0, dispatch_get_main_queue());
    dispatch_source_set_timer(t, DISPATCH_TIME_NOW, (uint64_t)(NSEC_PER_SEC / cps), 0);
    __weak typeof(self) ws = self;
    dispatch_source_set_event_handler(t, ^{ [ws tick]; });
    self.timer = t;
    dispatch_resume(t);
    [self refreshCircleColor];
}

- (void)stop {
    if (self.timer) {
        dispatch_source_cancel(self.timer);
        self.timer = nil;
    }
    self.endTime = 0;
    self.sw.on = NO;
    self.circleLabel.text = @"";
    self.stat.text = [NSString stringWithFormat:@"Đã click: %ld", (long)self.taps];
    [self refreshCircleColor];
}

- (void)tick {
    if (self.endTime > 0 && CACurrentMediaTime() >= self.endTime) {
        [self stop];
        return;
    }
    UIWindow *tw = [self targetWindow];
    if (!tw) return;
    CGPoint sp = [self.root convertPoint:self.circle.center toView:nil];
    CGPoint p = [tw convertPoint:sp fromWindow:self.win];
    CHTap(tw, p);
    self.taps++;
    if (self.endTime > 0) {
        NSInteger remain = (NSInteger)ceil(self.endTime - CACurrentMediaTime());
        if (remain != self.lastShown) {
            self.lastShown = remain;
            self.circleLabel.text = [NSString stringWithFormat:@"%lds", (long)remain];
            self.stat.text = [NSString stringWithFormat:@"Đã click: %ld", (long)self.taps];
        }
    } else if (self.taps % 30 == 0) {
        self.circleLabel.text = @"∞";
        self.stat.text = [NSString stringWithFormat:@"Đã click: %ld", (long)self.taps];
    }
}

@end

#pragma mark - Khởi động khi app mở

__attribute__((constructor)) static void CHInit(void) {
    __block id token = nil;
    token = [[NSNotificationCenter defaultCenter]
        addObserverForName:UIApplicationDidBecomeActiveNotification
                    object:nil
                     queue:NSOperationQueue.mainQueue
                usingBlock:^(NSNotification *n) {
                    [[NSNotificationCenter defaultCenter] removeObserver:token];
                    dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(1.0 * NSEC_PER_SEC)),
                                   dispatch_get_main_queue(), ^{ [[CHController shared] setup]; });
                }];
    // dự phòng nếu dylib nạp muộn, đã qua sự kiện "active"
    dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(4.0 * NSEC_PER_SEC)), dispatch_get_main_queue(), ^{
        if (UIApplication.sharedApplication.applicationState == UIApplicationStateActive) [[CHController shared] setup];
    });
}
