import type { Session } from './types';

/** A failed API request, with the server's message and its validation errors (first message per field). */
export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string> = {},
    ) {
        super(message);
    }
}

let current: Session | null = null;
let onSignedOut: () => void = () => {};

/** Point the API at a server and token; `signedOut` runs when the server stops accepting the token. */
export function configureApi(
    session: Session | null,
    signedOut: () => void,
): void {
    current = session;
    onSignedOut = signedOut;
}

export function session(): Session {
    if (!current) {
        throw new ApiError('Not signed in', 401);
    }

    return current;
}

/** The server's address for a path, e.g. `/settings/ai`. */
export function serverUrl(path: string): string {
    return new URL(path, session().server).toString();
}

/** Whether a URL is on the signed-in server, so it's safe to send the token along. */
function isOwnServer(url: string): boolean {
    try {
        return new URL(url).origin === new URL(session().server).origin;
    } catch {
        return false;
    }
}

/**
 * A request to the server's API (`/api/v1/...`): JSON in and out, or multipart for FormData.
 * Throws an ApiError when the response isn't OK; a 401 also signs the app out.
 */
export async function api<T = void>(
    path: string,
    body?: unknown,
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' = body === undefined
        ? 'GET'
        : 'POST',
    headers: Record<string, string> = {},
): Promise<T> {
    const { server, token } = session();
    const isForm = body instanceof FormData;
    const init: RequestInit = {
        method,
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
            ...(body === undefined || isForm
                ? {}
                : { 'Content-Type': 'application/json' }),
            ...headers,
        },
    };

    if (body !== undefined) {
        init.body = isForm ? body : JSON.stringify(body);
    }

    let response: Response;

    try {
        response = await fetch(new URL(`/api/v1/${path}`, server), init);
    } catch {
        throw new ApiError(
            `Can't reach ${new URL(server).host}. Check your connection.`,
            0,
        );
    }

    if (response.status === 401) {
        onSignedOut();
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const json = await response.json().catch(() => ({}));

    if (!response.ok) {
        const errors = Object.fromEntries(
            Object.entries((json.errors ?? {}) as Record<string, string[]>).map(
                ([field, messages]) => [field, messages[0]],
            ),
        );

        throw new ApiError(
            json.message ?? `Request failed (${response.status})`,
            response.status,
            errors,
        );
    }

    return json as T;
}

/**
 * A local address for a file the server only gives out with the token (project icons, attachments), for `<img>`.
 * Revoke it with URL.revokeObjectURL when done.
 */
export async function blobUrl(url: string): Promise<string> {
    if (!isOwnServer(url)) {
        return url;
    }

    const response = await fetch(url, {
        headers: { Authorization: `Bearer ${session().token}` },
    });

    if (!response.ok) {
        throw new ApiError(
            `Request failed (${response.status})`,
            response.status,
        );
    }

    return URL.createObjectURL(await response.blob());
}
