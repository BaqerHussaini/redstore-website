<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Cart.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';

/**
 * Cart operations.
 *
 * Guest carts are stored only in the browser (localStorage); `validate()`
 * checks them against authoritative PostgreSQL data and never persists
 * anything. Authenticated customers get a saved cart in `cart_items`, and every
 * read/write is scoped to the session user id supplied by CustomerAuth.
 */
final class CartController
{
    private const MAX_ITEMS = 50;
    private const MAX_QUANTITY = 999;

    private Product $products;
    private Cart $cart;

    public function __construct()
    {
        $db = redstore_db();
        $this->products = new Product($db);
        $this->cart = new Cart($db);
    }

    /**
     * POST /api/cart/validate
     *
     * Body: { "items": [{ "product_id": uuid, "variant_id": uuid|null, "quantity": int }] }
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function validate(array $input): array
    {
        $parsed = $this->parseInputItems($input);
        $data = $this->buildCart($parsed);

        return [$data, ['count' => count($data['items'])]];
    }

    /**
     * GET /api/cart
     *
     * Returns the saved cart. Unavailable lines are removed and quantities are
     * clamped to current stock, so the database always matches what is shown.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function show(string $userId): array
    {
        $rows = $this->cart->itemsForUser($userId);
        if ($rows === []) {
            return [$this->emptyCart(), ['reconciled' => false]];
        }

        $built = $this->buildCart($rows);
        [$kept, $changed] = $this->reconcileRows($userId, $built);

        if ($changed) {
            $rows = $this->cart->itemsForUser($userId);
            $built = $rows === [] ? $this->emptyCart() : $this->buildCart($rows);
        }

        return [$built, ['reconciled' => $changed, 'count' => count($kept)]];
    }

    /**
     * POST /api/cart/items
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function add(string $userId, array $input): array
    {
        $productId = Input::uuid($input, 'product_id', true);
        $variantId = Input::uuid($input, 'variant_id', false);
        $quantity = Input::integer($input, 'quantity', 1, self::MAX_QUANTITY, 0);
        if ($quantity < 1) {
            throw new ApiException(422, 'invalid_quantity', 'The quantity must be at least 1.');
        }

        $existing = $this->cart->findLine($userId, $productId, $variantId);
        if ($existing === null && $this->cart->countLines($userId) >= self::MAX_ITEMS) {
            throw new ApiException(
                422,
                'too_many_items',
                'A cart may contain at most ' . self::MAX_ITEMS . ' different items.'
            );
        }

        $requested = min(self::MAX_QUANTITY, (int) ($existing['quantity'] ?? 0) + $quantity);
        $built = $this->buildCart([[
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => $requested,
        ]]);
        $item = $built['items'][0];

        if (!$item['available']) {
            throw new ApiException(422, (string) ($item['reason'] ?? 'unavailable'), $this->reasonMessage($item));
        }
        if (!$item['within_stock']) {
            $stock = (int) $item['stock_quantity'];
            $already = (int) ($existing['quantity'] ?? 0);
            $extra = $already > 0 ? " ({$already} already in your cart)" : '';
            throw new ApiException(
                422,
                'insufficient_stock',
                $stock > 0
                    ? "Only {$stock} available{$extra}."
                    : 'This product is out of stock.'
            );
        }

        $this->cart->upsert($userId, $productId, $variantId, (int) $item['quantity']);

        return $this->show($userId);
    }

    /**
     * PATCH /api/cart/items/{id}
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function update(string $userId, string $itemId, array $input): array
    {
        $row = $this->cart->findItem($userId, $itemId);
        if ($row === null) {
            throw new ApiException(404, 'not_found', 'This item is no longer in your cart.');
        }

        $quantity = Input::integer($input, 'quantity', 1, self::MAX_QUANTITY, 0);
        if ($quantity < 1) {
            throw new ApiException(422, 'invalid_quantity', 'The quantity must be at least 1.');
        }

        $built = $this->buildCart([[
            'id' => $row['id'],
            'product_id' => $row['product_id'],
            'variant_id' => $row['variant_id'],
            'quantity' => $quantity,
        ]]);
        $item = $built['items'][0];

        if (!$item['available']) {
            throw new ApiException(422, (string) ($item['reason'] ?? 'unavailable'), $this->reasonMessage($item));
        }
        if (!$item['within_stock']) {
            $stock = (int) $item['stock_quantity'];
            throw new ApiException(
                422,
                'insufficient_stock',
                $stock > 0 ? "Only {$stock} left in stock." : 'This product is out of stock.'
            );
        }

        $this->cart->updateQuantity($userId, $itemId, (int) $item['quantity']);

        return $this->show($userId);
    }

    /**
     * DELETE /api/cart/items/{id}
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function remove(string $userId, string $itemId): array
    {
        if (!$this->cart->deleteItem($userId, $itemId)) {
            throw new ApiException(404, 'not_found', 'This item is no longer in your cart.');
        }

        return $this->show($userId);
    }

    /**
     * DELETE /api/cart
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function clear(string $userId): array
    {
        $this->cart->clear($userId);

        return [$this->emptyCart(), ['cleared' => true]];
    }

    /**
     * POST /api/cart/merge
     *
     * Merges a guest cart into the saved cart after login. Duplicate
     * product/variant lines are combined, quantities are clamped to stock and
     * unavailable lines are skipped.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function merge(string $userId, array $input): array
    {
        $parsed = $this->parseInputItems($input);
        if ($parsed === []) {
            [$data] = $this->show($userId);
            return [$data, ['merged' => 0, 'skipped' => 0, 'adjusted' => 0]];
        }

        $rows = $this->cart->itemsForUser($userId);
        $lineCount = count($rows);
        $existingByKey = [];
        foreach ($rows as $row) {
            $existingByKey[$this->lineKey($row['product_id'], $row['variant_id'])] = $row;
        }

        $lines = [];
        foreach ($parsed as $line) {
            $key = $this->lineKey($line['product_id'], $line['variant_id']);
            $existing = $existingByKey[$key] ?? null;
            $lines[] = [
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'quantity' => min(
                    self::MAX_QUANTITY,
                    (int) ($existing['quantity'] ?? 0) + $line['quantity']
                ),
            ];
        }

        $built = $this->buildCart($lines);
        $merged = 0;
        $skipped = 0;
        $adjusted = 0;

        foreach ($built['items'] as $index => $item) {
            $line = $lines[$index];
            $key = $this->lineKey($line['product_id'], $line['variant_id']);
            $existing = $existingByKey[$key] ?? null;

            if (!$item['available'] || (int) $item['stock_quantity'] < 1 || (int) $item['quantity'] < 1) {
                $skipped += 1;
                continue;
            }

            if ($existing === null && $lineCount >= self::MAX_ITEMS) {
                $skipped += 1;
                continue;
            }

            if (!$item['within_stock']) {
                $adjusted += 1;
            }

            if ($existing === null) {
                $lineCount += 1;
            }

            $this->cart->upsert(
                $userId,
                $line['product_id'],
                $line['variant_id'],
                (int) $item['quantity']
            );
            $merged += 1;
        }

        [$data] = $this->show($userId);

        return [$data, ['merged' => $merged, 'skipped' => $skipped, 'adjusted' => $adjusted]];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{product_id: string, variant_id: ?string, quantity: int}>
     */
    private function parseInputItems(array $input): array
    {
        $items = $input['items'] ?? null;
        if (!is_array($items)) {
            throw new ApiException(422, 'invalid_items', 'Provide an items array.');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new ApiException(
                422,
                'too_many_items',
                'A cart may contain at most ' . self::MAX_ITEMS . ' line items.'
            );
        }

        return $this->parseItems($items);
    }

