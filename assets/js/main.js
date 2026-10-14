/**
 * RedStore – main script
 * Handles the mobile navigation toggle and the footer year.
 */
(() => {
  'use strict';

  const toggle = document.querySelector('.nav-toggle');
  const list = document.getElementById('nav-list');
  const desktopQuery = window.matchMedia('(min-width: 768px)');

  const setMenu = (open) => {
    list.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  };

  if (toggle && list) {
    toggle.addEventListener('click', () => {
      setMenu(toggle.getAttribute('aria-expanded') !== 'true');
    });

    // Close after choosing a link
    list.addEventListener('click', (event) => {
      if (event.target.closest('a')) setMenu(false);
    });

    // Close with Escape and return focus to the button
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        setMenu(false);
        toggle.focus();
      }
    });

    // Reset when resizing up to the desktop layout
    desktopQuery.addEventListener('change', (event) => {
      if (event.matches) setMenu(false);
    });
  }

  const year = document.getElementById('year');
  if (year) year.textContent = new Date().getFullYear();
})();
