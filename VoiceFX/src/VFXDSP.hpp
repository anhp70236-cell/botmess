// VoiceFX.mm v2 - dylib doi giong (Nam/Nu/Em be) cho Werewolves Voice Online (TD-PSOLA, tu can cao do)
// Hook AudioUnitRender (bus 1 = mic) bang fishhook, xu ly PCM truoc khi game gui di.
#import <UIKit/UIKit.h>
#import <Foundation/Foundation.h>
#import <AudioToolbox/AudioToolbox.h>
#import <os/lock.h>
#include "VFXDSP.hpp"
extern "C" {
#include "fishhook.h"
}

#pragma clang diagnostic ignored "-Wdeprecated-declarations"

// =====================================================================
// 1) THAM SO + TRANG THAI
// =====================================================================
static VFXParams gP;
static std::atomic<int> gCalls{0}, gProcessed{0}, gSR{0}, gIsFloat{0}, gCh{0};

// =====================================================================
// 2) HOOK AudioUnitRender
// =====================================================================
struct VFXSlot {
    AudioUnit unit; int state;  // 0 moi, 1 xu ly, 2 bo qua
    AudioStreamBasicDescription fmt; VFXProcessor* proc; unsigned calls;
};
static VFXSlot gSlots[8];
static os_unfair_lock gLock = OS_UNFAIR_LOCK_INIT;

static VFXSlot* vfxGetSlot(AudioUnit u) {
    os_unfair_lock_lock(&gLock);
    VFXSlot* r = nullptr;
    for (int i = 0; i < 8; i++) if (gSlots[i].unit == u) { r = &gSlots[i]; break; }
    if (!r) for (int i = 0; i < 8; i++) if (!gSlots[i].unit) { gSlots[i].unit = u; gSlots[i].state = 0; r = &gSlots[i]; break; }
    os_unfair_lock_unlock(&gLock);
    return r;
}

static bool vfxIsIOUnit(AudioUnit u) {
    AudioComponent c = AudioComponentInstanceGetComponent(u); if (!c) return false;
    AudioComponentDescription d; if (AudioComponentGetDescription(c, &d) != noErr) return false;
    return d.componentType == kAudioUnitType_Output &&
           (d.componentSubType == kAudioUnitSubType_RemoteIO || d.componentSubType == kAudioUnitSubType_VoiceProcessingIO);
}

static void vfxRefresh(VFXSlot* s) {
    bool io = vfxIsIOUnit(s->unit);
    AudioStreamBasicDescription f; UInt32 sz = sizeof(f); memset(&f, 0, sz);
    OSStatus e = AudioUnitGetProperty(s->unit, kAudioUnitProperty_StreamFormat, kAudioUnitScope_Output, 1, &f, &sz);
    bool ok = io && e == noErr && f.mFormatID == kAudioFormatLinearPCM && f.mSampleRate >= 8000 && f.mChannelsPerFrame >= 1;
    bool isF = (f.mFormatFlags & kAudioFormatFlagIsFloat) != 0;
    if (ok) ok = isF ? (f.mBitsPerChannel == 32) : (f.mBitsPerChannel == 16);
    if (!ok) { s->state = 2; return; }
    if (!s->proc || fabs(s->fmt.mSampleRate - f.mSampleRate) > 1.0) {
        delete s->proc; s->proc = new VFXProcessor(); s->proc->init((float)f.mSampleRate, 4096);
    }
    s->fmt = f; s->state = 1;
    gSR = (int)f.mSampleRate; gIsFloat = isF ? 1 : 0; gCh = (int)f.mChannelsPerFrame;
}

