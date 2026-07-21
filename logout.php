<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (app_is_logged_in()) {
    app_log_action((int) $_SESSION['user_id'], 'logout', 'User logged out');
}

session_unset();
session_destroy();
app_redirect('/login.php');
