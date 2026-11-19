#import "ModMenuUI.h"
#import "VoiceModManager.h"

// ================= Passthrough Container =================
@interface VMPassthroughView : UIView
@end

@implementation VMPassthroughView
- (UIView *)hitTest:(CGPoint)point withEvent:(UIEvent *)event {
    UIView *hit = [super hitTest:point withEvent:event];
    return (hit == self) ? nil : hit;
}
@end

// ================= iOS Checkbox Row =================
@interface VMCheckRow : UIControl
@property (nonatomic, assign) BOOL checked;
@property (nonatomic, copy) void (^onToggle)(BOOL checked);
- (instancetype)initWithTitle:(NSString *)title subtitle:(NSString *)subtitle checked:(BOOL)checked;
@end

@implementation VMCheckRow {
    UIView *_boxView;
    UILabel *_checkMark;
    UILabel *_titleLabel;
    UILabel *_subLabel;
}

- (instancetype)initWithTitle:(NSString *)title subtitle:(NSString *)subtitle checked:(BOOL)checked {
    if ((self = [super initWithFrame:CGRectZero])) {
        self.translatesAutoresizingMaskIntoConstraints = NO;
        self.backgroundColor = [UIColor colorWithRed:0.15 green:0.16 blue:0.20 alpha:0.95];
        self.layer.cornerRadius = 10.0;
        self.layer.borderWidth = 0.5;
        self.layer.borderColor = [UIColor colorWithWhite:1.0 alpha:0.08].CGColor;

        _boxView = [[UIView alloc] init];
        _boxView.translatesAutoresizingMaskIntoConstraints = NO;
        _boxView.userInteractionEnabled = NO;
        _boxView.layer.cornerRadius = 6.0;
        _boxView.layer.borderWidth = 1.2;
        [self addSubview:_boxView];

        _checkMark = [[UILabel alloc] init];
        _checkMark.translatesAutoresizingMaskIntoConstraints = NO;
        _checkMark.text = @"✓";
        _checkMark.textColor = [UIColor whiteColor];
        _checkMark.font = [UIFont systemFontOfSize:13 weight:UIFontWeightBold];
        _checkMark.textAlignment = NSTextAlignmentCenter;
        [_boxView addSubview:_checkMark];

        _titleLabel = [[UILabel alloc] init];
        _titleLabel.translatesAutoresizingMaskIntoConstraints = NO;
        _titleLabel.text = title;
        _titleLabel.textColor = [UIColor colorWithWhite:0.95 alpha:1.0];
        _titleLabel.font = [UIFont systemFontOfSize:13.5 weight:UIFontWeightMedium];
        _titleLabel.numberOfLines = 0; // Không bao giờ bị mất chữ
        _titleLabel.lineBreakMode = NSLineBreakByWordWrapping;
        [self addSubview:_titleLabel];

        _subLabel = [[UILabel alloc] init];
        _subLabel.translatesAutoresizingMaskIntoConstraints = NO;
        _subLabel.text = subtitle;
        _subLabel.textColor = [UIColor colorWithWhite:0.62 alpha:1.0];
        _subLabel.font = [UIFont systemFontOfSize:11.5 weight:UIFontWeightRegular];
        _subLabel.numberOfLines = 0;
        _subLabel.lineBreakMode = NSLineBreakByWordWrapping;
        [self addSubview:_subLabel];

        [NSLayoutConstraint activateConstraints:@[
            [_boxView.leadingAnchor constraintEqualToAnchor:self.leadingAnchor constant:12],
            [_boxView.centerYAnchor constraintEqualToAnchor:self.centerYAnchor],
            [_boxView.widthAnchor constraintEqualToConstant:22],
            [_boxView.heightAnchor constraintEqualToConstant:22],

            [_checkMark.centerXAnchor constraintEqualToAnchor:_boxView.centerXAnchor],
            [_checkMark.centerYAnchor constraintEqualToAnchor:_boxView.centerYAnchor],

            [_titleLabel.topAnchor constraintEqualToAnchor:self.topAnchor constant:10],
            [_titleLabel.leadingAnchor constraintEqualToAnchor:_boxView.trailingAnchor constant:10],
            [_titleLabel.trailingAnchor constraintEqualToAnchor:self.trailingAnchor constant:-12],

            [_subLabel.topAnchor constraintEqualToAnchor:_titleLabel.bottomAnchor constant:2],
            [_subLabel.leadingAnchor constraintEqualToAnchor:_titleLabel.leadingAnchor],
            [_subLabel.trailingAnchor constraintEqualToAnchor:_titleLabel.trailingAnchor],
            [_subLabel.bottomAnchor constraintEqualToAnchor:self.bottomAnchor constant:-10]
        ]];

        [self addTarget:self action:@selector(handleTap) forControlEvents:UIControlEventTouchUpInside];
        self.checked = checked;
    }
    return self;
}

