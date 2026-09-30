export type AppAuthMethod =
    | 'password'
    | 'google'
    | 'github'
    | 'microsoft'
    | 'onedrop';

export type AppAuthProvider = {
    id: Exclude<AppAuthMethod, 'password' | 'onedrop'>;
    /** The app offers this method. */
    enabled: boolean;
    client_id_set: boolean;
    client_secret_set: boolean;
    callback_urls: { label: string; url: string }[];
};

export type AppAuthStatus =
    | { configured: false }
    | {
          configured: true;
          library: string | null;
          methods: AppAuthMethod[];
          env_file: string;
          users_table: string;
          providers: AppAuthProvider[];
          login_url: string | null;
          published_login_url: string | null;
          /** The app has the .onedrop/users helper, so users can be added and passwords set here. */
          can_create: boolean;
          /** The app's roles, most powerful first. */
          roles: string[];
          onedrop: AppOneDrop;
      };

/** "Sign in with OneDrop": the app builder is the provider for this app. */
export type AppOneDrop = {
    /** The app builder accepts sign-ins for this app. */
    enabled: boolean;
    /** Null: everyone with a OneDrop account; otherwise only these groups' members. */
    group_ids: number[] | null;
    groups: { id: number; name: string }[];
};

/** Values straight from the app's database: types depend on the app. */
export type AppUser = {
    id: string | number | null;
    name: string | null;
    email: string | null;
    role: string | null;
    created_at: string | number | null;
    last_login_at: string | number | null;
    /** Set when the account is turned off. */
    disabled_at: string | number | null;
    /** They must choose a new password; null when the app has no such column. */
    password_change_required: boolean | null;
};

/** Account controls the app supports (older setups may lack some until the agent adds them). */
export type AppUserCapabilities = {
    /** The .onedrop/users helper: add users, set passwords, sign out everywhere. */
    helper: boolean;
    disable: boolean;
    require_password_change: boolean;
    roles: boolean;
    /** The helper can make one-time sign-in links. */
    sign_in_as: boolean;
};

export type AppUsersPage = {
    users: AppUser[];
    total: number;
    page: number;
    per_page: number;
    capabilities: AppUserCapabilities;
    roles: string[];
};