static void vfxHandle(AudioUnit u, AudioBufferList* io, UInt32 frames) {
    gCalls++;
    VFXSlot* s = vfxGetSlot(u); if (!s) return;
    if (s->state == 0 || (++s->calls % 400) == 0) vfxRefresh(s);
    if (s->state != 1 || !s->proc) return;
    if (!gP.enabled.load()) { s->proc->voiceActive = false; return; }

    const AudioStreamBasicDescription& f = s->fmt;
    bool isF = (f.mFormatFlags & kAudioFormatFlagIsFloat) != 0;
    bool ni  = (f.mFormatFlags & kAudioFormatFlagIsNonInterleaved) != 0;
    int ch = (int)f.mChannelsPerFrame, stride = ni ? 1 : ch, bps = isF ? 4 : 2;
    if (io->mNumberBuffers < 1 || !io->mBuffers[0].mData) return;
    UInt32 avail = io->mBuffers[0].mDataByteSize / (bps * stride);
    UInt32 n = frames < avail ? frames : avail;

    VFXCfg cfg;
    cfg.autoPitch = gP.autoPitch.load(); cfg.st = gP.semitones.load(); cfg.fm = gP.formant.load(); cfg.gainDb = gP.gainDb.load();
    cfg.gate = gP.gate.load(); cfg.target = gP.targetF0.load(); cfg.srcF0 = gP.srcF0.load(); cfg.fixedSemis = gP.fixedSemis.load();
    cfg.k = gP.intonation.load(); cfg.postHpf = gP.postHpf.load(); cfg.clarity = gP.clarity.load();
    cfg.shift = cfg.autoPitch || fabsf(cfg.fixedSemis + cfg.st) > 0.05f || fabsf(cfg.fm - 1.f) > 0.01f;
    float* x = s->proc->buf;

    UInt32 pos = 0;
    while (pos < n) {
        int c = (int)((n - pos) > 4096 ? 4096 : (n - pos));
        // doc kenh 0
        if (isF) { const float* p = (const float*)io->mBuffers[0].mData + (size_t)pos * stride; for (int i = 0; i < c; i++) x[i] = p[(size_t)i * stride]; }
        else     { const int16_t* p = (const int16_t*)io->mBuffers[0].mData + (size_t)pos * stride; for (int i = 0; i < c; i++) x[i] = p[(size_t)i * stride] * (1.f / 32768.f); }
        s->proc->process(x, c, cfg);
        // ghi lai (nhan ra moi kenh)
        if (!ni) {
            if (isF) { float* p = (float*)io->mBuffers[0].mData + (size_t)pos * stride; for (int i = 0; i < c; i++) for (int k = 0; k < ch; k++) p[(size_t)i * stride + k] = x[i]; }
            else { int16_t* p = (int16_t*)io->mBuffers[0].mData + (size_t)pos * stride;
                   for (int i = 0; i < c; i++) { float v = x[i] * 32768.f; v = v > 32767.f ? 32767.f : (v < -32768.f ? -32768.f : v); int16_t q = (int16_t)lrintf(v); for (int k = 0; k < ch; k++) p[(size_t)i * stride + k] = q; } }
        } else {
            for (UInt32 b = 0; b < io->mNumberBuffers; b++) {
                if (!io->mBuffers[b].mData) continue;
                if (isF) { float* p = (float*)io->mBuffers[b].mData + pos; for (int i = 0; i < c; i++) p[i] = x[i]; }
                else { int16_t* p = (int16_t*)io->mBuffers[b].mData + pos;
                       for (int i = 0; i < c; i++) { float v = x[i] * 32768.f; v = v > 32767.f ? 32767.f : (v < -32768.f ? -32768.f : v); p[i] = (int16_t)lrintf(v); } }
            }
        }
        pos += c;
    }
    gProcessed++;
}

static OSStatus (*orig_AudioUnitRender)(AudioUnit, AudioUnitRenderActionFlags*, const AudioTimeStamp*, UInt32, UInt32, AudioBufferList*) = NULL;
static OSStatus hook_AudioUnitRender(AudioUnit u, AudioUnitRenderActionFlags* flags, const AudioTimeStamp* ts,
                                     UInt32 bus, UInt32 frames, AudioBufferList* io) {
    OSStatus st = orig_AudioUnitRender(u, flags, ts, bus, frames, io);
    if (st == noErr && bus == 1 && io && frames > 0 && !(flags && (*flags & kAudioUnitRenderAction_OutputIsSilence)))
        vfxHandle(u, io, frames);
    return st;
}