- (void)setChecked:(BOOL)checked {
    _checked = checked;
    if (checked) {
        _boxView.backgroundColor = [UIColor colorWithRed:0.20 green:0.48 blue:0.96 alpha:1.0];
        _boxView.layer.borderColor = [UIColor colorWithRed:0.20 green:0.48 blue:0.96 alpha:1.0].CGColor;
        _checkMark.hidden = NO;
    } else {
        _boxView.backgroundColor = [UIColor colorWithRed:0.11 green:0.12 blue:0.15 alpha:1.0];
        _boxView.layer.borderColor = [UIColor colorWithWhite:1.0 alpha:0.22].CGColor;
        _checkMark.hidden = YES;
    }
}

- (void)handleTap {
    self.checked = !self.checked;
    if (self.onToggle) {
        self.onToggle(self.checked);
    }
}

@end

// ================= Collapsible Section =================
@interface VMSectionBox : UIView
@property (nonatomic, strong, readonly) UIStackView *contentStack;
- (instancetype)initWithTitle:(NSString *)title expanded:(BOOL)expanded;
@end

@implementation VMSectionBox {
    UIButton *_headerBtn;
    UILabel *_arrowLabel;
    BOOL _expanded;
}

- (instancetype)initWithTitle:(NSString *)title expanded:(BOOL)expanded {
    if ((self = [super initWithFrame:CGRectZero])) {
        self.translatesAutoresizingMaskIntoConstraints = NO;
        _expanded = expanded;

        _headerBtn = [UIButton buttonWithType:UIButtonTypeSystem];
        _headerBtn.translatesAutoresizingMaskIntoConstraints = NO;
        _headerBtn.backgroundColor = [UIColor colorWithRed:0.26 green:0.35 blue:0.48 alpha:0.95];
        _headerBtn.layer.cornerRadius = 9.0;
        _headerBtn.contentHorizontalAlignment = UIControlContentHorizontalAlignmentLeft;
        _headerBtn.contentEdgeInsets = UIEdgeInsetsMake(0, 32, 0, 12);
        [_headerBtn setTitle:title forState:UIControlStateNormal];
        [_headerBtn setTitleColor:[UIColor colorWithWhite:0.96 alpha:1.0] forState:UIControlStateNormal];
        _headerBtn.titleLabel.font = [UIFont systemFontOfSize:13.5 weight:UIFontWeightSemibold];
        [_headerBtn addTarget:self action:@selector(toggleExpand) forControlEvents:UIControlEventTouchUpInside];
        [self addSubview:_headerBtn];

        _arrowLabel = [[UILabel alloc] init];
        _arrowLabel.translatesAutoresizingMaskIntoConstraints = NO;
        _arrowLabel.textColor = [UIColor colorWithWhite:0.90 alpha:1.0];
        _arrowLabel.font = [UIFont systemFontOfSize:11 weight:UIFontWeightBold];
        _arrowLabel.userInteractionEnabled = NO;
        [_headerBtn addSubview:_arrowLabel];

        _contentStack = [[UIStackView alloc] init];
        _contentStack.translatesAutoresizingMaskIntoConstraints = NO;
        _contentStack.axis = UILayoutConstraintAxisVertical;
        _contentStack.spacing = 6.0;
        [self addSubview:_contentStack];

        [NSLayoutConstraint activateConstraints:@[
            [_headerBtn.topAnchor constraintEqualToAnchor:self.topAnchor],
            [_headerBtn.leadingAnchor constraintEqualToAnchor:self.leadingAnchor],
            [_headerBtn.trailingAnchor constraintEqualToAnchor:self.trailingAnchor],
            [_headerBtn.heightAnchor constraintEqualToConstant:36],

            [_arrowLabel.leadingAnchor constraintEqualToAnchor:_headerBtn.leadingAnchor constant:12],
            [_arrowLabel.centerYAnchor constraintEqualToAnchor:_headerBtn.centerYAnchor],

            [_contentStack.topAnchor constraintEqualToAnchor:_headerBtn.bottomAnchor constant:6],
            [_contentStack.leadingAnchor constraintEqualToAnchor:self.leadingAnchor],
            [_contentStack.trailingAnchor constraintEqualToAnchor:self.trailingAnchor],
            [_contentStack.bottomAnchor constraintEqualToAnchor:self.bottomAnchor]
        ]];

        [self updateStateAnimated:NO];
    }
    return self;
}

