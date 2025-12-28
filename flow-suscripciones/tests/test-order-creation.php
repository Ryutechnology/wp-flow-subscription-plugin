<?php
/**
 * Test Order Creation from Flow Webhook
 * This script specifically tests if the webhook creates new WooCommerce orders
 */

if (!defined('ABSPATH')) {
    require_once '../../../wp-config.php';
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🛒 Test Flow Webhook Order Creation</h2>\n";

$callback_url = rest_url('flow/v1/payment-callback');

// Get order count before test
function get_order_count() {
    $orders = wc_get_orders([
        'limit' => -1,
        'status' => ['pending', 'processing', 'on-hold', 'completed']
    ]);
    return count($orders);
}

// Check if we should run the test
if (isset($_POST['run_test'])) {
    $test_type = sanitize_text_field($_POST['test_type']);
    $customer_email = sanitize_email($_POST['customer_email']);
    $test_amount = intval($_POST['test_amount']);

    echo "<h3>🧪 Running Order Creation Test...</h3>\n";
    echo "<p><strong>Test Type:</strong> $test_type</p>\n";
    echo "<p><strong>Customer Email:</strong> $customer_email</p>\n";
    echo "<p><strong>Amount:</strong> $" . number_format($test_amount, 0) . " CLP</p>\n";

    // Count orders before
    $orders_before = get_order_count();
    echo "<p><strong>Orders before test:</strong> $orders_before</p>\n";

    // Setup the mock payment response
    add_filter('flow_api_get_payment_status', function($response, $token) use ($test_amount, $customer_email) {
        if (strpos($token, 'order_test_') === 0) {
            return [
                'commerceOrder' => 'TEST_ORDER_' . time(),
                'amount' => $test_amount,
                'flowOrder' => 'FLW_ORDER_' . uniqid(),
                'status' => 1, // Success status
                'payer' => $customer_email,
                'paymentData' => [
                    'date' => date('Y-m-d H:i:s'),
                    'currency' => 'CLP'
                ]
            ];
        }
        return $response;
    }, 10, 2);

    // Generate test token
    $test_token = 'order_test_' . time() . '_' . rand(1000, 9999);

    // Call the webhook
    $response = wp_remote_post($callback_url, [
        'body' => ['token' => $test_token],
        'timeout' => 30,
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Flow-Order-Test'
        ]
    ]);

    echo "<hr>\n";

    if (is_wp_error($response)) {
        echo "<div style='color: red; background: #ffebee; padding: 15px; border-left: 4px solid red;'>\n";
        echo "<strong>❌ Webhook Error:</strong> " . $response->get_error_message() . "\n";
        echo "</div>\n";
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        echo "<div style='background: #e8f5e8; padding: 15px; border-left: 4px solid green;'>\n";
        echo "<strong>✅ Webhook Response:</strong> Status $status_code<br>\n";
        echo "<strong>Response:</strong> " . esc_html($response_body) . "\n";
        echo "</div>\n";

        // Count orders after
        sleep(2); // Wait a bit for order creation
        $orders_after = get_order_count();
        echo "<p><strong>Orders after test:</strong> $orders_after</p>\n";

        $orders_created = $orders_after - $orders_before;

        if ($orders_created > 0) {
            echo "<div style='background: #d4edda; padding: 15px; border: 2px solid #28a745; border-radius: 5px;'>\n";
            echo "<h4>🎉 SUCCESS: $orders_created new order(s) created!</h4>\n";

            // Get the latest orders
            $recent_orders = wc_get_orders([
                'limit' => $orders_created,
                'orderby' => 'date',
                'order' => 'DESC'
            ]);

            foreach ($recent_orders as $order) {
                echo "<p><strong>Order #" . $order->get_id() . "</strong><br>\n";
                echo "Email: " . $order->get_billing_email() . "<br>\n";
                echo "Status: " . $order->get_status() . "<br>\n";
                echo "Total: $" . number_format($order->get_total(), 0) . " CLP<br>\n";
                echo "Date: " . $order->get_date_created()->format('Y-m-d H:i:s') . "<br>\n";

                // Check if it's a recurring order
                $subscription_id = $order->get_meta('_flow_subscription_id');
                $original_order_id = $order->get_meta('_flow_original_order_id');

                if ($subscription_id) {
                    echo "Subscription ID: $subscription_id<br>\n";
                }
                if ($original_order_id) {
                    echo "Original Order ID: $original_order_id (This is a recurring order)<br>\n";
                }

                echo "</p>\n";
            }
            echo "</div>\n";
        } else {
            echo "<div style='background: #fff3cd; padding: 15px; border: 2px solid #ffc107; border-radius: 5px;'>\n";
            echo "<h4>⚠️ No new orders created</h4>\n";
            echo "<p>This could be due to:</p>\n";
            echo "<ul>\n";
            echo "<li>Payment status was not successful (status != 1)</li>\n";
            echo "<li>Missing customer email</li>\n";
            echo "<li>Error in order creation logic</li>\n";
            echo "<li>WooCommerce not properly configured</li>\n";
            echo "</ul>\n";
            echo "</div>\n";
        }
    }

    // Show recent log entries
    echo "<h4>📋 Recent Log Entries:</h4>\n";
    $log_file = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($log_file)) {
        $log_lines = file($log_file);
        $recent_lines = array_slice($log_lines, -30); // Last 30 lines

        echo "<div style='background: #f8f9fa; padding: 15px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; border: 1px solid #dee2e6;'>\n";
        foreach ($recent_lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (strpos($line, 'Flow Payment Callback') !== false) {
                echo "<span style='color: #007bff; font-weight: bold;'>" . esc_html($line) . "</span><br>\n";
            } elseif (strpos($line, 'ERROR') !== false || strpos($line, 'Fatal') !== false) {
                echo "<span style='color: #dc3545;'>" . esc_html($line) . "</span><br>\n";
            } elseif (strpos($line, 'Created') !== false || strpos($line, 'Success') !== false) {
                echo "<span style='color: #28a745;'>" . esc_html($line) . "</span><br>\n";
            } else {
                echo "<span style='color: #6c757d;'>" . esc_html($line) . "</span><br>\n";
            }
        }
        echo "</div>\n";
    } else {
        echo "<p><em>Debug log not found. Enable WP_DEBUG_LOG in wp-config.php</em></p>\n";
    }

    echo "<hr>\n";
}

