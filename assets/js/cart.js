/**
 * RedStore – guest shopping cart.
 *
 * The browser stores ONLY product IDs, variant IDs and quantities. Product
 * names, images, prices and stock are always re-validated against the PHP API
 * (and therefore PostgreSQL) before anything is displayed or changed.
 *
 * This module also renders the header cart badge and the toast notifications
 * used across the storefront.
 */
(() => {
  'use strict';

  const STORAGE_KEY = 'redstore_cart_v1';
  const MAX_ITEMS = 50;
  const MAX_QUANTITY = 999;
  const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

  const listeners = new Set();
  let memoryItems = [];

  class CartError extends Error {
    constructor(message, code = 'cart_error') {
      super(message);
      this.name = 'CartError';
      this.code = code;
    }
  }

  const isUuid = (value) => typeof value === 'string' && UUID_PATTERN.test(value);
  const clampInt = (value, min, max) => {
    const number = Number.parseInt(value, 10);
    if (!Number.isFinite(number)) return min;
    return Math.min(max, Math.max(min, number));
  };
  const keyFor = (productId, variantId) =>
    String(productId || '').toLowerCase() + '|' + (variantId ? String(variantId).toLowerCase() : '');

  const copyItems = (items) => items.map((item) => ({ ...item }));

  const sanitize = (raw) => {
    const source = Array.isArray(raw) ? raw : raw && Array.isArray(raw.items) ? raw.items : [];
    const merged = new Map();

    for (const entry of source) {
      if (!entry || typeof entry !== 'object') continue;

      const productId = String(entry.productId ?? entry.product_id ?? '').toLowerCase();
      const variantIdRaw = entry.variantId ?? entry.variant_id ?? null;
      const variantId = variantIdRaw ? String(variantIdRaw).toLowerCase() : null;

      if (!isUuid(productId)) continue;
      if (variantId !== null && !isUuid(variantId)) continue;

      const quantity = clampInt(entry.quantity, 1, MAX_QUANTITY);
      const key = keyFor(productId, variantId);
      const existing = merged.get(key);

      if (existing) {
        existing.quantity = Math.min(MAX_QUANTITY, existing.quantity + quantity);
      } else {
        merged.set(key, { productId, variantId, quantity });
      }

      if (merged.size >= MAX_ITEMS) break;
    }

    return Array.from(merged.values());
  };

  const readStored = () => {
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (raw === null) return null;
      return JSON.parse(raw);
    } catch {
      return null;
    }
  };

  const load = () => {
    const raw = readStored();
    if (raw === null) return copyItems(memoryItems);
    return sanitize(raw);
  };

  const save = (items) => {
    const clean = sanitize({ items });
    memoryItems = copyItems(clean);
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ version: 1, items: clean }));
    } catch {
      /* Storage can be unavailable (private mode/quota); memory keeps the cart working. */
    }
    emit();
    return clean;
  };

  const count = (items = load()) => items.reduce((total, item) => total + item.quantity, 0);

  const updateBadge = () => {
    const total = count();
    document.querySelectorAll('.cart-link').forEach((link) => {
      let badge = link.querySelector('.cart-count');
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'cart-count';
        link.append(badge);
      }
      badge.textContent = String(total);
      badge.hidden = total === 0;
      link.setAttribute(
        'aria-label',
        total === 0 ? 'Shopping cart, empty' : `Shopping cart, ${total} item${total === 1 ? '' : 's'}`
      );
    });
    return total;
  };

  const emit = () => {
    const total = updateBadge();
    listeners.forEach((listener) => {
      try {
        listener(total, load());
      } catch {
        /* A subscriber must not break the cart. */
      }
    });
    window.dispatchEvent(new CustomEvent('redstore:cart-changed', { detail: { count: total } }));
  };

  const subscribe = (listener) => {
    if (typeof listener !== 'function') return () => {};
    listeners.add(listener);
    return () => listeners.delete(listener);
  };

  const notify = (message, type = 'success') => {
    if (!message) return;

    let region = document.getElementById('redstore-toasts');
    if (!region) {
      region = document.createElement('div');
      region.id = 'redstore-toasts';
      region.className = 'cart-toasts';
      region.setAttribute('aria-live', 'polite');
      region.setAttribute('aria-atomic', 'false');
      document.body.append(region);
    }

    const toast = document.createElement('div');
    toast.className = 'cart-toast cart-toast--' + type;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

    const text = document.createElement('p');
    text.className = 'cart-toast__text';
    text.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'cart-toast__close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.textContent = '×';
    close.addEventListener('click', () => toast.remove(), { once: true });

    toast.append(text, close);
    region.append(toast);

    while (region.children.length > 4) {
      region.firstElementChild.remove();
    }

    const lifetime = type === 'error' ? 6000 : 4000;
    window.setTimeout(() => {
      toast.classList.add('is-leaving');
      window.setTimeout(() => toast.remove(), 250);
    }, lifetime);
  };

  const serverKey = (item) => keyFor(item.product_id, item.variant_id);
  const findServerItem = (data, key) =>
    (data?.items || []).find((item) => serverKey(item) === key) || null;

  const reasonMessage = (item) => {
    switch (item?.reason) {
      case 'variant_required':
        return 'Please select a size before adding this product.';
      case 'variant_unavailable':
        return 'The selected size is no longer available.';
      case 'out_of_stock':
        return 'This product is out of stock.';
      case 'insufficient_stock':
        return item.stock_quantity > 0
          ? `Only ${item.stock_quantity} left in stock.`
          : 'This product is out of stock.';
      case 'unavailable':
      default:
        return 'This product is no longer available.';
    }
  };

  const validate = async (items) => {
    if (typeof window.RedStoreAPI?.validateCart !== 'function') {
      throw new CartError('The cart service is unavailable. Please refresh the page and try again.', 'service_unavailable');
    }

    const payload = items.map((item) => ({
      product_id: item.productId,
      variant_id: item.variantId,
      quantity: item.quantity,
    }));

    const result = await window.RedStoreAPI.validateCart(payload);
    return result.data;
  };

  const emptySummary = () => ({
    item_count: 0,
    line_count: 0,
    currency: 'USD',
    subtotal: 0,
    shipping: null,
    tax: null,
    total: 0,
  });

  const add = async (productId, variantId, quantity) => {
    const product = String(productId || '').toLowerCase();
    const variant = variantId ? String(variantId).toLowerCase() : null;

    if (!isUuid(product)) {
      throw new CartError('This product could not be added to the cart.', 'invalid_product');
    }
    if (variant !== null && !isUuid(variant)) {
      throw new CartError('The selected variant is not valid.', 'invalid_variant');
    }

    const requested = clampInt(quantity, 1, MAX_QUANTITY);
    const items = load();
    const key = keyFor(product, variant);
    const line = items.find((item) => keyFor(item.productId, item.variantId) === key);

    if (line) {
      line.quantity = Math.min(MAX_QUANTITY, line.quantity + requested);
    } else {
      if (items.length >= MAX_ITEMS) {
        throw new CartError(`A cart can contain at most ${MAX_ITEMS} different items.`, 'too_many_items');
      }
      items.push({ productId: product, variantId: variant, quantity: requested });
    }

    const data = await validate(items);
    const serverItem = findServerItem(data, key);

    if (!serverItem || !serverItem.available) {
      throw new CartError(reasonMessage(serverItem), serverItem?.reason || 'unavailable');
    }

    if (!serverItem.within_stock) {
      const alreadyInCart = line ? line.quantity - requested : 0;
      const extra = alreadyInCart > 0 ? ` (${alreadyInCart} already in your cart)` : '';
      throw new CartError(
        serverItem.stock_quantity > 0
          ? `Only ${serverItem.stock_quantity} available${extra}.`
          : 'This product is out of stock.',
        'insufficient_stock'
      );
    }

    save(items);
    return data;
  };

  const updateQuantity = async (productId, variantId, quantity) => {
    const items = load();
    const key = keyFor(productId, variantId);
    const line = items.find((item) => keyFor(item.productId, item.variantId) === key);

    if (!line) {
      throw new CartError('This item is no longer in your cart.', 'not_found');
    }

    const requested = clampInt(quantity, 1, MAX_QUANTITY);
    if (requested === line.quantity) {
      return null;
    }

    line.quantity = requested;
    const data = await validate(items);
    const serverItem = findServerItem(data, key);

    if (!serverItem || !serverItem.available) {
      throw new CartError(reasonMessage(serverItem), serverItem?.reason || 'unavailable');
    }

    if (!serverItem.within_stock) {
      throw new CartError(`Only ${serverItem.stock_quantity} left in stock.`, 'insufficient_stock');
    }

    save(items);
    return data;
  };

  const remove = (productId, variantId) => {
    const key = keyFor(productId, variantId);
    const items = load().filter((item) => keyFor(item.productId, item.variantId) !== key);
    save(items);
    return items;
  };

  const clear = () => save([]);

  const buildReconciledData = (data, kept) => {
    const quantities = new Map(kept.map((item) => [keyFor(item.productId, item.variantId), item.quantity]));
    const items = (data?.items || [])
      .filter((item) => quantities.has(serverKey(item)))
      .map((item) => {
        const quantity = quantities.get(serverKey(item));
        return {
          ...item,
          quantity,
          line_total: Math.round(Number(item.unit_price) * quantity * 100) / 100,
          within_stock: quantity <= item.stock_quantity,
          adjusted: quantity !== item.requested_quantity,
        };
      });

    let subtotal = 0;
    let itemCount = 0;
    items.forEach((item) => {
      subtotal += Number(item.line_total) || 0;
      itemCount += Number(item.quantity) || 0;
    });
    subtotal = Math.round(subtotal * 100) / 100;

    return {
      items,
      summary: {
        item_count: itemCount,
        line_count: items.length,
        currency: data?.summary?.currency || 'USD',
        subtotal,
        shipping: null,
        tax: null,
        total: subtotal,
      },
    };
  };

  /**
   * Validate the stored cart, drop unavailable products and clamp quantities
   * to current stock. Returns null when the API cannot be reached so callers
   * can show a retry state without destroying the stored cart.
   */
  const reconcile = async () => {
    const items = load();
    if (items.length === 0) {
      return { items: [], summary: emptySummary() };
    }

    let data;
    try {
      data = await validate(items);
    } catch {
      return null;
    }

    const byKey = new Map((data.items || []).map((item) => [serverKey(item), item]));
    const kept = [];
    let removed = 0;
    let clamped = 0;

    for (const item of items) {
      const serverItem = byKey.get(keyFor(item.productId, item.variantId));

      if (!serverItem || !serverItem.available || serverItem.stock_quantity < 1) {
        removed += 1;
        continue;
      }

      const quantity = Math.min(item.quantity, serverItem.stock_quantity);
      if (quantity < 1) {
        removed += 1;
        continue;
      }
      if (quantity !== item.quantity) clamped += 1;

      kept.push({ productId: item.productId, variantId: item.variantId, quantity });
    }

    const changed = removed > 0 || clamped > 0;
    if (!changed) {
      return data;
    }

    save(kept);

    if (kept.length > 0) {
      try {
        data = await validate(kept);
      } catch {
        data = buildReconciledData(data, kept);
      }
    } else {
      data = { items: [], summary: emptySummary() };
    }

    const notes = [];
    if (removed > 0) notes.push(`${removed} unavailable item${removed === 1 ? '' : 's'} removed.`);
    if (clamped > 0) notes.push('Quantities were adjusted to available stock.');
    if (notes.length > 0) notify(notes.join(' '), 'info');

    return data;
  };

  window.addEventListener('storage', (event) => {
    if (event.key !== STORAGE_KEY) return;
    emit();
  });

  window.RedStoreCart = {
    CartError,
    MAX_ITEMS,
    MAX_QUANTITY,
    add,
    clear,
    count,
    getItems: load,
    keyFor,
    notify,
    reconcile,
    remove,
    subscribe,
    updateBadge,
    updateQuantity,
  };

  updateBadge();
})();
