export type AgentProvider = 'claude' | 'codex' | 'openrouter' | 'gemini';

/** The coding agent that works in the sandbox. */
export type AgentHarness = 'opencode' | 'claude_code';

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

/** An agent the user can run, and the providers it can use. */
export type CatalogHarness = {
    id: AgentHarness;
    label: string;
    providers: AgentProvider[];
};

export type CatalogProvider = {
    id: AgentProvider;
    label: string;
    default_model: string;
    models: CatalogModel[];
};

/** What runs: an agent, a provider's model and optional reasoning level. */
export type AgentSelection = {
    harness: AgentHarness;
    provider: AgentProvider;
    model: string;
    variant: string | null;
    name: string;
    efforts: string[];
};
