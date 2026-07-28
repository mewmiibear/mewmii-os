<?php

/**
 * Customer Order Resolution System - store credit wallet. Genuinely new (no existing wallet/
 * credit code anywhere in this app - confirmed by audit before writing this). One wallet row
 * per customer, created lazily on first use; every balance change is written through
 * wallet_apply_transaction() so a customer_wallet_transactions row and the balance update are
 * always the same atomic write - there is no code path that changes balance without leaving a
 * transaction record, satisfying "every credit change must create a transaction record."
 */

const WALLET_TRANSACTION_TYPES = ['refund', 'usage', 'adjustment'];

function wallet_get_or_create(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT * FROM customer_wallets WHERE customer_id = ?');
    $stmt->execute([$customerId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($wallet !== false) {
        return $wallet;
    }

    $pdo->prepare('INSERT INTO customer_wallets (customer_id, balance, currency) VALUES (?, 0.00, ?)')
        ->execute([$customerId, 'MYR']);

    $stmt->execute([$customerId]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function wallet_balance(PDO $pdo, int $customerId): float
{
    return (float) wallet_get_or_create($pdo, $customerId)['balance'];
}

/**
 * The single write path for every wallet balance change - $amount is signed (positive credits,
 * negative debits). Locks the wallet row (SELECT ... FOR UPDATE) so two concurrent changes
 * (e.g. a refund credit and a checkout debit happening at the same moment) can never race and
 * silently drop one of them. Throws if a debit would take the balance negative - callers
 * needing to check affordability first should call wallet_balance() before offering "use
 * credit" as an option, not rely on this throwing as the primary UX.
 */
function wallet_apply_transaction(PDO $pdo, int $customerId, string $type, float $amount, ?string $referenceType, ?int $referenceId, ?string $note): int
{
    if (!in_array($type, WALLET_TRANSACTION_TYPES, true)) {
        throw new RuntimeException('Invalid wallet transaction type.');
    }

    wallet_get_or_create($pdo, $customerId);

    $wasInTransaction = $pdo->inTransaction();
    if (!$wasInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $lockStmt = $pdo->prepare('SELECT balance FROM customer_wallets WHERE customer_id = ? FOR UPDATE');
        $lockStmt->execute([$customerId]);
        $currentBalance = (float) $lockStmt->fetchColumn();

        $newBalance = round($currentBalance + $amount, 2);
        if ($newBalance < 0) {
            throw new RuntimeException('Insufficient wallet balance.');
        }

        $pdo->prepare('UPDATE customer_wallets SET balance = ? WHERE customer_id = ?')
            ->execute([$newBalance, $customerId]);

        $txStmt = $pdo->prepare('
            INSERT INTO customer_wallet_transactions (customer_id, type, amount, reference_type, reference_id, note)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $txStmt->execute([$customerId, $type, round($amount, 2), $referenceType, $referenceId, $note]);
        $txId = (int) $pdo->lastInsertId();

        if (!$wasInTransaction) {
            $pdo->commit();
        }

        return $txId;
    } catch (Throwable $e) {
        if (!$wasInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/** Credits the wallet (e.g. a resolution's "store credit" choice) - amount is always positive here. */
function wallet_credit(PDO $pdo, int $customerId, float $amount, string $referenceType, ?int $referenceId, ?string $note): int
{
    return wallet_apply_transaction($pdo, $customerId, 'refund', abs($amount), $referenceType, $referenceId, $note);
}

/** Debits the wallet (e.g. spent at checkout) - amount is always positive here, stored as a negative transaction. */
function wallet_debit(PDO $pdo, int $customerId, float $amount, string $referenceType, ?int $referenceId, ?string $note): int
{
    return wallet_apply_transaction($pdo, $customerId, 'usage', -abs($amount), $referenceType, $referenceId, $note);
}

function wallet_list_transactions(PDO $pdo, int $customerId, int $limit = 50): array
{
    $stmt = $pdo->prepare('
        SELECT * FROM customer_wallet_transactions
        WHERE customer_id = ?
        ORDER BY id DESC
        LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$customerId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
