<?php
/**
 * VenueVue Outbox Enqueue Helper
 * ----------------------------------------------------------------------------
 * Used by VenueVue backend when finalizing a sale, waste record, or stock entry.
 * Simply inserts the JSON event into `sync_outbox`.
 */

class VenueVueOutbox {
    /**
     * Enqueue an event into the local outbox.
     *
     * @param PDO $pdo Active PDO database connection
     * @param string $entityType 'SALE' | 'STOCK_MOVEMENT' | 'BATCH_RECEIVE' | 'WASTE' | 'Z_READING'
     * @param string $transactionKey UUID / Idempotency Key
     * @param array $payload Associative array containing the event details
     * @return bool True if enqueued successfully
     */
    public static function enqueue(PDO $pdo, string $entityType, string $transactionKey, array $payload): bool {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO sync_outbox (entity_type, transaction_key, payload, status, created_at)
                VALUES (:entity_type, :tx_key, :payload, 'PENDING', NOW())
            ");
            
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $stmt->execute([
                ':entity_type' => $entityType,
                ':tx_key'      => $transactionKey,
                ':payload'     => $json,
            ]);
        } catch (PDOException $e) {
            // Log local error without crashing the cashier's checkout flow
            error_log("[VenueVue Outbox Error] Failed to enqueue: " . $e->getMessage());
            return false;
        }
    }
}