static OSStatus (*orig_AudioComponentInstanceDispose)(AudioComponentInstance) = NULL;
static OSStatus hook_AudioComponentInstanceDispose(AudioComponentInstance inst) {
    os_unfair_lock_lock(&gLock);
    for (int i = 0; i < 8; i++) if (gSlots[i].unit == (AudioUnit)inst) {
        gSlots[i].unit = NULL; gSlots[i].state = 0; gSlots[i].calls = 0;
        if (gSlots[i].proc) gSlots[i].proc->voiceActive = false;
    }
    os_unfair_lock_unlock(&gLock);
    return orig_AudioComponentInstanceDispose(inst);
}

// =====================================================================
// 3) PRESET
// =====================================================================
typedef struct { float f0, fm, gain, gate, hpf, fixed; } VFXPreset;
// f0 = cao do dich (Hz) khi bat "Tu can theo giong that"; fixed = so ban cung dich co dinh khi tat tu can
// [giong that: 0 Nam, 1 Nu][muon co: 0 Nam chuan, 1 Nam tram, 2 Nu chuan, 3 Nu cao, 4 Em be]
static const VFXPreset kPresets[2][5] = {
    { {120.f, 1.00f, 0.f, 0.30f,  80.f,  0.f}, { 98.f, 0.92f, 0.f, 0.30f,  70.f, -3.f}, {215.f, 1.17f, 0.f, 0.30f, 120.f,  8.5f}, {260.f, 1.24f, 0.f, 0.30f, 140.f, 12.f}, {380.f, 1.38f, 0.f, 0.30f, 180.f, 17.f} },
    { {120.f, 0.86f, 0.f, 0.30f,  80.f, -10.f}, { 98.f, 0.80f, 0.f, 0.30f,  70.f, -13.5f}, {215.f, 1.00f, 0.f, 0.30f, 110.f,  0.f}, {260.f, 1.07f, 0.f, 0.30f, 130.f,  3.5f}, {380.f, 1.20f, 0.f, 0.30f, 180.f, 10.f} },
};
static const float kSrcF0[2] = { 125.f, 215.f };   // tam cao do giong that (khoi dau; ung dung tu hoc theo ban)

// =====================================================================
// 4) GIAO DIEN (kieu iOS goc: blur he thong, mau he thong, khong phat sang)
// =====================================================================
@interface VFXOverlay : UIView
@property (nonatomic, copy) void (^onLayout)(void);
@end
@implementation VFXOverlay
- (UIView*)hitTest:(CGPoint)p withEvent:(UIEvent*)e { UIView* v = [super hitTest:p withEvent:e]; return v == self ? nil : v; }
- (void)layoutSubviews { [super layoutSubviews]; if (self.onLayout) self.onLayout(); }
@end

@interface VFXUI : NSObject
@property (nonatomic, strong) VFXOverlay* overlay;
@property (nonatomic, strong) UIVisualEffectView* btn;
@property (nonatomic, strong) UIVisualEffectView* panel;
@property (nonatomic, strong) UISwitch *swOn, *swClarity, *swAuto;
@property (nonatomic, strong) UISegmentedControl *segSrc, *segDst;
@property (nonatomic, strong) NSArray<UISlider*>* sliders;
@property (nonatomic, strong) NSArray<UILabel*>* valLabels;
@property (nonatomic, strong) UILabel* status;
@property (nonatomic, strong) NSTimer* timer;
@end

@implementation VFXUI

+ (instancetype)shared { static VFXUI* i; static dispatch_once_t t; dispatch_once(&t, ^{ i = [VFXUI new]; }); return i; }

- (void)start {
    dispatch_async(dispatch_get_main_queue(), ^{
        self.timer = [NSTimer scheduledTimerWithTimeInterval:1.0 target:self selector:@selector(tick) userInfo:nil repeats:YES];
    });
}

