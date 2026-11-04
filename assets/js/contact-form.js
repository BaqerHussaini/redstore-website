/**
 * RedStore – contact form
 * Client-side validation and mock submission for the contact page.
 */
(() => {
  'use strict';

  const form = document.getElementById('contact-form');
  if (!form) return;

  const status = document.getElementById('form-status');
  const submitButton = form.querySelector('[type="submit"]');
  const fields = Array.from(form.querySelectorAll('input, select, textarea'));

  const setStatus = (message, state = '') => {
    status.textContent = message;
    status.classList.toggle('is-success', state === 'success');
    status.classList.toggle('is-error', state === 'error');
  };

  const showError = (field, message) => {
    const error = document.getElementById(`${field.id}-error`);
    if (message) {
      field.setAttribute('aria-invalid', 'true');
    } else {
      field.removeAttribute('aria-invalid');
    }
    if (error) error.textContent = message;
  };

  const validateField = (field) => {
    const value = field.value.trim();
    let message = '';

    if (field.required && value === '') {
      message = field.dataset.error || 'This field is required.';
    } else if (field.type === 'email' && field.validity.typeMismatch) {
      message = 'Enter a valid email address.';
    } else if (field.minLength > 0 && value.length > 0 && value.length < field.minLength) {
      message = `Please use at least ${field.minLength} characters.`;
    }

    showError(field, message);
    return message === '';
  };

  const mockSubmit = (payload) =>
    new Promise((resolve) => {
      console.info('[RedStore] Contact form submission', payload);
      window.setTimeout(resolve, 800);
    });

  fields.forEach((field) => {
    field.addEventListener('blur', () => validateField(field));
    field.addEventListener('input', () => {
      if (field.getAttribute('aria-invalid') === 'true') validateField(field);
    });
    field.addEventListener('change', () => validateField(field));
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const results = fields.map(validateField);
    const invalidIndex = results.indexOf(false);

    if (invalidIndex !== -1) {
      setStatus('Please review the highlighted fields and try again.', 'error');
      fields[invalidIndex].focus();
      return;
    }

    const payload = Object.fromEntries(new FormData(form).entries());

    submitButton.disabled = true;
    form.setAttribute('aria-busy', 'true');
    setStatus('Sending your message…');

    try {
      await mockSubmit(payload);
      const firstName = payload.name.trim().split(/\s+/)[0];
      form.reset();
      fields.forEach((field) => showError(field, ''));
      setStatus(`Thanks, ${firstName}. Your message is on its way — we'll reply within one business day.`, 'success');
    } catch {
      setStatus('Something went wrong. Please try again or email support@redstore.com.', 'error');
    } finally {
      submitButton.disabled = false;
      form.removeAttribute('aria-busy');
    }
  });
})();
