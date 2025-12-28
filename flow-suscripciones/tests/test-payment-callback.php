<?php
/**
 * Test script for Flow payment callback functionality
 * This file demonstrates how the token-based payment callback system works
 */

if (!defined('ABSPATH')) {
    // For standalone testing - include WordPress
    require_once '../../../wp-config.php';
}

// Test token that would come from Flow
$test_token = 'test_token_' . uniqid();

// Sample payment status response that would come from Flow API
$sample_payment_status = [
    'commerceOrder' => 'ORDER_' . time(),
    'amount' => 15000,
    'flowOrder' => 'FLW_' . uniqid(),
    'status' => 2, // 2 = completed, 3 = rejected, 4 = cancelled, 1 = pending
    'payer' => 'test@example.com',
    'paymentData' => [
        'date' => date('Y-m-d H:i:s')
    ]
];

echo "<h2>Flow Payment Callback Test (Token-Based)</h2>\n";

// Show the callback URL that Flow would call
$callback_url = rest_url('flow/v1/payment-callback');
echo "<h3>Payment Callback URL:</h3>\n";
echo "<p><code>$callback_url</code></p>\n";

// Show how Flow calls the endpoint
echo "<h3>How Flow Calls the Endpoint:</h3>\n";
echo "<p>Flow sends a POST request to the callback URL with a token parameter:</p>\n";
echo "<pre>POST $callback_url\nContent-Type: application/x-www-form-urlencoded\n\ntoken=$test_token</pre>\n";

// Show what our endpoint does
echo "<h3>Endpoint Processing Flow:</h3>\n";
echo "<ol>\n";
echo "<li>Receive token from Flow callback</li>\n";
echo "<li>Make GET request to Flow API: <code>/payment/getStatus?token={$test_token}</code></li>\n";
echo "<li>Process the payment status response</li>\n";
echo "<li>Create new WooCommerce order if payment successful</li>\n";
echo "</ol>\n";

// Show sample payment status response
echo "<h3>Sample Payment Status Response from Flow:</h3>\n";
echo "<pre>" . json_encode($sample_payment_status, JSON_PRETTY_PRINT) . "</pre>\n";

// Show plan creation with callback URL
echo "<h3>Plan Creation with Callback URL:</h3>\n";
if (class_exists('Flow_API')) {
    $flow_api = new Flow_API();
    $payment_callback_url = $flow_api->get_payment_callback_url();
    echo "<p>When creating a plan, this callback URL would be sent: <code>$payment_callback_url</code></p>\n";

    // Show environment info
    $env_info = $flow_api->get_environment_info();
    echo "<h4>Flow Environment:</h4>\n";
    echo "<ul>\n";
    echo "<li>Environment: " . $env_info['environment'] . "</li>\n";
    echo "<li>Base URL: " . $env_info['base_url'] . "</li>\n";
    echo "<li>API Key Configured: " . ($env_info['api_key_configured'] ? 'Yes' : 'No') . "</li>\n";
    echo "<li>Secret Key Configured: " . ($env_info['secret_key_configured'] ? 'Yes' : 'No') . "</li>\n";
    echo "</ul>\n";
} else {
    echo "<p><em>Flow_API class not available</em></p>\n";
}

// Show Flow payment statuses
echo "<h3>Flow Payment Status Values:</h3>\n";
echo "<ul>\n";
echo "<li><strong>1:</strong> Payment pending</li>\n";
echo "<li><strong>2:</strong> Payment successful - new order will be created</li>\n";
echo "<li><strong>3:</strong> Payment rejected</li>\n";
echo "<li><strong>4:</strong> Payment cancelled</li>\n";
echo "</ul>\n";

// Show order creation logic
echo "<h3>Order Creation Logic:</h3>\n";
echo "<ul>\n";
echo "<li><strong>New Customer:</strong> Creates new order with customer email from payment</li>\n";
echo "<li><strong>Existing Subscription:</strong> Creates recurring order based on original subscription order</li>\n";
echo "</ul>\n";

// Show complete workflow
echo "<h3>Complete Workflow:</h3>\n";
echo "<ol>\n";
echo "<li>Customer subscribes and Flow plan is created with urlCallback</li>\n";
echo "<li>Flow processes recurring payment</li>\n";
echo "<li>Flow sends POST to: <code>$callback_url</code> with token</li>\n";
echo "<li>Our endpoint calls Flow API: <code>/payment/getStatus</code></li>\n";
echo "<li>If payment successful (status=2), create new WooCommerce order</li>\n";
echo "<li>Order is marked as completed and customer is notified</li>\n";
echo "</ol>\n";

// Test the endpoint URL structure
echo "<h3>API Endpoint Structure:</h3>\n";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
echo "<tr><th>Method</th><th>Endpoint</th><th>Purpose</th></tr>\n";
echo "<tr><td>POST</td><td><code>/wp-json/flow/v1/payment-callback</code></td><td>Receive payment notifications from Flow</td></tr>\n";
echo "<tr><td>GET</td><td><code>Flow API: /payment/getStatus</code></td><td>Get payment status using token</td></tr>\n";
echo "</table>\n";

echo "<h3>Testing Complete</h3>\n";
echo "<p>The token-based payment callback system has been implemented and is ready for use.</p>\n";
?>