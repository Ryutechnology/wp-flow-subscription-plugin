<?php
/**
 * Comprehensive WooCommerce Payment Method Requirements Checker
 */

add_action('wp_loaded', function() {
    if (current_user_can('manage_options') && isset($_GET['flow_check_requirements'])) {

        echo '<div style="background: white; padding: 20px; margin: 20px; border: 1px solid #ccc; font-family: monospace;">';
        echo '<h2>🔍 WooCommerce Payment Method Requirements Check</h2>';

        // 1. Basic WooCommerce Setup
        echo '<h3>1. Basic WooCommerce Setup</h3>';
        echo 'WooCommerce Active: ' . (class_exists('WooCommerce') ? '✅ YES' : '❌ NO') . '<br>';
        echo 'WC_Payment_Gateway Available: ' . (class_exists('WC_Payment_Gateway') ? '✅ YES' : '❌ NO') . '<br>';
        echo 'WC() Function Available: ' . (function_exists('WC') ? '✅ YES' : '❌ NO') . '<br>';

        if (!function_exists('WC')) {
            echo '<p style="color: red;">❌ WooCommerce not properly loaded - cannot continue checks</p>';
            echo '</div>';
            exit;
        }

        // 2. Gateway Registration
        echo '<h3>2. Gateway Registration</h3>';
        $all_gateways = WC()->payment_gateways()->payment_gateways();
        $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

        echo 'Total Registered Gateways: ' . count($all_gateways) . '<br>';
        echo 'Available Gateways: ' . count($available_gateways) . '<br>';

        if (isset($all_gateways['flow'])) {
            echo 'Flow Gateway Registered: ✅ YES<br>';
            $flow_gateway = $all_gateways['flow'];
            echo 'Flow Gateway Class: ' . get_class($flow_gateway) . '<br>';
            echo 'Flow Gateway Enabled: ' . ($flow_gateway->enabled === 'yes' ? '✅ YES' : '❌ NO') . '<br>';

            if (isset($available_gateways['flow'])) {
                echo 'Flow Gateway Available: ✅ YES<br>';
            } else {
                echo 'Flow Gateway Available: ❌ NO<br>';

                // Test is_available() method
                if (method_exists($flow_gateway, 'is_available')) {
                    $is_available = $flow_gateway->is_available();
                    echo 'Flow is_available() returns: ' . ($is_available ? '✅ TRUE' : '❌ FALSE') . '<br>';
                }
            }
        } else {
            echo 'Flow Gateway Registered: ❌ NO<br>';
        }

        // 3. Cart Status
        echo '<h3>3. Cart Status</h3>';
        if (WC()->cart) {
            echo 'Cart Exists: ✅ YES<br>';
            echo 'Cart Total: $' . WC()->cart->get_total() . '<br>';
            echo 'Cart Needs Payment: ' . (WC()->cart->needs_payment() ? '✅ YES' : '❌ NO') . '<br>';
            echo 'Cart Item Count: ' . WC()->cart->get_cart_contents_count() . '<br>';
            echo 'Cart Is Empty: ' . (WC()->cart->is_empty() ? '❌ YES' : '✅ NO') . '<br>';
        } else {
            echo 'Cart Exists: ❌ NO<br>';
        }

        // 4. Checkout Page
        echo '<h3>4. Checkout Configuration</h3>';
        $checkout_page_id = wc_get_page_id('checkout');
        echo 'Checkout Page ID: ' . $checkout_page_id . '<br>';
        echo 'Checkout Page Exists: ' . ($checkout_page_id !== -1 ? '✅ YES' : '❌ NO') . '<br>';

        if ($checkout_page_id !== -1) {
            $checkout_page = get_post($checkout_page_id);
            echo 'Checkout Page Status: ' . ($checkout_page && $checkout_page->post_status === 'publish' ? '✅ Published' : '❌ Not Published') . '<br>';
        }

        // 5. User/Guest Settings
        echo '<h3>5. User & Guest Settings</h3>';
        $guest_checkout = get_option('woocommerce_enable_guest_checkout');
        $account_required = get_option('woocommerce_enable_checkout_login_reminder');
        $user_logged_in = is_user_logged_in();

        echo 'Guest Checkout Enabled: ' . ($guest_checkout === 'yes' ? '✅ YES' : '❌ NO') . '<br>';
        echo 'User Logged In: ' . ($user_logged_in ? '✅ YES' : '❌ NO') . '<br>';
        echo 'Checkout Available: ' . (($guest_checkout === 'yes' || $user_logged_in) ? '✅ YES' : '❌ NO') . '<br>';

        // 6. Currency & Country
        echo '<h3>6. Currency & Location</h3>';
        echo 'Shop Currency: ' . get_woocommerce_currency() . '<br>';
        echo 'Shop Country: ' . WC()->countries->get_base_country() . '<br>';

        // Get customer country if available
        if (WC()->customer) {
            echo 'Customer Country: ' . (WC()->customer->get_billing_country() ?: 'Not Set') . '<br>';
        }

        // 7. Terms & Conditions
        echo '<h3>7. Terms & Conditions</h3>';
        $terms_page_id = wc_get_page_id('terms');
        $terms_required = $terms_page_id !== -1 && get_option('woocommerce_terms_page_id');
        echo 'Terms Page Required: ' . ($terms_required ? '⚠️ YES' : '✅ NO') . '<br>';

        // 8. Debug Available Gateways
        echo '<h3>8. All Available Payment Methods</h3>';
        if (!empty($available_gateways)) {
            foreach ($available_gateways as $gateway_id => $gateway) {
                echo '- ' . $gateway_id . ': ' . $gateway->title . ' (' . get_class($gateway) . ')<br>';
            }
        } else {
            echo '❌ NO PAYMENT METHODS AVAILABLE<br>';
        }

        // 9. Quick Fixes
        echo '<h3>9. Quick Fixes</h3>';
        echo '<a href="' . admin_url('admin.php?flow_quick_fix=1') . '" style="background: #0073aa; color: white; padding: 10px; text-decoration: none;">Apply Quick Fix</a><br><br>';

        // 10. Manual Steps
        echo '<h3>10. Manual Troubleshooting Steps</h3>';
        echo '<ol>';
        echo '<li><a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">Check Payment Settings</a></li>';
        echo '<li><a href="' . admin_url('admin.php?page=wc-settings&tab=account') . '">Check Account Settings</a></li>';
        echo '<li><a href="' . admin_url('edit.php?post_type=page') . '">Check Pages</a> - Ensure checkout page exists</li>';
        echo '<li>Add product to cart and test checkout</li>';
        echo '</ol>';

        echo '</div>';
        exit;
    }
});

