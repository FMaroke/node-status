/**
 * FMCE Node Status - page behaviour: peer table toggle, dark/light switch,
 * back-to-top button and cookie notice.
 *
 * Copyright 2026 FMCE Concept UG - SPDX-License-Identifier: Apache-2.0
 * CoAuthor: Claude / Claude Code - Opus 5.5
 */
(() => {
  'use strict';

  /* ---------- Show / hide the peer table ---------- */
  const peersLink = document.getElementById('peerslink');
  const peersPanel = document.getElementById('peerstable');
  if (peersLink && peersPanel) {
    peersLink.addEventListener('click', () => {
      const hide = !peersPanel.hidden;
      peersPanel.hidden = hide;
      peersLink.textContent = hide ? 'Show' : 'Hide';
      peersLink.setAttribute('aria-expanded', String(!hide));
    });
  }

  /* ---------- Dark/Light switch ----------
     The theme is rendered server-side (CSS tokens, chart scripts, node-link
     images), so the choice is stored in a cookie and the page is reloaded.
     The button shows the theme it switches to. */
  const themeBtn = document.getElementById('themeBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', () => {
      const next = themeBtn.dataset.themeCurrent === 'dark' ? 'light' : 'dark';
      const secure = location.protocol === 'https:' ? '; Secure' : '';
      document.cookie = 'theme=' + next + '; Max-Age=31536000; Path=/; SameSite=Lax' + secure;
      location.reload();
    });
  }

  /* ---------- Back-to-top ---------- */
  const topBtn = document.getElementById('myBtn');
  if (topBtn) {
    /**
     * toggle Shows the Top button only when the page is scrolled down
     *
     * @return {void}
     */
    const toggle = () => { topBtn.hidden = window.scrollY <= 20; };
    window.addEventListener('scroll', toggle, { passive: true });
    topBtn.addEventListener('click', () => window.scrollTo({ top: 0 }));
    toggle();
  }

  /* ---------- Cookie notice ----------
     Shown until "OK" is clicked. The click is remembered in the technical
     cookie "cookie_notice" (1 year), so PHP does not output the notice again.
     While the notice is shown, the Top button sits above it and the page gets
     extra space at the bottom, so the notice never hides the footer. */
  const notice = document.getElementById('cookieNotice');
  const noticeOk = document.getElementById('cookieOk');
  if (notice && noticeOk) {
    /**
     * placeTopBtn Moves the Top button above the cookie notice and keeps space for it
     *
     * @return {void}
     */
    const placeTopBtn = () => {
      const space = notice.isConnected ? (notice.offsetHeight + 32) + 'px' : '';
      if (topBtn) topBtn.style.bottom = space;
      document.body.style.paddingBottom = space;
    };
    placeTopBtn();
    window.addEventListener('resize', placeTopBtn);
    noticeOk.addEventListener('click', () => {
      const secure = location.protocol === 'https:' ? '; Secure' : '';
      document.cookie = 'cookie_notice=ok; Max-Age=31536000; Path=/; SameSite=Lax' + secure;
      notice.remove();
      placeTopBtn();
    });
  }
})();
