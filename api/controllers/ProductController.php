<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../lib/ApiException.php';

final class ProductController
{
    private const ALLOWED_SORTS = [
        'default',
        'featured',
        'newest',
        'price_asc',
        'price_desc',
        'name_asc',
        'name_desc',
    ];

    private Product $products;
    private Category $categories;

    public function __construct()
    {
        $db = redstore_db();
        $this->products = new Product($db);
        $this->categories = new Category($db);
    }

    /**
     * GET /api/products
     *
     * @param array<string, mixed> $query
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function index(array $query): array
    {
        $category = $this->categoryParam($query);
        $search = $this->searchParam($query);
        $sort = $this->sortParam($query);
        $page = $this->intParam($query, 'page', 1, 1, 100000);
        $limit = $this->intParam($query, 'limit', 12, 1, 48);
        $offset = ($page - 1) * $limit;

        $filters = [
            'category' => $category,
            'search' => $search,
            'sort' => $sort,
            'limit' => $limit,
            'offset' => $offset,
        ];

        $products = $this->products->findActive($filters);
        $total = $this->products->countActive($filters);
        $totalPages = $limit > 0 ? (int) ceil($total / $limit) : 0;

        return [
            $products,
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_previous' => $page > 1,
                'has_next' => $page < $totalPages,
                'filters' => [
                    'category' => $category,
                    'search' => $search,
                    'sort' => $sort,
                ],
            ],
        ];
    }

    /**
     * GET /api/products/featured
     *
     * @param array<string, mixed> $query
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function featured(array $query): array
    {
        $limit = $this->intParam($query, 'limit', 8, 1, 24);
        $products = $this->products->findFeatured($limit);

        return [
            $products,
            ['count' => count($products), 'limit' => $limit],
        ];
    }

    /**
     * GET /api/products/latest
     *
     * @param array<string, mixed> $query
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function latest(array $query): array
    {
        $limit = $this->intParam($query, 'limit', 8, 1, 24);
        $products = $this->products->findLatest($limit);

        return [
            $products,
            ['count' => count($products), 'limit' => $limit],
        ];
    }

    /**
     * GET /api/products/{slug}
     *
     * @return array<string, mixed>
     */
    public function show(string $slug): array
    {
        $slug = trim($slug);
        if (
            $slug === ''
            || strlen($slug) > 180
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1
        ) {
            throw new ApiException(400, 'invalid_slug', 'The product slug is not valid.');
        }

        $product = $this->products->findActiveBySlug($slug);
        if ($product === null) {
            throw new ApiException(404, 'product_not_found', 'Product not found.');
        }

        return $product;
    }

    /**
     * GET /api/categories
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function categories(): array
    {
        $categories = $this->categories->activeWithProductCounts();

        return [
            $categories,
            ['count' => count($categories)],
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function categoryParam(array $query): ?string
    {
        if (!isset($query['category']) || is_array($query['category'])) {
            return null;
        }

        $category = trim((string) $query['category']);
        if ($category === '') {
            return null;
        }

        if (strlen($category) > 100 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $category) !== 1) {
            throw new ApiException(400, 'invalid_category', 'The category filter is not valid.');
        }

        return $category;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function searchParam(array $query): ?string
    {
        if (!isset($query['search']) || is_array($query['search'])) {
            return null;
        }

        $search = trim(str_replace("\0", '', (string) $query['search']));
        if ($search === '') {
            return null;
        }

        if (mb_strlen($search) > 100) {
            throw new ApiException(400, 'invalid_search', 'The search term must be 100 characters or fewer.');
        }

        return $search;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function sortParam(array $query): string
    {
        if (!isset($query['sort']) || is_array($query['sort'])) {
            return 'default';
        }

        $sort = strtolower(trim((string) $query['sort']));
        if ($sort === '') {
            return 'default';
        }

        if (!in_array($sort, self::ALLOWED_SORTS, true)) {
            throw new ApiException(
                400,
                'invalid_sort',
                'The sort value is not supported. Use one of: ' . implode(', ', self::ALLOWED_SORTS) . '.'
            );
        }

        return $sort;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function intParam(array $query, string $key, int $default, int $min, int $max): int
    {
        if (!isset($query[$key]) || $query[$key] === '') {
            return $default;
        }

        if (is_array($query[$key])) {
            throw new ApiException(400, 'invalid_' . $key, 'The ' . $key . ' parameter is not valid.');
        }

        $value = filter_var(
            $query[$key],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $min, 'max_range' => $max]]
        );

        if ($value === false) {
            throw new ApiException(
                400,
                'invalid_' . $key,
                sprintf('The %s parameter must be an integer between %d and %d.', $key, $min, $max)
            );
        }

        return (int) $value;
    }
}
