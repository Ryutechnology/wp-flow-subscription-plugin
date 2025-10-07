<?php
/**
 * Checkout Compatibility Checker
 */

add_action('wp_loaded', function() {
    if (current_user_can('manage_options') && isset($_GET['flow_checkout_compatibility'])) {

        echo '<div style="background: white; padding: 20px; margin: 20px; border: 1px solid #ccc;">';
        echo '<h2>🔍 Flow Checkout Compatibility Check</h2>';

        // Check WooCommerce version
        echo '<h3>WooCommerce Version</h3>';
        if (defined('WC_VERSION')) {
            echo 'WooCommerce Version: ' . WC_VERSION . '<br>';

            $supports_blocks = version_compare(WC_VERSION, '8.3', '>=');
            echo 'Supports Block Checkout: ' . ($supports_blocks ? '✅ YES' : '❌ NO') . '<br>';
        } else {
            echo 'WooCommerce Version: ❌ Unknown<br>';
        }

        // Check checkout page content
        echo '<h3>Checkout Page Analysis</h3>';
        $checkout_page_id = wc_get_page_id('checkout');

        if ($checkout_page_id > 0) {
            $checkout_page = get_post($checkout_page_id);
            echo 'Checkout Page ID: ' . $checkout_page_id . '<br>';
            echo 'Checkout Page Status: ' . ($checkout_page->post_status === 'publish' ? '✅ Published' : '❌ Not Published') . '<br>';

            $has_blocks = strpos($checkout_page->post_content, '<!-- wp:woocommerce/checkout') !== false;
            $has_shortcode = strpos($checkout_page->post_content, '[woocommerce_checkout]') !== false;

            echo 'Uses Block Checkout: ' . ($has_blocks ? '⚠️ YES' : '✅ NO') . '<br>';
            echo 'Uses Classic Shortcode: ' . ($has_shortcode ? '✅ YES' : '❌ NO') . '<br>';

            if ($has_blocks) {
                echo '<p style="color: orange;"><strong>Block Checkout Detected:</strong> This may cause compatibility issues with Flow gateway.</p>';
            } elseif ($has_shortcode) {
                echo '<p style="color: green;"><strong>Classic Checkout:</strong> Compatible with Flow gateway.</p>';
            } else {
                echo '<p style="color: red;"><strong>Unknown Checkout Type:</strong> Please check checkout page content.</p>';
            }

        } else {
            echo 'Checkout Page: ❌ Not Found<br>';
        }

        // Check block support status
        echo '<h3>Block Support Status</h3>';
        echo 'WooCommerce Blocks Plugin: ' . (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType') ? '✅ Available' : '❌ Not Available') . '<br>';
        echo 'Flow Block Integration: ' . (class_exists('Flow_Blocks_Support') ? '✅ Loaded' : '❌ Not Loaded') . '<br>';

        // Check Flow gateway in different contexts
        echo '<h3>Flow Gateway Status</h3>';
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $available = WC()->payment_gateways()->get_available_payment_gateways();

            echo 'Flow Gateway Registered: ' . (isset($gateways['flow']) ? '✅ YES' : '❌ NO') . '<br>';
            echo 'Flow Gateway Available: ' . (isset($available['flow']) ? '✅ YES' : '❌ NO') . '<br>';

            if (isset($gateways['flow'])) {
                $flow_gateway = $gateways['flow'];
                echo 'Flow Gateway Supports: ' . implode(', ', $flow_gateway->supports ?? []) . '<br>';
            }
        }

        // Recommendations
        echo '<h3>Recommendations</h3>';
        if ($has_blocks) {
            echo '<div style="background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7;">';
            echo '<p><strong>Recommended Action:</strong> Switch to classic checkout for better compatibility.</p>';
            echo '<p><a href="' . admin_url('admin.php?flow_fix_checkout_blocks=1') . '" style="background: #007cba; color: white; padding: 8px 12px; text-decoration: none;">Switch to Classic Checkout</a></p>';
            echo '</div>';
        } else {
            echo '<div style="background: #d4edda; padding: 10px; border: 1px solid #c3e6cb;">';
            echo '<p><strong>Status:</strong> Your checkout should be compatible with Flow gateway.</p>';
            echo '</div>';
        }

        echo '<h3>Manual Steps</h3>';
        echo '<ol>';
        echo '<li><a href="' . admin_url('post.php?post=' . $checkout_page_id . '&action=edit') . '">Edit Checkout Page</a></li>';
        echo '<li>Replace any checkout blocks with: <code>[woocommerce_checkout]</code></li>';
        echo '<li><a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">Check Payment Settings</a></li>';
        echo '<li>Test checkout with a product in cart</li>';
        echo '</ol>';

        echo '</div>';
        exit;
    }
});

// Add to admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Checkout Compatibility',
        'Flow Compatibility',
        'manage_options',
        'flow-compatibility',
        function() {
            echo '<div class="wrap">';
            echo '<h1>Flow Checkout Compatibility</h1>';
            echo '<p>Check compatibility between Flow payment gateway and your checkout configuration.</p>';
            echo '<p><a href="' . admin_url('admin.php?flow_checkout_compatibility=1') . '" class="button-primary">Run Compatibility Check</a></p>';
            echo '</div>';
        }
    );
});
?>