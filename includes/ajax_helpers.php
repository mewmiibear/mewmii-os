<?php

/**
 * Shared boilerplate for the small JSON AJAX endpoints under modules/*\/ajax/. Every
 * endpoint is a thin wrapper around an existing backend function - these helpers just
 * make sure permission/CSRF failures come back as JSON instead of the HTML that
 * app_require_permission()/app_require_csrf() normally emit, which a fetch() caller
 * can't parse.
 */

function ajax_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function ajax_require_login(): void
{
    if (!app_is_logged_in()) {
        ajax_json(['error' => 'Not logged in.'], 401);
    }
}

function ajax_require_permission(string $permission): void
{
    ajax_require_login();

    if (!app_has_permission($permission)) {
        ajax_json(['error' => 'You do not have permission to do that.'], 403);
    }
}

function ajax_require_csrf(): void
{
    try {
        app_require_csrf();
    } catch (RuntimeException $exception) {
        ajax_json(['error' => $exception->getMessage()], 400);
    }
}

/**
 * Mewmii OS v2 Phase 2 - sibling of ajax_require_permission() for endpoints that return an
 * HTML fragment instead of JSON (the Drawer framework's content endpoints - see
 * docs/PHASE2_READINESS_REVIEW.md §2/§7 for why the Drawer deliberately returns HTML, not
 * JSON). Same app_has_permission() check, same 401/403 status codes; only the response body
 * changes, so it can be injected directly into the Drawer as a readable in-panel state
 * instead of a JSON object the Drawer would have no generic way to render.
 */
function ajax_require_permission_html(string $permission): void
{
    if (!app_is_logged_in()) {
        http_response_code(401);
        echo '<div class="p-3 text-muted">Please log in to view this.</div>';
        exit;
    }

    if (!app_has_permission($permission)) {
        http_response_code(403);
        echo '<div class="p-3 text-muted">You do not have permission to view this.</div>';
        exit;
    }
}
