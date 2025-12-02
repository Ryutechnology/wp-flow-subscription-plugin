<?php
/**
 * Flow Callback Simulator
 * This script simulates Flow sending a payment callback to test the endpoint
 */

if (!defined('ABSPATH')) {
    require_once '../../../wp-config.php';
}

// Enable error reporting for testing
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Flow Callback Simulator</h2>\n";

$callback_url = rest_url('flow/v1/payment-callback');
$site_url = home_url();

// Check if we should run the simulation
if (isset($_POST['simulate_callback'])) {
    $test_token = sanitize_text_field($_POST['test_token']);
    $mock_success = isset($_POST['mock_success']);

    echo "<h3>Running Simulation...</h3>\n";
    echo "<p><strong>Token:</strong> $test_token</p>\n";
    echo "<p><strong>Mock Success:</strong> " . ($mock_success ? 'Yes' : 'No') . "</p>\n";

    // Temporarily modify Flow API for testing if requested
    if ($mock_success) {
        // Create a temporary mock
        add_filter('flow_api_get_payment_status', function($response, $token) {
            if (strpos($token, 'test_') === 0) {
                return [
                    'commerceOrder' => 'ORDER_' . time(),
                    'amount' => 15000,
                    'flowOrder' => 'FLW_' . uniqid(),
                    'status' => 1, // 1 = successful payment (corrected based on system reminder)
                    'payer' => 'test@example.com',
                    'paymentData' => [
                        'date' => date('Y-m-d H:i:s')
                    ]
                ];
            }
            return $response;
        }, 10, 2);
    }

    // Simulate the callback
    $response = wp_remote_post($callback_url, [
        'body' => ['token' => $test_token],
        'timeout' => 30,
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Flow-Callback-Simulator'
        ]
    ]);

    if (is_wp_error($response)) {
        echo "<div style='color: red; background: #ffebee; padding: 15px; border-left: 4px solid red;'>\n";
        echo "<strong>❌ Error:</strong> " . $response->get_error_message() . "\n";
        echo "</div>\n";
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        echo "<div style='background: #e8f5e8; padding: 15px; border-left: 4px solid green;'>\n";
        echo "<strong>✅ Response Status:</strong> $status_code<br>\n";
        echo "<strong>Response Body:</strong><br>\n";
        echo "<pre>" . esc_html($response_body) . "</pre>\n";
        echo "</div>\n";

        // Try to decode JSON response
        $json_response = json_decode($response_body, true);
        if ($json_response) {
            echo "<h4>Parsed Response:</h4>\n";
            echo "<pre>" . json_encode($json_response, JSON_PRETTY_PRINT) . "</pre>\n";
        }
    }

    // Show recent log entries
    echo "<h4>Recent Log Entries:</h4>\n";
    $log_file = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($log_file)) {
        $log_lines = file($log_file);
        $recent_lines = array_slice($log_lines, -20); // Last 20 lines

        echo "<div style='background: #f5f5f5; padding: 10px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;'>\n";
        foreach ($recent_lines as $line) {
            if (strpos($line, 'Flow Payment Callback') !== false) {
                echo "<span style='color: blue;'>" . esc_html($line) . "</span><br>\n";
            } else {
                echo esc_html($line) . "<br>\n";
            }
        }
        echo "</div>\n";
    } else {
        echo "<p><em>Debug log not found. Make sure WP_DEBUG_LOG is enabled.</em></p>\n";
    }

    echo "<hr>\n";
}

?>

<h3>Callback Test Form</h3>
<form method="POST" style="max-width: 600px;">
    <table style="width: 100%;">
        <tr>
            <td style="padding: 10px;"><strong>Test Token:</strong></td>
            <td style="padding: 10px;">
                <input type="text" name="test_token" value="test_token_<?php echo time(); ?>"
                       style="width: 100%; padding: 5px;" required>
                <small>This token will be sent to the callback endpoint</small>
            </td>
        </tr>
        <tr>
            <td style="padding: 10px;"><strong>Mock Success:</strong></td>
            <td style="padding: 10px;">
                <label>
                    <input type="checkbox" name="mock_success" value="1" checked>
                    Simulate successful payment (will create a test order)
                </label>
                <br><small>If unchecked, will test with real Flow API call (will likely fail with test token)</small>
            </td>
        </tr>
    </table>

    <div style="padding: 20px 10px;">
        <input type="submit" name="simulate_callback" value="Simulate Flow Callback"
               style="background: #007cba; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px;">
    </div>
</form>

