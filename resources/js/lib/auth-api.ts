import ProjectAuthController from '@/actions/App/Http/Controllers/ProjectAuthController';
import { jsonRequest } from '@/lib/json-request';
import type {
    AppAuthMethod,
    AppAuthStatus,
    AppOneDrop,
    AppUsersPage,
} from '@/types';

/**
 * Calls to the app's own sign-in, through the project's sandbox.
 */
export const authApi = {
    status: (projectId: number) =>
        jsonRequest<AppAuthStatus>(ProjectAuthController.show.url(projectId)),

    users: (projectId: number, search: string, page: number) =>
        jsonRequest<AppUsersPage>(
            ProjectAuthController.users.url(projectId, {
                query: { search, page },
            }),
        ),

    createUser: (
        projectId: number,
        user: { name: string; email: string; password: string },
    ) =>
        jsonRequest<{ created: boolean }>(
            ProjectAuthController.storeUser.url(projectId),
            user,
        ),

    updateUser: (
        projectId: number,
        id: string,
        values: {
            name?: string;
            email?: string;
            password?: string;
            sign_out?: boolean;
            disabled?: boolean;
            password_change_required?: boolean;
            role?: string;
        },
    ) =>
        jsonRequest<{ updated: boolean }>(
            ProjectAuthController.updateUser.url({
                project: projectId,
                user: id,
            }),
            values,
            'PATCH',
        ),

    deleteUser: (projectId: number, id: string) =>
        jsonRequest<{ deleted: boolean }>(
            ProjectAuthController.destroyUser.url({
                project: projectId,
                user: id,
            }),
            {},
            'DELETE',
        ),

    signOutUser: (projectId: number, id: string) =>
        jsonRequest<{ signed_out: boolean }>(
            ProjectAuthController.signOutUser.url({
                project: projectId,
                user: id,
            }),
            {},
        ),

    exportUrl: (projectId: number) =>
        ProjectAuthController.export.url(projectId),

    /** A one-time link that opens the app signed in as this user. */
    signInAs: (projectId: number, id: string) =>
        jsonRequest<{ url: string }>(
            ProjectAuthController.signInAs.url({
                project: projectId,
                user: id,
            }),
            {},
        ).then((body) => body.url),

    setOneDropAccess: (projectId: number, groupIds: number[] | null) =>
        jsonRequest<AppOneDrop>(
            ProjectAuthController.oneDropAccess.url(projectId),
            { group_ids: groupIds },
            'PUT',
        ),

    /** Ask the agent to add the account controls the app doesn't have yet. */
    requestHelper: (projectId: number) =>
        jsonRequest<{ queued: boolean }>(
            ProjectAuthController.helper.url(projectId),
            {},
        ),

    saveKeys: (
        projectId: number,
        provider: string,
        keys: { client_id?: string; client_secret?: string },
    ) =>
        jsonRequest<AppAuthStatus>(
            ProjectAuthController.keys.url(projectId),
            { provider, ...keys },
            'PUT',
        ),

    /** Ask the agent to add sign-in with these methods, or change the app to exactly these. */
    setup: (projectId: number, methods: AppAuthMethod[]) =>
        jsonRequest<{ queued: boolean }>(
            ProjectAuthController.setup.url(projectId),
            { methods },
        ),
};
