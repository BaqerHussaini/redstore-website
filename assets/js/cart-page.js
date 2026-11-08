/**
 * RedStore – shopping cart page.
 * Renders authoritative cart data returned by the cart validation API.
 */
(() => {
  'use strict';

  const cart = window.RedStoreCart;
  const helpers = window.RedStore;
  const list = document.getElementById('cart-items');
  const content = document.getElementById('cart-content');
  const status = document.getElementById('cart-status');
  const empty = document.getElementById('cart-empty');
  const subtotalCell = document.getElementById('cart-subtotal');
  const totalCell = document.getElementById('cart-total');
  const clearButton = document.getElementById('cart-clear');

  if (!cart || !helpers || !list || !content || !status || !empty) return;

  let current = { items: [], summary: emptySummary() };
  let loading = false;

  function emptySummary() {
    return {
      item_count: 0,
      line_count: 0,
      currency: 'USD',
      subtotal: 0,
      shipping: null,
      tax: null,
      total: 0,
    };
  }

  const money = (value, currency) => helpers.formatPrice(value, currency || 'USD');

  const setStatus = (message, { error = false, retry = false } = {}) => {
    status.replaceChildren();
    status.hidden = false;
    status.className = 'cart-status' + (error ? ' cart-status--error' : '');
    status.setAttribute('role', error ? 'alert' : 'status');

    const text = document.createElement('p');
    text.textContent = message;
    status.append(text);

    if (retry) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn cart-status__retry';
      button.textContent = 'Try again';
      button.addEventListener('click', () => load(), { once: true });
      status.append(button);
    }
  };

  const showEmpty = () => {
    status.hidden = true;
    content.hidden = true;
    empty.hidden = false;
  };

  const showContent = () => {
    status.hidden = true;
    empty.hidden = true;
    content.hidden = false;
  };

  const summarize = (items, currency) => {
    let subtotal = 0;
    let itemCount = 0;

    items.forEach((item) => {
      if (!item.available) return;
      subtotal += Number(item.line_total) || 0;
      itemCount += Number(item.quantity) || 0;
    });

    subtotal = Math.round(subtotal * 100) / 100;

    return {
      item_count: itemCount,
      line_count: items.length,
      currency: currency || 'USD',
      subtotal,
      shipping: null,
      tax: null,
      total: subtotal,
    };
  };

  const renderSummary = (summary) => {
    const safe = summary || emptySummary();
    if (subtotalCell) subtotalCell.textContent = money(safe.subtotal, safe.currency);
    if (totalCell) totalCell.textContent = money(safe.total, safe.currency);
  };

  const setRowBusy = (row, busy) => {
    if (!row) return;
    row.classList.toggle('is-busy', busy);
    row.setAttribute('aria-busy', String(busy));
    row.querySelectorAll('button, input').forEach((control) => {
      if (!(control instanceof HTMLButtonElement && control.dataset.alwaysEnabled === 'true')) {
        control.disabled = busy;
      }
    });
  };

  const changeQuantity = async (item, next, row) => {
    if (!item.available) {
      cart.notify('This item is not available.', 'error');
      return;
    }

    const max = Math.max(1, Number(item.stock_quantity) || 1);
    let quantity = Number.parseInt(next, 10);

    if (!Number.isFinite(quantity) || quantity < 1) {
      cart.notify('Enter a quantity of at least 1.', 'error');
      render(current);
      return;
    }

    if (quantity > max) {
      cart.notify(`Only ${max} available for ${item.product?.name || 'this item'}.`, 'error');
      quantity = max;
    }

    if (quantity === item.quantity) {
      render(current);
      return;
    }

    setRowBusy(row, true);

    try {
      const data = await cart.updateQuantity(item.product_id, item.variant_id, quantity);
      render(data || current);
    } catch (error) {
      cart.notify(error?.message || 'Could not update the quantity.', 'error');
      render(current);
    }
  };

  const removeItem = (item) => {
    const name = item.product?.name || 'Item';
    cart.remove(item.product_id, item.variant_id);

    const items = current.items.filter(
      (row) => cart.keyFor(row.product_id, row.variant_id) !== cart.keyFor(item.product_id, item.variant_id)
    );

    if (items.length === 0) {
      current = { items: [], summary: emptySummary() };
      cart.notify(`${name} removed from your cart.`, 'info');
      showEmpty();
      return;
    }

    current = {
      items,
      summary: summarize(items, current.summary?.currency),
    };

    renderItems(items);
    renderSummary(current.summary);
    showContent();
    cart.notify(`${name} removed from your cart.`, 'info');
  };

  const createRow = (item) => {
    const row = document.createElement('tr');
    row.dataset.key = cart.keyFor(item.product_id, item.variant_id);
    row.classList.toggle('is-unavailable', !item.available);

    const productCell = document.createElement('td');
    productCell.dataset.label = 'Product';

    const wrapper = document.createElement('div');
    wrapper.className = 'cart-item';

    const image = document.createElement('img');
    image.className = 'cart-item__image';
    image.src = item.product?.image?.url || helpers.placeholderImage;
    image.alt = item.product?.image?.alt || item.product?.name || 'Product image';
    image.width = 80;
    image.height = 80;
    image.loading = 'lazy';
    image.decoding = 'async';
    image.addEventListener(
      'error',
      () => {
        image.src = helpers.placeholderImage;
      },
      { once: true }
    );

    const details = document.createElement('div');
    details.className = 'cart-item__details';

    const name = document.createElement('p');
    name.className = 'cart-item__name';

    const nameText = item.product?.name || 'Product unavailable';
    if (item.product?.slug) {
      const link = document.createElement('a');
      link.href = helpers.productDetailsUrl(item.product.slug);
      link.textContent = nameText;
      name.append(link);
    } else {
      name.textContent = nameText;
    }

    details.append(name);

    const variantParts = [];
    if (item.size) variantParts.push('Size: ' + item.size);
    if (item.color) variantParts.push('Color: ' + item.color);
    if (variantParts.length > 0) {
      const variant = document.createElement('p');
      variant.className = 'cart-item__variant';
      variant.textContent = variantParts.join(' · ');
      details.append(variant);
    }

    const unitPrice = document.createElement('small');
    unitPrice.className = 'cart-item__price';
    unitPrice.textContent = item.available
      ? 'Price: ' + money(item.unit_price, item.currency)
      : 'No longer available';
    details.append(unitPrice);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'cart-item__remove';
    remove.textContent = 'Remove';
    remove.setAttribute('aria-label', `Remove ${nameText} from cart`);
    remove.addEventListener('click', () => removeItem(item));
    details.append(remove);

    wrapper.append(image, details);
    productCell.append(wrapper);

    const quantityCell = document.createElement('td');
    quantityCell.dataset.label = 'Quantity';

    const quantityWrap = document.createElement('div');
    quantityWrap.className = 'cart-qty';

    const decrease = document.createElement('button');
    decrease.type = 'button';
    decrease.className = 'cart-qty__btn';
    decrease.textContent = '−';
    decrease.setAttribute('aria-label', `Decrease quantity for ${nameText}`);
    decrease.disabled = !item.available || item.quantity <= 1;
    decrease.addEventListener('click', () => changeQuantity(item, item.quantity - 1, row));

    const input = document.createElement('input');
    input.className = 'cart-item__qty';
    input.type = 'number';
    input.min = '1';
    input.max = String(Math.max(1, item.stock_quantity));
    input.value = String(item.quantity);
    input.inputMode = 'numeric';
    input.setAttribute('aria-label', `Quantity for ${nameText}`);
    input.disabled = !item.available;
    input.addEventListener('change', () => changeQuantity(item, input.value, row));
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        input.blur();
      }
    });

    const increase = document.createElement('button');
    increase.type = 'button';
    increase.className = 'cart-qty__btn';
    increase.textContent = '+';
    increase.setAttribute('aria-label', `Increase quantity for ${nameText}`);
    increase.disabled = !item.available || item.quantity >= item.stock_quantity;
    increase.addEventListener('click', () => changeQuantity(item, item.quantity + 1, row));

    quantityWrap.append(decrease, input, increase);
    quantityCell.append(quantityWrap);

    const subtotal = document.createElement('td');
    subtotal.dataset.label = 'Subtotal';
    subtotal.className = 'cart-item__subtotal';
    subtotal.textContent = item.available ? money(item.line_total, item.currency) : '—';

    row.append(productCell, quantityCell, subtotal);
    return row;
  };

  const renderItems = (items) => {
    const fragment = document.createDocumentFragment();
    items.forEach((item) => fragment.append(createRow(item)));
    list.replaceChildren(fragment);
  };

  const render = (data) => {
    current = data && Array.isArray(data.items) ? data : { items: [], summary: emptySummary() };

    if (current.items.length === 0) {
      showEmpty();
      return;
    }

    renderItems(current.items);
    renderSummary(current.summary);
    showContent();
  };

  const load = async () => {
    if (loading) return;
    loading = true;
    content.hidden = true;
    empty.hidden = true;
    setStatus('Loading your cart…');

    try {
      const data = await cart.reconcile();
      if (data === null) {
        setStatus('We could not load your cart right now. Check your connection and try again.', {
          error: true,
          retry: true,
        });
        return;
      }
      render(data);
    } catch (error) {
      setStatus(error?.message || 'We could not load your cart. Please try again.', {
        error: true,
        retry: true,
      });
    } finally {
      loading = false;
    }
  };

  if (clearButton) {
    clearButton.addEventListener('click', () => {
      if (current.items.length === 0) return;
      if (!window.confirm('Remove all items from your cart?')) return;
      cart.clear();
      current = { items: [], summary: emptySummary() };
      cart.notify('Your cart has been cleared.', 'info');
      showEmpty();
    });
  }

  cart.subscribe((count) => {
    if (count === 0 && !loading && current.items.length > 0) {
      current = { items: [], summary: emptySummary() };
      showEmpty();
    }
  });

  load();
})();
