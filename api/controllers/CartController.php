<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';

/**
 * Validates guest cart contents against authoritative PostgreSQL data.
 *
 * Guest carts are stored only in the browser (localStorage). This endpoint
 * never persists anything and never trusts client-supplied names, prices or
 * stock. It returns the current product/variant data plus a server-calculated
 * summary so the storefront can render an accurate cart.
 */
final class CartController
{
    private const MAX_ITEMS = 50;
    private const MAX_QUANTITY = 999;

    private Product $products;

    public function __construct()
    {
        $this->products = new Product(redstore_db());
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

        $parsed = $this->parseItems($items);
        $products = [];
        foreach ($this->products->findActiveByIds(array_column($parsed, 'product_id')) as $product) {
            $products[(string) $product['id']] = $product;
        }

        $resultItems = [];
        $subtotal = 0.0;
        $itemCount = 0;

        foreach ($parsed as $row) {
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

            $resultItems[] = [
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
        }

        $subtotal = round($subtotal, 2);

        return [
            [
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
            ],
            ['count' => count($resultItems)],
        ];
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

            $key = $productId . '|' . ($variantId ?? '');
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
     * @param array{product_id: string, variant_id: ?string, quantity: int} $row
     * @param array<string, mixed>|null $productInfo
     * @return array<string, mixed>
     */
    private function unavailableItem(array $row, string $reason, ?array $productInfo = null): array
    {
        return [
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
    }
}
