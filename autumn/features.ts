import { feature } from 'atmn';

/** What AI runs cost at provider rates, in cents (OneDrop tracks each run's estimated cost here). */
export const aiUsage = feature({
    internalId: 'fe_3K8mA0ixzEL21QzvoAKOInjj837',
    featureId: 'ai_usage',
    name: 'AI usage',
    type: 'metered',
    consumable: true,
});

/** AI credits, 1 credit = 1 cent of AI usage at provider rates, no markup. */
export const aiCredits = feature({
    internalId: 'fe_3K8m9y16Lb7wCv8NCdu9ckNkCZ0',
    featureId: 'ai_credits',
    name: 'AI credits',
    type: 'credit_system',
    creditSchema: [{ meteredFeatureId: aiUsage.featureId, creditCost: 1 }],
});

export const features = [aiUsage, aiCredits];
