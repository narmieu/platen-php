// Runs before the document's own scripts. Gotenberg calls window.__platenReady() until it
// returns true, then prints. The outcome travels back in document.title, which becomes
// the PDF's /Title, where platen reads it and restores the real title.
(() => {
  'use strict';

  const config = __PLATEN_CONFIG__;
  const report = { title: null, console: [], failed: [], timeout: null, error: null };
  const failedElements = new WeakSet();
  const startedAt = Date.now();
  let firstCheckAt = 0;
  let pendingRequests = 0;
  let lastActivityAt = startedAt;
  let finished = false;

  const describe = (value) => {
    if (typeof value === 'string') return value;
    if (value instanceof Error) return String(value);
    try {
      const json = JSON.stringify(value);
      return json === undefined ? String(value) : json;
    } catch (error) {
      return String(value);
    }
  };
  const remember = (list, value, limit) => {
    if (list.length < limit) list.push(String(value).slice(0, 300));
  };

  // Resources that fail to load. Error events do not bubble, but reach capturing listeners.
  window.addEventListener('error', (event) => {
    const target = event.target;
    if (target instanceof Element) {
      // With JavaScript off, scripts are blocked on purpose; they are not missing.
      if (!config.javascript && target.localName === 'script') return;
      failedElements.add(target);
      let url = target.currentSrc || target.src || target.href || target.data || '';
      if (typeof url !== 'string') url = url.baseVal || '';
      if (url) remember(report.failed, url, 10);
      return;
    }
    if (config.javascript) remember(report.console, 'uncaught error: ' + (event.message || describe(event.error)), 10);
  }, true);

  window.addEventListener('unhandledrejection', (event) => {
    if (config.javascript) remember(report.console, 'unhandled rejection: ' + describe(event.reason), 10);
  });

  if (config.javascript) {
    // DocRaptor stops rendering when a document writes to the console.
    for (const level of ['log', 'info', 'warn', 'error', 'debug']) {
      const original = console[level];
      console[level] = function (...args) {
        remember(report.console, level + ': ' + args.map(describe).join(' '), 10);
        return original.apply(this, args);
      };
    }

    // Requests made by the document's scripts: rendering waits for their results.
    const settled = () => {
      pendingRequests -= 1;
      lastActivityAt = Date.now();
    };
    if (typeof window.fetch === 'function') {
      const originalFetch = window.fetch;
      window.fetch = function (...args) {
        pendingRequests += 1;
        lastActivityAt = Date.now();
        const request = originalFetch.apply(this, args);
        request.then(settled, settled);
        return request;
      };
    }
    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (...args) {
      pendingRequests += 1;
      lastActivityAt = Date.now();
      this.addEventListener('loadend', settled, { once: true });
      try {
        return originalSend.apply(this, args);
      } catch (error) {
        this.removeEventListener('loadend', settled);
        settled();
        throw error;
      }
    };
    try {
      new PerformanceObserver(() => { lastActivityAt = Date.now(); }).observe({ type: 'resource' });
    } catch (error) {
      // Not available: the pending request count still applies.
    }
  }

  // Chromium does not print lazy images that are out of view.
  const loadEagerly = () => {
    for (const element of document.querySelectorAll('img[loading="lazy"], iframe[loading="lazy"]')) {
      element.setAttribute('loading', 'eager');
    }
  };
  document.addEventListener('DOMContentLoaded', loadEagerly);

  const finish = (timeout) => {
    finished = true;
    report.timeout = timeout;
    report.title = document.title || null;
    // Chromium draws a broken-image icon where Prince and WeasyPrint show the alt text.
    for (const image of Array.from(document.images)) {
      if (failedElements.has(image)) image.replaceWith(document.createTextNode(image.getAttribute('alt') || ''));
    }
    document.title = config.marker + JSON.stringify(report);
    return true;
  };

  const ready = () => {
    const now = Date.now();
    if (now - startedAt > config.renderTimeout) return finish('render');
    if (document.readyState !== 'complete') return false;
    firstCheckAt = firstCheckAt || now;
    if (config.javascript) {
      loadEagerly();
      const busy = pendingRequests > 0 || now - lastActivityAt < config.quietPeriod;
      if (busy && now - firstCheckAt < config.settleTimeout) return false;
      if (typeof window.docraptorJavaScriptFinished === 'function') {
        let done;
        try {
          done = window.docraptorJavaScriptFinished() === true;
        } catch (error) {
          remember(report.console, 'uncaught error: ' + describe(error), 10);
          done = true;
        }
        if (!done) return now - firstCheckAt > config.javascriptTimeout ? finish('javascript') : false;
      }
    }
    for (const image of Array.from(document.images)) {
      if (!image.complete) return false;
    }
    if (document.fonts && document.fonts.status === 'loading') return false;
    return finish(null);
  };

  Object.defineProperty(window, '__platenReady', {
    value: () => {
      if (finished) return true;
      try {
        return ready();
      } catch (error) {
        report.error = describe(error).slice(0, 300);
        return finish(null);
      }
    },
  });
})();
