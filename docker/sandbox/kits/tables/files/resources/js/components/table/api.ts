/**
 * Requests to the table's endpoints (routes/tables.php), with Laravel's CSRF cookie and the Echo socket id,
 * so the server's broadcasts skip the browser that made the change.
 */

export class TableRequestError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }

    /** The first message for each failing key, e.g. "12.stage" => "“Done” isn't an option for Stage." */
    firstErrors(): string[] {
        const messages = Object.values(this.errors).map((list) => list[0]);

        return messages.length > 0 ? messages : [this.message];
    }
}

let socketId: () => string | null = () => null;

/** Set by the live updates hook once Echo is connected. */
export function setSocketId(resolve: () => string | null): void {
    socketId = resolve;
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

export async function request<T>(
    method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();
    const socket = socketId();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (socket) {
        headers['X-Socket-ID'] = socket;
    }

    let payload: BodyInit | undefined;

    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    let response: Response;

    try {
        response = await fetch(url, {
            method,
            headers,
            ...(payload === undefined ? {} : { body: payload }),
            credentials: 'same-origin',
        });
    } catch {
        throw new TableRequestError(
            "Couldn't reach the server. Check your connection and try again.",
            0,
        );
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const json = await response.json().catch(() => null);

    if (!response.ok) {
        const message =
            response.status === 403
                ? "You don't have permission to do that."
                : response.status === 419
                  ? 'Your session expired. Reload the page and try again.'
                  : (json?.message ?? 'Something went wrong. Try again.');

        throw new TableRequestError(
            message,
            response.status,
            json?.errors ?? {},
        );
    }

    return json as T;
}
