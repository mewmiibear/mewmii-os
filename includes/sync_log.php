<?php

function sync_log_write(PDO $pdo, string $syncType, string $status, ?int $referenceId = null, ?string $errorMessage = null): void
{
    $stmt = $pdo->prepare('
        INSERT INTO sync_logs (sync_type, reference_id, status, error_message)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$syncType, $referenceId, $status, $errorMessage]);
}

function sync_log_success(PDO $pdo, string $syncType, ?int $referenceId = null): void
{
    sync_log_write($pdo, $syncType, 'success', $referenceId, null);
}

function sync_log_failure(PDO $pdo, string $syncType, string $errorMessage, ?int $referenceId = null): void
{
    sync_log_write($pdo, $syncType, 'failed', $referenceId, $errorMessage);
}
