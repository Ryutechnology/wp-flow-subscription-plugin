<?php
/**
 * Force Classic Checkout (Alternative Solution)
 * This forces WooCommerce to use the classic checkout instead of blocks
 */

if (!defined('ABSPATH')) exit;

// Removed problematic filter that was causing shortcode errors

// Option 2: Check and fix checkout page content (safer approach)
add_action('admin_init', function() {
    // Only run for admin users and not on every page load
    if (!current_user_can('manage_options')) {
        return;
    }

    if (function_exists('wc_get_page_id')) {
        $checkout_page_id = wc_get_page_id('checkout');

        if ($checkout_page_id > 0) {
            $checkout_page = get_post($checkout_page_id);

            // If the checkout page contains block content, prepare to replace it
            if ($checkout_page && strpos($checkout_page->post_content, '<!-- wp:woocommerce/checkout') !== false) {
                // Add admin notice instead of automatic replacement
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning">';
                    echo '<p><strong>Flow Suscripciones:</strong> Block checkout detected. ';
                    echo '<a href="' . admin_url('admin.php?flow_fix_checkout_blocks=1') . '">Switch to classic checkout</a> for better compatibility.</p>';
                    echo '</div>';
                });
            }
        }
    }
});

// Removed duplicate admin notice - handled above in admin_init

// Manual fix action
add_action('init', function() {
    if (current_user_can('manage_options') && isset($_GET['flow_fix_checkout_blocks'])) {
        $checkout_page_id = wc_get_page_id('checkout');

        if ($checkout_page_id > 0) {
            // Create clean shortcode content
            $clean_content = '[woocommerce_checkout]';

            // Update the page with clean content
            $result = wp_update_post([
                'ID' => $checkout_page_id,
                'post_content' => $clean_content
            ], true);

            if (is_wp_error($result)) {
                wp_redirect(admin_url('edit.php?post_type=page&message=error'));
            } else {
                wp_redirect(admin_url('edit.php?post_type=page&message=6'));
            }
            exit;
        }
    }
});

// Add admin menu option
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Checkout Fix',
        'Flow Checkout Fix',
        'manage_options',
        'flow-checkout-fix',
        function() {
            echo '<div class="wrap">';
            echo '<h1>Flow Checkout Block Fix</h1>';

            $checkout_page_id = wc_get_page_id('checkout');
            $checkout_page = get_post($checkout_page_id);
            $is_block_checkout = $checkout_page && strpos($checkout_page->post_content, '<!-- wp:woocommerce/checkout') !== false;

            echo '<h2>Current Checkout Type</h2>';
            if ($is_block_checkout) {
                echo '<p>❌ <strong>Block Checkout</strong> - May cause compatibility issues with Flow gateway</p>';
                echo '<p><a href="' . admin_url('admin.php?flow_fix_checkout_blocks=1') . '" class="button-primary">Switch to Classic Checkout</a></p>';
            } else {
                echo '<p>✅ <strong>Classic Checkout</strong> - Compatible with Flow gateway</p>';
            }

            echo '<h2>Manual Steps</h2>';
            echo '<ol>';
            echo '<li><a href="' . admin_url('post.php?post=' . $checkout_page_id . '&action=edit') . '">Edit Checkout Page</a></li>';
            echo '<li>Remove any checkout blocks</li>';
            echo '<li>Add the shortcode: <code>[woocommerce_checkout]</code></li>';
            echo '<li>Save the page</li>';
            echo '</ol>';

            echo '</div>';
        }
    );
});
?>