    /**
     * @param list<mixed> $items
     * @return list<array{product_id: string, variant_id: ?string, quantity: int}>
     */
    private function parseItems(array $items): array
    {
        $aggregated = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new ApiException(422, 'invalid_item', 'Each cart item must be an object.');
            }

            $productId = Input::uuid($item, 'product_id', true);
            $variantId = Input::uuid($item, 'variant_id', false);
            $quantity = Input::integer($item, 'quantity', 1, self::MAX_QUANTITY, 0);
            if ($quantity < 1) {
                throw new ApiException(422, 'invalid_quantity', 'The quantity must be at least 1.');
            }

            $key = $this->lineKey($productId, $variantId);
            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                ];
                continue;
            }

            $aggregated[$key]['quantity'] = min(
                self::MAX_QUANTITY,
                $aggregated[$key]['quantity'] + $quantity
            );
        }

        return array_values($aggregated);
    }

    /**
     * @param list<array{id?: string, product_id: string, variant_id: ?string, quantity: int}> $lines
     * @return array{items: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function buildCart(array $lines): array
    {
        $products = [];
        foreach ($this->products->findActiveByIds(array_column($lines, 'product_id')) as $product) {
            $products[(string) $product['id']] = $product;
        }

        $resultItems = [];
        $subtotal = 0.0;
        $itemCount = 0;

        foreach ($lines as $row) {
            $product = $products[$row['product_id']] ?? null;
            if ($product === null) {
                $resultItems[] = $this->unavailableItem($row, 'unavailable');
                continue;
            }

            $productInfo = [
                'id' => (string) $product['id'],
                'name' => (string) $product['name'],
                'slug' => (string) $product['slug'],
                'image' => $product['main_image'] !== null ? [
                    'url' => (string) $product['main_image']['url'],
                    'alt' => (string) $product['main_image']['alt'],
                ] : null,
            ];

            $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
            $stock = 0;
            $size = null;
            $color = null;

            if ($variants !== []) {
                if ($row['variant_id'] === null) {
                    $resultItems[] = $this->unavailableItem($row, 'variant_required', $productInfo);
                    continue;
                }

                $variant = null;
                foreach ($variants as $candidate) {
                    if ((string) $candidate['id'] === $row['variant_id']) {
                        $variant = $candidate;
                        break;
                    }
                }

                if ($variant === null) {
                    $resultItems[] = $this->unavailableItem($row, 'variant_unavailable', $productInfo);
                    continue;
                }

                $stock = (int) $variant['stock_quantity'];
                $size = (string) $variant['size'];
                $color = (string) ($variant['color'] ?? '');
            } else {
                if ($row['variant_id'] !== null) {
                    $resultItems[] = $this->unavailableItem($row, 'variant_unavailable', $productInfo);
                    continue;
                }
                $stock = (int) $product['stock_quantity'];
            }

            $unitPrice = (float) $product['effective_price'];
            $available = $stock > 0;
            $withinStock = $available && $row['quantity'] <= $stock;
            $effectiveQuantity = max(0, min($row['quantity'], $stock));
            $lineTotal = $available ? round($unitPrice * $effectiveQuantity, 2) : 0.0;

            $status = 'available';
            if (!$available) {
                $status = 'out_of_stock';
            } elseif (!$withinStock) {
                $status = 'insufficient_stock';
            }

            if ($available) {
                $subtotal += $lineTotal;
                $itemCount += $effectiveQuantity;
            }

            $resultItem = [
                'product_id' => $row['product_id'],
                'variant_id' => $row['variant_id'],
                'requested_quantity' => $row['quantity'],
                'quantity' => $effectiveQuantity,
                'unit_price' => round($unitPrice, 2),
                'line_total' => $lineTotal,
                'currency' => (string) ($product['currency'] ?? 'USD'),
                'product' => $productInfo,
                'size' => $size,
                'color' => $color !== '' ? $color : null,
                'stock_quantity' => $stock,
                'in_stock' => $available,
                'available' => $available,
                'within_stock' => $withinStock,
                'adjusted' => $effectiveQuantity !== $row['quantity'],
                'reason' => $status === 'available' ? null : $status,
            ];

            if (array_key_exists('id', $row)) {
                $resultItem['id'] = $row['id'];
            }

            $resultItems[] = $resultItem;
        }

        $subtotal = round($subtotal, 2);

        return [
            'items' => $resultItems,
            'summary' => [
                'item_count' => $itemCount,
                'line_count' => count($resultItems),
                'currency' => 'USD',
                'subtotal' => $subtotal,
                'shipping' => null,
                'tax' => null,
                'total' => $subtotal,
            ],
        ];
    }

    /**
     * @param array{items: list<array<string, mixed>>, summary: array<string, mixed>} $built
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function reconcileRows(string $userId, array $built): array
    {
        $kept = [];
        $changed = false;

        foreach ($built['items'] as $item) {
            $id = $item['id'] ?? null;

            if (!$item['available'] || (int) $item['stock_quantity'] < 1) {
                if (is_string($id)) {
                    $this->cart->deleteItem($userId, $id);
                    $changed = true;
                }
                continue;
            }

            if ((int) $item['quantity'] !== (int) $item['requested_quantity']) {
                if (is_string($id)) {
                    $this->cart->updateQuantity($userId, $id, (int) $item['quantity']);
                    $changed = true;
                }
            }

            $kept[] = $item;
        }

        return [$kept, $changed];
    }

    /**
     * @param array{product_id: string, variant_id: ?string, quantity: int} $row
     * @param array<string, mixed>|null $productInfo
     * @return array<string, mixed>
     */
    private function unavailableItem(array $row, string $reason, ?array $productInfo = null): array
    {
        $item = [
            'product_id' => $row['product_id'],
            'variant_id' => $row['variant_id'],
            'requested_quantity' => $row['quantity'],
            'quantity' => 0,
            'unit_price' => 0.0,
            'line_total' => 0.0,
            'currency' => 'USD',
            'product' => $productInfo,
            'size' => null,
            'color' => null,
            'stock_quantity' => 0,
            'in_stock' => false,
            'available' => false,
            'within_stock' => false,
            'adjusted' => false,
            'reason' => $reason,
        ];

        if (array_key_exists('id', $row)) {
            $item['id'] = $row['id'];
        }

        return $item;
    }

    /**
     * @return array{items: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function emptyCart(): array
    {
        return [
            'items' => [],
            'summary' => [
                'item_count' => 0,
                'line_count' => 0,
                'currency' => 'USD',
                'subtotal' => 0,
                'shipping' => null,
                'tax' => null,
                'total' => 0,
            ],
        ];
    }

    private function lineKey(string $productId, ?string $variantId): string
    {
        return $productId . '|' . ($variantId ?? '');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function reasonMessage(array $item): string
    {
        return match ($item['reason'] ?? 'unavailable') {
            'variant_required' => 'Please select a size before adding this product.',
            'variant_unavailable' => 'The selected size is no longer available.',
            'out_of_stock' => 'This product is out of stock.',
            'insufficient_stock' => (int) ($item['stock_quantity'] ?? 0) > 0
                ? 'Only ' . (int) $item['stock_quantity'] . ' left in stock.'
                : 'This product is out of stock.',
            default => 'This product is no longer available.',
        };
    }
}