// Check for existing subscriptions to test recurring orders
$existing_subscriptions = wc_get_orders([
    'meta_query' => [
        [
            'key' => '_flow_subscription_id',
            'compare' => 'EXISTS'
        ]
    ],
    'limit' => 5,
    'orderby' => 'date',
    'order' => 'DESC'
]);

?>

<h3>🛒 Order Creation Test Form</h3>
<form method="POST" style="max-width: 800px;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd;"><strong>Test Type:</strong></td>
            <td style="padding: 10px; border: 1px solid #ddd;">
                <label style="display: block; margin-bottom: 10px;">
                    <input type="radio" name="test_type" value="new_customer" checked>
                    <strong>New Customer Order</strong> - Test creating order for new customer
                </label>
                <label style="display: block;">
                    <input type="radio" name="test_type" value="recurring">
                    <strong>Recurring Order</strong> - Test creating recurring order for existing subscription
                </label>
            </td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd;"><strong>Customer Email:</strong></td>
            <td style="padding: 10px; border: 1px solid #ddd;">
                <input type="email" name="customer_email" value="test.orders@example.com"
                       style="width: 100%; padding: 8px;" required>
                <small>For recurring test, use email of existing subscription</small>
            </td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd;"><strong>Test Amount:</strong></td>
            <td style="padding: 10px; border: 1px solid #ddd;">
                <input type="number" name="test_amount" value="15000" min="1000" step="1000"
                       style="width: 200px; padding: 8px;" required>
                <small>Monto en pesos chilenos (15000 = $15.000 CLP)</small>
            </td>
        </tr>
    </table>

    <div style="padding: 20px 0;">
        <input type="submit" name="run_test" value="🧪 Run Order Creation Test"
               style="background: #007cba; color: white; padding: 15px 30px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
    </div>
</form>

