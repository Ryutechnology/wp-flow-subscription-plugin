<?php
/**
 * Shortcode Error Fix and Diagnostic
 */

if (!defined('ABSPATH')) exit;

// Add diagnostic to check for shortcode issues
add_action('wp_loaded', function() {
    if (current_user_can('manage_options') && isset($_GET['flow_shortcode_check'])) {

        echo '<div style="background: white; padding: 20px; margin: 20px; border: 1px solid #ccc;">';
        echo '<h2>🔍 Shortcode Error Diagnostic</h2>';

        // Check WordPress version
        echo '<h3>WordPress Version</h3>';
        echo 'WordPress Version: ' . get_bloginfo('version') . '<br>';

        // Check checkout page content
        echo '<h3>Checkout Page Content</h3>';
        $checkout_page_id = wc_get_page_id('checkout');

        if ($checkout_page_id > 0) {
            $checkout_page = get_post($checkout_page_id);
            echo 'Checkout Page ID: ' . $checkout_page_id . '<br>';

            if ($checkout_page) {
                echo 'Content Length: ' . strlen($checkout_page->post_content) . ' characters<br>';

                // Check for problematic content
                $has_blocks = strpos($checkout_page->post_content, '<!-- wp:woocommerce/checkout') !== false;
                $has_shortcode = strpos($checkout_page->post_content, '[woocommerce_checkout]') !== false;
                $has_brackets_in_content = preg_match('/\[.*\[.*\].*\]/', $checkout_page->post_content);

                echo 'Contains Block Markup: ' . ($has_blocks ? '⚠️ YES' : '✅ NO') . '<br>';
                echo 'Contains Classic Shortcode: ' . ($has_shortcode ? '✅ YES' : '❌ NO') . '<br>';
                echo 'Contains Nested Brackets: ' . ($has_brackets_in_content ? '⚠️ YES' : '✅ NO') . '<br>';

                // Show actual content (first 500 chars)
                echo '<h4>Content Preview:</h4>';
                echo '<textarea readonly style="width: 100%; height: 150px;">';
                echo esc_textarea(substr($checkout_page->post_content, 0, 500));
                if (strlen($checkout_page->post_content) > 500) {
                    echo '...[truncated]';
                }
                echo '</textarea>';

                // Recommendations
                if ($has_blocks) {
                    echo '<div style="background: #fff3cd; padding: 10px; margin: 10px 0; border: 1px solid #ffeaa7;">';
                    echo '<strong>Issue Found:</strong> Block checkout detected. This may cause shortcode conflicts.<br>';
                    echo '<a href="' . admin_url('admin.php?flow_fix_checkout_blocks=1') . '" style="background: #007cba; color: white; padding: 8px 12px; text-decoration: none;">Fix Checkout Page</a>';
                    echo '</div>';
                } elseif (!$has_shortcode) {
                    echo '<div style="background: #f8d7da; padding: 10px; margin: 10px 0; border: 1px solid #f5c6cb;">';
                    echo '<strong>Issue Found:</strong> No valid checkout shortcode found.<br>';
                    echo '<a href="' . admin_url('post.php?post=' . $checkout_page_id . '&action=edit') . '">Edit Checkout Page</a>';
                    echo '</div>';
                } else {
                    echo '<div style="background: #d4edda; padding: 10px; margin: 10px 0; border: 1px solid #c3e6cb;">';
                    echo '<strong>Status:</strong> Checkout page looks good!';
                    echo '</div>';
                }
            }
        } else {
            echo '❌ Checkout page not found<br>';
        }

        // Check registered shortcodes
        echo '<h3>WooCommerce Shortcodes</h3>';
        global $shortcode_tags;

        $wc_shortcodes = array_filter($shortcode_tags, function($key) {
            return strpos($key, 'woocommerce') !== false;
        }, ARRAY_FILTER_USE_KEY);

        echo 'Registered WooCommerce Shortcodes:<br>';
        foreach ($wc_shortcodes as $shortcode => $callback) {
            echo '- [' . $shortcode . ']<br>';
        }

        echo '<h3>Quick Fixes</h3>';
        echo '<p><a href="' . admin_url('admin.php?flow_fix_checkout_blocks=1') . '" style="background: #0073aa; color: white; padding: 10px; text-decoration: none;">Fix Checkout Page</a></p>';

        echo '</div>';
        exit;
    }
});

// Add to admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Shortcode Fix',
        'Flow Shortcode Fix',
        'manage_options',
        'flow-shortcode-fix',
        function() {
            echo '<div class="wrap">';
            echo '<h1>Flow Shortcode Error Fix</h1>';
            echo '<p>Diagnose and fix shortcode-related errors with the checkout page.</p>';
            echo '<p><a href="' . admin_url('admin.php?flow_shortcode_check=1') . '" class="button-primary">Check Shortcode Issues</a></p>';
            echo '</div>';
        }
    );
});

// Clean up any problematic shortcode registrations on plugin activation
register_activation_hook(__FILE__, function() {
    // Ensure clean shortcode environment
    if (function_exists('remove_all_shortcodes')) {
        // Don't remove all, but ensure WooCommerce ones are clean
        if (function_exists('WC')) {
            WC()->shortcodes();
        }
    }
});
?>