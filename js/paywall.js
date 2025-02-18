$(document).ready(function () {
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

  // Subscribe to access state changes
  PaywallAccess.subscribe(function(hasAccess) {
    if (hasAccess) {
      $(".paywall-overlay").hide();
      $(".article-content").css("max-height", "none");
    } else {
      $(".paywall-overlay").show();
      $(".article-content").css("max-height", "var(--article-preview-height)");
    }
  });
});
