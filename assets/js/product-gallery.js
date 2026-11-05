/**
 * RedStore – product details gallery
 * Swaps the main product image when a thumbnail is selected.
 * Uses event delegation so dynamically rendered thumbnails work too.
 */
(() => {
  'use strict';

  const gallery = document.querySelector('.product-gallery');
  if (!gallery) return;

  const mainImage = gallery.querySelector('.product-gallery__main');
  const thumbs = gallery.querySelector('.product-gallery__thumbs');
  if (!mainImage || !thumbs) return;

  thumbs.addEventListener('click', (event) => {
    const thumb = event.target.closest('.product-gallery__thumb');
    if (!thumb || !thumbs.contains(thumb)) return;

    const image = thumb.querySelector('img');
    if (!image) return;

    mainImage.src = image.src;
    mainImage.alt = image.alt;

    thumbs.querySelectorAll('.product-gallery__thumb').forEach((item) => {
      item.setAttribute('aria-pressed', String(item === thumb));
    });
  });
})();
