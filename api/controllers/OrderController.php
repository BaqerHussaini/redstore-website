<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Cart.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Input.php';

/**
 * Customer order placement.
 *
 * The cart is always loaded from the database for the signed-in customer.
 * Prices, names, images and stock are re-read inside a transaction so a stale
 * browser can never place an order using outdated product data.
 */
final class OrderController
{
    private Product $products;
    private Cart $cart;

    public function __construct(private readonly PDO $db)
    {
        $this->products = new Product($db);
        $this->cart = new Cart($db);
    }

    /**
     * POST /api/orders
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function place(string $userId, array $input): array
    {
        $shipping = $this->shippingDetails($input);
        $rows = $this->cart->itemsForUser($userId);

        if ($rows === []) {
            throw new ApiException(422, 'empty_cart', 'Your cart is empty. Add items before placing an order.');
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['product_id'],
            $rows
        )));
        $variantIds = array_values(array_unique(array_filter(
            array_map(static fn (array $row): ?string => $row['variant_id'] !== null ? (string) $row['variant_id'] : null, $rows),
            static fn (?string $id): bool => $id !== null
        )));

        $this->db->beginTransaction();

        try {
            $this->lockRows('products', $productIds);
            $this->lockRows('product_variants', $variantIds);

            $products = [];
            foreach ($this->products->findActiveByIds($productIds) as $product) {
                $products[(string) $product['id']] = $product;
            }

            $items = [];
            $subtotal = 0.0;
            $itemCount = 0;

            foreach ($rows as $row) {
                $product = $products[(string) $row['product_id']] ?? null;
                if ($product === null) {
                    throw new ApiException(
                        422,
                        'item_unavailable',
                        'One or more items in your cart are no longer available. Review your cart and try again.'
                    );
                }

                $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
                $variant = null;
                $size = null;

                if ($variants !== []) {
                    if ($row['variant_id'] === null) {
                        throw new ApiException(
                            422,
                            'variant_required',
                            'Please select a size before ordering ' . (string) $product['name'] . '.'
                        );
                    }

                    foreach ($variants as $candidate) {
                        if ((string) $candidate['id'] === (string) $row['variant_id']) {
                            $variant = $candidate;
                            break;
                        }
                    }

                    if ($variant === null) {
                        throw new ApiException(
                            422,
                            'item_unavailable',
                            'The selected size for ' . (string) $product['name'] . ' is no longer available.'
                        );
                    }

                    $stock = (int) $variant['stock_quantity'];
                    $size = (string) $variant['size'];
                } else {
                    if ($row['variant_id'] !== null) {
                        throw new ApiException(
                            422,
                            'item_unavailable',
                            'One or more items in your cart are no longer available. Review your cart and try again.'
                        );
                    }
                    $stock = (int) $product['stock_quantity'];
                }

                $quantity = (int) $row['quantity'];
                if ($stock < 1) {
                    throw new ApiException(
                        422,
                        'out_of_stock',
                        (string) $product['name'] . ' is out of stock. Remove it from your cart and try again.'
                    );
                }
                if ($quantity > $stock) {
                    throw new ApiException(
                        422,
                        'insufficient_stock',
                        'Only ' . $stock . ' of ' . (string) $product['name'] . ' available. Update your cart and try again.'
                    );
                }

                $unitPrice = round((float) $product['effective_price'], 2);
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;
                $itemCount += $quantity;

                $items[] = [
                    'product_id' => (string) $product['id'],
                    'variant_id' => $variant !== null ? (string) $variant['id'] : null,
                    'product_name' => (string) $product['name'],
                    'product_image_url' => $product['main_image']['url'] ?? null,
                    'variant_size' => $size,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'product_stock' => (int) $product['stock_quantity'],
                    'variant_stock' => $variant !== null ? (int) $variant['stock_quantity'] : null,
                ];
            }

            $subtotal = round($subtotal, 2);
            if ($items === [] || $itemCount < 1) {
                throw new ApiException(422, 'empty_cart', 'Your cart is empty. Add items before placing an order.');
            }

            $orderId = $this->insertOrder($userId, $shipping, $subtotal);
            $this->insertItems($orderId, $items);
            $this->decrementStock($items);
            $this->cart->clear($userId);

            $this->db->commit();

            return [
                'id' => $orderId,
                'order_number' => $shipping['order_number'],
                'status' => 'pending',
                'subtotal' => $subtotal,
                'shipping_fee' => 0.0,
                'total_amount' => $subtotal,
                'currency' => 'USD',
                'item_count' => $itemCount,
                'line_count' => count($items),
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function shippingDetails(array $input): array
    {
        $email = Input::validateEmail((string) Input::string($input, 'email', 255));

        return [
            'order_number' => $this->orderNumber(),
            'shipping_full_name' => Input::string($input, 'full_name', 120),
            'shipping_email' => $email,
            'shipping_phone' => Input::string($input, 'phone', 30, false),
            'shipping_address_line1' => Input::string($input, 'address_line1', 180),
            'shipping_address_line2' => Input::string($input, 'address_line2', 180, false),
            'shipping_city' => Input::string($input, 'city', 100),
            'shipping_state' => Input::string($input, 'state', 100, false),
            'shipping_postal_code' => Input::string($input, 'postal_code', 20),
            'shipping_country' => Input::string($input, 'country', 100),
            'notes' => Input::string($input, 'notes', 2000, false),
        ];
    }

    private function orderNumber(): string
    {
        return 'RS' . date('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
    }

    /**
     * @param array<string, mixed> $shipping
     */
    private function insertOrder(string $userId, array $shipping, float $subtotal): string
    {
        $statement = $this->db->prepare(
            'INSERT INTO orders (
                order_number, user_id, status, subtotal, shipping_fee, total_amount, currency,
                shipping_full_name, shipping_email, shipping_phone,
                shipping_address_line1, shipping_address_line2, shipping_city, shipping_state,
                shipping_postal_code, shipping_country, notes
             ) VALUES (
                :order_number, :user_id, :status, :subtotal, :shipping_fee, :total_amount, :currency,
                :shipping_full_name, :shipping_email, :shipping_phone,
                :shipping_address_line1, :shipping_address_line2, :shipping_city, :shipping_state,
                :shipping_postal_code, :shipping_country, :notes
             ) RETURNING id'
        );

