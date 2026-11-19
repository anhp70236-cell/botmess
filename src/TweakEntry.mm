#import <UIKit/UIKit.h>
#import "ModMenuUI.h"
#import "VoiceModManager.h"

__attribute__((constructor))
static void VoiceModDylibInit(void) {
    @autoreleasepool {
        [[NSNotificationCenter defaultCenter]
            addObserverForName:UIApplicationDidBecomeActiveNotification
                        object:nil
                         queue:[NSOperationQueue mainQueue]
                    usingBlock:^(NSNotification * _Nonnull note) {
            static dispatch_once_t onceToken;
            dispatch_once(&onceToken, ^{
                dispatch_after(dispatch_time(DISPATCH_TIME_NOW, (int64_t)(2.5 * NSEC_PER_SEC)),
                               dispatch_get_main_queue(), ^{
                    [[VoiceModManager shared] loadAndApplySavedSettings];
                    [ModMenuUI install];
                });
            });
        }];
    }
}