<?php if (!empty($existing_subscriptions)): ?>
<h3>📋 Existing Subscriptions (for Recurring Tests)</h3>
<table border="1" style="border-collapse: collapse; width: 100%;">
    <tr style="background: #f0f0f0;">
        <th style="padding: 8px;">Order ID</th>
        <th style="padding: 8px;">Email</th>
        <th style="padding: 8px;">Subscription ID</th>
        <th style="padding: 8px;">Total</th>
        <th style="padding: 8px;">Date</th>
    </tr>
    <?php foreach ($existing_subscriptions as $order): ?>
    <tr>
        <td style="padding: 8px;">#<?php echo $order->get_id(); ?></td>
        <td style="padding: 8px;"><?php echo $order->get_billing_email(); ?></td>
        <td style="padding: 8px;"><?php echo $order->get_meta('_flow_subscription_id'); ?></td>
        <td style="padding: 8px;">$<?php echo number_format($order->get_total(), 0); ?> CLP</td>
        <td style="padding: 8px;"><?php echo $order->get_date_created()->format('Y-m-d H:i:s'); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<p><em>Use these emails in the form above to test recurring order creation</em></p>
<?php else: ?>
<h3>📋 No Existing Subscriptions Found</h3>
<p>Currently no subscription orders found. All tests will create new customer orders.</p>
<?php endif; ?>

<h3>📊 Expected Results</h3>
<div style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
    <h4>For New Customer:</h4>
    <ul>
        <li>✅ New WooCommerce order created</li>
        <li>✅ Order status: "processing"</li>
        <li>✅ Customer email matches test email</li>
        <li>✅ Order total matches test amount</li>
        <li>✅ No subscription metadata (new customer)</li>
    </ul>

    <h4>For Existing Subscription:</h4>
    <ul>
        <li>✅ New recurring order created</li>
        <li>✅ Copies billing info from original order</li>
        <li>✅ Links to original subscription order</li>
        <li>✅ Contains metadata: _flow_original_order_id</li>
        <li>✅ Same products as original subscription</li>
    </ul>

    <h4>Verification Steps:</h4>
    <ol>
        <li>Check "Orders after test" count increases</li>
        <li>Verify order details match payment data</li>
        <li>Check WordPress debug log for creation messages</li>
        <li>Confirm order appears in WooCommerce admin</li>
    </ol>
</div>

<h3>🔧 System Status</h3>
<?php
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
echo "<tr><td style='padding: 8px;'><strong>WordPress Version:</strong></td><td style='padding: 8px;'>" . get_bloginfo('version') . "</td></tr>\n";
echo "<tr><td style='padding: 8px;'><strong>WooCommerce:</strong></td><td style='padding: 8px;'>" . (class_exists('WooCommerce') ? '✅ Active v' . WC()->version : '❌ Not Active') . "</td></tr>\n";
echo "<tr><td style='padding: 8px;'><strong>Flow Plugin:</strong></td><td style='padding: 8px;'>" . (class_exists('Flow_Activator') ? '✅ Loaded' : '❌ Not Loaded') . "</td></tr>\n";
echo "<tr><td style='padding: 8px;'><strong>Current Orders:</strong></td><td style='padding: 8px;'>" . get_order_count() . "</td></tr>\n";
echo "<tr><td style='padding: 8px;'><strong>Callback URL:</strong></td><td style='padding: 8px;'><code>$callback_url</code></td></tr>\n";
echo "<tr><td style='padding: 8px;'><strong>Debug Log:</strong></td><td style='padding: 8px;'>" . (file_exists(WP_CONTENT_DIR . '/debug.log') ? '✅ Available' : '❌ Enable WP_DEBUG_LOG') . "</td></tr>\n";
echo "</table>\n";
?>

<h3>📝 Quick Manual Verification</h3>
<p>After running the test, you can also verify order creation by:</p>
<ul>
    <li><strong>WooCommerce Admin:</strong> Go to WooCommerce → Orders to see new orders</li>
    <li><strong>Database Check:</strong> Look in wp_posts table for post_type = 'shop_order'</li>
    <li><strong>REST API:</strong> <code>GET /wp-json/wc/v3/orders</code> (requires API access)</li>
    <li><strong>WordPress Admin:</strong> Check recent activity or notifications</li>
</ul>