<h3>Manual Testing Options</h3>
<div style="background: #f9f9f9; padding: 15px; border-radius: 5px;">

    <h4>1. cURL Command</h4>
    <p>Test from command line:</p>
    <code style="background: #333; color: #fff; padding: 10px; display: block; border-radius: 3px;">
        curl -X POST "<?php echo $callback_url; ?>" \<br>
        &nbsp;&nbsp;-H "Content-Type: application/x-www-form-urlencoded" \<br>
        &nbsp;&nbsp;-d "token=test_token_123"
    </code>

    <h4>2. Webhook Testing Tools</h4>
    <ul>
        <li><strong>Postman:</strong> Create a POST request to <code><?php echo $callback_url; ?></code></li>
        <li><strong>Insomnia:</strong> Similar to Postman</li>
        <li><strong>ngrok:</strong> For testing with external webhooks</li>
    </ul>

    <h4>3. Browser Developer Tools</h4>
    <p>Use the Network tab to inspect the requests and responses</p>

    <h4>4. WordPress REST API Tester</h4>
    <p>Use WordPress's built-in REST API testing:</p>
    <code style="background: #333; color: #fff; padding: 10px; display: block; border-radius: 3px;">
        // In WordPress admin or theme functions.php<br>
        $request = new WP_REST_Request('POST', '/flow/v1/payment-callback');<br>
        $request->set_param('token', 'test_token_123');<br>
        $response = rest_do_request($request);<br>
        var_dump($response->get_data());
    </code>
</div>

<h3>Expected Responses</h3>
<table border="1" style="border-collapse: collapse; width: 100%;">
    <tr style="background: #f0f0f0;">
        <th style="padding: 10px;">Scenario</th>
        <th style="padding: 10px;">Status Code</th>
        <th style="padding: 10px;">Response</th>
    </tr>
    <tr>
        <td style="padding: 10px;">Valid token, successful payment</td>
        <td style="padding: 10px;">200</td>
        <td style="padding: 10px;">{"message": "Payment callback processed successfully"}</td>
    </tr>
    <tr>
        <td style="padding: 10px;">Valid token, failed payment</td>
        <td style="padding: 10px;">200</td>
        <td style="padding: 10px;">{"message": "Payment callback processed successfully"}</td>
    </tr>
    <tr>
        <td style="padding: 10px;">Missing token</td>
        <td style="padding: 10px;">400</td>
        <td style="padding: 10px;">{"error": "Token is required"}</td>
    </tr>
    <tr>
        <td style="padding: 10px;">Invalid token / Flow API error</td>
        <td style="padding: 10px;">400/500</td>
        <td style="padding: 10px;">{"error": "Flow error: ..."}</td>
    </tr>
</table>

<h3>Debugging Tips</h3>
<ul>
    <li><strong>Enable WordPress Debug Logging:</strong> Add to wp-config.php:
        <code style="display: block; background: #f0f0f0; padding: 5px;">
            define('WP_DEBUG', true);<br>
            define('WP_DEBUG_LOG', true);
        </code>
    </li>
    <li><strong>Check Error Logs:</strong> Look in <code>/wp-content/debug.log</code></li>
    <li><strong>Monitor Network:</strong> Use browser dev tools or server logs</li>
    <li><strong>Test Endpoint Availability:</strong> Make sure the REST API is working</li>
</ul>

<h3>Integration with Flow</h3>
<p>When ready to test with real Flow:</p>
<ol>
    <li>Configure your Flow plan with callback URL: <code><?php echo $callback_url; ?></code></li>
    <li>Process a test payment through Flow</li>
    <li>Flow will send a real token to your callback</li>
    <li>Monitor logs to see the actual payment data</li>
</ol>

<?php
// Show current WordPress and plugin status
echo "<h3>System Status</h3>\n";
echo "<table border='1' style='border-collapse: collapse;'>\n";
echo "<tr><td style='padding: 5px;'><strong>WordPress Version:</strong></td><td style='padding: 5px;'>" . get_bloginfo('version') . "</td></tr>\n";
echo "<tr><td style='padding: 5px;'><strong>Site URL:</strong></td><td style='padding: 5px;'>$site_url</td></tr>\n";
echo "<tr><td style='padding: 5px;'><strong>Callback URL:</strong></td><td style='padding: 5px;'>$callback_url</td></tr>\n";
echo "<tr><td style='padding: 5px;'><strong>REST API:</strong></td><td style='padding: 5px;'>" . (function_exists('rest_url') ? '✅ Available' : '❌ Not Available') . "</td></tr>\n";
echo "<tr><td style='padding: 5px;'><strong>Flow_Activator:</strong></td><td style='padding: 5px;'>" . (class_exists('Flow_Activator') ? '✅ Loaded' : '❌ Not Loaded') . "</td></tr>\n";
echo "<tr><td style='padding: 5px;'><strong>Debug Log:</strong></td><td style='padding: 5px;'>" . (file_exists(WP_CONTENT_DIR . '/debug.log') ? '✅ Available' : '❌ Not Found') . "</td></tr>\n";
echo "</table>\n";
?>