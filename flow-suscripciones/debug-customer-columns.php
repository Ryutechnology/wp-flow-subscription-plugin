<?php
/**
 * Debug Customer Columns - Tool to help identify WooCommerce customer list implementation
 */

if (!defined('ABSPATH')) exit;

// Add debug menu item
add_action('admin_menu', 'flow_debug_customer_columns_menu');

function flow_debug_customer_columns_menu() {
    add_submenu_page(
        'woocommerce',
        'Flow Debug Columns',
        'Debug Columns',
        'manage_options',
        'flow-debug-columns',
        'flow_debug_customer_columns_page'
    );
}

function flow_debug_customer_columns_page() {
    ?>
    <div class="wrap">
        <h1>Flow Customer Columns Debug</h1>

        <h2>Current WooCommerce Customer List Implementation</h2>

        <?php
        // Check various WooCommerce classes and hooks
        $debug_info = array();

        // Check if WooCommerce is active
        $debug_info['WooCommerce Active'] = class_exists('WooCommerce') ? 'Yes' : 'No';

        // Check WooCommerce version
        if (defined('WC_VERSION')) {
            $debug_info['WooCommerce Version'] = WC_VERSION;
        }

        // Check for WooCommerce Admin
        $debug_info['WooCommerce Admin Active'] = class_exists('Automattic\WooCommerce\Admin\WCAdminHelper') ? 'Yes' : 'No';

        // Check for customer list table classes
        $debug_info['WC_Admin_Customers_List_Table'] = class_exists('WC_Admin_Customers_List_Table') ? 'Yes' : 'No';

        // Check current screen
        $screen = get_current_screen();
        if ($screen) {
            $debug_info['Current Screen ID'] = $screen->id;
            $debug_info['Current Screen Base'] = $screen->base;
        }

        // Check available hooks
        global $wp_filter;
        $relevant_hooks = array();
        foreach ($wp_filter as $hook_name => $hook) {
            if (strpos($hook_name, 'customer') !== false || strpos($hook_name, 'woocommerce') !== false) {
                if (strpos($hook_name, 'column') !== false || strpos($hook_name, 'list') !== false) {
                    $relevant_hooks[] = $hook_name;
                }
            }
        }

        // Check if we're currently on a customer-related page
        $current_page_info = array();
        if (isset($_GET['page'])) {
            $current_page_info['Current Page Parameter'] = $_GET['page'];
        }
        if (isset($_GET['role'])) {
            $current_page_info['Current Role Filter'] = $_GET['role'];
        }
        $referrer = wp_get_referer();
        if ($referrer) {
            $current_page_info['Referrer'] = $referrer;
        }

        // Get WooCommerce menu items for testing
        $wc_menu_items = array(
            array(
                'title' => 'WordPress Users (All)',
                'url' => 'users.php',
                'full_url' => admin_url('users.php')
            ),
            array(
                'title' => 'WordPress Users (Customers)',
                'url' => 'users.php?role=customer',
                'full_url' => admin_url('users.php?role=customer')
            ),
            array(
                'title' => 'WooCommerce Customers (Legacy)',
                'url' => 'admin.php?page=wc-customers',
                'full_url' => admin_url('admin.php?page=wc-customers')
            ),
            array(
                'title' => 'WooCommerce Admin Customers (React)',
                'url' => 'admin.php?page=wc-admin&path=/customers',
                'full_url' => admin_url('admin.php?page=wc-admin&path=%2Fcustomers')
            )
        );

        ?>

        <table class="widefat">
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($debug_info as $check => $result): ?>
                <tr>
                    <td><strong><?php echo esc_html($check); ?></strong></td>
                    <td><?php echo esc_html($result); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>Current Page Information</h3>
        <table class="widefat">
            <tbody>
                <?php foreach ($current_page_info as $key => $value): ?>
                <tr>
                    <td><strong><?php echo esc_html($key); ?></strong></td>
                    <td><?php echo esc_html($value); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>WooCommerce Customer Menu Items</h3>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Menu Title</th>
                    <th>Target URL</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($wc_menu_items as $item): ?>
                <tr>
                    <td><strong><?php echo esc_html($item['title']); ?></strong></td>
                    <td><code><?php echo esc_html($item['url']); ?></code></td>
                    <td><a href="<?php echo esc_url($item['full_url']); ?>" target="_blank">Test This Link</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>Relevant Available Hooks</h3>
        <ul>
            <?php foreach ($relevant_hooks as $hook): ?>
            <li><code><?php echo esc_html($hook); ?></code></li>
            <?php endforeach; ?>
        </ul>

        <h3>Test Links</h3>
        <ul>
            <li><a href="<?php echo admin_url('users.php'); ?>">WordPress Users (All)</a></li>
            <li><a href="<?php echo admin_url('users.php?role=customer'); ?>">WordPress Users (Customers Only)</a></li>
            <?php if (function_exists('WC')): ?>
            <li><a href="<?php echo admin_url('admin.php?page=wc-customers'); ?>">WooCommerce Customers</a></li>
            <li><a href="<?php echo admin_url('admin.php?page=wc-admin&path=/customers'); ?>"><strong>WooCommerce Admin Customers (React)</strong></a></li>
            <?php endif; ?>
        </ul>

        <div class="notice notice-info">
            <p><strong>Note for WooCommerce Admin (React Interface):</strong></p>
            <ul>
                <li>The React-based interface at <code>/wp-admin/admin.php?page=wc-admin&path=%2Fcustomers</code> uses different technology</li>
                <li>Flow columns are added via JavaScript that modifies the React table</li>
                <li>Flow data is provided through REST API integration</li>
                <li>If columns don't appear immediately, check browser console for JavaScript logs</li>
                <li>The interface may take a few seconds to load and modify the table</li>
            </ul>
        </div>

        <h3>Manual Column Test</h3>
        <p>Here's how the Flow columns would look:</p>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Flow Status</th>
                    <th>Flow Subscription ID</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Test Customer</td>
                    <td><span style="color: #46b450; font-weight: bold;">✓ Activa</span></td>
                    <td><code>flow_sub_123456</code></td>
                </tr>
                <tr>
                    <td>Another Customer</td>
                    <td><span style="color: #ffb900; font-weight: bold;">⏳ Pendiente</span></td>
                    <td><code>flow_sub_789012</code></td>
                </tr>
                <tr>
                    <td>Inactive Customer</td>
                    <td><span style="color: #dc3232; font-weight: bold;">✗ Inactiva</span></td>
                    <td><span style="color: #999;">-</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}

// Add admin notice to show debug page link
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if ($screen && (strpos($screen->id, 'woocommerce') !== false || strpos($screen->id, 'users') !== false)) {
        static $notice_shown = false;
        if ($notice_shown) return;
        $notice_shown = true;

        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>Flow Customer Columns Debug:</strong> ';
        echo '<a href="' . admin_url('admin.php?page=flow-debug-columns') . '">Click here to debug column visibility issues</a>';
        echo '</p>';
        echo '</div>';
    }
});
?>