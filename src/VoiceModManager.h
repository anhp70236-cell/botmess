#import <Foundation/Foundation.h>

@interface VoiceModManager : NSObject

@property (nonatomic, assign) BOOL hearWolfAndThirdParty;  // Nghe voice Sói & Phe thứ 3 khi là Dân
@property (nonatomic, assign) BOOL hearDeadAtNight;        // Nghe voice người chết khi về đêm
@property (nonatomic, assign) BOOL thirdPartySeeAllRoles;  // Phe thứ 3 xem tất cả chức năng
@property (nonatomic, assign) BOOL villagerSeeThirdParty;  // Dân xem tất cả chức năng phe thứ 3
@property (nonatomic, assign, readonly) BOOL isApplied;

+ (instancetype)shared;
- (void)loadAndApplySavedSettings;
- (BOOL)applyAllBeforeMatch;
- (void)resetAll;
- (NSString *)statusSummary;

@end
