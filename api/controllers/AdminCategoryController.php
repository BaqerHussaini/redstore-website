<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminCategory.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';

final class AdminCategoryController
{
    private AdminCategory $categories;

    public function __construct(?PDO $db = null)
    {
        $this->categories = new AdminCategory($db ?? redstore_db());
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function index(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $search = trim((string) ($query['search'] ?? ''));
        if (mb_strlen($search) > 80) {
            throw new ApiException(422, 'invalid_search', 'The search term must be 80 characters or fewer.');
        }

        [$rows, $total] = $this->categories->paginate([
            'search' => $search,
            'page' => $page,
            'limit' => $limit,
        ]);

        return [
            $rows,
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
                'filters' => ['search' => $search],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->categories->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $category = $this->categories->find($this->uuidOrFail($id));
        if ($category === null) {
            throw new ApiException(404, 'category_not_found', 'Category not found.');
        }

        return $category;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $data = $this->parse($input, null);
        $id = $this->categories->create($data['name'], $data['slug']);

        return $this->show($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input): array
    {
        $id = $this->uuidOrFail($id);
        $existing = $this->categories->find($id);
        if ($existing === null) {
            throw new ApiException(404, 'category_not_found', 'Category not found.');
        }

        $data = $this->parse($input, $existing);
        $this->categories->update($id, $data['name'], $data['slug']);

        return $this->show($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: string, reassigned: int, unassigned: int}
     */
    public function delete(string $id, array $input): array
    {
        $id = $this->uuidOrFail($id);
        $reassignTo = Input::uuid($input, 'reassign_to', false);
        $confirmUnassign = Input::boolean($input, 'confirm_unassign');

        $result = $this->categories->delete($id, $reassignTo, $confirmUnassign);

        return ['id' => $id, 'reassigned' => $result['reassigned'], 'unassigned' => $result['unassigned']];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array{name: string, slug: string}
     */
    private function parse(array $input, ?array $existing): array
    {
        $name = (string) Input::string($input, 'name', 80, true);
        $slugInput = trim((string) ($input['slug'] ?? ''));
        $exclude = $existing !== null ? (string) $existing['id'] : null;

        if ($slugInput === '') {
            $slug = $this->categories->uniqueSlug($name, $exclude);
        } else {
            $slug = strtolower($slugInput);
            if (strlen($slug) > 100 || !Input::validSlug($slug)) {
                throw new ApiException(
                    422,
                    'invalid_slug',
                    'The slug may only contain lowercase letters, numbers and single hyphens.'
                );
            }
            if ($this->categories->slugExists($slug, $exclude)) {
                throw new ApiException(409, 'category_slug_exists', 'Another category already uses that slug.');
            }
        }

        return ['name' => $name, 'slug' => $slug];
    }

    private function uuidOrFail(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) !== 1) {
            throw new ApiException(404, 'category_not_found', 'Category not found.');
        }

        return strtolower($id);
    }
}
