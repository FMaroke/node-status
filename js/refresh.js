/**
 * FMCE Node Status - background refresh of the page.
 * Replaces <meta http-equiv="refresh">: every 'refresh_interval' seconds the
 * changed parts of the page are fetched (index.php?partial=1) and replaced.
 * Charts are redrawn only when their data has new points. The peer table is
 * refreshed only every 'refresh_peers_every'-th time and keeps its sorting.
 * Nothing is fetched while the browser tab is hidden.
 *
 * Copyright 2026 FMCE Concept UG - SPDX-License-Identifier: Apache-2.0
 * CoAuthor: Claude / Claude Code - Opus 5.5
 */
(() => {
  'use strict';

  const root = document.getElementById('container');
  if (!root || !window.fetch) return;

  const interval   = parseInt(root.dataset.refresh || '0', 10);
  const peersEvery = Math.max(1, parseInt(root.dataset.refreshPeers || '3', 10));
  if (!interval) return;   // refresh_interval = 0: switched off

  let counter = 0;
  let busy    = false;

  /**
   * currentChartIds Returns the ids of the charts shown on the page
   *
   * @return {string} Sorted, comma separated ids
   */
  function currentChartIds() {
    return Array.from(document.querySelectorAll('#charts .chart')).map((el) => el.id).sort().join(',');
  }

  /**
   * rememberSorting Returns which column of the peer table is sorted
   *
   * @return {Object|null} {index, reverse} or null if not sorted
   */
  function rememberSorting() {
    const headers = document.querySelectorAll('#live-peers table thead th');
    for (let i = 0; i < headers.length; i++) {
      if (/\bsorttable_sorted_reverse\b/.test(headers[i].className)) return { index: i, reverse: true };
      if (/\bsorttable_sorted\b/.test(headers[i].className)) return { index: i, reverse: false };
    }
    return null;
  }

  /**
   * restoreSorting Makes the new peer table sortable and sorts it like before
   *
   * @param {Object|null} sorting Result of rememberSorting()
   *
   * @return {void}
   */
  function restoreSorting(sorting) {
    const table = document.querySelector('#live-peers table.sortable');
    if (!table || !window.sorttable) return;
    sorttable.makeSortable(table);
    if (!sorting) return;
    const th = table.tHead.rows[0].cells[sorting.index];
    if (!th) return;
    th.click();                       // first click: ascending
    if (sorting.reverse) th.click();  // second click: descending
  }

  /**
   * replacePart Replaces the content of one refreshable part ("live-<name>")
   *
   * @param {string} name Name of the part
   * @param {string} html New HTML from the server
   *
   * @return {boolean} false if the part does not exist on the page
   */
  function replacePart(name, html) {
    const el = document.getElementById('live-' + name);
    if (!el) return false;
    if (name === 'peers') {
      const sorting = rememberSorting();
      el.innerHTML = html;
      restoreSorting(sorting);
      if (window.fmcePeerPager) fmcePeerPager.refresh();   // keep the current page
    } else {
      el.innerHTML = html;
    }
    return true;
  }

  /**
   * updateCharts Redraws every chart whose data has new points
   *
   * The same code works for every chart: the data goes to window.fmceChartData[stat]
   * and js/charts.js (fmceDrawChart) draws the element again from its data-chart settings.
   *
   * @param {Object} charts Chart meta data from the server (id => {stat, last, title, aria, updated})
   *
   * @return {Promise<void>}
   */
  async function updateCharts(charts) {
    if (!window.google || !google.visualization || typeof window.fmceDrawChart !== 'function') return;   // charts not loaded (yet)

    for (const [id, meta] of Object.entries(charts)) {
      const el = document.getElementById(id);
      if (!el || String(meta.updated) === el.dataset.updated) continue;

      const res = await fetch('chartdata.php?format=json&stat=' + encodeURIComponent(meta.stat), { cache: 'no-store' });
      if (!res.ok) throw new Error('chart data ' + meta.stat + ': HTTP ' + res.status);
      window.fmceChartData = window.fmceChartData || {};
      window.fmceChartData[meta.stat] = await res.json();

      el.dataset.last    = meta.last;
      el.dataset.title   = meta.title;
      el.dataset.updated = String(meta.updated);
      if (meta.aria) el.setAttribute('aria-label', meta.aria);
      window.fmceDrawChart(el);
    }
  }

  /**
   * refresh Fetches the changed parts of the page and replaces them
   *
   * @return {Promise<void>}
   */
  async function refresh() {
    if (busy || document.hidden) return;
    busy = true;
    counter++;
    const withPeers = (counter % peersEvery === 0);

    try {
      const url = location.pathname + '?partial=1' + (withPeers ? '&peers=1' : '');
      const res = await fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const live = await res.json();

      // A chart appeared or disappeared: the page structure changed, load it once completely
      if (Object.keys(live.charts || {}).sort().join(',') !== currentChartIds()) {
        location.reload();
        return;
      }

      for (const [name, html] of Object.entries(live.parts || {})) {
        if (!replacePart(name, html)) {   // part missing on the page: structure changed
          location.reload();
          return;
        }
      }
      await updateCharts(live.charts || {});
    } catch (err) {
      // Keep the shown values; the next interval tries again
      console.warn('Background refresh failed:', err.message);
    } finally {
      busy = false;
    }
  }

  setInterval(refresh, interval * 1000);

  // Back in the tab: refresh at once instead of waiting for the next interval
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refresh();
  });
})();
