<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ApiException.php';

final class ProductImage
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forProduct(string $productId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, product_id, image_url, alt_text, sort_order, is_primary, created_at, updated_at
             FROM product_images
             WHERE product_id = :product_id
             ORDER BY sort_order ASC, created_at ASC'
        );
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, product_id, image_url, alt_text, sort_order, is_primary
             FROM product_images WHERE id = :id LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function countForProduct(string $productId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = :product_id');
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function nextTemporaryOrder(string $productId): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = :product_id'
        );
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function shiftSortOrders(string $productId, int $offset = 10000): void
    {
        $statement = $this->db->prepare(
            'UPDATE product_images SET sort_order = sort_order + :offset WHERE product_id = :product_id'
        );
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $statement->execute();
    }

    public function insert(
        string $productId,
        string $imageUrl,
        string $altText,
        int $sortOrder,
        bool $isPrimary
    ): string {
        $statement = $this->db->prepare(
            'INSERT INTO product_images (product_id, image_url, alt_text, sort_order, is_primary)
             VALUES (:product_id, :image_url, :alt_text, :sort_order, :is_primary)
             RETURNING id'
        );
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $statement->bindValue(':image_url', $imageUrl, PDO::PARAM_STR);
        $statement->bindValue(':alt_text', $altText, PDO::PARAM_STR);
        $statement->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
        $statement->bindValue(':is_primary', $isPrimary, PDO::PARAM_BOOL);
        $statement->execute();

        return (string) $statement->fetchColumn();
    }

    public function update(
        string $id,
        string $imageUrl,
        string $altText,
        int $sortOrder,
        bool $isPrimary
    ): void {
        $statement = $this->db->prepare(
            'UPDATE product_images
             SET image_url = :image_url, alt_text = :alt_text, sort_order = :sort_order, is_primary = :is_primary
             WHERE id = :id'
        );
        $statement->bindValue(':image_url', $imageUrl, PDO::PARAM_STR);
        $statement->bindValue(':alt_text', $altText, PDO::PARAM_STR);
        $statement->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
        $statement->bindValue(':is_primary', $isPrimary, PDO::PARAM_BOOL);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();
    }

    public function updateMeta(string $id, string $altText, int $sortOrder): void
    {
        $statement = $this->db->prepare(
            'UPDATE product_images SET alt_text = :alt_text, sort_order = :sort_order WHERE id = :id'
        );
        $statement->bindValue(':alt_text', $altText, PDO::PARAM_STR);
        $statement->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();
    }

    public function setPrimary(string $productId, ?string $imageId): void
    {
        $clear = $this->db->prepare(
            'UPDATE product_images SET is_primary = false WHERE product_id = :product_id AND is_primary = true'
        );
        $clear->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $clear->execute();

        if ($imageId === null) {
            return;
        }

        $set = $this->db->prepare(
            'UPDATE product_images SET is_primary = true WHERE id = :id AND product_id = :product_id'
        );
        $set->bindValue(':id', $imageId, PDO::PARAM_STR);
        $set->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $set->execute();
    }

    public function delete(string $id): void
    {
        $statement = $this->db->prepare('DELETE FROM product_images WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();
    }

    public function setProductImageUrl(string $productId, string $imageUrl): void
    {
        $statement = $this->db->prepare('UPDATE products SET image_url = :image_url WHERE id = :id');
        $statement->bindValue(':image_url', $imageUrl, PDO::PARAM_STR);
        $statement->bindValue(':id', $productId, PDO::PARAM_STR);
        $statement->execute();
    }

    public function primaryImage(string $productId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, image_url, alt_text, sort_order, is_primary
             FROM product_images
             WHERE product_id = :product_id
             ORDER BY is_primary DESC, sort_order ASC, created_at ASC
             LIMIT 1'
        );
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<string>
     */
    public function objectPathsForProduct(string $productId): array
    {
        $statement = $this->db->prepare('SELECT image_url FROM product_images WHERE product_id = :product_id');
        $statement->bindValue(':product_id', $productId, PDO::PARAM_STR);
        $statement->execute();

        return array_values(array_map(
            static fn (array $row): string => (string) $row['image_url'],
            $statement->fetchAll() ?: []
        ));
    }
}
