<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';

final class AdminCategory
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function paginate(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $where = '1 = 1';
        $params = [];
        if ($search !== '') {
            $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $where = '(c.name ILIKE :search OR c.slug ILIKE :search)';
            $params[':search'] = $term;
        }

        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $count = $this->db->prepare('SELECT COUNT(*) FROM categories c WHERE ' . $where);
        foreach ($params as $key => $value) {
            $count->bindValue($key, $value, PDO::PARAM_STR);
        }
        $count->execute();
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT
                    c.id, c.name, c.slug, c.created_at, c.updated_at,
                    COUNT(p.id) AS product_count,
                    COUNT(p.id) FILTER (WHERE p.is_active = true) AS active_product_count
                FROM categories c
                LEFT JOIN products p ON p.category_id = c.id
                WHERE ' . $where . '
                GROUP BY c.id, c.name, c.slug, c.created_at, c.updated_at
                ORDER BY c.name ASC
                LIMIT :limit OFFSET :offset';

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [$statement->fetchAll() ?: [], $total];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $statement = $this->db->query(
            'SELECT id, name, slug FROM categories ORDER BY name ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT c.id, c.name, c.slug, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
             FROM categories c WHERE c.id = :id LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function slugExists(string $slug, ?string $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM categories WHERE slug = :slug';
        $params = [':slug' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    public function nameExists(string $name, ?string $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM categories WHERE name = :name';
        $params = [':name' => $name];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    public function uniqueSlug(string $base, ?string $excludeId = null): string
    {
        $base = Input::slugify($base, 100);
        $slug = $base;
        $suffix = 2;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = substr($base, 0, 90) . '-' . $suffix;
            $suffix++;
            if ($suffix > 1000) {
                throw new ApiException(409, 'slug_conflict', 'A unique category slug could not be generated.');
            }
        }

        return $slug;
    }

    public function create(string $name, string $slug): string
    {
        if ($this->nameExists($name)) {
            throw new ApiException(409, 'category_name_exists', 'A category with that name already exists.');
        }
        if ($this->slugExists($slug)) {
            throw new ApiException(409, 'category_slug_exists', 'A category with that slug already exists.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO categories (name, slug) VALUES (:name, :slug) RETURNING id'
        );
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':slug', $slug, PDO::PARAM_STR);
        $statement->execute();

        return (string) $statement->fetchColumn();
    }

    public function update(string $id, string $name, string $slug): void
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'category_not_found', 'Category not found.');
        }
        if ($this->nameExists($name, $id)) {
            throw new ApiException(409, 'category_name_exists', 'A category with that name already exists.');
        }
        if ($this->slugExists($slug, $id)) {
            throw new ApiException(409, 'category_slug_exists', 'A category with that slug already exists.');
        }

        $statement = $this->db->prepare(
            'UPDATE categories SET name = :name, slug = :slug WHERE id = :id'
        );
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':slug', $slug, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_STR);
        $statement->execute();
    }

    /**
     * @return array{reassigned: int, unassigned: int}
     */
    public function delete(string $id, ?string $reassignTo, bool $confirmUnassign): array
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'category_not_found', 'Category not found.');
        }

        $countStatement = $this->db->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
        $countStatement->bindValue(':id', $id, PDO::PARAM_STR);
        $countStatement->execute();
        $productCount = (int) $countStatement->fetchColumn();

        $reassigned = 0;
        $unassigned = 0;

        if ($productCount > 0) {
            if ($reassignTo !== null && $reassignTo !== '') {
                if ($reassignTo === $id) {
                    throw new ApiException(422, 'invalid_reassign', 'Choose a different category to move products to.');
                }
                if ($this->find($reassignTo) === null) {
                    throw new ApiException(422, 'invalid_reassign', 'The selected replacement category does not exist.');
                }

                $update = $this->db->prepare(
                    'UPDATE products SET category_id = :reassign_to WHERE category_id = :id'
                );
                $update->bindValue(':reassign_to', $reassignTo, PDO::PARAM_STR);
                $update->bindValue(':id', $id, PDO::PARAM_STR);
                $update->execute();
                $reassigned = $update->rowCount();
            } elseif ($confirmUnassign) {
                $update = $this->db->prepare(
                    'UPDATE products SET category_id = NULL WHERE category_id = :id'
                );
                $update->bindValue(':id', $id, PDO::PARAM_STR);
                $update->execute();
                $unassigned = $update->rowCount();
            } else {
                throw new ApiException(
                    409,
                    'category_in_use',
                    'This category still has products. Reassign them to another category or confirm unassigning them first.'
                );
            }
        }

        $delete = $this->db->prepare('DELETE FROM categories WHERE id = :id');
        $delete->bindValue(':id', $id, PDO::PARAM_STR);
        $delete->execute();

        return ['reassigned' => $reassigned, 'unassigned' => $unassigned];
    }

    /**
     * @return array{total: int, active: int}
     */
    public function counts(): array
    {
        $row = $this->db->query(
            'SELECT COUNT(DISTINCT c.id) AS total,
                    COUNT(DISTINCT c.id) FILTER (WHERE p.is_active = true) AS active
             FROM categories c
             LEFT JOIN products p ON p.category_id = c.id'
        )->fetch();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
        ];
    }
}
