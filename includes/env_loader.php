<?php

/**
 * Minimal, dependency-free .env loader (Security Hardening Phase 4C). This codebase has no
 * Composer/vendor directory anywhere - pulling in a library (e.g. vlucas/phpdotenv) would be
 * its first dependency ever, for what's really a ~30-line problem. Reads KEY=value lines from
 * .env at the project root into getenv()/$_ENV, then returns - no framework, no caching, no
 * side effects beyond that.
 *
 * Silently no-ops if .env doesn't exist - production may not have one deployed yet during the
 * migration window, and this must never break the app (or the CLI cron script) by requiring a
 * file that isn't there yet. See config.php for how these values are actually consumed: every
 * secret keeps its existing hardcoded value as a fallback, so nothing breaks whether or not
 * .env is present.
 */
function env_load(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        if ($key === '') {
            continue;
        }

        $value = trim(substr($line, $eqPos + 1));

        // Strip one layer of matching surrounding quotes, if present - lets a value contain
        // '#' or leading/trailing spaces without being misread. Not required for a plain
        // KEY=value line.
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && $value[-1] === '"')
            || ($value[0] === "'" && $value[-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        // Never override a real server-level environment variable that's already set - .env
        // is a convenience/fallback layer, not the final authority once the server itself
        // defines the same key.
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

env_load(dirname(__DIR__) . '/.env');
