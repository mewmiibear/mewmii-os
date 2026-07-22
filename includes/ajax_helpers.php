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
