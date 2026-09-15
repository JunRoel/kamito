/* =========================================================
   KAMITO — Auth page logic
   - Tab switching (sign in / create account)
   - Password show/hide
   - Login and registration
   ========================================================= */
(function () {
  'use strict';

  const csrf = document.body.dataset.csrf || '';
  const initialMode = document.body.dataset.initialMode || 'login';

  const tabs = Array.from(document.querySelectorAll('.auth-tab'));

  const panels = {
    login: document.getElementById('loginPanel'),
    register: document.getElementById('registerPanel'),
  };

  /* ---------------- tab switching ---------------- */

  function setMode(mode) {
    if (!panels[mode]) return;

    Object.keys(panels).forEach((key) => {
      panels[key].hidden = key !== mode;
    });

    tabs.forEach((tab) => {
      tab.setAttribute(
        'aria-selected',
        String(tab.dataset.mode === mode)
      );
    });
  }

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      setMode(tab.dataset.mode);
    });
  });

  document.querySelectorAll('[data-switch]').forEach((btn) => {
    btn.addEventListener('click', () => {
      setMode(btn.dataset.switch);
    });
  });

  setMode(initialMode);

  /* ---------------- password show/hide ---------------- */

  document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = btn.parentElement.querySelector('input');

      if (!input) return;

      const show = input.type === 'password';

      input.type = show ? 'text' : 'password';

      btn.textContent = show ? 'Hide' : 'Show';

      btn.setAttribute(
        'aria-label',
        show ? 'Hide password' : 'Show password'
      );
    });
  });

  /* ---------------- messages ---------------- */

  function showMessage(el, text, type) {
    if (!el) return;

    el.textContent = text;
    el.className = 'form-message ' + type;
  }

  /* ---------------- POST JSON ---------------- */

  async function postJSON(url, payload) {
    const res = await fetch(url, {
      method: 'POST',

      headers: {
        'Content-Type': 'application/json'
      },

      body: JSON.stringify(
        Object.assign(
          { csrf: csrf },
          payload
        )
      )
    });

    const text = await res.text();

    try {
      return JSON.parse(text);

    } catch (err) {

      if (res.status === 404) {
        throw new Error(
          'File not found (404): ' +
          url +
          ' — check that the PHP file exists.'
        );
      }

      if (res.status >= 500) {
        throw new Error(
          'Server error (HTTP ' +
          res.status +
          ') in ' +
          url +
          ' — check C:\\xampp\\php\\logs\\php_error_log.'
        );
      }

      throw new Error(
        'Server sent an invalid response (HTTP ' +
        res.status +
        ').'
      );
    }
  }

  /* ---------------- network errors ---------------- */

  function networkError(err) {
    if (err instanceof TypeError) {
      return 'Could not reach the server. Make sure the page is opened through http://localhost and Apache is running.';
    }

    return err.message ||
      'Something went wrong. Please try again.';
  }

  const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  /* =========================================================
     LOGIN
     ========================================================= */

  const loginForm = document.getElementById('loginForm');
  const loginMessage = document.getElementById('loginMessage');
  const loginSubmit = document.getElementById('loginSubmit');

  if (loginForm) {

    loginForm.addEventListener('submit', async (e) => {

      e.preventDefault();

      showMessage(loginMessage, '', '');

      const email =
        document.getElementById('loginEmail').value.trim();

      const password =
        document.getElementById('loginPassword').value;

      /* Validate email */

      if (!emailRe.test(email)) {
        return showMessage(
          loginMessage,
          'Please enter a valid email address.',
          'error'
        );
      }

      /* Validate password */

      if (!password) {
        return showMessage(
          loginMessage,
          'Please enter your password.',
          'error'
        );
      }

      loginSubmit.disabled = true;
      loginSubmit.textContent = 'Signing in…';

      try {

        /*
         * IMPORTANT:
         * login.php and login_handler.php
         * are in the same /php/ folder.
         */
        const data = await postJSON(
          'login_handler.php',
          {
            email: email,
            password: password
          }
        );

        if (data.success) {

          window.location.href =
            data.redirect || 'account.php';

        } else {

          showMessage(
            loginMessage,
            data.message ||
              'Sign-in failed. Please try again.',
            'error'
          );
        }

      } catch (err) {

        showMessage(
          loginMessage,
          networkError(err),
          'error'
        );

      } finally {

        loginSubmit.disabled = false;
        loginSubmit.textContent = 'Sign in';

      }
    });
  }

  /* =========================================================
     REGISTER
     ========================================================= */

  const registerForm =
    document.getElementById('registerForm');

  const registerMessage =
    document.getElementById('registerMessage');

  const registerSubmit =
    document.getElementById('registerSubmit');

  if (registerForm) {

    registerForm.addEventListener('submit', async (e) => {

      e.preventDefault();

      showMessage(
        registerMessage,
        '',
        ''
      );

      const payload = {

        full_name:
          document.getElementById('regName')
            .value.trim(),

        email:
          document.getElementById('regEmail')
            .value.trim(),

        password:
          document.getElementById('regPassword')
            .value,

        confirm_password:
          document.getElementById('regConfirm')
            .value
      };

      /* Validate name */

      if (payload.full_name.length < 2) {
        return showMessage(
          registerMessage,
          'Please enter your full name.',
          'error'
        );
      }

      /* Validate email */

      if (!emailRe.test(payload.email)) {
        return showMessage(
          registerMessage,
          'Please enter a valid email address.',
          'error'
        );
      }

      /* Validate password */

      if (payload.password.length < 8) {
        return showMessage(
          registerMessage,
          'Password must be at least 8 characters.',
          'error'
        );
      }

      /* Confirm password */

      if (
        payload.password !==
        payload.confirm_password
      ) {
        return showMessage(
          registerMessage,
          'Passwords do not match.',
          'error'
        );
      }

      registerSubmit.disabled = true;
      registerSubmit.textContent = 'Creating account…';

      try {

        /*
         * IMPORTANT:
         * login.php and register_handler.php
         * are in the same /php/ folder.
         */
        const data = await postJSON(
          'register_handler.php',
          payload
        );

        if (data.success) {

          showMessage(
            registerMessage,
            data.message || 'Account created.',
            'success'
          );

          registerForm.reset();

          // Account is 'pending' and the user is NOT signed in — redirecting
          // to account.php would bounce them to login.php and erase this
          // message, so we stay on this page and just show the success text.
          if (data.redirect) {
            window.location.href = data.redirect;
          }

        } else {

          showMessage(
            registerMessage,
            data.message ||
              'Could not create your account.',
            'error'
          );
        }

      } catch (err) {

        showMessage(
          registerMessage,
          networkError(err),
          'error'
        );

      } finally {

        registerSubmit.disabled = false;
        registerSubmit.textContent =
          'Create account';

      }
    });
  }

})();