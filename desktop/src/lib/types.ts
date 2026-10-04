/** Where the app is signed in (DESK-001). Kept in the system keychain. */
export type Session = {
    server: string;
    token: string;
};
