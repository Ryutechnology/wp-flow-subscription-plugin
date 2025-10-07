<?php
/**
 * Quick fix for checkout - ensures basic WooCommerce settings are correct
 */

// Force enable some basic WooCommerce settings that might prevent payment methods
add_action('init', function() {
    if (current_user_can('manage_options') && isset($_GET['flow_quick_fix'])) {

        // Enable guest checkout (in case it's disabled)
        update_option('woocommerce_enable_guest_checkout', 'yes');

        // Make sure checkout page exists and is set
        $checkout_page_id = wc_get_page_id('checkout');
        if ($checkout_page_id === -1) {
            // Create checkout page if it doesn't exist
            $checkout_page = array(
                'post_title'   => 'Checkout',
                'post_content' => '[woocommerce_checkout]',
                'post_status'  => 'publish',
                'post_type'    => 'page'
            );
            $checkout_page_id = wp_insert_post($checkout_page);
            update_option('woocommerce_checkout_page_id', $checkout_page_id);
        }

        // Force enable Flow gateway
        $flow_settings = get_option('woocommerce_flow_settings', array());
        $flow_settings['enabled'] = 'yes';
        $flow_settings['title'] = 'Flow Suscripciones';
        $flow_settings['description'] = 'Paga de forma segura con Flow';
        update_option('woocommerce_flow_settings', $flow_settings);

        // Add success message
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p><strong>Quick Fix Applied!</strong> Checkout settings updated and Flow gateway enabled.</p></div>';
        });

        wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout'));
        exit;
    }
});

// Add a quick diagnostic and fix button
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Quick Fix Checkout',
        'Flow Quick Fix',
        'manage_options',
        'flow-quick-fix',
        function() {
            echo '<div class="wrap">';
            echo '<h1>Flow Checkout Quick Fix</h1>';

            // Check current status
            echo '<h2>Current Status</h2>';

            // Check if checkout page exists
            $checkout_page_id = wc_get_page_id('checkout');
            echo '<p>Checkout page: ' . ($checkout_page_id !== -1 ? '✅ Exists' : '❌ Missing') . '</p>';

            // Check guest checkout
            $guest_checkout = get_option('woocommerce_enable_guest_checkout');
            echo '<p>Guest checkout: ' . ($guest_checkout === 'yes' ? '✅ Enabled' : '❌ Disabled') . '</p>';

            // Check Flow gateway settings
            $flow_settings = get_option('woocommerce_flow_settings', array());
            $flow_enabled = isset($flow_settings['enabled']) && $flow_settings['enabled'] === 'yes';
            echo '<p>Flow gateway: ' . ($flow_enabled ? '✅ Enabled' : '❌ Disabled') . '</p>';

            // Check available gateways
            if (function_exists('WC') && WC()->payment_gateways()) {
                $available = WC()->payment_gateways()->get_available_payment_gateways();
                echo '<p>Available payment methods: ' . count($available) . '</p>';
                if (!empty($available)) {
                    echo '<ul>';
                    foreach ($available as $gateway) {
                        echo '<li>' . $gateway->title . ' (' . $gateway->id . ')</li>';
                    }
                    echo '</ul>';
                }
            }

            echo '<h2>Quick Fix</h2>';
            echo '<p>Click the button below to automatically fix common checkout issues:</p>';
            echo '<p><a href="' . admin_url('admin.php?flow_quick_fix=1') . '" class="button-primary">Apply Quick Fix</a></p>';

            echo '<h2>Manual Steps</h2>';
            echo '<ol>';
            echo '<li><a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">Go to WooCommerce Payment Settings</a></li>';
            echo '<li>Make sure "Flow Suscripciones" is enabled</li>';
            echo '<li><a href="' . admin_url('admin.php?page=wc-settings&tab=account') . '">Check Account Settings</a> - ensure guest checkout is allowed</li>';
            echo '</ol>';

            echo '</div>';
        }
    );
});
?>