static UIWindow* vfxKeyWindow(void) {
    for (UIScene* sc in UIApplication.sharedApplication.connectedScenes) {
        if (![sc isKindOfClass:UIWindowScene.class] || sc.activationState != UISceneActivationStateForegroundActive) continue;
        for (UIWindow* w in ((UIWindowScene*)sc).windows) if (w.isKeyWindow) return w;
    }
    return UIApplication.sharedApplication.keyWindow ?: UIApplication.sharedApplication.windows.firstObject;
}

- (void)tick {
    UIWindow* w = vfxKeyWindow(); if (!w) return;
    if (!self.overlay) [self build];
    if (self.overlay.superview != w) { self.overlay.frame = w.bounds; [w addSubview:self.overlay]; }
    else if (w.subviews.lastObject != self.overlay) [w bringSubviewToFront:self.overlay];
    if (!self.panel.hidden) [self updateStatus];
}

// ---- tien ich tao control ----
static UILabel* vfxLabel(NSString* t, UIFont* f, UIColor* c) { UILabel* l = [UILabel new]; l.text = t; l.font = f; l.textColor = c; return l; }

- (UIView*)sliderRow:(NSString*)title min:(float)mn max:(float)mx tag:(int)tag valueLabel:(UILabel* __autoreleasing*)outVal slider:(UISlider* __autoreleasing*)outSl {
    UILabel* t = vfxLabel(title, [UIFont systemFontOfSize:14], UIColor.labelColor);
    UILabel* v = vfxLabel(@"", [UIFont monospacedDigitSystemFontOfSize:14 weight:UIFontWeightRegular], UIColor.secondaryLabelColor);
    v.textAlignment = NSTextAlignmentRight;
    UIStackView* h = [[UIStackView alloc] initWithArrangedSubviews:@[t, v]]; h.distribution = UIStackViewDistributionFill;
    [v setContentHuggingPriority:UILayoutPriorityRequired forAxis:UILayoutConstraintAxisHorizontal];
    UISlider* s = [UISlider new]; s.minimumValue = mn; s.maximumValue = mx; s.tag = tag;
    [s addTarget:self action:@selector(sliderChanged:) forControlEvents:UIControlEventValueChanged];
    UIStackView* col = [[UIStackView alloc] initWithArrangedSubviews:@[h, s]]; col.axis = UILayoutConstraintAxisVertical; col.spacing = 4;
    *outVal = v; *outSl = s; return col;
}

- (UIView*)switchRow:(NSString*)title sw:(UISwitch* __autoreleasing*)outSw bold:(BOOL)bold sel:(SEL)sel {
    UILabel* t = vfxLabel(title, bold ? [UIFont systemFontOfSize:17 weight:UIFontWeightSemibold] : [UIFont systemFontOfSize:14], UIColor.labelColor);
    UISwitch* s = [UISwitch new]; [s addTarget:self action:sel forControlEvents:UIControlEventValueChanged];
    UIStackView* h = [[UIStackView alloc] initWithArrangedSubviews:@[t, s]]; h.alignment = UIStackViewAlignmentCenter;
    *outSw = s; return h;
}

