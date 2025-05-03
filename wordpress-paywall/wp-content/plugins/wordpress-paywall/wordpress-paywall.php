<?php
/**
 * Plugin Name: WordPress Paywall with MemberPress Integration
 * Plugin URI: http://example.com/wordpress-paywall
 * Description: A paywall system that works with MemberPress and micropayments
 * Version: 1.5.0
 * Author: Your Name
 * Author URI: http://example.com
 * License: GPL2
 */

if (!defined("ABSPATH")) {
    exit();
}

class WordPress_Paywall
{
    private $debug_mode = true;

    // -- PAYWALL ON/OFF SWITCH --
    // Set this to false to completely disable the paywall functionality
    private $paywall_enabled = true;
    // -- END PAYWALL ON/OFF SWITCH --

    public function __construct()
    {
        // Initialize plugin on WordPress init (when WordPress core functions are available)
        add_action("init", [$this, "init_plugin"]);

        // Core functionality (only if paywall is enabled)
        if ($this->paywall_enabled) {
            add_action("wp_enqueue_scripts", [$this, "enqueue_scripts"]);
            add_action("wp_ajax_openpage_unlock_post", [
                $this,
                "handle_unlock",
            ]);
            add_action("wp_ajax_nopriv_openpage_unlock_post", [
                $this,
                "handle_unlock",
            ]);
        }
    }

    /**
     * Initialize the plugin after WordPress core is loaded
     */
    public function init_plugin()
    {
        // Only set up filters if paywall is enabled
        if (!$this->paywall_enabled) {
            $this->simple_log("Paywall is DISABLED");
            return;
        }

        // Force display full content if unlocked (runs LAST)
        add_filter(
            "the_content",
            [$this, "force_display_full_content"],
            999999
        );

        // Since this runs on WordPress init, we can use WordPress functions safely
        if ($this->is_memberpress_active()) {
            $this->setup_memberpress_integration();
            $this->simple_log("MemberPress detected, integration set up");
        } else {
            $this->simple_log(
                "MemberPress not detected, using standard content filter"
            );

            // If no MemberPress, we'll handle content restriction ourselves
            add_filter("the_content", [$this, "maybe_restrict_content"], 10);
        }
    }

    /**
     * Setup MemberPress integration
     */
    private function setup_memberpress_integration()
    {
        // CORRECT OFFICIAL MEMBERPRESS HOOKS
        // These hooks let us bypass MemberPress protection for unlocked content
        add_filter(
            "mepr-pre-run-rule-content",
            [$this, "maybe_bypass_memberpress_content"],
            11,
            3
        );
        add_filter(
            "mepr-pre-run-rule-redirection",
            [$this, "maybe_bypass_memberpress_redirect"],
            11,
            3
        );

        // Additional protection bypass for MemberPress
        add_filter(
            "mepr_is_content_restricted",
            [$this, "maybe_unrestrict_content_check"],
            999,
            2
        );

        // Most importantly - this removes any unauthorized message
        add_filter(
            "mepr-unauthorized-message",
            [$this, "maybe_remove_unauthorized_message"],
            999,
            2
        );

        // Very aggressive content replacement - use when MemberPress is stubborn
        add_filter(
            "mepr-authorize-content",
            [$this, "maybe_force_content_display"],
            999,
            2
        );

        // Another filter that MemberPress sometimes uses
        add_filter(
            "mepr_process_post_content_shortcodes",
            [$this, "force_process_shortcodes"],
            999,
            2
        );
    }

    /**
     * Check if MemberPress is active
     */
    private function is_memberpress_active()
    {
        // Check only if paywall is enabled
        if (!$this->paywall_enabled) {
            return false;
        }
        return class_exists("MeprCtrlFactory") ||
            class_exists("MeprHooks") ||
            class_exists("MeprRulesCtrl");
    }