- (void)toggleExpand {
    _expanded = !_expanded;
    [self updateStateAnimated:YES];
}

- (void)updateStateAnimated:(BOOL)animated {
    _arrowLabel.text = _expanded ? @"▼" : @"▶";
    void (^changes)(void) = ^{
        self->_contentStack.hidden = !self->_expanded;
        self->_contentStack.alpha = self->_expanded ? 1.0 : 0.0;
    };
    if (animated) {
        [UIView animateWithDuration:0.18 animations:changes];
    } else {
        changes();
    }
}

@end

// ================= Main Floating Controller =================
@interface ModMenuController : NSObject
@property (nonatomic, strong) VMPassthroughView *overlayRoot;
@property (nonatomic, strong) UIView *menuCard;
@property (nonatomic, strong) UIButton *miniPill;
@property (nonatomic, strong) UISegmentedControl *tabControl;
@property (nonatomic, strong) UIView *voiceTabContainer;
@property (nonatomic, strong) UIView *roleTabContainer;
@property (nonatomic, strong) UILabel *statusLabel;
@property (nonatomic, strong) VMCheckRow *rowWolfVoice;
@property (nonatomic, strong) VMCheckRow *rowDeadVoice;
@property (nonatomic, strong) VMCheckRow *rowThirdSeeAll;
@property (nonatomic, strong) VMCheckRow *rowVillagerSee3rd;
@end

@implementation ModMenuController

+ (instancetype)shared {
    static ModMenuController *ctrl = nil;
    static dispatch_once_t onceToken;
    dispatch_once(&onceToken, ^{
        ctrl = [[ModMenuController alloc] init];
    });
    return ctrl;
}

- (UIWindow *)activeKeyWindow {
    if (@available(iOS 13.0, *)) {
        for (UIScene *scene in UIApplication.sharedApplication.connectedScenes) {
            if (scene.activationState == UISceneActivationStateForegroundActive &&
                [scene isKindOfClass:[UIWindowScene class]]) {
                UIWindowScene *ws = (UIWindowScene *)scene;
                for (UIWindow *w in ws.windows) {
                    if (w.isKeyWindow) return w;
                }
                if (ws.windows.firstObject) return ws.windows.firstObject;
            }
        }
    }
    return UIApplication.sharedApplication.windows.firstObject;
}

- (void)setupIfNeeded {
    if (self.overlayRoot && self.overlayRoot.superview) return;
    UIWindow *win = [self activeKeyWindow];
    if (!win) return;

    self.overlayRoot = [[VMPassthroughView alloc] initWithFrame:win.bounds];
    self.overlayRoot.autoresizingMask = UIViewAutoresizingFlexibleWidth | UIViewAutoresizingFlexibleHeight;
    self.overlayRoot.backgroundColor = [UIColor clearColor];
    [win addSubview:self.overlayRoot];

    [self buildMiniPill];
    [self buildMenuCard];
    [self syncFromManager];
}

