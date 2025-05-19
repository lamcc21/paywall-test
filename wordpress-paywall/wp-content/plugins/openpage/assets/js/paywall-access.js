const PaywallAccess = (function () {
  const API_BASE_URL = "https://micro-payments.fly.dev";
  const MAX_RETRIES = 5;
  const RETRY_DELAY = 1000;

  let _hasAccess = false;
  let _retryCount = 0;
  const _subscribers = [];

  function notifySubscribers() {
    _subscribers.forEach((cb) => cb(_hasAccess));
  }

  function requestToken() {
    console.log(`Requesting token (attempt ${_retryCount + 1})...`);
    window.postMessage({ type: "REQUEST_TOKEN" }, "*");

    if (_retryCount < MAX_RETRIES) {
      _retryCount++;
      setTimeout(() => {
        if (!_hasAccess) requestToken();
      }, RETRY_DELAY);
    }
  }

  return {
    get hasAccess() {
      return _hasAccess;
    },

    subscribe(callback) {
      _subscribers.push(callback);
      callback(_hasAccess);
      return () => {
        const i = _subscribers.indexOf(callback);
        if (i > -1) _subscribers.splice(i, 1);
      };
    },

    init() {
      console.log("PaywallAccess initialized");

      window.addEventListener(
        "message",
        function (event) {
          if (event.source !== window) return;
          if (event.data.type === "TOKEN_RESPONSE") {
            _retryCount = MAX_RETRIES;
            this.checkAccess(event.data.token);
          }
        }.bind(this),
      );

      requestToken();
    },

    async checkAccess(authProof) {
      try {
        const response = await fetch(`${API_BASE_URL}/access`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Auth-Proof": authProof,
          },
          body: JSON.stringify({ url: window.location.href }),
        });

        const data = await response.json();
        _hasAccess = data.hasAccess;
        notifySubscribers();

        if (data.hasAccess) {
          // Fetch and inject full content
          const res = await fetch(
            `/wp-json/openpage/v1/post/${leakypaywall_data.post_id}`,
          );
          if (res.ok) {
            const html = await res.text();
            const container = document.getElementById("paywall-content");
            if (container) container.innerHTML = html;
          }
        }
      } catch (err) {
        console.error("Access check or unlock failed:", err);
        _hasAccess = false;
        notifySubscribers();
      }
    },
  };
})();

PaywallAccess.init();
