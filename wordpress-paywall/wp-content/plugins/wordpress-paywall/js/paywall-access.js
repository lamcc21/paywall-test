const PaywallAccess = (function () {
  const API_BASE_URL = "https://micro-payments.fly.dev";
  const MAX_RETRIES = 5;
  const RETRY_DELAY = 1000; // 1 second

  let _hasAccess = false;
  let _retryCount = 0;
  let _isInitialized = false;
  const _subscribers = [];

  function notifySubscribers() {
    _subscribers.forEach((callback) => callback(_hasAccess));
  }

  function requestToken() {
    console.log(`PaywallAccess: Requesting token (attempt ${_retryCount + 1})...`);
    window.postMessage({ type: "REQUEST_TOKEN" }, "*");

    if (_retryCount < MAX_RETRIES) {
      _retryCount++;
      setTimeout(() => {
        if (!_hasAccess && _isInitialized) {
          requestToken();
        }
      }, RETRY_DELAY);
    }
  }

  async function notifyWordPressOfUnlock(postId) {
    if (!postId) {
      console.error("PaywallAccess: No post ID available");
      return;
    }
    
    if (!OpenPageUnlocker?.ajax_url || !OpenPageUnlocker?.nonce) {
      console.error("PaywallAccess: Missing WordPress configuration");
      return;
    }

    console.log(`PaywallAccess: Notifying WordPress of unlock for post ID ${postId}`);
    
    try {
      const response = await fetch(OpenPageUnlocker.ajax_url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "openpage_unlock_post",
          post_id: postId,
          nonce: OpenPageUnlocker.nonce,
        }),
      });
      
      const result = await response.json();
      if (result.success) {
        console.log("PaywallAccess: Successfully notified WordPress of unlock");
        if (result.data?.reload || OpenPageUnlocker?.reload_on_unlock) {
          console.log("PaywallAccess: Reloading page...");
          window.location.reload();
        }
      } else {
        console.error("PaywallAccess: WordPress reported error during unlock", result);
      }
    } catch (error) {
      console.error("PaywallAccess: Failed to notify WordPress of unlock:", error);
    }
  }

  return {
    get hasAccess() {
      return _hasAccess;
    },

    subscribe(callback) {
      if (typeof callback !== 'function') {
        console.error("PaywallAccess: Subscribe requires a function callback");
        return () => {};
      }
      
      _subscribers.push(callback);
      callback(_hasAccess);
      
      return () => {
        const index = _subscribers.indexOf(callback);
        if (index > -1) _subscribers.splice(index, 1);
      };
    },

    init() {
      if (_isInitialized) {
        console.warn("PaywallAccess: Already initialized");
        return;
      }
      
      _isInitialized = true;
      console.log("PaywallAccess: Initializing...");

      window.addEventListener(
        "message",
        function (event) {
          if (event.source !== window) return;
          if (event.data.type === "TOKEN_RESPONSE") {
            console.log("PaywallAccess: Received token response");
            _retryCount = MAX_RETRIES;
            this.checkAccess(event.data.token);
          }
        }.bind(this),
      );

      requestToken();
    },

    async checkAccess(authProof) {
      if (!authProof) {
        console.error("PaywallAccess: No auth proof provided");
        _hasAccess = false;
        notifySubscribers();
        return;
      }
      
      console.log("PaywallAccess: Checking access with auth proof");
      
      try {
        const response = await fetch(`${API_BASE_URL}/access`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Auth-Proof": authProof,
          },
          body: JSON.stringify({
            url: window.location.href,
            post_id: OpenPageUnlocker?.post_id || null,
          }),
        });

        if (!response.ok) {
          throw new Error(`Server responded with ${response.status}`);
        }

        const data = await response.json();
        console.log("PaywallAccess: Access check result:", data);
        _hasAccess = data.hasAccess;

        if (_hasAccess) {
          await notifyWordPressOfUnlock(OpenPageUnlocker.post_id);
        }

        notifySubscribers();
      } catch (error) {
        console.error("PaywallAccess: Error checking access:", error);
        _hasAccess = false;
        notifySubscribers();
      }
    },
  };
})();

// Initialize when DOM is loaded
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', PaywallAccess.init.bind(PaywallAccess));
} else {
  PaywallAccess.init();
}