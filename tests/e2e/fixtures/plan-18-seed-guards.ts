import { test as base } from '@playwright/test';

export const test = base.extend<{ seedGuard: void }>({
  seedGuard: [async ({}, use, testInfo) => {
    const declaredSeed = testInfo.annotations.find(a => a.type === 'seed')?.description;
    const actualSeed = process.env.PLAYWRIGHT_SEED_PROFILE || 'default';
    
    // Some specs run for all ('all'), some for specific ones
    if (declaredSeed && declaredSeed !== 'all') {
      if (declaredSeed !== actualSeed) {
        throw new Error(`Seed Guard Failed: Spec expects seed '${declaredSeed}', but running with '${actualSeed}'`);
      }
    }

    await use();
  }, { auto: true }],
});
