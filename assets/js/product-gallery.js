/**
 * RedStore – product details gallery
 * Swaps the main product image when a thumbnail is selected.
 */
(() => {
  'use strict';

  const gallery = document.querySelector('.product-gallery');
  if (!gallery) return;

  const mainImage = gallery.querySelector('.product-gallery__main');
  const thumbs = Array.from(gallery.querySelectorAll('.product-gallery__thumb'));
  if (!mainImage || !thumbs.length) return;

  const select = (thumb) => {
    const image = thumb.querySelector('img');
    if (!image) return;

    mainImage.src = image.src;
    mainImage.alt = image.alt;

    thumbs.forEach((item) => {
      item.setAttribute('aria-pressed', String(item === thumb));
    });
  };

  thumbs.forEach((thumb) => {
    thumb.addEventListener('click', () => select(thumb));
  });
})();
