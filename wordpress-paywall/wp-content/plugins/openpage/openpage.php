<?php
/**
 * Plugin Name: OpenPage Leaky Paywall Unlocker
 * Description: Unlocks content client-side using token verification without breaking Leaky Paywall.
 * Version: 1.4.0
 */

// ✅ Avoid serving cached unlocked content
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

// ✅ REST API: securely return post HTML if access is granted
add_action("rest_api_init", function () {
    register_rest_route("openpage/v1", "/post/(?P<id>\d+)", [
        "methods" => "GET",
        "callback" => function ($data) {
            if (
                isset($_COOKIE["lp_has_access"]) &&
                $_COOKIE["lp_has_access"] === "true"
            ) {
                $post = get_post($data["id"]);
                if ($post && $post->post_status === "publish") {
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

// ✅ Inject JS + pass post ID
add_action("wp_enqueue_scripts", function () {
    global $post;

    if (!is_singular() || !isset($post->ID)) {
        return;
    }

    $post_id = $post->ID;
    $has_access =
        isset($_COOKIE["lp_has_access"]) &&
        $_COOKIE["lp_has_access"] === "true";

    wp_enqueue_script(
        "paywall-access",
        plugins_url("assets/js/paywall-access.js", __FILE__),
        [],
        "1.4.0",
        true
    );

    wp_localize_script("paywall-access", "leakypaywall_data", [
        "has_access" => $has_access,
        "post_id" => $post_id,
    ]);
});
