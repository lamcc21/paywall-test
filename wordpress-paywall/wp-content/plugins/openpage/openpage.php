<?php
/**
 * Plugin Name: Leaky Paywall Bypass
 * Description: Secure client-side content unlocking for Leaky Paywall.
 * Version: 1.2.0
 */

// Prevent caching if access granted
add_action("template_redirect", function () {
    if (
        isset($_COOKIE["lp_has_access"]) &&
        $_COOKIE["lp_has_access"] === "true"
    ) {
        if (!defined("DONOTCACHEPAGE")) {
            define("DONOTCACHEPAGE", true);
        }
    }
});

// REST API to return full post HTML if access is granted
add_action("rest_api_init", function () {
    register_rest_route("openpage/v1", "/post/(?P<id>\d+)", [
        "methods" => "GET",
        "callback" => function ($data) {
            if (
                isset($_COOKIE["lp_has_access"]) &&
                $_COOKIE["lp_has_access"] === "true"
            ) {
                $post = get_post($data["id"]);
                if ($post) {
                    return apply_filters("the_content", $post->post_content);
                }
            }
            return new WP_Error("forbidden", "Access denied", [
                "status" => 403,
            ]);
        },
        "permission_callback" => "__return_true",
    ]);
});

// Replace main content with placeholder for JS injection
add_filter(
    "the_content",
    function ($content) {
        if (is_single() && !current_user_can("manage_options")) {
            return '<div id="paywall-content">🔒 This article is behind a paywall. Checking access…</div>';
        }
        return $content;
    },
    5
);

// Always return true for access to prevent Leaky Paywall from hiding content server-side
add_filter(
    "leaky_paywall_current_user_can_access",
    function ($can_access, $post_id) {
        return true;
    },
    20,
    2
);

// Load paywall-access.js and pass context
add_action("wp_enqueue_scripts", function () {
    global $post;

    $has_access =
        isset($_COOKIE["lp_has_access"]) &&
        $_COOKIE["lp_has_access"] === "true";
    $post_id = $post ? $post->ID : 0;

    wp_enqueue_script(
        "paywall-access",
        plugins_url("assets/js/paywall-access.js", __FILE__),
        [],
        "1.2.0",
        true
    );

    wp_localize_script("paywall-access", "leakypaywall_data", [
        "has_access" => $has_access,
        "post_id" => $post_id,
    ]);
});
