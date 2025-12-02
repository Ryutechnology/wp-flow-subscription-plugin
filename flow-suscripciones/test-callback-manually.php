<?php
/**
 * Manual test script for Flow payment callback
 * Use this to test the callback endpoint directly
 */

if (!defined('ABSPATH')) {
    require_once '../../../wp-config.php';
}

echo "<h2>Flow Payment Callback Manual Test</h2>\n";

$callback_url = rest_url('flow/v1/payment-callback');
echo "<h3>Callback URL:</h3>\n";
echo "<p><code>$callback_url</code></p>\n";

// Test 1: Direct endpoint call
echo "<h3>Test 1: Direct Endpoint Call</h3>\n";

$test_token = 'test_token_' . uniqid();
echo "<p>Testing with token: <code>$test_token</code></p>\n";

// Simulate what Flow would send
$post_data = array('token' => $test_token);

// Make internal request to our endpoint
$request = new WP_REST_Request('POST', '/flow/v1/payment-callback');
$request->set_param('token', $test_token);

// Get the activator instance to test directly
if (class_exists('Flow_Activator')) {
    $activator = new Flow_Activator();

    echo "<h4>Making request to endpoint...</h4>\n";
    try {
        $response = $activator->handle_payment_callback($request);
        $response_data = $response->get_data();
        $status_code = $response->get_status();

        echo "<p><strong>Response Status:</strong> $status_code</p>\n";
        echo "<p><strong>Response Data:</strong></p>\n";
        echo "<pre>" . json_encode($response_data, JSON_PRETTY_PRINT) . "</pre>\n";

        if (isset($response_data['error'])) {
            echo "<p style='color: orange;'>⚠️ Expected error since we're using a test token</p>\n";
        }

    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>\n";
    }
} else {
    echo "<p style='color: red;'>❌ Flow_Activator class not found</p>\n";
}

// Test 2: cURL example
echo "<h3>Test 2: cURL Command Example</h3>\n";
echo "<p>You can test the endpoint from command line using cURL:</p>\n";
echo "<pre>";
echo "curl -X POST '$callback_url' \\\n";
echo "  -H 'Content-Type: application/x-www-form-urlencoded' \\\n";
echo "  -d 'token=test_token_12345'\n";
echo "</pre>\n";

// Test 3: Browser form
echo "<h3>Test 3: Browser Form Test</h3>\n";
echo "<form method='POST' action='$callback_url' target='_blank'>\n";
echo "  <label>Token: <input type='text' name='token' value='test_token_browser_" . time() . "' style='width: 300px;'></label><br><br>\n";
echo "  <input type='submit' value='Test Callback Endpoint' style='padding: 10px 20px; background: #007cba; color: white; border: none; cursor: pointer;'>\n";
echo "</form>\n";
echo "<p><em>This will open the result in a new tab</em></p>\n";

// Test 4: Postman/API client example
echo "<h3>Test 4: API Client (Postman) Example</h3>\n";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
echo "<tr><th>Setting</th><th>Value</th></tr>\n";
echo "<tr><td>Method</td><td>POST</td></tr>\n";
echo "<tr><td>URL</td><td><code>$callback_url</code></td></tr>\n";
echo "<tr><td>Content-Type</td><td>application/x-www-form-urlencoded</td></tr>\n";
echo "<tr><td>Body</td><td>token=your_test_token</td></tr>\n";
echo "</table>\n";

// Test 5: Mock Flow response
echo "<h3>Test 5: Mock Flow API Response</h3>\n";
echo "<p>To test with a successful payment, you can modify the Flow_API class temporarily:</p>\n";
echo "<pre>";
echo "// In class-flow-api.php, temporarily modify get_payment_status() to return:\n";
echo "public function get_payment_status(\$token) {\n";
echo "    if (strpos(\$token, 'test_') === 0) {\n";
echo "        return [\n";
echo "            'commerceOrder' => 'ORDER_' . time(),\n";
echo "            'amount' => 15000,\n";
echo "            'flowOrder' => 'FLW_' . uniqid(),\n";
echo "            'status' => 1, // 1 = successful payment\n";
echo "            'payer' => 'test@example.com',\n";
echo "            'paymentData' => ['date' => date('Y-m-d H:i:s')]\n";
echo "        ];\n";
echo "    }\n";
echo "    return \$this->request('payment/getStatus', ['token' => \$token], 'GET');\n";
echo "}\n";
echo "</pre>\n";

// Test 6: WordPress REST API testing
echo "<h3>Test 6: WordPress REST API Direct Test</h3>\n";
echo "<p>You can also test using WordPress's REST API functions:</p>\n";
echo "<pre>";
echo "\$request = new WP_REST_Request('POST', '/flow/v1/payment-callback');\n";
echo "\$request->set_param('token', 'your_test_token');\n";
echo "\$response = rest_do_request(\$request);\n";
echo "var_dump(\$response->get_data());\n";
echo "</pre>\n";

// Show logs location
echo "<h3>Debugging: Check Logs</h3>\n";
echo "<p>Monitor the WordPress debug log to see what's happening:</p>\n";
echo "<ul>\n";
echo "<li><strong>WordPress Debug Log:</strong> <code>/wp-content/debug.log</code></li>\n";
echo "<li><strong>Server Error Log:</strong> Check your hosting control panel</li>\n";
echo "</ul>\n";
echo "<p>Look for log entries starting with: <code>Flow Payment Callback:</code></p>\n";

// Show expected Flow behavior
echo "<h3>Expected Flow Behavior</h3>\n";
echo "<p>When Flow processes a real payment, it will:</p>\n";
echo "<ol>\n";
echo "<li>Send a POST request to your callback URL</li>\n";
echo "<li>Include a <code>token</code> parameter in the request body</li>\n";
echo "<li>Expect a 200 response to confirm the callback was processed</li>\n";
echo "</ol>\n";

echo "<h3>Status Code Meanings</h3>\n";
echo "<ul>\n";
echo "<li><strong>200:</strong> Callback processed successfully</li>\n";
echo "<li><strong>400:</strong> Bad request (missing token or Flow API error)</li>\n";
echo "<li><strong>500:</strong> Server error (check logs)</li>\n";
echo "</ul>\n";

?>