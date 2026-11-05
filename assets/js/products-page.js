/**
 * RedStore – product listing page.
 * Handles category filters, search, sorting, pagination and URL state.
 */
(() => {
  'use strict';

  const grid = document.getElementById('products-grid');
  const form = document.getElementById('products-filters');
  const searchInput = document.getElementById('product-search');
  const categorySelect = document.getElementById('category-filter');
  const sortSelect = document.getElementById('sort');
  const summary = document.getElementById('products-summary');
  const pagination = document.getElementById('pagination');
  const paginationList = pagination?.querySelector('ul');
  const ui = window.RedStoreUI;

  if (!grid || !form || !searchInput || !categorySelect || !sortSelect || !ui || !paginationList) {
    return;
  }

  const SORTS = ['default', 'featured', 'newest', 'price_asc', 'price_desc', 'name_asc', 'name_desc'];
  const DEFAULT_LIMIT = 12;

  const cleanCategory = (value) => {
    const category = String(value || '').trim().toLowerCase();
    return /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(category) && category.length <= 100 ? category : '';
  };

  const cleanSort = (value) => {
    const sort = String(value || '').trim().toLowerCase();
    return SORTS.includes(sort) ? sort : 'default';
  };

  const cleanPage = (value) => {
    const page = Number.parseInt(String(value || '1'), 10);
    return Number.isFinite(page) && page > 0 ? Math.min(page, 100000) : 1;
  };

  const readState = () => {
    const params = new URLSearchParams(window.location.search);
    return {
      search: String(params.get('search') || '').trim().slice(0, 100),
      category: cleanCategory(params.get('category')),
      sort: cleanSort(params.get('sort')),
      page: cleanPage(params.get('page')),
    };
  };

  let state = readState();
  let searchTimer = null;
  let loadSequence = 0;

  const buildSearchParams = () => {
    const params = new URLSearchParams();
    if (state.search) params.set('search', state.search);
    if (state.category) params.set('category', state.category);
    if (state.sort !== 'default') params.set('sort', state.sort);
    if (state.page > 1) params.set('page', String(state.page));
    return params;
  };

  const writeUrl = (mode = 'push') => {
    const params = buildSearchParams();
    const query = params.toString();
    const url = window.location.pathname + (query ? '?' + query : '') + window.location.hash;
    if (url === window.location.pathname + window.location.search + window.location.hash) return;
    window.history[mode === 'replace' ? 'replaceState' : 'pushState']({ page: state.page }, '', url);
  };

  const syncControls = () => {
    searchInput.value = state.search;
    sortSelect.value = state.sort;
    categorySelect.value = state.category;
  };

  const pageHref = (page) => {
    const params = buildSearchParams();
    if (page > 1) params.set('page', String(page));
    else params.delete('page');
    const query = params.toString();
    return window.location.pathname + (query ? '?' + query : '');
  };

  const addPageItem = (label, page, { current = false, ariaLabel = null, text = false } = {}) => {
    const item = document.createElement('li');

    if (text) {
      const span = document.createElement('span');
      span.textContent = label;
      span.setAttribute('aria-hidden', 'true');
      item.append(span);
      return item;
    }

    const link = document.createElement('a');
    link.href = pageHref(page);
    link.textContent = label;
    if (ariaLabel) link.setAttribute('aria-label', ariaLabel);
    if (current) link.setAttribute('aria-current', 'page');

    link.addEventListener('click', (event) => {
      event.preventDefault();
      state.page = page;
      writeUrl('push');
      loadProducts();
      document.getElementById('products-title')?.scrollIntoView({ block: 'start' });
    });

    item.append(link);
    return item;
  };

  const renderPagination = (meta) => {
    paginationList.replaceChildren();

    if (!meta || meta.total_pages <= 1) {
      pagination.hidden = true;
      return;
    }

    pagination.hidden = false;
    const total = meta.total_pages;
    const current = meta.page;

    if (meta.has_previous) {
      paginationList.append(addPageItem('←', current - 1, { ariaLabel: 'Previous page' }));
    }

    const pages = [];
    if (total <= 7) {
      for (let page = 1; page <= total; page += 1) pages.push(page);
    } else {
      pages.push(1);
      if (current > 3) pages.push('…');
      const start = Math.max(2, current - 1);
      const end = Math.min(total - 1, current + 1);
      for (let page = start; page <= end; page += 1) pages.push(page);
      if (current < total - 2) pages.push('…');
      pages.push(total);
    }

    pages.forEach((page) => {
      if (page === '…') {
        paginationList.append(addPageItem('…', current, { text: true }));
      } else {
        paginationList.append(addPageItem(String(page), page, { current: page === current }));
      }
    });

    if (meta.has_next) {
      paginationList.append(addPageItem('→', current + 1, { ariaLabel: 'Next page' }));
    }
  };

  const loadProducts = async () => {
    const sequence = ++loadSequence;

    grid.setAttribute('aria-busy', 'true');
    ui.showStatus(grid, { message: 'Loading products…' });
    if (summary) summary.textContent = '';
    pagination.hidden = true;

    try {
      const result = await window.RedStoreAPI.getProducts({
        search: state.search,
        category: state.category,
        sort: state.sort,
        page: state.page,
        limit: DEFAULT_LIMIT,
      });

      if (sequence !== loadSequence) return;

      const meta = result.meta;
      if (meta && meta.page !== state.page && meta.page > 0) {
        state.page = meta.page;
        writeUrl('replace');
      }

      if (meta && result.data.length === 0 && meta.total_pages > 0 && state.page > meta.total_pages) {
        state.page = meta.total_pages;
        writeUrl('replace');
        await loadProducts();
        return;
      }

      ui.renderProductGrid(grid, result.data, {
        emptyMessage: state.search
          ? 'No products matched your search.'
          : 'No products are available in this category.',
      });

      if (summary && meta) {
        if (meta.total === 0) {
          summary.textContent = '0 products';
        } else {
          const first = (meta.page - 1) * meta.limit + 1;
          const last = Math.min(meta.page * meta.limit, meta.total);
          summary.textContent = 'Showing ' + first + '–' + last + ' of ' + meta.total + ' products';
        }
      }

      renderPagination(meta);
    } catch (error) {
      if (sequence !== loadSequence) return;

      ui.showStatus(grid, {
        type: 'alert',
        message: error?.message || 'Products could not be loaded.',
        retry: loadProducts,
      });
      if (summary) summary.textContent = '';
      pagination.hidden = true;
    } finally {
      if (sequence === loadSequence) {
        grid.setAttribute('aria-busy', 'false');
      }
    }
  };

  const populateCategories = async () => {
    try {
      const result = await window.RedStoreAPI.getCategories();
      categorySelect.replaceChildren();

      const all = document.createElement('option');
      all.value = '';
      all.textContent = 'All Categories';
      categorySelect.append(all);

      (result.data || []).forEach((category) => {
        const option = document.createElement('option');
        option.value = category.slug;
        option.textContent = category.name + ' (' + category.product_count + ')';
        categorySelect.append(option);
      });

      if (state.category && !Array.from(categorySelect.options).some((option) => option.value === state.category)) {
        const option = document.createElement('option');
        option.value = state.category;
        option.textContent = state.category;
        categorySelect.append(option);
      }

      categorySelect.value = state.category;
    } catch {
      // The product request will surface a useful error state if the API is down.
    }
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    state.search = searchInput.value.trim().slice(0, 100);
    state.page = 1;
    writeUrl('push');
    loadProducts();
  });

  categorySelect.addEventListener('change', () => {
    state.category = cleanCategory(categorySelect.value);
    state.page = 1;
    writeUrl('push');
    loadProducts();
  });

  sortSelect.addEventListener('change', () => {
    state.sort = cleanSort(sortSelect.value);
    state.page = 1;
    writeUrl('push');
    loadProducts();
  });

  searchInput.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
      state.search = searchInput.value.trim().slice(0, 100);
      state.page = 1;
      writeUrl('replace');
      loadProducts();
    }, 350);
  });

  window.addEventListener('popstate', () => {
    state = readState();
    syncControls();
    loadProducts();
  });

  syncControls();
  populateCategories();
  loadProducts();
})();
