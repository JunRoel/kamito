/**
 * KAMITO — site scripts
 * Handles: nav toggle, scroll header state, animated stat counters,
 * pre-order modal (open/close/submit), newsletter signup.
 */
(function () {
  'use strict';

  /* ============ NAV TOGGLE (mobile menu) ============ */
  var navToggle = document.getElementById('navToggle');
  var mainNav   = document.getElementById('mainNav');

  if (navToggle && mainNav) {
    navToggle.addEventListener('click', function () {
      var isOpen = mainNav.classList.toggle('is-open');
      navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }

  /* ============ HEADER SHADOW ON SCROLL ============ */
  var siteHeader = document.getElementById('siteHeader');
  if (siteHeader) {
    window.addEventListener('scroll', function () {
      siteHeader.classList.toggle('is-scrolled', window.scrollY > 8);
    });
  }

  /* ============ ANIMATED STAT COUNTERS ============ */
  var counters = document.querySelectorAll('.counter');
  if (counters.length) {
    var animateCounter = function (el) {
      var target   = parseFloat(el.dataset.count);
      var decimals = parseInt(el.dataset.decimals || '0', 10);
      var duration = 1200;
      var start    = performance.now();

      function tick(now) {
        var progress = Math.min((now - start) / duration, 1);
        var value = target * progress;
        el.textContent = decimals ? value.toFixed(decimals) : Math.round(value);
        if (progress < 1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
    };

    var counterObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          animateCounter(entry.target);
          counterObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.5 });

    counters.forEach(function (el) { counterObserver.observe(el); });
  }

  /* ============ PRE-ORDER MODAL ============ */
  var preorderOverlay = document.getElementById('preorderOverlay');
  var preorderForm    = document.getElementById('preorderForm');
  var preorderMessage = document.getElementById('preorderMessage');
  var preorderSubmit  = document.getElementById('preorderSubmit');
  var preorderClose   = document.getElementById('preorderClose');
  var openTriggers    = document.querySelectorAll('[data-open-preorder]');
  var emailRe         = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  var lastFocusedEl   = null;

  function showPreorderMessage(text, type) {
    if (!preorderMessage) return;
    preorderMessage.textContent = text;
    preorderMessage.className = 'form-message ' + type;
  }

  function openPreorderModal() {
    if (!preorderOverlay) return;
    showPreorderMessage('', '');
    lastFocusedEl = document.activeElement;
    preorderOverlay.classList.add('is-open');
    document.body.classList.add('modal-open');
    var firstField = document.getElementById('fullName');
    if (firstField) firstField.focus();
  }

  function closePreorderModal() {
    if (!preorderOverlay) return;
    preorderOverlay.classList.remove('is-open');
    document.body.classList.remove('modal-open');
    if (lastFocusedEl) lastFocusedEl.focus();
  }

  openTriggers.forEach(function (btn) {
    btn.addEventListener('click', openPreorderModal);
  });

  if (preorderClose) {
    preorderClose.addEventListener('click', closePreorderModal);
  }

  if (preorderOverlay) {
    preorderOverlay.addEventListener('click', function (e) {
      if (e.target === preorderOverlay) closePreorderModal();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && preorderOverlay && preorderOverlay.classList.contains('is-open')) {
      closePreorderModal();
    }
  });

  if (preorderForm) {
    preorderForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      showPreorderMessage('', '');

      var csrfField    = document.getElementById('preorderCsrf');
      var paddleIdField = document.getElementById('preorderPaddleId');

      var payload = {
        csrf:       csrfField ? csrfField.value : '',
        paddle_id:  parseInt(paddleIdField ? paddleIdField.value : '0', 10),
        full_name:  document.getElementById('fullName').value.trim(),
        email:      document.getElementById('email').value.trim(),
        phone:      document.getElementById('phone').value.trim(),
        grip_size:  document.getElementById('gripSize').value,
        quantity:   parseInt(document.getElementById('quantity').value, 10),
        notes:      (document.getElementById('notes') || {}).value || ''
      };

      if (!payload.csrf) {
        return showPreorderMessage('Security token missing. Please reload the page.', 'error');
      }
      if (!payload.paddle_id) {
        return showPreorderMessage('Please choose a paddle.', 'error');
      }
      if (payload.full_name.length < 2) {
        return showPreorderMessage('Please enter your full name.', 'error');
      }
      if (!emailRe.test(payload.email)) {
        return showPreorderMessage('Please enter a valid email address.', 'error');
      }
      if (!(payload.quantity >= 1 && payload.quantity <= 10)) {
        return showPreorderMessage('Quantity must be between 1 and 10.', 'error');
      }

      preorderSubmit.disabled = true;
      preorderSubmit.textContent = 'Placing pre-order…';

      try {
        var res = await fetch('php/preorder_handler.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        var text = await res.text();
        var data;

        try {
          data = JSON.parse(text);
        } catch (err) {
          throw new Error(res.status === 404
            ? 'File not found (404): php/preorder_handler.php — check the path.'
            : 'Server error (HTTP ' + res.status + ').');
        }

        if (data.success) {
          showPreorderMessage(data.message || 'Pre-order placed.', 'success');
          preorderForm.reset();
          setTimeout(closePreorderModal, 2500);
        } else {
          showPreorderMessage(data.message || 'Could not place your pre-order.', 'error');
        }
      } catch (err) {
        showPreorderMessage(err instanceof TypeError
          ? 'Could not reach the server. Make sure Apache is running.'
          : err.message, 'error');
      } finally {
        preorderSubmit.disabled = false;
        preorderSubmit.textContent = 'Confirm Pre-Order';
      }
    });
  }

  /* ============ NEWSLETTER SIGNUP ============ */
  var newsletterForm    = document.getElementById('newsletterForm');
  var newsletterEmail   = document.getElementById('newsletterEmail');
  var newsletterMessage = document.getElementById('newsletterMessage');

  if (newsletterForm) {
    newsletterForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      var email = newsletterEmail.value.trim();

      if (!emailRe.test(email)) {
        newsletterMessage.textContent = 'Please enter a valid email address.';
        newsletterMessage.className = 'form-message error';
        return;
      }

      var submitBtn = newsletterForm.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;

      try {
        var res = await fetch('php/newsletter_handler.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: email, source: 'footer' })
        });

        var data = await res.json();

        newsletterMessage.textContent = data.message || (data.success ? 'You are on the list.' : 'Could not subscribe.');
        newsletterMessage.className = 'form-message ' + (data.success ? 'success' : 'error');

        if (data.success) newsletterForm.reset();
      } catch (err) {
        newsletterMessage.textContent = 'Could not reach the server. Make sure Apache is running.';
        newsletterMessage.className = 'form-message error';
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

})();