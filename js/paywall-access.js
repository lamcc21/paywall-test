const PaywallAccess = (function () {
  const API_BASE_URL = "https://micro-payments.fly.dev";
  const MAX_RETRIES = 5;
  const RETRY_DELAY = 1000; // 1 second

  let _hasAccess = false;
  let _retryCount = 0;
  const _subscribers = [];

  function notifySubscribers() {
    _subscribers.forEach((callback) => callback(_hasAccess));
  }

  function requestToken() {
    console.log(`Requesting token (attempt ${_retryCount + 1})...`);
    window.postMessage({ type: "REQUEST_TOKEN" }, "*");

    // Retry if we don't get a response
    if (_retryCount < MAX_RETRIES) {
      _retryCount++;
      setTimeout(() => {
        if (!_hasAccess) {
          requestToken();
        }
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
        const index = _subscribers.indexOf(callback);
        if (index > -1) _subscribers.splice(index, 1);
      };
    },

    init() {
      console.log("PaywallAccess initialized");

      window.addEventListener(
        "message",
        function (event) {
          if (event.source !== window) return;
          if (event.data.type === "TOKEN_RESPONSE") {
            _retryCount = MAX_RETRIES; // Stop retrying once we get a response
            this.checkAccess(event.data.token);
          }
        }.bind(this),
      );

      // Start requesting token
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
          body: JSON.stringify({
            url: window.location.href,
          }),
        });

        const data = await response.json();
        _hasAccess = data.hasAccess;
        notifySubscribers();
      } catch (error) {
        _hasAccess = false;
        notifySubscribers();
      }
    },
  };
})();

PaywallAccess.init();
