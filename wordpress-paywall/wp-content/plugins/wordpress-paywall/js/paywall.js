jQuery(document).ready(function($) {
  console.log("Paywall UI initialized");
  
  // Modal handling
  $(".show-modal").click(function () {
    $("#subscribeModal").fadeIn(300);
  });

  $(".btn-close, .modal-footer .btn-secondary").click(function () {
    $("#subscribeModal").fadeOut(200);
  });

  // Close modal when clicking outside
  $(window).click(function (e) {
    if ($(e.target).is(".modal")) {
      $("#subscribeModal").fadeOut(200);
    }
  });

  // Define default paywall UI elements
  const DEFAULT_PAYWALL_CONFIG = {
    contentSelector: ".article-content",
    overlaySelector: ".paywall-overlay",
    previewHeight: "300px"
  };
  
  // Merge with any global config provided by WordPress
  const paywallConfig = $.extend({}, DEFAULT_PAYWALL_CONFIG, window.PaywallConfig || {});
  
  // Make sure we have the CSS variable for article preview height
  if (paywallConfig.contentSelector) {
    document.documentElement.style.setProperty('--article-preview-height', paywallConfig.previewHeight);
  }
  
  // Subscribe to access state changes
  if (typeof PaywallAccess !== 'undefined') {
    PaywallAccess.subscribe(function(hasAccess) {
      console.log("Access state changed:", hasAccess);
      
      if (hasAccess) {
        // User has access, show full content
        $(paywallConfig.overlaySelector).fadeOut(300);
        $(paywallConfig.contentSelector).css({
          "max-height": "none",
          "overflow": "visible"
        });
        
        // Notify any other scripts that content is now visible
        $(document).trigger('paywall:content_unlocked');
      } else {
        // User doesn't have access, show limited content
        $(paywallConfig.overlaySelector).fadeIn(300);
        $(paywallConfig.contentSelector).css({
          "max-height": "var(--article-preview-height)",
          "overflow": "hidden"
        });
        
        // Position the overlay correctly
        repositionOverlay();
      }
    });
  } else {
    console.error("PaywallAccess not found! Make sure paywall-access.js is loaded.");
  }
  
  // Function to reposition the overlay based on the preview height
  function repositionOverlay() {
    const contentHeight = $(paywallConfig.contentSelector).height();
    const previewHeight = parseInt(paywallConfig.previewHeight);
    
    // Only apply if content is longer than preview height
    if (contentHeight > previewHeight) {
      $(paywallConfig.overlaySelector).css({
        "top": `calc(${paywallConfig.previewHeight} - 100px)`
      });
    }
  }
  
  // Reposition on window resize
  $(window).on('resize', function() {
    if (!PaywallAccess.hasAccess) {
      repositionOverlay();
    }
  });
  
  // Initial positioning
  setTimeout(repositionOverlay, 100);
});