- (void)buildMiniPill {
    self.miniPill = [UIButton buttonWithType:UIButtonTypeSystem];
    self.miniPill.frame = CGRectMake(16, 90, 96, 34);
    self.miniPill.backgroundColor = [UIColor colorWithRed:0.11 green:0.12 blue:0.16 alpha:0.92];
    self.miniPill.layer.cornerRadius = 17.0;
    self.miniPill.layer.borderWidth = 0.8;
    self.miniPill.layer.borderColor = [UIColor colorWithWhite:1.0 alpha:0.14].CGColor;
    [self.miniPill setTitle:@"Menu  ▸" forState:UIControlStateNormal];
    [self.miniPill setTitleColor:[UIColor colorWithWhite:0.95 alpha:1.0] forState:UIControlStateNormal];
    self.miniPill.titleLabel.font = [UIFont systemFontOfSize:13 weight:UIFontWeightSemibold];
    self.miniPill.hidden = YES;

    [self.miniPill addTarget:self action:@selector(expandMenu) forControlEvents:UIControlEventTouchUpInside];

    UIPanGestureRecognizer *pan = [[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(dragView:)];
    [self.miniPill addGestureRecognizer:pan];

    [self.overlayRoot addSubview:self.miniPill];
}

- (void)buildMenuCard {
    CGFloat width = MIN(self.overlayRoot.bounds.size.width - 28.0, 340.0);
    CGFloat x = (self.overlayRoot.bounds.size.width - width) * 0.5;
    CGFloat y = 84.0;

    self.menuCard = [[UIView alloc] initWithFrame:CGRectMake(x, y, width, 410)];
    self.menuCard.backgroundColor = [UIColor colorWithRed:0.10 green:0.11 blue:0.14 alpha:0.96];
    self.menuCard.layer.cornerRadius = 16.0;
    self.menuCard.layer.borderWidth = 0.8;
    self.menuCard.layer.borderColor = [UIColor colorWithWhite:1.0 alpha:0.12].CGColor;
    self.menuCard.clipsToBounds = YES;
    [self.overlayRoot addSubview:self.menuCard];

    // Header (kéo thả menu qua thanh header)
    UIView *header = [[UIView alloc] init];
    header.translatesAutoresizingMaskIntoConstraints = NO;
    header.backgroundColor = [UIColor colorWithRed:0.13 green:0.14 blue:0.18 alpha:1.0];
    [self.menuCard addSubview:header];

    UIPanGestureRecognizer *drag = [[UIPanGestureRecognizer alloc] initWithTarget:self action:@selector(dragView:)];
    [header addGestureRecognizer:drag];

    UIButton *minBtn = [UIButton buttonWithType:UIButtonTypeSystem];
    minBtn.translatesAutoresizingMaskIntoConstraints = NO;
    [minBtn setTitle:@"Thu nhỏ" forState:UIControlStateNormal];
    [minBtn setTitleColor:[UIColor colorWithRed:0.42 green:0.66 blue:1.0 alpha:1.0] forState:UIControlStateNormal];
    minBtn.titleLabel.font = [UIFont systemFontOfSize:12.5 weight:UIFontWeightSemibold];
    [minBtn addTarget:self action:@selector(minimizeMenu) forControlEvents:UIControlEventTouchUpInside];
    [header addSubview:minBtn];

    UILabel *titleLbl = [[UILabel alloc] init];
    titleLbl.translatesAutoresizingMaskIntoConstraints = NO;
    titleLbl.text = @"Bảng Điều Khiển";
    titleLbl.textColor = [UIColor colorWithWhite:0.96 alpha:1.0];
    titleLbl.font = [UIFont systemFontOfSize:14.5 weight:UIFontWeightSemibold];
    [header addSubview:titleLbl];

    // Tab bar gọn chuẩn iOS — chữ hiển thị đầy đủ
    self.tabControl = [[UISegmentedControl alloc] initWithItems:@[@"Voice Trận", @"Chức Năng"]];
    self.tabControl.translatesAutoresizingMaskIntoConstraints = NO;
    self.tabControl.selectedSegmentIndex = 0;
    if (@available(iOS 13.0, *)) {
        self.tabControl.selectedSegmentTintColor = [UIColor colorWithRed:0.20 green:0.48 blue:0.96 alpha:1.0];
        self.tabControl.backgroundColor = [UIColor colorWithRed:0.14 green:0.15 blue:0.19 alpha:1.0];
    }
    [self.tabControl setTitleTextAttributes:@{
        NSForegroundColorAttributeName: [UIColor colorWithWhite:0.75 alpha:1.0],
        NSFontAttributeName: [UIFont systemFontOfSize:13 weight:UIFontWeightMedium]
    } forState:UIControlStateNormal];
    [self.tabControl setTitleTextAttributes:@{
        NSForegroundColorAttributeName: [UIColor whiteColor],
        NSFontAttributeName: [UIFont systemFontOfSize:13 weight:UIFontWeightSemibold]
    } forState:UIControlStateSelected];
    [self.tabControl addTarget:self action:@selector(tabChanged) forControlEvents:UIControlEventValueChanged];
    [self.menuCard addSubview:self.tabControl];

    // ScrollView nội dung
    UIScrollView *scroll = [[UIScrollView alloc] init];
    scroll.translatesAutoresizingMaskIntoConstraints = NO;
    scroll.showsVerticalScrollIndicator = YES;
    [self.menuCard addSubview:scroll];

    UIView *contentHolder = [[UIView alloc] init];
    contentHolder.translatesAutoresizingMaskIntoConstraints = NO;
    [scroll addSubview:contentHolder];

    // Tab 0: Voice
    self.voiceTabContainer = [[UIView alloc] init];
    self.voiceTabContainer.translatesAutoresizingMaskIntoConstraints = NO;
    [contentHolder addSubview:self.voiceTabContainer];

    VMSectionBox *voiceSec = [[VMSectionBox alloc] initWithTitle:@"Nghe Voice Trong Trận" expanded:YES];
    [self.voiceTabContainer addSubview:voiceSec];

    __weak typeof(self) weakSelf = self;
    self.rowWolfVoice = [[VMCheckRow alloc] initWithTitle:@"Nghe voice Sói & Phe thứ 3"
                                                 subtitle:@"Nghe kênh Sói và Phe thứ 3 khi bạn là Dân"
                                                  checked:NO];
    self.rowWolfVoice.onToggle = ^(BOOL on) {
        [VoiceModManager shared].hearWolfAndThirdParty = on;
        [weakSelf updateStatusLabel];
    };
    [voiceSec.contentStack addArrangedSubview:self.rowWolfVoice];

    self.rowDeadVoice = [[VMCheckRow alloc] initWithTitle:@"Nghe voice người chết ban đêm"
                                                 subtitle:@"Mở nhận âm thanh kênh người đã chết khi về đêm"
                                                  checked:NO];
    self.rowDeadVoice.onToggle = ^(BOOL on) {
        [VoiceModManager shared].hearDeadAtNight = on;
        [weakSelf updateStatusLabel];
    };
    [voiceSec.contentStack addArrangedSubview:self.rowDeadVoice];

    // Tab 1: Chức năng (Role)
    self.roleTabContainer = [[UIView alloc] init];
    self.roleTabContainer.translatesAutoresizingMaskIntoConstraints = NO;
    self.roleTabContainer.hidden = YES;
    [contentHolder addSubview:self.roleTabContainer];

    VMSectionBox *roleSec = [[VMSectionBox alloc] initWithTitle:@"Hiển Thị Chức Năng Người Chơi" expanded:YES];
    [self.roleTabContainer addSubview:roleSec];

    self.rowThirdSeeAll = [[VMCheckRow alloc] initWithTitle:@"Phe thứ 3 xem tất cả chức năng"
                                                   subtitle:@"Khi không phải Dân, xem chức năng toàn bộ người khác"
                                                    checked:NO];
    self.rowThirdSeeAll.onToggle = ^(BOOL on) {
        [VoiceModManager shared].thirdPartySeeAllRoles = on;
        [weakSelf updateStatusLabel];
    };
    [roleSec.contentStack addArrangedSubview:self.rowThirdSeeAll];

    self.rowVillagerSee3rd = [[VMCheckRow alloc] initWithTitle:@"Dân xem chức năng phe thứ 3"
                                                      subtitle:@"Khi là Dân, hiển thị vai trò của tất cả phe thứ 3"
                                                       checked:NO];
    self.rowVillagerSee3rd.onToggle = ^(BOOL on) {
        [VoiceModManager shared].villagerSeeThirdParty = on;
        [weakSelf updateStatusLabel];
    };
    [roleSec.contentStack addArrangedSubview:self.rowVillagerSee3rd];

    // Footer: trạng thái + 2 nút thao tác rõ chữ
    self.statusLabel = [[UILabel alloc] init];
    self.statusLabel.translatesAutoresizingMaskIntoConstraints = NO;
    self.statusLabel.textColor = [UIColor colorWithWhite:0.65 alpha:1.0];
    self.statusLabel.font = [UIFont systemFontOfSize:11.5 weight:UIFontWeightRegular];
    self.statusLabel.textAlignment = NSTextAlignmentCenter;
    self.statusLabel.numberOfLines = 0;
    [self.menuCard addSubview:self.statusLabel];

    UIStackView *btnRow = [[UIStackView alloc] init];
    btnRow.translatesAutoresizingMaskIntoConstraints = NO;
    btnRow.axis = UILayoutConstraintAxisHorizontal;
    btnRow.distribution = UIStackViewDistributionFillEqually;
    btnRow.spacing = 8.0;
    [self.menuCard addSubview:btnRow];

    UIButton *applyBtn = [UIButton buttonWithType:UIButtonTypeSystem];
    applyBtn.backgroundColor = [UIColor colorWithRed:0.20 green:0.48 blue:0.96 alpha:1.0];
    applyBtn.layer.cornerRadius = 9.0;
    [applyBtn setTitle:@"Áp Dụng Trước Trận" forState:UIControlStateNormal];
    [applyBtn setTitleColor:[UIColor whiteColor] forState:UIControlStateNormal];
    applyBtn.titleLabel.font = [UIFont systemFontOfSize:13 weight:UIFontWeightSemibold];
    [applyBtn addTarget:self action:@selector(onApplyTapped) forControlEvents:UIControlEventTouchUpInside];

    UIButton *resetBtn = [UIButton buttonWithType:UIButtonTypeSystem];
    resetBtn.backgroundColor = [UIColor colorWithRed:0.20 green:0.22 blue:0.27 alpha:1.0];
    resetBtn.layer.cornerRadius = 9.0;
    [resetBtn setTitle:@"Tắt Tất Cả" forState:UIControlStateNormal];
    [resetBtn setTitleColor:[UIColor colorWithWhite:0.90 alpha:1.0] forState:UIControlStateNormal];
    resetBtn.titleLabel.font = [UIFont systemFontOfSize:13 weight:UIFontWeightMedium];
    [resetBtn addTarget:self action:@selector(onResetTapped) forControlEvents:UIControlEventTouchUpInside];

    [btnRow addArrangedSubview:applyBtn];
    [btnRow addArrangedSubview:resetBtn];

    // Auto Layout
    [NSLayoutConstraint activateConstraints:@[
        [header.topAnchor constraintEqualToAnchor:self.menuCard.topAnchor],
        [header.leadingAnchor constraintEqualToAnchor:self.menuCard.leadingAnchor],
        [header.trailingAnchor constraintEqualToAnchor:self.menuCard.trailingAnchor],
        [header.heightAnchor constraintEqualToConstant:44],

        [titleLbl.centerXAnchor constraintEqualToAnchor:header.centerXAnchor],
        [titleLbl.centerYAnchor constraintEqualToAnchor:header.centerYAnchor],

        [minBtn.trailingAnchor constraintEqualToAnchor:header.trailingAnchor constant:-12],
        [minBtn.centerYAnchor constraintEqualToAnchor:header.centerYAnchor],

        [self.tabControl.topAnchor constraintEqualToAnchor:header.bottomAnchor constant:10],
        [self.tabControl.leadingAnchor constraintEqualToAnchor:self.menuCard.leadingAnchor constant:12],
        [self.tabControl.trailingAnchor constraintEqualToAnchor:self.menuCard.trailingAnchor constant:-12],
        [self.tabControl.heightAnchor constraintEqualToConstant:32],

        [scroll.topAnchor constraintEqualToAnchor:self.tabControl.bottomAnchor constant:10],
        [scroll.leadingAnchor constraintEqualToAnchor:self.menuCard.leadingAnchor constant:12],
        [scroll.trailingAnchor constraintEqualToAnchor:self.menuCard.trailingAnchor constant:-12],
        [scroll.bottomAnchor constraintEqualToAnchor:self.statusLabel.topAnchor constant:-8],

        [contentHolder.topAnchor constraintEqualToAnchor:scroll.topAnchor],
        [contentHolder.leadingAnchor constraintEqualToAnchor:scroll.leadingAnchor],
        [contentHolder.trailingAnchor constraintEqualToAnchor:scroll.trailingAnchor],
        [contentHolder.bottomAnchor constraintEqualToAnchor:scroll.bottomAnchor],
        [contentHolder.widthAnchor constraintEqualToAnchor:scroll.widthAnchor],

        [self.voiceTabContainer.topAnchor constraintEqualToAnchor:contentHolder.topAnchor],
        [self.voiceTabContainer.leadingAnchor constraintEqualToAnchor:contentHolder.leadingAnchor],
        [self.voiceTabContainer.trailingAnchor constraintEqualToAnchor:contentHolder.trailingAnchor],
        [self.voiceTabContainer.bottomAnchor constraintEqualToAnchor:contentHolder.bottomAnchor],

        [voiceSec.topAnchor constraintEqualToAnchor:self.voiceTabContainer.topAnchor],
        [voiceSec.leadingAnchor constraintEqualToAnchor:self.voiceTabContainer.leadingAnchor],
        [voiceSec.trailingAnchor constraintEqualToAnchor:self.voiceTabContainer.trailingAnchor],
        [voiceSec.bottomAnchor constraintLessThanOrEqualToAnchor:self.voiceTabContainer.bottomAnchor],

        [self.roleTabContainer.topAnchor constraintEqualToAnchor:contentHolder.topAnchor],
        [self.roleTabContainer.leadingAnchor constraintEqualToAnchor:contentHolder.leadingAnchor],
        [self.roleTabContainer.trailingAnchor constraintEqualToAnchor:contentHolder.trailingAnchor],
        [self.roleTabContainer.bottomAnchor constraintEqualToAnchor:contentHolder.bottomAnchor],

        [roleSec.topAnchor constraintEqualToAnchor:self.roleTabContainer.topAnchor],
        [roleSec.leadingAnchor constraintEqualToAnchor:self.roleTabContainer.leadingAnchor],
        [roleSec.trailingAnchor constraintEqualToAnchor:self.roleTabContainer.trailingAnchor],
        [roleSec.bottomAnchor constraintLessThanOrEqualToAnchor:self.roleTabContainer.bottomAnchor],

        [self.statusLabel.leadingAnchor constraintEqualToAnchor:self.menuCard.leadingAnchor constant:12],
        [self.statusLabel.trailingAnchor constraintEqualToAnchor:self.menuCard.trailingAnchor constant:-12],
        [self.statusLabel.bottomAnchor constraintEqualToAnchor:btnRow.topAnchor constant:-8],

        [btnRow.leadingAnchor constraintEqualToAnchor:self.menuCard.leadingAnchor constant:12],
        [btnRow.trailingAnchor constraintEqualToAnchor:self.menuCard.trailingAnchor constant:-12],
        [btnRow.bottomAnchor constraintEqualToAnchor:self.menuCard.bottomAnchor constant:-12],
        [btnRow.heightAnchor constraintEqualToConstant:36]
    ]];
}

- (void)syncFromManager {
    VoiceModManager *m = [VoiceModManager shared];
    self.rowWolfVoice.checked      = m.hearWolfAndThirdParty;
    self.rowDeadVoice.checked      = m.hearDeadAtNight;
    self.rowThirdSeeAll.checked    = m.thirdPartySeeAllRoles;
    self.rowVillagerSee3rd.checked = m.villagerSeeThirdParty;
    [self updateStatusLabel];
}

- (void)updateStatusLabel {
    self.statusLabel.text = [[VoiceModManager shared] statusSummary];
}

- (void)tabChanged {
    BOOL showVoice = (self.tabControl.selectedSegmentIndex == 0);
    self.voiceTabContainer.hidden = !showVoice;
    self.roleTabContainer.hidden  = showVoice;
}

- (void)onApplyTapped {
    BOOL ok = [[VoiceModManager shared] applyAllBeforeMatch];
    self.statusLabel.text = ok
        ? @"Đã áp dụng thành công — Vào trận ngay"
        : @"Lỗi ghi bộ nhớ (kiểm tra quyền JB/JIT hoặc UnityFramework)";
}

- (void)onResetTapped {
    [[VoiceModManager shared] resetAll];
    [self syncFromManager];
    self.statusLabel.text = @"Đã khôi phục toàn bộ trạng thái gốc";
}

- (void)minimizeMenu {
    self.miniPill.center = CGPointMake(
        MAX(60, MIN(self.overlayRoot.bounds.size.width - 60, self.menuCard.frame.origin.x + 52)),
        MAX(60, MIN(self.overlayRoot.bounds.size.height - 60, self.menuCard.frame.origin.y + 22))
    );
    [UIView animateWithDuration:0.18 animations:^{
        self.menuCard.alpha = 0.0;
    } completion:^(BOOL finished) {
        self.menuCard.hidden = YES;
        self.miniPill.alpha = 0.0;
        self.miniPill.hidden = NO;
        [UIView animateWithDuration:0.16 animations:^{
            self.miniPill.alpha = 1.0;
        }];
    }];
}

- (void)expandMenu {
    [UIView animateWithDuration:0.15 animations:^{
        self.miniPill.alpha = 0.0;
    } completion:^(BOOL finished) {
        self.miniPill.hidden = YES;
        self.menuCard.alpha = 0.0;
        self.menuCard.hidden = NO;
        [UIView animateWithDuration:0.18 animations:^{
            self.menuCard.alpha = 1.0;
        }];
    }];
}

- (void)dragView:(UIPanGestureRecognizer *)pan {
    UIView *target = (pan.view == self.miniPill) ? self.miniPill : self.menuCard;
    CGPoint delta = [pan translationInView:self.overlayRoot];
    CGPoint next = CGPointMake(target.center.x + delta.x, target.center.y + delta.y);

    // Giữ không bị kéo trôi ra ngoài màn hình
    CGRect bounds = self.overlayRoot.bounds;
    CGFloat halfW = target.bounds.size.width * 0.5;
    CGFloat halfH = target.bounds.size.height * 0.5;
    next.x = MAX(halfW + 6, MIN(bounds.size.width - halfW - 6, next.x));
    next.y = MAX(halfH + 40, MIN(bounds.size.height - halfH - 20, next.y));

    target.center = next;
    [pan setTranslation:CGPointZero inView:self.overlayRoot];
}

@end

@implementation ModMenuUI

+ (void)install {
    dispatch_async(dispatch_get_main_queue(), ^{
        [[ModMenuController shared] setupIfNeeded];
    });
}

@end
