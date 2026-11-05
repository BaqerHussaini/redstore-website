/**
 * RedStore – shared DOM renderers for product cards and states.
 * Product data is inserted with textContent / attributes, never as raw HTML.
 */
(() => {
  'use strict';

  const statusHost = (container) =>
    ['UL', 'OL'].includes(container.tagName) ? document.createElement('li') : document.createElement('div');

  const showStatus = (container, { type = 'status', message, retry = null } = {}) => {
    container.replaceChildren();
    const item = statusHost(container);
    item.className = 'products-status';
    item.setAttribute('role', type === 'alert' ? 'alert' : 'status');

    const text = document.createElement('p');
    text.textContent = message || '';
    item.append(text);

    if (typeof retry === 'function') {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'products-status__retry';
      button.textContent = 'Try again';
      button.addEventListener('click', retry, { once: true });
      item.append(button);
    }

    container.append(item);
  };

  const applyFallback = (image) => {
    image.addEventListener(
      'error',
      () => {
        image.src = window.RedStore.placeholderImage;
      },
      { once: true }
    );
  };

  const createPrice = (product) => {
    const price = document.createElement('p');
    price.className = 'product-card__price';

    const hasSale =
      product.sale_price !== null &&
      product.sale_price !== undefined &&
      Number(product.sale_price) < Number(product.price);

    if (hasSale) {
      price.classList.add('product-card__price--sale');
      price.append(document.createTextNode(window.RedStore.formatPrice(product.sale_price, product.currency)));

      const original = document.createElement('s');
      original.className = 'product-card__price-was';
      original.textContent = window.RedStore.formatPrice(product.price, product.currency);
      price.append(original);
    } else {
      price.textContent = window.RedStore.formatPrice(product.effective_price, product.currency);
    }

    return price;
  };

  const createProductCard = (product, { headingLevel = 3 } = {}) => {
    const item = document.createElement('li');
    const article = document.createElement('article');
    article.className = 'product-card';

    const link = document.createElement('a');
    link.className = 'product-card__link';
    link.href = window.RedStore.productDetailsUrl(product.slug);

    const image = document.createElement('img');
    image.src = product.main_image?.url || window.RedStore.placeholderImage;
    image.alt = product.main_image?.alt || product.name;
    image.width = 540;
    image.height = 720;
    image.loading = 'lazy';
    image.decoding = 'async';
    applyFallback(image);

    const heading = document.createElement('h' + headingLevel);
    heading.className = 'product-card__name';
    heading.textContent = product.name;

    link.append(image, heading);
    article.append(link, createPrice(product));

    if (product.in_stock === false) {
      const stock = document.createElement('p');
      stock.className = 'product-card__stock';
      stock.textContent = 'Out of stock';
      article.append(stock);
    }

    item.append(article);
    return item;
  };

  const renderProductGrid = (container, products, options = {}) => {
    const list = Array.isArray(products) ? products : [];
    container.replaceChildren();

    if (list.length === 0) {
      showStatus(container, {
        message: options.emptyMessage || 'No products found.',
      });
      return;
    }

    const fragment = document.createDocumentFragment();
    list.forEach((product) => {
      fragment.append(createProductCard(product, options));
    });
    container.append(fragment);
  };

  window.RedStoreUI = {
    createProductCard,
    renderProductGrid,
    showStatus,
  };
})();