// Add to admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Requirements Check',
        'Flow Requirements',
        'manage_options',
        'flow-requirements',
        function() {
            echo '<div class="wrap">';
            echo '<h1>WooCommerce Payment Method Requirements</h1>';
            echo '<p>Check all requirements for payment methods to appear in checkout.</p>';
            echo '<p><a href="' . admin_url('admin.php?flow_check_requirements=1') . '" class="button-primary">Check All Requirements</a></p>';

            echo '<h2>Common Issues & Solutions</h2>';
            echo '<ul>';
            echo '<li><strong>Empty Cart:</strong> Add products to cart before testing</li>';
            echo '<li><strong>Cart Total $0:</strong> Ensure products have prices > 0</li>';
            echo '<li><strong>Guest Checkout Disabled:</strong> Enable guest checkout or login</li>';
            echo '<li><strong>Gateway Disabled:</strong> Enable Flow gateway in settings</li>';
            echo '<li><strong>Missing Checkout Page:</strong> Recreate WooCommerce pages</li>';
            echo '</ul>';

            echo '</div>';
        }
    );
});

// Add admin notice if critical requirements are missing
add_action('admin_notices', function() {
    if (current_user_can('manage_options') && function_exists('WC')) {
        $issues = array();

        // Check critical issues
        $checkout_page_id = wc_get_page_id('checkout');
        if ($checkout_page_id === -1) {
            $issues[] = 'Checkout page missing';
        }

        $guest_checkout = get_option('woocommerce_enable_guest_checkout');
        if ($guest_checkout !== 'yes' && !is_user_logged_in()) {
            $issues[] = 'Guest checkout disabled';
        }

        $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if (empty($available_gateways)) {
            $issues[] = 'No payment methods available';
        }

        if (!empty($issues)) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>WooCommerce Payment Issues:</strong> ' . implode(', ', $issues) . '</p>';
            echo '<p><a href="' . admin_url('tools.php?page=flow-requirements') . '">Check Requirements</a> | ';
            echo '<a href="' . admin_url('admin.php?flow_quick_fix=1') . '">Quick Fix</a></p>';
            echo '</div>';
        }
    }
});
?>