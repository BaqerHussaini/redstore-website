/**
 * RedStore – dynamic product details page.
 * The product is identified by ?slug=... and loaded from the PHP API.
 */
(() => {
  'use strict';

  const status = document.getElementById('product-status');
  const detail = document.querySelector('.product-detail__inner');
  const mainImage = document.getElementById('product-image');
  const thumbs = document.querySelector('.product-gallery__thumbs');
  const breadcrumb = document.querySelector('.breadcrumb ol');
  const title = document.getElementById('product-title');
  const price = document.getElementById('product-price');
  const description = document.getElementById('product-description');
  const stockText = document.getElementById('product-stock');
  const sizeSelect = document.getElementById('product-size');
  const quantity = document.getElementById('product-quantity');
  const addButton = document.querySelector('.product-info__add');
  const relatedSection = document.querySelector('.related-products');
  const relatedGrid = document.getElementById('related-products');
  const ui = window.RedStoreUI;

  if (!status || !detail || !mainImage || !ui) return;

  const api = window.RedStoreAPI;
  const helpers = window.RedStore;
  const slug = String(new URLSearchParams(window.location.search).get('slug') || '').trim();

  const setStatus = ({ titleText = '', message, retry = null, link = null } = {}) => {
    status.replaceChildren();
    status.hidden = false;
    status.setAttribute('role', titleText ? 'alert' : 'status');

    if (titleText) {
      const heading = document.createElement('p');
      heading.className = 'product-status__title';
      heading.textContent = titleText;
      status.append(heading);
    }

    const text = document.createElement('p');
    text.textContent = message || '';
    status.append(text);

    if (typeof retry === 'function') {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'products-status__retry';
      button.textContent = 'Try again';
      button.addEventListener('click', retry, { once: true });
      status.append(button);
    }

    if (link) {
      const anchor = document.createElement('a');
      anchor.href = link.href;
      anchor.textContent = link.label;
      const wrapper = document.createElement('p');
      wrapper.append(anchor);
      status.append(wrapper);
    }
  };

  const updateMeta = (product) => {
    document.title = product.name + ' | RedStore';

    const descriptionText = (product.description || 'Shop ' + product.name + ' at RedStore.').slice(0, 160);
    const metaDescription = document.querySelector('meta[name="description"]');
    if (metaDescription) metaDescription.setAttribute('content', descriptionText);

    const ogTitle = document.querySelector('meta[property="og:title"]');
    if (ogTitle) ogTitle.setAttribute('content', product.name + ' | RedStore');

    const ogDescription = document.querySelector('meta[property="og:description"]');
    if (ogDescription) ogDescription.setAttribute('content', descriptionText);

    const imageUrl = product.main_image?.url;
    const ogImage = document.querySelector('meta[property="og:image"]');
    if (ogImage && imageUrl) {
      ogImage.setAttribute('content', new URL(imageUrl, helpers.siteRoot).href);
    }
  };

  const renderBreadcrumb = (product) => {
    if (!breadcrumb) return;
    breadcrumb.replaceChildren();

    const home = document.createElement('li');
    const homeLink = document.createElement('a');
    homeLink.href = new URL('index.html', helpers.siteRoot).href;
    homeLink.textContent = 'Home';
    home.append(homeLink);
    breadcrumb.append(home);

    if (product.category) {
      const category = document.createElement('li');
      const categoryLink = document.createElement('a');
      const params = new URLSearchParams({ category: product.category.slug });
      categoryLink.href = new URL('pages/products.html?' + params.toString(), helpers.siteRoot).href;
      categoryLink.textContent = product.category.name;
      category.append(categoryLink);
      breadcrumb.append(category);
    }

    const current = document.createElement('li');
    current.setAttribute('aria-current', 'page');
    current.textContent = product.name;
    breadcrumb.append(current);
  };

  const renderPrice = (product) => {
    if (!price) return;
    price.replaceChildren();
    price.classList.toggle('product-info__price--sale', product.sale_price !== null && product.sale_price !== undefined && Number(product.sale_price) < Number(product.price));

    const effective = product.sale_price !== null && product.sale_price !== undefined && Number(product.sale_price) < Number(product.price)
      ? product.sale_price
      : product.effective_price ?? product.price;

    price.append(document.createTextNode(helpers.formatPrice(effective, product.currency)));

    const hasSale = product.sale_price !== null && product.sale_price !== undefined && Number(product.sale_price) < Number(product.price);
    if (hasSale) {
      const original = document.createElement('s');
      original.textContent = helpers.formatPrice(product.price, product.currency);
      price.append(original);
    }
  };

  const renderGallery = (product) => {
    const gallery = Array.isArray(product.gallery) ? product.gallery : [];
    const fallback = product.main_image?.url || helpers.placeholderImage;
    mainImage.src = fallback;
    mainImage.alt = product.main_image?.alt || product.name;
    mainImage.addEventListener(
      'error',
      () => {
        mainImage.src = helpers.placeholderImage;
      },
      { once: true }
    );

    if (!thumbs) return;
    thumbs.replaceChildren();

    if (gallery.length <= 1) {
      thumbs.hidden = true;
      return;
    }

    thumbs.hidden = false;
    gallery.forEach((image, index) => {
      const button = document.createElement('button');
      button.className = 'product-gallery__thumb';
      button.type = 'button';
      button.setAttribute('aria-controls', 'product-image');
      button.setAttribute('aria-pressed', String(index === 0));
      button.setAttribute('aria-label', 'Show image ' + (index + 1) + ': ' + (image.alt || product.name));

      const imageEl = document.createElement('img');
      imageEl.src = image.url;
      imageEl.alt = image.alt || product.name;
      imageEl.width = 600;
      imageEl.height = 600;
      imageEl.loading = 'lazy';
      imageEl.decoding = 'async';
      imageEl.addEventListener(
        'error',
        () => {
          imageEl.src = helpers.placeholderImage;
        },
        { once: true }
      );

      button.append(imageEl);
      thumbs.append(button);
    });
  };

  const setStockMessage = (message, outOfStock = false) => {
    if (!stockText) return;
    stockText.textContent = message;
    stockText.classList.toggle('product-info__stock--out', outOfStock);
  };

  const renderVariants = (product) => {
    if (!sizeSelect) return;

    const variants = Array.isArray(product.variants) ? product.variants : [];
    const byId = new Map(variants.map((variant) => [variant.id, variant]));

    sizeSelect.replaceChildren();
    sizeSelect.hidden = variants.length === 0;

    if (variants.length === 0) {
      setStockMessage(
        product.in_stock ? product.stock_quantity + ' in stock' : 'Out of stock',
        !product.in_stock
      );
      if (quantity) quantity.max = String(Math.max(1, product.stock_quantity));
      if (addButton) {
        addButton.disabled = !product.in_stock;
        addButton.textContent = product.in_stock ? 'Add To Cart' : 'Out of Stock';
      }
      return;
    }

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select Size';
    sizeSelect.append(placeholder);

    variants.forEach((variant) => {
      const option = document.createElement('option');
      option.value = variant.id;
      option.disabled = !variant.in_stock;
      option.textContent =
        variant.size +
        (variant.color ? ' – ' + variant.color : '') +
        (!variant.in_stock ? ' (Out of stock)' : '');
      sizeSelect.append(option);
    });

    const updateForVariant = () => {
      const selected = byId.get(sizeSelect.value);
      if (!selected) {
        setStockMessage(product.in_stock ? 'Select a size to see availability.' : 'Out of stock', !product.in_stock);
        if (quantity) quantity.max = String(Math.max(1, product.stock_quantity));
        if (addButton) {
          addButton.disabled = !product.in_stock;
          addButton.textContent = product.in_stock ? 'Add To Cart' : 'Out of Stock';
        }
        return;
      }

      setStockMessage(
        selected.in_stock ? selected.stock_quantity + ' in stock' : 'Out of stock',
        !selected.in_stock
      );
      if (quantity) {
        quantity.max = String(Math.max(1, selected.stock_quantity));
        if (Number(quantity.value) > selected.stock_quantity) {
          quantity.value = String(Math.max(1, selected.stock_quantity));
        }
      }
      if (addButton) {
        addButton.disabled = !selected.in_stock;
        addButton.textContent = selected.in_stock ? 'Add To Cart' : 'Out of Stock';
      }
    };

    sizeSelect.addEventListener('change', updateForVariant);
    updateForVariant();
  };

  const renderRelated = async (product) => {
    if (!relatedSection || !relatedGrid) return;

    if (!product.category?.slug) {
      relatedSection.hidden = true;
      return;
    }

    relatedSection.hidden = false;
    ui.showStatus(relatedGrid, { message: 'Loading related products…' });

    try {
      const result = await api.getProducts({
        category: product.category.slug,
        sort: 'default',
        limit: 5,
        page: 1,
      });

      const related = (result.data || []).filter((item) => item.id !== product.id).slice(0, 4);
      if (related.length === 0) {
        relatedSection.hidden = true;
        return;
      }

      ui.renderProductGrid(relatedGrid, related);
    } catch {
      relatedSection.hidden = true;
    }
  };

  const renderProduct = (product) => {
    if (title) title.textContent = product.name;
    renderPrice(product);
    renderBreadcrumb(product);
    renderGallery(product);
    renderVariants(product);

    if (description) {
      description.textContent = product.description || 'Product details will be available soon.';
    }

    if (quantity) {
      quantity.min = '1';
      quantity.value = '1';
    }

    updateMeta(product);
    status.hidden = true;
    detail.hidden = false;
    renderRelated(product);
  };

  const showNotFound = () => {
    document.title = 'Product Not Found | RedStore';
    detail.hidden = true;
    if (relatedSection) relatedSection.hidden = true;
    setStatus({
      titleText: 'Product not found',
      message: 'The product you are looking for is unavailable or no longer exists.',
      link: {
        href: new URL('pages/products.html', helpers.siteRoot).href,
        label: 'Browse all products',
      },
    });
  };

  const load = async () => {
    detail.hidden = true;
    if (relatedSection) relatedSection.hidden = true;

    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug) || slug.length > 180) {
      showNotFound();
      return;
    }

    setStatus({ message: 'Loading product…' });

    try {
      const product = await api.getProductBySlug(slug);
      renderProduct(product);
    } catch (error) {
      if (error?.status === 404) {
        showNotFound();
        return;
      }

      detail.hidden = true;
      setStatus({
        titleText: 'Unable to load this product',
        message: error?.message || 'Please try again in a moment.',
        retry: load,
        link: {
          href: new URL('pages/products.html', helpers.siteRoot).href,
          label: 'Browse all products',
        },
      });
    }
  };

  if (quantity) {
    quantity.addEventListener('change', () => {
      const max = Number(quantity.max || 1);
      const value = Math.min(max, Math.max(1, Number.parseInt(quantity.value, 10) || 1));
      quantity.value = String(value);
    });
  }

  load();
})();
