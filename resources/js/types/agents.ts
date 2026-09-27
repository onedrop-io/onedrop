export type AgentProvider = 'claude' | 'codex' | 'openrouter';

export type AgentConnection = {
    id: number;
    provider: AgentProvider;
    credential_type: 'api_key' | 'oauth_token';
    hint: string;
    is_default: boolean;
    verified: boolean;
};
