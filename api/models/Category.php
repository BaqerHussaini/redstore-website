<?php
declare(strict_types=1);

final class Category
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeWithProductCounts(): array
    {
        $sql = <<<'SQL'
            SELECT
                c.id,
                c.name,
                c.slug,
                COUNT(p.id) AS product_count
            FROM categories c
            LEFT JOIN products p
                ON p.category_id = c.id
                AND p.is_active = true
            GROUP BY c.id, c.name, c.slug
            ORDER BY c.name ASC
            SQL;

        $statement = $this->db->query($sql);

        $categories = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            $categories[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'product_count' => (int) $row['product_count'],
            ];
        }

        return $categories;
    }
}
