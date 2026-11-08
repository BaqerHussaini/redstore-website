/**
 * RedStore – account page.
 * Handles customer login, registration, sign-out and the saved-cart handover.
 */
(() => {
  'use strict';

  const api = window.RedStoreAPI;
  const cart = window.RedStoreCart;
  const card = document.querySelector('.auth-card');
  const accountPanel = document.getElementById('account-panel');
  if (!card || !accountPanel || !api) return;

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

  const loginForm = document.getElementById('login-form');
  const registerForm = document.getElementById('register-form');
  const loginStatus = document.getElementById('login-status');
  const registerStatus = document.getElementById('register-status');
  const accountName = document.getElementById('account-name');
  const accountEmail = document.getElementById('account-email');
  const logoutButton = document.getElementById('logout-button');

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

  const setMessage = (element, message) => {
    if (!element) return;
    element.textContent = message || '';
    element.hidden = !message;
    element.classList.toggle('is-error', Boolean(message));
  };

  const setBusy = (form, busy) => {
    if (!form) return;
    form.setAttribute('aria-busy', String(busy));
    form.querySelectorAll('input, button').forEach((control) => {
      control.disabled = busy;
    });
  };

  const renderSession = (user) => {
    if (user) {
      card.hidden = true;
      accountPanel.hidden = false;
      if (accountName) accountName.textContent = user.full_name || 'RedStore customer';
      if (accountEmail) accountEmail.textContent = user.email || '';
      return;
    }

    card.hidden = false;
    accountPanel.hidden = true;
    if (accountName) accountName.textContent = '';
    if (accountEmail) accountEmail.textContent = '';
  };

  const afterSignIn = async (user) => {
    renderSession(user);
    setMessage(loginStatus, '');
    setMessage(registerStatus, '');

    if (cart) {
      try {
        await cart.mergeGuestCart();
      } catch (error) {
        cart.notify(error?.message || 'Your guest cart could not be merged.', 'error');
      }
    }
  };

  Object.entries(views).forEach(([name, view]) => {
    view.tab.addEventListener('click', () => show(name));
  });

  const showLoginLink = document.getElementById('show-login');
  if (showLoginLink) {
    showLoginLink.addEventListener('click', (event) => {
      event.preventDefault();
      show('login', true);
    });
  }

  if (tabs) {
    tabs.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;

      event.preventDefault();
      const current = card.classList.contains('is-login') ? 'login' : 'register';
      show(current === 'login' ? 'register' : 'login', true);
    });
  }

  if (registerForm) {
    registerForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      setMessage(registerStatus, '');

      const fullName = registerForm.elements.full_name?.value?.trim() || '';
      const email = registerForm.elements.email?.value?.trim() || '';
      const password = registerForm.elements.password?.value || '';

      if (!email) {
        setMessage(registerStatus, 'Enter your email address.');
        return;
      }
      if (password.length < 8) {
        setMessage(registerStatus, 'The password must be at least 8 characters long.');
        return;
      }

      setBusy(registerForm, true);
      try {
        const result = await api.register(fullName, email, password);
        await afterSignIn(result.data?.user);
        registerForm.reset();
        cart?.notify('Account created. You are signed in.', 'success');
      } catch (error) {
        setMessage(registerStatus, error?.message || 'Could not create your account.');
      } finally {
        setBusy(registerForm, false);
      }
    });
  }

  if (loginForm) {
    loginForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      setMessage(loginStatus, '');

      const email = loginForm.elements.email?.value?.trim() || '';
      const password = loginForm.elements.password?.value || '';

      if (!email || !password) {
        setMessage(loginStatus, 'Enter your email and password.');
        return;
      }

      setBusy(loginForm, true);
      try {
        const result = await api.login(email, password);
        await afterSignIn(result.data?.user);
        loginForm.reset();
        cart?.notify('Signed in. Your saved cart is ready.', 'success');
      } catch (error) {
        setMessage(loginStatus, error?.message || 'Could not sign in.');
      } finally {
        setBusy(loginForm, false);
      }
    });
  }

  if (logoutButton) {
    logoutButton.addEventListener('click', async () => {
      logoutButton.disabled = true;

      try {
        await api.logout();
        if (cart) await cart.refreshSession();
        renderSession(null);
        show('login');
        cart?.notify('You have been signed out.', 'info');
      } catch (error) {
        cart?.notify(error?.message || 'Could not sign out.', 'error');
      } finally {
        logoutButton.disabled = false;
      }
    });
  }

  show('register');

  (async () => {
    try {
      const session = await api.getSession();
      renderSession(session?.data?.user || null);
    } catch {
      renderSession(null);
    }
  })();
})();
