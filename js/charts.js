/**
 * FMCE Node Status - draws all charts of the page.
 * One script for every chart: each element with a data-chart attribute is drawn
 * from the settings PHP writes there (getChartDefinitions in php/data_lib.php,
 * displayChart in php/functions.php) and from its data in window.fmceChartData,
 * which chartdata.php fills. There is no code for a single chart here, so a new
 * chart needs no change in this file.
 * Colours come from the CSS variables of the theme (--chart-* in css/style.css).
 * Google Charts is loaded once; charts are redrawn on window resize.
 *
 * Copyright 2026 FMCE Concept UG - SPDX-License-Identifier: Apache-2.0
 * CoAuthor: Claude / Claude Code - Opus 5.5
 */
(() => {
  'use strict';

  const holder = document.getElementById('charts');
  if (!holder || !window.google || !google.charts) return;

  const DAY              = 86400000;   // one day in milliseconds
  const MAX_TICK_DAYS    = 10;         // up to this range: one tick per day at midnight
  const TICK_LABEL_WIDTH = 56;         // pixels per date label ("17.11.") incl. space

  // Google chart class and stacking per chart type of the chart definition
  const CHART_TYPES = {
    line:           { cls: 'LineChart',   stacked: false },
    area:           { cls: 'AreaChart',   stacked: false },
    stacked_area:   { cls: 'AreaChart',   stacked: true },
    column:         { cls: 'ColumnChart', stacked: false },
    stacked_column: { cls: 'ColumnChart', stacked: true },
  };

  /**
   * getFormat Returns the display formats set by PHP (data-format of #charts)
   *
   * @return {Object} {language, decimal, group, datetime, axis, axis_time (ICU patterns)}
   */
  function getFormat() {
    const defaults = {
      language: 'en', decimal: '.', group: ',', datetime: 'yyyy-MM-dd HH:mm:ss', axis: 'MM-dd', axis_time: 'HH:mm',
    };
    try {
      return Object.assign(defaults, JSON.parse(holder.dataset.format || '{}'));
    } catch (err) {
      return defaults;
    }
  }

  /**
   * cssVar Reads a CSS variable of the page (theme colours)
   *
   * @param {string} name Name with leading "--", e.g. '--chart-bg'
   *
   * @return {string} Value without spaces, '' if not set
   */
  function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }

  /**
   * getThemeColors Returns the chart colours of the active theme
   *
   * @return {Object} {bg, title, text, grid, palette (list of colours)}
   */
  function getThemeColors() {
    const palette = [];
    for (let i = 1; i <= 12; i++) {
      const color = cssVar('--chart-' + i);
      if (!color) break;
      palette.push(color);
    }
    return {
      bg: cssVar('--chart-bg'),
      title: cssVar('--chart-title'),
      text: cssVar('--chart-text'),
      grid: cssVar('--chart-grid'),
      palette: palette,
    };
  }

  /**
   * resolveColor Turns a colour setting into a colour value
   *
   * @param {string} value CSS variable ("--chart-accent"), "#rrggbb" or ''
   *
   * @return {string} Colour, '' if none
   */
  function resolveColor(value) {
    if (typeof value !== 'string' || value === '') return '';
    return value.indexOf('--') === 0 ? cssVar(value) : value;
  }

  /**
   * axisFormat Returns the label format of the time axis for one chart
   *
   * 'auto': data over less than two days are labelled with the time of day
   * (e.g. "14:00"), longer ranges with the date (e.g. "17.11.").
   *
   * @param {string} mode 'auto', 'date' or 'time' (axis of the chart definition)
   * @param {Array}  rows Chart table: header row, then [time in ms, value, ...]
   * @param {Object} fmt  Display formats (getFormat)
   *
   * @return {string} ICU pattern
   */
  function axisFormat(mode, rows, fmt) {
    if (mode === 'time') return fmt.axis_time;
    if (mode === 'date') return fmt.axis;
    const range = timeRange(rows);
    return (range && range.max - range.min < 2 * DAY) ? fmt.axis_time : fmt.axis;
  }

  /**
   * timeRange Returns the first and the last time of a chart table
   *
   * @param {Array} rows Chart table: header row, then [time in ms, value, ...]
   *
   * @return {Object|null} {min, max} in milliseconds, null with less than two points
   */
  function timeRange(rows) {
    if (!Array.isArray(rows) || rows.length < 3) return null;
    let min = Infinity;
    let max = -Infinity;
    for (let i = 1; i < rows.length; i++) {
      const t = Number(rows[i][0]);
      if (t < min) min = t;
      if (t > max) max = t;
    }
    return { min: min, max: max };
  }

  /**
   * dayTicks Returns one tick per day at midnight for a date axis
   *
   * Google Charts puts ticks every 12 hours on charts over 2 to 4 days, so every
   * date appeared twice. Up to MAX_TICK_DAYS days the ticks are set to midnight
   * (local time of the browser, as the labels); on narrow charts only every
   * n-th day, so the labels fit. Longer ranges: Google chooses the ticks.
   *
   * @param {Array}  rows  Chart table: header row, then [time in ms, value, ...]
   * @param {number} width Width of the chart element in pixels
   *
   * @return {Array|null} List of dates, null = let Google choose
   */
  function dayTicks(rows, width) {
    const range = timeRange(rows);
    if (!range || range.max - range.min > MAX_TICK_DAYS * DAY) return null;

    const start = new Date(range.min);
    const days  = [];
    for (let i = 0; ; i++) {
      const midnight = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);   // DST safe
      if (midnight.getTime() > range.max) break;
      if (midnight.getTime() >= range.min) days.push(midnight);
    }
    if (days.length === 0) return null;   // no midnight in the range (axis forced to 'date')
    const fit  = Math.max(2, Math.floor((width - 80) / TICK_LABEL_WIDTH));   // labels that fit the plot area
    const step = Math.ceil(days.length / fit);
    return days.filter((day, i) => i % step === 0);
  }

  /**
   * buildTable Builds a Google DataTable from the chart table of chartdata.php
   *
   * Column 0 (time in milliseconds) becomes a date with the configured date/time
   * pattern for the tooltips; all other columns get the configured decimal point,
   * thousands separator and number of decimals.
   *
   * @param {Array}  rows     Chart table: header row, then [time, value, ...]
   * @param {number} decimals Decimal places of the values
   * @param {Object} fmt      Display formats (getFormat)
   *
   * @return {google.visualization.DataTable}
   */
  function buildTable(rows, decimals, fmt) {
    const table = Array.isArray(rows) && rows.length > 0
      ? rows.map((row, i) => (i === 0 ? row : [new Date(row[0])].concat(row.slice(1))))
      : [['Date', 'Value']];
    const data = google.visualization.arrayToDataTable(table);

    new google.visualization.DateFormat({ pattern: fmt.datetime }).format(data, 0);
    const numbers = new google.visualization.NumberFormat({
      decimalSymbol: fmt.decimal,
      groupingSymbol: fmt.group,
      fractionDigits: decimals,
    });
    for (let col = 1; col < data.getNumberOfColumns(); col++) {
      numbers.format(data, col);
    }
    return data;
  }

  /**
   * drawChart Draws one chart element from its data-chart settings
   *
   * Settings (JSON): {stat, type, legend, decimals, color, axis}. The title comes from
   * data-title, the data from window.fmceChartData[stat].
   *
   * @param {HTMLElement} el Chart element (class "chart")
   *
   * @return {void}
   */
  function drawChart(el) {
    let cfg;
    try {
      cfg = JSON.parse(el.dataset.chart || '{}');
    } catch (err) {
      console.error('Invalid data-chart of #' + el.id + ':', err);
      return;
    }
    const fmt    = getFormat();
    const theme  = getThemeColors();
    const type   = CHART_TYPES[cfg.type] || CHART_TYPES.line;
    const rows   = (window.fmceChartData || {})[cfg.stat];
    const fixed  = resolveColor(cfg.color);
    const format = axisFormat(cfg.axis, rows, fmt);
    const legend = (!cfg.legend || cfg.legend === 'none')
      ? 'none'
      : { position: cfg.legend, maxLines: 3, textStyle: { color: theme.text, fontSize: 12 } };

    const options = {
      backgroundColor: theme.bg,
      title: el.dataset.title || '',
      titleTextStyle: { color: theme.title, fontSize: 14, bold: true },
      hAxis: {
        format: format,
        maxTextLines: 1,   // narrow charts: leave out labels instead of breaking them into two lines
        textStyle: { color: theme.text, fontSize: 12, bold: true },
        gridlines: { color: theme.grid },
      },
      vAxis: {
        textStyle: { color: theme.text },
        gridlines: { color: theme.grid },
        baselineColor: theme.grid,
      },
      legend: legend,
      colors: fixed ? [fixed].concat(theme.palette) : theme.palette,
      isStacked: type.stacked,
      // Tooltip for the whole time column (all series), not only exactly on a line;
      // also easier to hit on touch screens
      focusTarget: 'category',
    };
    if (options.colors.length === 0) delete options.colors;   // no palette in the CSS: Google default
    if (format === fmt.axis) {
      const ticks = dayTicks(rows, el.clientWidth);   // every date only once
      if (ticks) options.hAxis.ticks = ticks;
    }
    if (cfg.legend === 'top') {
      // Room for the legend lines below the title and a wider plot area, so that
      // the series names are not cut off; narrow charts (phones) get a smaller font
      const narrow = el.clientWidth < 380;
      options.chartArea = { left: 48, right: 16, top: 84, bottom: 36 };
      options.legend.textStyle.fontSize = narrow ? 10 : 11;
    }

    new google.visualization[type.cls](el).draw(buildTable(rows, Number(cfg.decimals) || 0, fmt), options);
  }

  /**
   * drawAllCharts Draws every chart element of the page
   *
   * @return {void}
   */
  function drawAllCharts() {
    holder.querySelectorAll('.chart[data-chart]').forEach((el) => {
      try {
        drawChart(el);
      } catch (err) {
        console.error('Chart #' + el.id + ' failed:', err);
      }
    });
  }

  // Used by js/refresh.js after new data for one chart has arrived
  window.fmceDrawChart = drawChart;

  // Draw only after DOMContentLoaded: then all deferred chartdata.php scripts have
  // run. readyState is already 'interactive' while deferred scripts are still
  // executing, so it cannot be used for this check.
  let domReady = false;
  document.addEventListener('DOMContentLoaded', () => { domReady = true; }, { once: true });
  /**
   * onReady Runs a function after DOMContentLoaded (at once if it has already fired)
   *
   * @param {Function} fn Function to run
   *
   * @return {void}
   */
  const onReady = (fn) =>
    domReady ? fn() : document.addEventListener('DOMContentLoaded', fn, { once: true });

  // The language sets month names and number symbols of the chart axes
  google.charts.load('current', { packages: ['corechart'], language: getFormat().language });
  google.charts.setOnLoadCallback(() => onReady(drawAllCharts));

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(drawAllCharts, 500);
  });
})();
