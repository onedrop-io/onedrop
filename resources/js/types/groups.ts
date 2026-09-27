export type GroupRole = 'owner' | 'member';

export type GroupSummary = {
    id: number;
    name: string;
    description: string | null;
    members_count: number;
    role: GroupRole | null;
};

export type GroupMember = {
    id: number;
    name: string;
    email: string;
    role: GroupRole;
};
