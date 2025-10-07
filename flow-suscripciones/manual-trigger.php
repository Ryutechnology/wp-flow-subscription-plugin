<?php
/**
 * Manual Gateway Registration Trigger
 * Add this to wp-config.php temporarily: require_once('/path/to/manual-trigger.php');
 */

// Add a manual trigger in WordPress admin
add_action('wp_loaded', function() {
    if (is_admin() && current_user_can('manage_options') && isset($_GET['flow_force_register'])) {

        echo '<div style="background: white; padding: 20px; margin: 20px; border: 1px solid #ccc;">';
        echo '<h2>Flow Gateway Manual Registration</h2>';

        // Check basic requirements
        echo '<h3>Requirements Check:</h3>';
        echo 'WooCommerce: ' . (class_exists('WooCommerce') ? '✅ Active' : '❌ Not Active') . '<br>';
        echo 'WC_Payment_Gateway: ' . (class_exists('WC_Payment_Gateway') ? '✅ Available' : '❌ Not Available') . '<br>';

        if (class_exists('WooCommerce') && class_exists('WC_Payment_Gateway')) {

            // Load our gateway class directly
            $gateway_file = plugin_dir_path(__FILE__) . 'includes/class-flow-payment-gateway.php';
            if (file_exists($gateway_file)) {
                require_once $gateway_file;
                echo 'Gateway file loaded: ✅ Success<br>';
            } else {
                echo 'Gateway file loaded: ❌ File not found at ' . $gateway_file . '<br>';
            }

            echo 'Flow_Payment_Gateway class: ' . (class_exists('Flow_Payment_Gateway') ? '✅ Loaded' : '❌ Not Loaded') . '<br>';

            // Check current gateways
            if (function_exists('WC') && WC()->payment_gateways()) {
                $gateways = WC()->payment_gateways()->payment_gateways();
                echo '<h3>Current WooCommerce Gateways:</h3>';
                foreach ($gateways as $id => $gateway) {
                    echo $id . ': ' . get_class($gateway) . '<br>';
                }

                // Try to add our gateway manually
                if (class_exists('Flow_Payment_Gateway') && !isset($gateways['flow'])) {
                    echo '<h3>Manual Registration Attempt:</h3>';
                    $gateways['flow'] = new Flow_Payment_Gateway();
                    echo 'Flow gateway manually added to array<br>';

                    // Try to update the WooCommerce gateways
                    WC()->payment_gateways()->payment_gateways = $gateways;
                    echo 'WooCommerce gateways array updated<br>';
                }

                // Check again
                $gateways_after = WC()->payment_gateways()->payment_gateways();
                if (isset($gateways_after['flow'])) {
                    echo '✅ Flow gateway now present in WooCommerce!<br>';
                } else {
                    echo '❌ Flow gateway still not registered<br>';
                }
            }

        } else {
            echo '<p style="color: red;">Cannot proceed - WooCommerce requirements not met</p>';
        }

        echo '<hr><p><a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">Go to WooCommerce Payment Settings</a></p>';
        echo '</div>';

        exit;
    }
});

// Add a link in admin to trigger manual registration
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Manual Register',
        'Flow Manual Register',
        'manage_options',
        'flow-manual-register',
        function() {
            echo '<div class="wrap">';
            echo '<h1>Flow Payment Gateway Manual Registration</h1>';
            echo '<p>If the Flow payment gateway is not appearing in WooCommerce, use this tool to force registration.</p>';
            echo '<p><a href="' . admin_url('admin.php?flow_force_register=1') . '" class="button-primary">Force Register Gateway</a></p>';
            echo '</div>';
        }
    );
});
?>