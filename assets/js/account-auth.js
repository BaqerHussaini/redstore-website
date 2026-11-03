/**
 * RedStore – account auth tabs
 * Handles the login/register card switching on the account page.
 */
(() => {
  'use strict';

  const card = document.querySelector('.auth-card');
  if (!card) return;

  const tabs = card.querySelector('.auth-card__tabs');
  const views = {
    login: {
      tab: document.getElementById('login-tab'),
      panel: document.getElementById('login-form'),
    },
    register: {
      tab: document.getElementById('register-tab'),
      panel: document.getElementById('register-form'),
    },
  };

  const show = (name, moveFocus = false) => {
    Object.entries(views).forEach(([viewName, view]) => {
      const isActive = viewName === name;

      view.tab.setAttribute('aria-selected', String(isActive));
      view.tab.tabIndex = isActive ? 0 : -1;
      view.panel.inert = !isActive;
      view.panel.setAttribute('aria-hidden', String(!isActive));

      if (isActive && moveFocus) view.tab.focus();
    });

    card.classList.toggle('is-login', name === 'login');
  };

  Object.entries(views).forEach(([name, view]) => {
    view.tab.addEventListener('click', () => show(name));
  });

  const showLoginLink = document.getElementById('show-login');
  showLoginLink.addEventListener('click', (event) => {
    event.preventDefault();
    show('login', true);
  });

  tabs.addEventListener('keydown', (event) => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;

    event.preventDefault();
    const current = card.classList.contains('is-login') ? 'login' : 'register';
    show(current === 'login' ? 'register' : 'login', true);
  });

  show('register');
})();
