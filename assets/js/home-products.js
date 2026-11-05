/**
 * RedStore – homepage product sections.
 * Featured and latest products come from the PHP API.
 */
(() => {
  'use strict';

  const featuredGrid = document.getElementById('featured-products');
  const latestGrid = document.getElementById('latest-products');
  const ui = window.RedStoreUI;

  if (!featuredGrid || !latestGrid || !ui) return;

  const loadSection = async (grid, loader, emptyMessage) => {
    grid.setAttribute('aria-busy', 'true');
    ui.showStatus(grid, { message: 'Loading products…' });

    try {
      const result = await loader();
      ui.renderProductGrid(grid, result.data, { emptyMessage });
    } catch (error) {
      ui.showStatus(grid, {
        type: 'alert',
        message: error?.message || 'Products could not be loaded.',
        retry: () => loadSection(grid, loader, emptyMessage),
      });
    } finally {
      grid.setAttribute('aria-busy', 'false');
    }
  };

  loadSection(
    featuredGrid,
    () => window.RedStoreAPI.getFeaturedProducts({ limit: 4 }),
    'No featured products are available right now.'
  );

  loadSection(
    latestGrid,
    () => window.RedStoreAPI.getLatestProducts({ limit: 8 }),
    'No products are available right now.'
  );
})();