    /**
     * Enqueue necessary scripts
     */
    public function enqueue_scripts()
    {
        // Don't load scripts if paywall is disabled
        if (!$this->paywall_enabled || !is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        wp_enqueue_script(
            "paywall-access",
            plugin_dir_url(__FILE__) . "js/paywall-access.js",
            ["jquery"],
            "1.5.0", // Version number matches plugin version
            true
        );

        wp_enqueue_script(
            "paywall",
            plugin_dir_url(__FILE__) . "js/paywall.js",
            ["jquery", "paywall-access"],
            "1.5.0",
            true
        );

        // Check if this post is already unlocked
        $is_already_unlocked = $this->has_unlock_access($post_id);

        wp_localize_script("paywall-access", "OpenPageUnlocker", [
            "ajax_url" => admin_url("admin-ajax.php"),
            "nonce" => wp_create_nonce("openpage_unlock"),
            "post_id" => $post_id,
            "is_already_unlocked" => $is_already_unlocked, // Tell JS if we're already unlocked
            "reload_on_unlock" => false, // Let JS handle this rather than forcing reloads
        ]);
    }

    /**
     * Our own content restriction function for non-MemberPress sites
     */
    public function maybe_restrict_content($content)
    {
        // Ignore if paywall is disabled
        if (!$this->paywall_enabled) {
            return $content;
        }

        if (!is_singular() || is_admin()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        // If this content is unlocked or user is admin, show full content
        if (
            $this->has_unlock_access($post_id) ||
            current_user_can("edit_posts")
        ) {
            return $content;
        }

        // Otherwise, show a truncated version with paywall
        $excerpt = wp_trim_words($content, 55, "...");

        return $excerpt .
            '
        <div class="paywall-overlay" style="text-align: center; padding: 20px; margin-top: 20px; background: #f8f9fa; border-radius: 5px;">
            <p>This content is locked</p>
            <button class="show-modal" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">
                Unlock with OpenPage
            </button>
        </div>';
    }

    /**
     * Handle unlock request via AJAX
     */
    public function handle_unlock()
    {
        // Don't process unlock if paywall is disabled
        if (!$this->paywall_enabled) {
            wp_send_json_error(["message" => "Paywall is disabled"]);
            return;
        }

        check_ajax_referer("openpage_unlock", "nonce");

        $post_id = isset($_POST["post_id"]) ? (int) $_POST["post_id"] : 0;
        $has_real_access =
            isset($_POST["has_real_access"]) &&
            $_POST["has_real_access"] === "1";

        if ($post_id <= 0) {
            $this->simple_log("Invalid post ID in unlock request");
            wp_send_json_error([
                "message" => "Invalid post ID",
                "reset_access" => true,
            ]);
            return;
        }

        // CRITICAL: Only store unlock if real access was granted
        if (!$has_real_access) {
            $this->simple_log(
                "Unlock request without real access for post $post_id, rejecting"
            );
            wp_send_json_error([
                "message" => "No micropayment access verified",
                "reset_access" => true,
            ]);
            return;
        }

        // Check if already unlocked to prevent spam
        if ($this->has_unlock_access($post_id)) {
            $this->simple_log(
                "Post $post_id already unlocked, ignoring duplicate request"
            );
            wp_send_json_success([
                "message" => "Already unlocked",
                "already_unlocked" => true,
                "reload" => false,
            ]);
            return;
        }

        // Store unlock status based on user login state
        if (is_user_logged_in()) {
            $this->store_unlock_for_user($post_id);
        } else {
            $this->store_unlock_in_cookie($post_id);
        }

        $this->simple_log("Unlock successful for post ID: $post_id");
        wp_send_json_success([
            "message" => "Content unlocked successfully",
            "reload" => true,
        ]);
    }

    /**
     * Store unlock status in user meta for logged-in users
     */
    private function store_unlock_for_user($post_id)
    {
        $user_id = get_current_user_id();
        $unlocked_posts = get_user_meta(
            $user_id,
            "openpage_unlocked_posts",
            true
        );

        if (!is_array($unlocked_posts)) {
            $unlocked_posts = [];
        }

        $unlocked_posts[$post_id] = time();
        update_user_meta($user_id, "openpage_unlocked_posts", $unlocked_posts);
        $this->simple_log("Stored unlock for user $user_id, post: $post_id");
    }

    /**
     * Store unlock status in cookie for non-logged in users
     */
    private function store_unlock_in_cookie($post_id)
    {
        $cookie_name = "openpage_unlocked_posts";
        $unlocked_posts = isset($_COOKIE[$cookie_name])
            ? json_decode(stripslashes($_COOKIE[$cookie_name]), true)
            : [];

        if (!is_array($unlocked_posts)) {
            $unlocked_posts = [];
        }

        $unlocked_posts[$post_id] = time();

        // Set cookie with correct path and secure settings
        $secure = is_ssl();
        $http_only = false; // Allow JavaScript to read cookie
        $path = "/";
        $domain = ""; // Empty uses current domain

        setcookie(
            $cookie_name,
            json_encode($unlocked_posts),
            time() + 86400 * 30,
            $path,
            $domain,
            $secure,
            $http_only
        );
        $_COOKIE[$cookie_name] = json_encode($unlocked_posts); // Also update the current request
        $this->simple_log(
            "Stored unlock in cookie for post: $post_id, value: " .
                json_encode($unlocked_posts)
        );
    }

    /**
     * Check if a post has been unlocked
     */
    private function has_unlock_access($post_id)
    {
        // If paywall is disabled, everyone has access
        if (!$this->paywall_enabled) {
            return true;
        }

        // Always allow admin/editor access
        if (current_user_can("edit_posts")) {
            $this->simple_log("Admin/editor access granted for post: $post_id");
            return true;
        }

        if (is_user_logged_in()) {
            // Check user meta for unlocked posts
            $user_id = get_current_user_id();
            $unlocked_posts = get_user_meta(
                $user_id,
                "openpage_unlocked_posts",
                true
            );

            if (is_array($unlocked_posts) && isset($unlocked_posts[$post_id])) {
                $this->simple_log(
                    "Found unlock in user meta for post: $post_id"
                );
                return true;
            }
        } else {
            // Check cookie for unlocked posts
            $cookie_name = "openpage_unlocked_posts";
            if (isset($_COOKIE[$cookie_name])) {
                $cookie_value = $_COOKIE[$cookie_name];
                $this->simple_log("Found cookie: $cookie_value");

                $unlocked_posts = json_decode(
                    stripslashes($cookie_value),
                    true
                );

                if (
                    is_array($unlocked_posts) &&
                    isset($unlocked_posts[$post_id])
                ) {
                    $this->simple_log(
                        "Found unlock in cookie for post: $post_id (cookie value: $cookie_value)"
                    );
                    return true;
                } else {
                    $this->simple_log(
                        "Post $post_id not found in cookie (cookie value: $cookie_value)"
                    );
                }
            } else {
                $this->simple_log("No unlock cookie found");
            }
        }

        $this->simple_log("No unlock access for post: $post_id");
        return false;
    }

    /**
     * MemberPress bypass: Content protection
     * This is the main hook that tells MemberPress to ignore its rules
     */
    public function maybe_bypass_memberpress_content($protect, $post, $uri = "")
    {
        // Ignore if paywall is disabled
        if (!$this->paywall_enabled) {
            return $protect;
        }

        $post_id = $this->get_post_id_from_input($post);
        if (!$post_id) {
            return $protect;
        }

        $this->simple_log("MemberPress content check for post: $post_id");

        if ($this->has_unlock_access($post_id)) {
            $this->simple_log(
                "Bypassing MemberPress protection for post: $post_id"
            );
            return false; // Do not protect this content
        }

        return $protect;
    }

    /**
     * MemberPress bypass: Redirection
     * This prevents MemberPress from redirecting away from unlocked content
     */
    public function maybe_bypass_memberpress_redirect($redirect, $url, $delim)
    {
        // Ignore if paywall is disabled
        if (!$this->paywall_enabled) {
            return $redirect;
        }

        global $post;
        $post_id = isset($post->ID) ? $post->ID : 0;

        if ($post_id && $this->has_unlock_access($post_id)) {
            $this->simple_log(
                "Bypassing MemberPress redirect for post: $post_id"
            );
            return false; // Do not redirect away from this content
        }

        return $redirect;
    }

    /**
     * MemberPress bypass: Content restriction check
     */
    public function maybe_unrestrict_content_check($is_restricted, $post_id)
    {
        // Ignore if paywall is disabled
        if (!$this->paywall_enabled) {
            return $is_restricted;
        }

        if ($post_id && $this->has_unlock_access($post_id)) {
            $this->simple_log(
                "Unrestricting MemberPress content check for post: $post_id"
            );
            return false; // Not restricted
        }

        return $is_restricted;
    }

    /**
     * Remove the unauthorized message if content is unlocked
     */
    public function maybe_remove_unauthorized_message($message, $post_id)
    {
        // Ignore if paywall is disabled
        if (!$this->paywall_enabled) {
            return $message;
        }

        if ($post_id && $this->has_unlock_access($post_id)) {
            $this->simple_log(
                "Removing unauthorized message for post: $post_id"
            );
            return ""; // Empty message
        }

        return $message;
    }

    /**
     * Force display of full content when MemberPress normally wouldn't
     */
    public function maybe_force_content_display($content, $post_id)
    {
        // Ignore if paywall is disabled
        if (!$this->paywall_enabled) {
            return $content;
        }

        if ($post_id && $this->has_unlock_access($post_id)) {
            $post = get_post($post_id);
            if ($post) {
                $this->simple_log(
                    "Forcing display of full content for post: $post_id"
                );
                return $post->post_content; // Return full content
            }
        }

        return $content;
    }

    /**
     * Force shortcodes to be processed in content
     */
    public function force_process_shortcodes($process, $post_id)
    {
        // Ignore if paywall is disabled
        if (!$this->paywall_enabled) {
            return $process;
        }

        if ($post_id && $this->has_unlock_access($post_id)) {
            $this->simple_log(
                "Forcing shortcode processing for post: $post_id"
            );
            return true; // Process shortcodes
        }

        return $process;
    }

    /**
     * This is our ultimate fallback that will always show the full content
     * if the post has been unlocked, regardless of any other filtering
     */
    public function force_display_full_content($content)
    {
        // Ignore if paywall is disabled
        if (!$this->paywall_enabled) {
            return $content;
        }

        if (!is_singular() || is_admin()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        if ($this->has_unlock_access($post_id)) {
            // If this is already the raw post content, return it
            $post = get_post($post_id);
            if (!$post) {
                return $content;
            }

            $this->simple_log(
                "Force displaying full content for post: $post_id"
            );

            // This is the most aggressive approach - completely ignore any filtering
            // and return the raw post content with shortcodes processed
            $full_content = $post->post_content;

            // Process shortcodes in the content
            $full_content = do_shortcode($full_content);

            // Fix images and formatting
            $full_content = wpautop($full_content);

            return $full_content;
        }

        return $content;
    }

    /**
     * Helper to get a post ID from various inputs
     */
    private function get_post_id_from_input($post_input)
    {
        if (is_numeric($post_input)) {
            return (int) $post_input;
        }

        if (is_object($post_input) && isset($post_input->ID)) {
            return (int) $post_input->ID;
        }

        if (is_array($post_input) && isset($post_input["ID"])) {
            return (int) $post_input["ID"];
        }

        // If we have a global post and no other ID was found
        global $post;
        if (isset($post->ID)) {
            return (int) $post->ID;
        }

        return 0;
    }

    /**
     * Simple error logging that doesn't rely on WordPress functions
     */
    private function simple_log($message)
    {
        if (!$this->debug_mode) {
            return;
        }

        // Log to WordPress error log
        error_log("[WordPress Paywall] $message");

        // Log to custom file
        $log_file = plugin_dir_path(__FILE__) . "paywall-debug.log";
        $timestamp = date("[Y-m-d H:i:s]");
        $user_info = "";

        // Only try to get user info if WordPress is fully loaded
        if (
            function_exists("is_user_logged_in") &&
            is_user_logged_in() &&
            function_exists("get_current_user_id")
        ) {
            $user_info = " [User: " . get_current_user_id() . "]";
        } else {
            $user_info = " [System]";
        }

        // Safely append to file
        $file_content = "$timestamp$user_info $message\n";
        @file_put_contents($log_file, $file_content, FILE_APPEND);
    }
}

// Initialize the plugin
new WordPress_Paywall();
