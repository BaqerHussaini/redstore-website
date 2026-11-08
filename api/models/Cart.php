<?php
declare(strict_types=1);

/**
 * Persistence for authenticated (saved) carts.
 *
 * The browser never supplies a user id; every query is scoped to the session
 * user id passed in by the controller. Prices, stock and product data are not
 * stored here — only ids and quantities, mirroring the guest cart.
 */
final class Cart
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<array{id: string, product_id: string, variant_id: ?string, quantity: int}>
     */
    public function itemsForUser(string $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, product_id, variant_id, quantity
             FROM cart_items
             WHERE user_id = :user_id
             ORDER BY created_at ASC, id ASC'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->execute();

        $items = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            $items[] = [
                'id' => (string) $row['id'],
                'product_id' => (string) $row['product_id'],
                'variant_id' => $row['variant_id'] !== null ? (string) $row['variant_id'] : null,
                'quantity' => (int) $row['quantity'],
            ];
        }

        return $items;
    }

    /**
     * @return array{id: string, product_id: string, variant_id: ?string, quantity: int}|null
     */
    public function findLine(string $userId, string $productId, ?string $variantId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, product_id, variant_id, quantity
             FROM cart_items
             WHERE user_id = :user_id
               AND product_id = :product_id
               AND variant_id IS NOT DISTINCT FROM :variant_id
             LIMIT 1'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $this->bindNullableUuid($statement, ':variant_id', $variantId);
        $statement->execute();

        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'product_id' => (string) $row['product_id'],
            'variant_id' => $row['variant_id'] !== null ? (string) $row['variant_id'] : null,
            'quantity' => (int) $row['quantity'],
        ];
    }

    /**
     * @return array{id: string, product_id: string, variant_id: ?string, quantity: int}|null
     */
    public function findItem(string $userId, string $itemId): ?array
    {
        if (preg_match(self::UUID_PATTERN, $itemId) !== 1) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT id, product_id, variant_id, quantity
             FROM cart_items
             WHERE user_id = :user_id AND id = :id
             LIMIT 1'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->bindValue(':id', $itemId, PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'product_id' => (string) $row['product_id'],
            'variant_id' => $row['variant_id'] !== null ? (string) $row['variant_id'] : null,
            'quantity' => (int) $row['quantity'],
        ];
    }

    public function upsert(string $userId, string $productId, ?string $variantId, int $quantity): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO cart_items (user_id, product_id, variant_id, quantity)
             VALUES (:user_id, :product_id, :variant_id, :quantity)
             ON CONFLICT (user_id, product_id, variant_id)
             DO UPDATE SET quantity = EXCLUDED.quantity, updated_at = now()'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $this->bindNullableUuid($statement, ':variant_id', $variantId);
        $statement->bindValue(':quantity', $quantity, PDO::PARAM_INT);
        $statement->execute();
    }

    public function updateQuantity(string $userId, string $itemId, int $quantity): bool
    {
        $statement = $this->db->prepare(
            'UPDATE cart_items SET quantity = :quantity, updated_at = now()
             WHERE user_id = :user_id AND id = :id'
        );
        $statement->bindValue(':quantity', $quantity, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->bindValue(':id', $itemId, PDO::PARAM_STR);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    public function deleteItem(string $userId, string $itemId): bool
    {
        $statement = $this->db->prepare(
            'DELETE FROM cart_items WHERE user_id = :user_id AND id = :id'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->bindValue(':id', $itemId, PDO::PARAM_STR);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    public function deleteLine(string $userId, string $productId, ?string $variantId): bool
    {
        $statement = $this->db->prepare(
            'DELETE FROM cart_items
             WHERE user_id = :user_id
               AND product_id = :product_id
               AND variant_id IS NOT DISTINCT FROM :variant_id'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $this->bindNullableUuid($statement, ':variant_id', $variantId);
        $statement->execute();

        return $statement->rowCount() > 0;
    }

    public function clear(string $userId): void
    {
        $statement = $this->db->prepare('DELETE FROM cart_items WHERE user_id = :user_id');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->execute();
    }

    public function countLines(string $userId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM cart_items WHERE user_id = :user_id');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->execute();

        return (int) ($statement->fetchColumn() ?: 0);
    }

    private function bindNullableUuid(PDOStatement $statement, string $key, ?string $value): void
    {
        if ($value === null) {
            $statement->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $statement->bindValue($key, $value, PDO::PARAM_STR);
    }
}
