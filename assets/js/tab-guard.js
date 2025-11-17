'use strict';

(function () {
  const HEARTBEAT_URL = '/security/tab-heartbeat.php';
  const STORAGE_KEY = 'sec_tab_id';
  const TAB_COOKIE_NAME = '__sec_tab';
  const HEARTBEAT_BASE_INTERVAL_MS = 35000;
  const HEARTBEAT_JITTER_MS = 5000;
  const HEARTBEAT_TIMEOUT_MS = 5000;

  if (typeof window === 'undefined' || !window.sessionStorage) {
    return;
  }

  function generateId() {
    if (window.crypto && window.crypto.randomUUID) {
      return window.crypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      for (let i = 0; i < bytes.length; i += 1) {
        bytes[i] = Math.floor(Math.random() * 256);
      }
    }
    return Array.from(bytes).map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  let tabId = null;
  try {
    tabId = window.sessionStorage.getItem(STORAGE_KEY);
    if (!tabId) {
      tabId = generateId();
      window.sessionStorage.setItem(STORAGE_KEY, tabId);
    }
  } catch (err) {
    // sessionStorage might be disabled
    tabId = generateId();
  }

  function setTabCookie(value) {
    try {
      document.cookie = `${TAB_COOKIE_NAME}=${encodeURIComponent(value)}; path=/; SameSite=Lax`;
    } catch (err) {
      // ignore cookie issues (e.g., disabled cookies)
    }
  }

  try {
    window.__SEC_TAB_ID__ = tabId;
  } catch (err) {
    // ignore
  }

  setTabCookie(tabId);

  function buildPayload(event) {
    return JSON.stringify({
      event,
      tabId,
    });
  }

  function handleResponse(response) {
    if (response.status === 403 || response.status === 429) {
      window.location.reload();
      return;
    }

    setTabCookie(tabId);
  }

  function sendHeartbeat(eventName = 'heartbeat') {
    if (document.visibilityState && document.visibilityState !== 'visible' && eventName === 'heartbeat') {
      return;
    }

    if (navigator.onLine === false) {
      return;
    }

    const controller = new AbortController();
    const signal = controller.signal;
    const timeout = setTimeout(() => controller.abort(), HEARTBEAT_TIMEOUT_MS);

    fetch(HEARTBEAT_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Tab-Id': tabId,
        'X-Tab-Event': eventName,
      },
      body: buildPayload(eventName),
      signal,
    })
      .then(handleResponse)
      .catch(() => {
        // ignore network errors; next heartbeat will retry
      })
      .finally(() => {
        clearTimeout(timeout);
      });
  }

  function sendCloseEvent() {
    const payload = buildPayload('close');
    if (navigator.sendBeacon) {
      try {
        navigator.sendBeacon(
          HEARTBEAT_URL,
          new Blob([payload], { type: 'application/json' }),
        );
        return;
      } catch (err) {
        // fallback to fetch
      }
    }

    navigator.fetch?.(HEARTBEAT_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Tab-Id': tabId,
        'X-Tab-Event': 'close',
      },
      body: payload,
      keepalive: true,
    });
  }

  let heartbeatTimer = null;

  function scheduleHeartbeat() {
    const jitter = Math.floor(Math.random() * HEARTBEAT_JITTER_MS);
    const delay = HEARTBEAT_BASE_INTERVAL_MS + jitter;

    heartbeatTimer = window.setTimeout(() => {
      sendHeartbeat();
      scheduleHeartbeat();
    }, delay);
  }

  function stopHeartbeat() {
    if (heartbeatTimer !== null) {
      clearTimeout(heartbeatTimer);
      heartbeatTimer = null;
    }
  }

  function startHeartbeat() {
    stopHeartbeat();
    sendHeartbeat();
    scheduleHeartbeat();
  }

  if (!document.hidden) {
    startHeartbeat();
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      startHeartbeat();
    } else {
      sendHeartbeat('pause');
      stopHeartbeat();
    }
  });

  window.addEventListener('focus', () => {
    if (!heartbeatTimer) {
      startHeartbeat();
    }
  });

  window.addEventListener('beforeunload', () => {
    stopHeartbeat();
    sendCloseEvent();
  });
})();
