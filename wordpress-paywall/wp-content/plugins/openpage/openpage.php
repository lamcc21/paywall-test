<?php
/**
 * Plugin Name: Leaky Paywall Bypass
 * Description: Bypasses Leaky Paywall restrictions.
 * Version: 1.0.0
 */

// Enqueue the JavaScript file
add_action("wp_enqueue_scripts", "enqueue_paywall_bypass_script");
function enqueue_paywall_bypass_script()
{
    // Localize the data for JavaScript
    $has_access =
        isset($_COOKIE["lp_has_access"]) && $_COOKIE["lp_has_access"] == "true";

    wp_localize_script("paywall-access", "leakypaywall_data", [
        "has_access" => $has_access,
    ]);

    // Debugging output to the webpage (REMOVE AFTER DEBUGGING)
    echo '<script>console.log("PHP: leakypaywall_data localized:", ' .
        json_encode([
            "has_access" => $has_access,
        ]) .
        ");</script>";

    wp_enqueue_script(
        "paywall-access",
        plugins_url("assets/js/paywall-access.js", __FILE__),
        [], // Dependencies (e.g., jQuery)
        "1.0.0", // Version
        true // Load in footer
    );
}

// Shortcode to conditionally display content
add_shortcode("show_if_subscribed", "show_if_subscribed_shortcode");
function show_if_subscribed_shortcode($atts, $content = null)
{
    //Check for Leaky Paywall cookie.
    if (
        isset($_COOKIE["lp_has_access"]) &&
        $_COOKIE["lp_has_access"] == "true"
    ) {
        return $content; // Display content if cookie is set
    }

    // Return content wrapped in a div and JavaScript to control visibility
    return '<div id="paywall-content" style="display:none;">' .
        $content .
        '</div>
           <script>
            PaywallAccess.subscribe(function(hasAccess) {
                if(hasAccess){
                    document.getElementById("paywall-content").style.display = "block";
                }
            });
           </script>';
}
