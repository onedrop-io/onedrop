import { plan } from 'atmn';
import { aiCredits } from './features';

/** Every new organization starts here: $5 of AI credits once, then $1 a month. */
export const free = plan({
    internalId: 'prod_3K8m9vAnpqKISJ4A5XerBUHmK9f',
    planId: 'free',
    versionSlug: 'v1',
    active: true,
    name: 'Free',
    group: 'main',
    autoEnable: true,
    items: [
        {
            featureId: aiCredits.featureId,
            included: 100,
            reset: { interval: 'month' },
        },
        { featureId: aiCredits.featureId, included: 500 },
    ],
});

export const plans = [free];
