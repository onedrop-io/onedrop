export type AgentProvider = 'claude' | 'codex' | 'openrouter';

export type AgentConnection = {
    id: number;
    provider: AgentProvider;
    credential_type: 'api_key' | 'oauth_token' | 'chatgpt';
    hint: string;
    is_default: boolean;
    verified: boolean;
};

export type CatalogModel = {
    id: string;
    name: string;
    featured: boolean;
    efforts: string[];
    context: number | null;
    cost: { input: number; output: number } | null;
    released: string | null;
};

export type CatalogProvider = {
    id: AgentProvider;
    label: string;
    default_model: string;
    models: CatalogModel[];
};

/** What the agent runs: a provider's model and optional reasoning level. */
export type AgentSelection = {
    provider: AgentProvider;
    model: string;
    variant: string | null;
    name: string;
    efforts: string[];
};
