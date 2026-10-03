export type BuildMode = 'simple' | 'advanced';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    is_admin: boolean;
    /** Simple or Advanced (PRJ-013); null until they choose, which shows everything. */
    build_mode: BuildMode | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};

export type SocialProviderOption = {
    id: string;
    label: string;
};

export type SocialAccountRow = {
    provider: string;
    label: string;
    account: { id: number; email: string | null } | null;
};