        $statement->bindValue(':order_number', $shipping['order_number'], PDO::PARAM_STR);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $statement->bindValue(':status', 'pending', PDO::PARAM_STR);
        $statement->bindValue(':subtotal', $subtotal);
        $statement->bindValue(':shipping_fee', 0.0);
        $statement->bindValue(':total_amount', $subtotal);
        $statement->bindValue(':currency', 'USD', PDO::PARAM_STR);
        $statement->bindValue(':shipping_full_name', $shipping['shipping_full_name'], PDO::PARAM_STR);
        $statement->bindValue(':shipping_email', $shipping['shipping_email'], PDO::PARAM_STR);
        $this->bindNullableString($statement, ':shipping_phone', $shipping['shipping_phone']);
        $statement->bindValue(':shipping_address_line1', $shipping['shipping_address_line1'], PDO::PARAM_STR);
        $this->bindNullableString($statement, ':shipping_address_line2', $shipping['shipping_address_line2']);
        $statement->bindValue(':shipping_city', $shipping['shipping_city'], PDO::PARAM_STR);
        $this->bindNullableString($statement, ':shipping_state', $shipping['shipping_state']);
        $statement->bindValue(':shipping_postal_code', $shipping['shipping_postal_code'], PDO::PARAM_STR);
        $statement->bindValue(':shipping_country', $shipping['shipping_country'], PDO::PARAM_STR);
        $this->bindNullableString($statement, ':notes', $shipping['notes']);
        $statement->execute();

        $value = $statement->fetchColumn();
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('The order could not be created.');
        }

        return $value;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function insertItems(string $orderId, array $items): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO order_items (
                order_id, product_id, variant_id, product_name, product_image_url,
                variant_size, unit_price, quantity
             ) VALUES (
                :order_id, :product_id, :variant_id, :product_name, :product_image_url,
                :variant_size, :unit_price, :quantity
             )'
        );

        foreach ($items as $item) {
            $statement->bindValue(':order_id', $orderId, PDO::PARAM_STR);
            $statement->bindValue(':product_id', $item['product_id'], PDO::PARAM_STR);
            $this->bindNullableString($statement, ':variant_id', $item['variant_id']);
            $statement->bindValue(':product_name', $item['product_name'], PDO::PARAM_STR);
            $this->bindNullableString($statement, ':product_image_url', $item['product_image_url']);
            $this->bindNullableString($statement, ':variant_size', $item['variant_size']);
            $statement->bindValue(':unit_price', $item['unit_price']);
            $statement->bindValue(':quantity', $item['quantity'], PDO::PARAM_INT);
            $statement->execute();
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function decrementStock(array $items): void
    {
        $variantUpdate = $this->db->prepare(
            'UPDATE product_variants
             SET stock_quantity = stock_quantity - :quantity
             WHERE id = :id'
        );
        $aggregateUpdate = $this->db->prepare(
            'UPDATE products p
             SET stock_quantity = COALESCE((
                 SELECT SUM(pv.stock_quantity) FROM product_variants pv WHERE pv.product_id = p.id
             ), 0)
             WHERE p.id = :id'
        );
        $productUpdate = $this->db->prepare(
            'UPDATE products
             SET stock_quantity = stock_quantity - :quantity
             WHERE id = :id'
        );

        foreach ($items as $item) {
            if ($item['variant_id'] !== null) {
                $variantUpdate->bindValue(':quantity', (int) $item['quantity'], PDO::PARAM_INT);
                $variantUpdate->bindValue(':id', $item['variant_id'], PDO::PARAM_STR);
                $variantUpdate->execute();

                $aggregateUpdate->bindValue(':id', $item['product_id'], PDO::PARAM_STR);
                $aggregateUpdate->execute();
            } else {
                $productUpdate->bindValue(':quantity', (int) $item['quantity'], PDO::PARAM_INT);
                $productUpdate->bindValue(':id', $item['product_id'], PDO::PARAM_STR);
                $productUpdate->execute();
            }
        }
    }

    /**
     * @param list<string> $ids
     */
    private function lockRows(string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare("SELECT id FROM {$table} WHERE id IN ($placeholders) FOR UPDATE");
        $statement->execute($ids);
    }

    private function bindNullableString(PDOStatement $statement, string $key, ?string $value): void
    {
        if ($value === null) {
            $statement->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $statement->bindValue($key, $value, PDO::PARAM_STR);
    }
}
