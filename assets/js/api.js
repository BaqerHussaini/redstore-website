/**
 * RedStore – shared API client.
 * All product data is fetched from the PHP API, never directly from Supabase.
 */
(() => {
  'use strict';

  const currentScript =
    document.currentScript ||
    Array.from(document.scripts).find((script) => /\/assets\/js\/api\.js(?:\?|$)/.test(script.src));

  const scriptUrl = currentScript
    ? new URL(currentScript.src, document.baseURI)
    : new URL('assets/js/api.js', document.baseURI);

  const siteRoot = new URL('../../', scriptUrl);
  const apiBase = new URL('../../api', scriptUrl).href.replace(/\/+$/, '');

  class ApiError extends Error {
    constructor(message, status = 0, code = 'request_failed', details = null) {
      super(message);
      this.name = 'ApiError';
      this.status = status;
      this.code = code;
      this.details = details;
    }
  }

  const buildUrl = (path, params = {}) => {
    const url = new URL(apiBase + path);
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return;
      url.searchParams.set(key, String(value));
    });
    return url.toString();
  };

  const request = async (path, params = {}) => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 15000);

    let response;
    try {
      response = await fetch(buildUrl(path, params), {
        method: 'GET',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: controller.signal,
      });
    } catch (error) {
      if (error?.name === 'AbortError') {
        throw new ApiError('The request timed out. Please try again.', 0, 'timeout');
      }
      throw new ApiError('Unable to reach the store API. Please try again.', 0, 'network_error');
    } finally {
      window.clearTimeout(timer);
    }

    let payload;
    try {
      payload = await response.json();
    } catch {
      throw new ApiError('The store API returned an invalid response.', response.status, 'invalid_response');
    }

    if (!response.ok || payload?.success === false) {
      const apiError = payload?.error || {};
      throw new ApiError(
        apiError.message || 'The store API could not complete the request.',
        response.status,
        apiError.code || 'request_failed',
        apiError.details || null
      );
    }

    return {
      data: payload.data,
      meta: payload.meta || null,
    };
  };

  const getProducts = (params = {}) => request('/products', params);
  const getFeaturedProducts = (params = {}) => request('/products/featured', params);
  const getLatestProducts = (params = {}) => request('/products/latest', params);
  const getCategories = () => request('/categories');

  const getProductBySlug = async (slug) => {
    const cleanSlug = String(slug || '').trim();
    if (!cleanSlug) throw new ApiError('A product slug is required.', 400, 'invalid_slug');
    const result = await request('/products/' + encodeURIComponent(cleanSlug));
    return result.data;
  };

  const productDetailsUrl = (slug) =>
    new URL('pages/product-details.html?slug=' + encodeURIComponent(slug), siteRoot).href;

  const formatPrice = (value, currency = 'USD') => {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '';
    try {
      return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency || 'USD',
      }).format(amount);
    } catch {
      return '$' + amount.toFixed(2);
    }
  };

  window.RedStore = {
    siteRoot: siteRoot.href,
    apiBase,
    placeholderImage: new URL('assets/images/products/placeholder.svg', siteRoot).href,
    productDetailsUrl,
    formatPrice,
    ApiError,
  };

  window.RedStoreAPI = {
    getProducts,
    getProductBySlug,
    getFeaturedProducts,
    getLatestProducts,
    getCategories,
  };
})();
