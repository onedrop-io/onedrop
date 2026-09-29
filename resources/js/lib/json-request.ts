/**
 * A JSON request to the app builder with the session's CSRF token.
 * GET without a body; otherwise `method` (POST by default) with a JSON body, or multipart for FormData.
 * Throws the server's message when the response isn't OK (with the response's `status` and body as `data`).
 */
export async function jsonRequest<T>(
    url: string,
    body?: unknown,
    method: "POST" | "PUT" | "PATCH" | "DELETE" = "POST",
): Promise<T> {
    const token = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1];
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method: body === undefined ? "GET" : method,
        headers: {
            Accept: "application/json",
            ...(body === undefined
                ? {}
                : {
                      ...(isForm ? {} : { "Content-Type": "application/json" }),
                      "X-XSRF-TOKEN": decodeURIComponent(token ?? ""),
                  }),
        },
        credentials: "same-origin",
        body:
            body === undefined
                ? undefined
                : isForm
                  ? body
                  : JSON.stringify(body),
    });
    const json = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw Object.assign(
            new Error(json.message ?? `Request failed (${response.status})`),
            { status: response.status, data: json },
        );
    }

    return json as T;
}