- (void)build {
    CGRect sb = UIScreen.mainScreen.bounds;
    self.overlay = [[VFXOverlay alloc] initWithFrame:sb];
    self.overlay.autoresizingMask = UIViewAutoresizingFlexibleWidth | UIViewAutoresizingFlexibleHeight;
    __weak VFXUI* ws = self;
    self.overlay.onLayout = ^{ [ws layoutPanel]; };

    // nut noi
    self.btn = [[UIVisualEffectView alloc] initWithEffect:[UIBlurEffect effectWithStyle:UIBlurEffectStyleSystemThinMaterial]];
    self.btn.frame = CGRectMake(10, 150, 44, 44); self.btn.layer.cornerRadius = 22; self.btn.clipsToBounds = YES;
    UIImageView* ic = [[UIImageView alloc] initWithImage:[UIImage systemImageNamed:@"waveform"]];
    ic.tintColor = UIColor.labelColor; ic.contentMode = UIViewContentModeScaleAspectFit; ic.frame = CGRectMake(11, 11, 22, 22);
    [self.btn.contentView addSubview:ic];
    [self.btn addGestureRecognizer:[[UITapGestureRecognizer alloc] initWithTarget:self action:@selector(togglePanel)]];
    [self.btn addGestureRecognizer:[[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(dragBtn:)]];
    [self.overlay addSubview:self.btn];

    // bang dieu khien
    self.panel = [[UIVisualEffectView alloc] initWithEffect:[UIBlurEffect effectWithStyle:UIBlurEffectStyleSystemMaterial]];
    self.panel.layer.cornerRadius = 20; self.panel.clipsToBounds = YES; self.panel.hidden = YES;
    [self.overlay addSubview:self.panel];

    UIScrollView* sv = [UIScrollView new]; sv.translatesAutoresizingMaskIntoConstraints = NO; sv.alwaysBounceVertical = NO;
    [self.panel.contentView addSubview:sv];
    [NSLayoutConstraint activateConstraints:@[
        [sv.leadingAnchor constraintEqualToAnchor:self.panel.contentView.leadingAnchor],
        [sv.trailingAnchor constraintEqualToAnchor:self.panel.contentView.trailingAnchor],
        [sv.topAnchor constraintEqualToAnchor:self.panel.contentView.topAnchor],
        [sv.bottomAnchor constraintEqualToAnchor:self.panel.contentView.bottomAnchor]]];

    UIStackView* st = [UIStackView new]; st.axis = UILayoutConstraintAxisVertical; st.spacing = 14; st.translatesAutoresizingMaskIntoConstraints = NO;
    [sv addSubview:st];
    [NSLayoutConstraint activateConstraints:@[
        [st.leadingAnchor constraintEqualToAnchor:sv.contentLayoutGuide.leadingAnchor constant:18],
        [st.trailingAnchor constraintEqualToAnchor:sv.contentLayoutGuide.trailingAnchor constant:-18],
        [st.topAnchor constraintEqualToAnchor:sv.contentLayoutGuide.topAnchor constant:18],
        [st.bottomAnchor constraintEqualToAnchor:sv.contentLayoutGuide.bottomAnchor constant:-18],
        [st.widthAnchor constraintEqualToAnchor:sv.frameLayoutGuide.widthAnchor constant:-36]]];

    UISwitch* sw1; [st addArrangedSubview:[self switchRow:@"Đổi giọng" sw:&sw1 bold:YES sel:@selector(togglesChanged)]]; self.swOn = sw1;

    [st addArrangedSubview:vfxLabel(@"Giọng thật của bạn", [UIFont systemFontOfSize:13], UIColor.secondaryLabelColor)];
    self.segSrc = [[UISegmentedControl alloc] initWithItems:@[@"Nam", @"Nữ"]];
    [self.segSrc addTarget:self action:@selector(presetChanged) forControlEvents:UIControlEventValueChanged];
    [st addArrangedSubview:self.segSrc];

    [st addArrangedSubview:vfxLabel(@"Giọng muốn có", [UIFont systemFontOfSize:13], UIColor.secondaryLabelColor)];
    self.segDst = [[UISegmentedControl alloc] initWithItems:@[@"Nam", @"Nam trầm", @"Nữ", @"Nữ cao", @"Em bé"]];
    [self.segDst setTitleTextAttributes:@{NSFontAttributeName: [UIFont systemFontOfSize:11]} forState:UIControlStateNormal];
    [self.segDst addTarget:self action:@selector(presetChanged) forControlEvents:UIControlEventValueChanged];
    [st addArrangedSubview:self.segDst];

    UISwitch* sw3; [st addArrangedSubview:[self switchRow:@"Tự căn theo giọng thật (khuyên bật)" sw:&sw3 bold:NO sel:@selector(togglesChanged)]]; self.swAuto = sw3;

    NSArray* defs = @[ @[@"Tinh chỉnh cao độ", @-6, @6], @[@"Âm sắc", @0.7, @1.5], @[@"Âm lượng", @-6, @12], @[@"Lọc ồn", @0, @1] ];
    NSMutableArray *sl = [NSMutableArray array], *vl = [NSMutableArray array];
    for (int i = 0; i < 4; i++) {
        UILabel* v; UISlider* s;
        UIView* row = [self sliderRow:defs[i][0] min:[defs[i][1] floatValue] max:[defs[i][2] floatValue] tag:i valueLabel:&v slider:&s];
        [st addArrangedSubview:row]; [sl addObject:s]; [vl addObject:v];
    }
    self.sliders = sl; self.valLabels = vl;

    UISwitch* sw2; [st addArrangedSubview:[self switchRow:@"Tăng độ rõ giọng" sw:&sw2 bold:NO sel:@selector(togglesChanged)]]; self.swClarity = sw2;

    self.status = vfxLabel(@"", [UIFont systemFontOfSize:12], UIColor.secondaryLabelColor); self.status.numberOfLines = 0;
    [st addArrangedSubview:self.status];

    UIButton* reset = [UIButton buttonWithType:UIButtonTypeSystem];
    [reset setTitle:@"Đặt lại" forState:UIControlStateNormal]; reset.titleLabel.font = [UIFont systemFontOfSize:15];
    [reset addTarget:self action:@selector(resetAll) forControlEvents:UIControlEventTouchUpInside];
    [st addArrangedSubview:reset];

    [self loadState];
}

- (void)layoutPanel {
    if (!self.panel) return;
    CGRect b = self.overlay.bounds; UIEdgeInsets sa = self.overlay.safeAreaInsets;
    CGFloat w = MIN(330, b.size.width - 24);
    CGFloat h = MIN(520, b.size.height - sa.top - sa.bottom - 40);
    self.panel.frame = CGRectMake((b.size.width - w) / 2, sa.top + 20, w, h);
    CGPoint c = self.btn.center;   // giu nut trong man hinh sau khi xoay
    c.x = MIN(MAX(c.x, 22), b.size.width - 22); c.y = MIN(MAX(c.y, sa.top + 22), b.size.height - sa.bottom - 22); self.btn.center = c;
}

- (void)togglePanel {
    BOOL show = self.panel.hidden;
    if (show) { [self layoutPanel]; self.panel.alpha = 0; self.panel.hidden = NO; [self updateStatus]; }
    [UIView animateWithDuration:0.2 animations:^{ self.panel.alpha = show ? 1 : 0; } completion:^(BOOL f) { if (!show) self.panel.hidden = YES; }];
}

- (void)dragBtn:(UIPanGestureRecognizer*)g {
    CGPoint t = [g translationInView:self.overlay]; CGPoint c = self.btn.center; c.x += t.x; c.y += t.y; self.btn.center = c;
    [g setTranslation:CGPointZero inView:self.overlay];
    if (g.state == UIGestureRecognizerStateEnded) {
        CGRect b = self.overlay.bounds; CGFloat x = c.x < b.size.width / 2 ? 30 : b.size.width - 30;
        [UIView animateWithDuration:0.2 animations:^{ self.btn.center = CGPointMake(x, c.y); }];
    }
}

// ---- trang thai ----
- (void)updateStatus {
    int calls = gCalls.load(), proc = gProcessed.load();
    NSString* s;
    if (calls == 0) s = @"Mic: chưa thấy luồng thu âm. Hãy bật mic trong trận rồi mở lại menu.";
    else if (gSR.load() == 0) s = @"Mic: có luồng thu âm nhưng định dạng chưa hỗ trợ.";
    else s = [NSString stringWithFormat:@"Mic: đã bắt • %d Hz • %@ • %d kênh%@", gSR.load(), gIsFloat.load() ? @"Float32" : @"Int16", gCh.load(),
              self.swOn.isOn ? [NSString stringWithFormat:@" • đã xử lý %d", proc] : @" • đang tắt"];
    self.status.text = s;
}

// ---- dong bo UI <-> tham so ----
- (void)refreshLabels {
    float st = self.sliders[0].value, fm = self.sliders[1].value, g = self.sliders[2].value, gt = self.sliders[3].value;
    self.valLabels[0].text = [NSString stringWithFormat:@"%+.1f bán cung", st];
    self.valLabels[1].text = [NSString stringWithFormat:@"%+.0f%%", (fm - 1.f) * 100.f];
    self.valLabels[2].text = [NSString stringWithFormat:@"%+.0f dB", g];
    self.valLabels[3].text = [NSString stringWithFormat:@"%.0f%%", gt * 100.f];
}

- (void)push {
    NSInteger si = MAX(0, MIN(1, self.segSrc.selectedSegmentIndex)), di = MAX(0, MIN(4, self.segDst.selectedSegmentIndex));
    VFXPreset p = kPresets[si][di];
    gP.semitones = self.sliders[0].value; gP.formant = self.sliders[1].value; gP.gainDb = self.sliders[2].value; gP.gate = self.sliders[3].value;
    gP.targetF0 = p.f0; gP.srcF0 = kSrcF0[si]; gP.fixedSemis = p.fixed; gP.postHpf = p.hpf;
    gP.autoPitch = self.swAuto.isOn; gP.clarity = self.swClarity.isOn; gP.enabled = self.swOn.isOn;
    NSUserDefaults* d = NSUserDefaults.standardUserDefaults;
    [d setBool:self.swOn.isOn forKey:@"vfx2_on"]; [d setBool:self.swClarity.isOn forKey:@"vfx2_clarity"]; [d setBool:self.swAuto.isOn forKey:@"vfx2_auto"];
    [d setInteger:si forKey:@"vfx2_src"]; [d setInteger:di forKey:@"vfx2_dst"];
    for (int i = 0; i < 4; i++) [d setFloat:self.sliders[i].value forKey:[NSString stringWithFormat:@"vfx2_s%d", i]];
}

- (void)applyPreset {
    NSInteger s = self.segSrc.selectedSegmentIndex, t = self.segDst.selectedSegmentIndex;
    if (s < 0 || t < 0) return;
    VFXPreset p = kPresets[s][t];
    self.sliders[0].value = 0.f; self.sliders[1].value = p.fm; self.sliders[2].value = p.gain; self.sliders[3].value = p.gate;
}
- (void)presetChanged { [self applyPreset]; [self refreshLabels]; [self push]; }
- (void)sliderChanged:(UISlider*)s { [self refreshLabels]; [self push]; }
- (void)togglesChanged { [self push]; [self updateStatus]; }

- (void)loadState {
    NSUserDefaults* d = NSUserDefaults.standardUserDefaults;
    BOOL has = [d objectForKey:@"vfx2_src"] != nil;
    self.segSrc.selectedSegmentIndex = has ? [d integerForKey:@"vfx2_src"] : 0;
    self.segDst.selectedSegmentIndex = has ? [d integerForKey:@"vfx2_dst"] : 2;
    self.swOn.on = has ? [d boolForKey:@"vfx2_on"] : NO;
    self.swClarity.on = has ? [d boolForKey:@"vfx2_clarity"] : YES;
    self.swAuto.on = has ? [d boolForKey:@"vfx2_auto"] : YES;
    if (has) { for (int i = 0; i < 4; i++) self.sliders[i].value = [d floatForKey:[NSString stringWithFormat:@"vfx2_s%d", i]]; }
    else [self applyPreset];
    [self refreshLabels]; [self push];
}

- (void)resetAll {
    self.swOn.on = NO; self.swClarity.on = YES; self.swAuto.on = YES; self.segSrc.selectedSegmentIndex = 0; self.segDst.selectedSegmentIndex = 2;
    [self applyPreset]; [self refreshLabels]; [self push]; [self updateStatus];
}
@end

// =====================================================================
// 5) KHOI TAO
// =====================================================================
__attribute__((constructor)) static void vfxInit(void) {
    struct rebinding rs[2] = {
        { "AudioUnitRender", (void*)hook_AudioUnitRender, (void**)&orig_AudioUnitRender },
        { "AudioComponentInstanceDispose", (void*)hook_AudioComponentInstanceDispose, (void**)&orig_AudioComponentInstanceDispose } };
    rebind_symbols(rs, 2);
    [[VFXUI shared] start];
    NSLog(@"[VoiceFX] loaded");
}
