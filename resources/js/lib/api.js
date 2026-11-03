/**
 * Small helper for the single dispatch API: POST /api/{action}
 *
 * The route sits behind the web middleware group, so requests need the
 * session cookie (same-origin fetch sends it) and the XSRF token header
 * read from the XSRF-TOKEN cookie.
 *
 * On success the parsed body is returned (payloads carry `ok: true`).
 * On an error payload an Error is thrown with `.code` set to the server's
 * preserved error code (e.g. `invite_incorrect`, `guests_locked`) and
 * `.status` set to the HTTP status.
 */

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function post(action, data = {}) {
    let response;
    try {
        response = await fetch(`/api/${action}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(data),
        });
    } catch (networkError) {
        const error = new Error('Could not reach the server. Check your connection and try again.');
        error.code = 'network_error';
        error.cause = networkError;
        throw error;
    }

    let body = null;
    try {
        body = await response.json();
    } catch {
        body = null;
    }

    if (!response.ok || !body || body.ok === false) {
        const error = new Error(
            (body && (body.message || body.error_message)) ||
                'Something went wrong. Please try again.',
        );
        error.code = (body && (body.error || body.code)) || 'request_failed';
        error.status = response.status;
        error.body = body;
        throw error;
    }

    return body;
}

export default post;
