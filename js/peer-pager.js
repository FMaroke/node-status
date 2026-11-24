/**
 * FMCE Node Status - pages for the peer table.
 * The server sends all peers; this script shows only one page of them
 * (rows outside the page get the "hidden" attribute) and builds the
 * navigation  « ‹  Page x of y · Peers a–b of n  › »  plus a page size choice.
 * Sorting (sorttable.js) still works on all peers; after a sort by the
 * visitor the table goes back to page 1. Without JavaScript all peers are shown.
 * The column headers can also be sorted with the keyboard (Tab, Enter or
 * Space) and tell screen readers the sort order (aria-sort).
 *
 * Copyright 2026 FMCE Concept UG - SPDX-License-Identifier: Apache-2.0
 * CoAuthor: Claude / Claude Code - Opus 5.5
 */
(() => {
  'use strict';

  const panel = document.getElementById('peerstable');
  const pager = document.getElementById('peer-pager');
  if (!panel || !pager) return;

  // Rows per page from config.php ('peers_per_page', 0 = all)
  let perPage = Math.max(0, parseInt(panel.dataset.perPage || '10', 10));
  let page    = 1;

  /**
   * getRows Returns the rows of the current peer table
   *
   * @return {HTMLTableRowElement[]}
   */
  function getRows() {
    const table = panel.querySelector('table');
    return (table && table.tBodies[0]) ? Array.from(table.tBodies[0].rows) : [];
  }

  /**
   * updateHeaders Makes the column headers keyboard focusable and sets aria-sort
   *
   * sorttable.js marks the sorted column with the classes "sorttable_sorted"
   * (ascending) and "sorttable_sorted_reverse" (descending). Runs after every
   * sort and after every refresh, because refresh.js replaces the table.
   *
   * @return {void}
   */
  function updateHeaders() {
    panel.querySelectorAll('table.sortable thead th').forEach((th) => {
      th.tabIndex = 0;
      if (/\bsorttable_sorted_reverse\b/.test(th.className)) {
        th.setAttribute('aria-sort', 'descending');
      } else if (/\bsorttable_sorted\b/.test(th.className)) {
        th.setAttribute('aria-sort', 'ascending');
      } else {
        th.removeAttribute('aria-sort');
      }
    });
  }

  /**
   * button Creates one navigation button
   *
   * @param {string}   symbol   Visible symbol
   * @param {string}   label    Text for screen readers / tooltip
   * @param {boolean}  disabled Greyed out (first/last page)
   * @param {Function} onClick  Action
   *
   * @return {HTMLButtonElement}
   */
  function button(symbol, label, disabled, onClick) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pager-btn';
    btn.textContent = symbol;
    btn.title = label;
    btn.setAttribute('aria-label', label);
    btn.disabled = disabled;
    btn.addEventListener('click', onClick);
    return btn;
  }

  /**
   * sizeChoice Creates the "rows per page" selection
   *
   * @return {HTMLLabelElement}
   */
  function sizeChoice() {
    const label  = document.createElement('label');
    const select = document.createElement('select');
    label.className = 'pager-size';
    label.append('Rows: ', select);

    const sizes = [10, 25, 50, 0];
    if (sizes.indexOf(perPage) === -1) sizes.unshift(perPage);
    sizes.forEach((size) => {
      const option = document.createElement('option');
      option.value = String(size);
      option.textContent = size === 0 ? 'All' : String(size);
      option.selected = (size === perPage);
      select.appendChild(option);
    });
    select.addEventListener('change', () => {
      perPage = parseInt(select.value, 10);
      page = 1;
      render();
    });
    return label;
  }

  /**
   * render Shows the rows of the current page and rebuilds the navigation
   *
   * @return {void}
   */
  function render() {
    updateHeaders();

    const rows  = getRows();
    const total = rows.length;
    const pages = (perPage > 0) ? Math.max(1, Math.ceil(total / perPage)) : 1;
    page = Math.min(Math.max(1, page), pages);   // e.g. fewer peers after a refresh

    const first = (perPage > 0) ? (page - 1) * perPage : 0;
    const last  = (perPage > 0) ? Math.min(total, first + perPage) : total;
    rows.forEach((row, i) => { row.hidden = (i < first || i >= last); });

    // Navigation (with the "Rows" choice) when the peers need more than one page,
    // or when there are more peers than the smallest page size (10): then the
    // visitor can always switch back from "All" to pages.
    pager.textContent = '';
    const needsPager = (perPage > 0 && total > perPage) || total > 10;
    if (!needsPager) {
      pager.hidden = true;
      return;
    }
    pager.hidden = false;

    const status = document.createElement('span');
    status.className = 'pager-status';
    status.setAttribute('aria-live', 'polite');
    status.textContent = (perPage > 0)
      ? 'Page ' + page + ' of ' + pages + ' · Peers ' + (first + 1) + '–' + last + ' of ' + total
      : 'All ' + total + ' peers';

    /**
     * go Returns the click handler of a navigation button
     *
     * @param {number} target Page to show
     *
     * @return {Function} Handler that shows the page
     */
    const go = (target) => () => { page = target; render(); };
    pager.append(
      button('«', 'First page', page <= 1, go(1)),
      button('‹', 'Previous page', page <= 1, go(page - 1)),
      status,
      button('›', 'Next page', page >= pages, go(page + 1)),
      button('»', 'Last page', page >= pages, go(pages)),
      sizeChoice()
    );
  }

  // A sort by the visitor (click on a column header) starts again at page 1.
  // Clicks made by refresh.js to restore the sorting are not "trusted" and keep the page.
  // composedPath(): a click on the sort arrow of sorttable.js hits an element that
  // sorttable.js removes while sorting; event.target.closest() would then find no thead.
  panel.addEventListener('click', (event) => {
    if (!event.composedPath().some((el) => el.tagName === 'THEAD')) return;
    if (event.isTrusted) page = 1;
    setTimeout(render, 0);   // after sorttable.js has sorted the rows
  });

  // Keyboard: Enter or Space on a focused column header sorts like a click.
  // The page is set here, because a click made by script is not "trusted".
  panel.addEventListener('keydown', (event) => {
    const th = event.target.closest('thead th');
    if (!th || (event.key !== 'Enter' && event.key !== ' ')) return;
    event.preventDefault();   // Space would scroll the page
    page = 1;
    th.click();
  });

  // Called by refresh.js after the peer table was replaced
  window.fmcePeerPager = { refresh: render };

  render();
})();
