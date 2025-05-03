const PaywallAccess = (function () {
  const API_BASE_URL = "https://micro-payments.fly.dev";
  const MAX_RETRIES = 5;
  const RETRY_DELAY = 1000; // 1 second

  let _hasAccess = false;
  let _retryCount = 0;
  let _isInitialized = false;
  let _isProcessing = false; // Flag to prevent multiple concurrent calls
  let _lastUnlockTime = 0; // Track when we last unlocked content
  const _subscribers = [];

  function notifySubscribers() {
    _subscribers.forEach((callback) => callback(_hasAccess));
  }

  function requestToken() {
    if (_isProcessing) return; // Don't request if we're already processing
    
    console.log(`PaywallAccess: Requesting token (attempt ${_retryCount + 1})...`);
    window.postMessage({ type: "REQUEST_TOKEN" }, "*");

    if (_retryCount < MAX_RETRIES) {
      _retryCount++;
      setTimeout(() => {
        if (!_hasAccess && _isInitialized && !_isProcessing) {
          requestToken();
        }
      }, RETRY_DELAY);
    }
  }

  // Check cookies for existing access
  function checkCookiesForAccess() {
    try {
      const cookieName = 'openpage_unlocked_posts';
      const cookieValue = getCookie(cookieName);
      
      if (cookieValue) {
        const unlockedPosts = JSON.parse(cookieValue);
        const currentPostId = OpenPageUnlocker?.post_id;
        
        if (unlockedPosts && currentPostId && unlockedPosts[currentPostId]) {
          console.log(`PaywallAccess: Found existing access in cookie for post ${currentPostId}`);
          _hasAccess = true;
          notifySubscribers();
          return true;
        }
      }
    } catch (e) {
      console.error("PaywallAccess: Error checking cookies", e);
    }
    return false;
  }
  
  // Helper function to get cookie value
  function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
  }

  async function notifyWordPressOfUnlock(postId) {
    // Don't notify WordPress if we don't actually have access
    if (!_hasAccess) {
      console.log("PaywallAccess: No access granted, not notifying WordPress");
      return;
    }
    
    // Prevent duplicate unlocks in a short time period
    const now = Date.now();
    if (now - _lastUnlockTime < 3000) { // 3 seconds
      console.log("PaywallAccess: Skipping duplicate unlock request");
      return;
    }
    
    _lastUnlockTime = now;
    _isProcessing = true;
    
    if (!postId) {
      console.error("PaywallAccess: No post ID available");
      _isProcessing = false;
      return;
    }
    
    if (!OpenPageUnlocker?.ajax_url || !OpenPageUnlocker?.nonce) {
      console.error("PaywallAccess: Missing WordPress configuration");
      _isProcessing = false;
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
          has_real_access: _hasAccess ? "1" : "0", // Explicitly tell server our access state
        }),
      });
      
      const result = await response.json();
      if (result.success) {
        console.log("PaywallAccess: Successfully notified WordPress of unlock");
        // Don't reload on every success - let our cookie detection handle access
        if (result.data?.reload || OpenPageUnlocker?.reload_on_unlock) {
          // Set a flag in sessionStorage to prevent endless reloads
          sessionStorage.setItem('openpage_just_unlocked', 'true');
          console.log("PaywallAccess: Reloading page...");
          window.location.reload();
        }
      } else {
        console.error("PaywallAccess: WordPress reported error during unlock", result);
        // Reset access if server rejected our access
        if (result.data?.reset_access) {
          _hasAccess = false;
          notifySubscribers();
        }
      }
    } catch (error) {
      console.error("PaywallAccess: Failed to notify WordPress of unlock:", error);
    } finally {
      _isProcessing = false;
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
      
      // Check if we just reloaded after an unlock
      if (sessionStorage.getItem('openpage_just_unlocked') === 'true') {
        console.log("PaywallAccess: Page was just unlocked and reloaded");
        sessionStorage.removeItem('openpage_just_unlocked');
        
        // Don't assume access - check cookies explicitly
        checkCookiesForAccess();
        return;
      }
      
      // First check cookies to see if we already have access
      if (checkCookiesForAccess()) {
        console.log("PaywallAccess: Access found in cookies, skipping token request");
        return;
      }

      window.addEventListener(
        "message",
        function (event) {
          if (event.source !== window) return;
          if (event.data.type === "TOKEN_RESPONSE" && !_isProcessing) {
            console.log("PaywallAccess: Received token response");
            _retryCount = MAX_RETRIES; // Stop retrying
            this.checkAccess(event.data.token);
          }
        }.bind(this),
      );

      // Only request a token if we don't already have access
      if (!_hasAccess) {
        requestToken();
      }
    },

    async checkAccess(authProof) {
      if (_isProcessing) return; // Prevent concurrent checks
      _isProcessing = true;
      
      if (!authProof) {
        console.error("PaywallAccess: No auth proof provided");
        _hasAccess = false;
        notifySubscribers();
        _isProcessing = false;
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
        
        // IMPORTANT: Only set _hasAccess if API explicitly grants access
        _hasAccess = !!data.hasAccess; // Use double-negation to ensure boolean
        console.log("PaywallAccess: Access state set to:", _hasAccess);

        // Only notify WordPress if we actually got access
        if (_hasAccess) {
          await notifyWordPressOfUnlock(OpenPageUnlocker.post_id);
        } else {
          console.log("PaywallAccess: Access denied by API");
        }

        notifySubscribers();
      } catch (error) {
        console.error("PaywallAccess: Error checking access:", error);
        _hasAccess = false;
        notifySubscribers();
      } finally {
        _isProcessing = false;
      }
    },
    
    // Manual method to reset access state (for debugging)
    resetAccess() {
      _hasAccess = false;
      notifySubscribers();
      console.log("PaywallAccess: Access manually reset");
    }
  };
})();

// Initialize when DOM is loaded
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', PaywallAccess.init.bind(PaywallAccess));
} else {
  PaywallAccess.init();
}