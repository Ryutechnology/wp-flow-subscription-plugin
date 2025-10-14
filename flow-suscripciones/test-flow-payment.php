<?php
/**
 * Flow Payment Integration Test Tool
 */

if (!defined('ABSPATH')) exit;

// Test Flow payment creation
add_action('wp_loaded', function() {
    if (current_user_can('manage_options') && isset($_GET['flow_test_payment'])) {

        echo '<div style="background: white; padding: 20px; margin: 20px; border: 1px solid #ccc;">';
        echo '<h2>🧪 Flow Subscription Integration Test</h2>';

        // Check API credentials
        echo '<h3>1. API Credentials Check</h3>';
        $api_key = get_option('flow_api_key');
        $secret_key = get_option('flow_secret_key');

        echo 'API Key: ' . ($api_key ? '✅ Configured (' . substr($api_key, 0, 10) . '...)' : '❌ Not configured') . '<br>';
        echo 'Secret Key: ' . ($secret_key ? '✅ Configured (' . substr($secret_key, 0, 10) . '...)' : '❌ Not configured') . '<br>';

        if (!$api_key || !$secret_key) {
            echo '<p style="color: red;">Configure API credentials in Flow Suscripciones settings first.</p>';
            echo '</div>';
            exit;
        }

        // Test subscription creation
        echo '<h3>2. Test Subscription Creation</h3>';

        // Initialize Flow API
        $flow_api = new Flow_API();

        // Test data
        $test_email = 'test@example.com';
        $test_name = 'Test Customer';
        $test_amount = 5000; // $5000 CLP

        echo 'Test Subscription Data:<br>';
        echo '- Email: ' . $test_email . '<br>';
        echo '- Name: ' . $test_name . '<br>';
        echo '- Amount: $' . $test_amount . ' CLP<br>';

        // Step 1: Create plan
        echo '<h4>Step 1: Creating Plan</h4>';
        $plan_name = 'Test Plan - ' . get_bloginfo('name') . ' - $' . $test_amount;
        $plan_response = $flow_api->create_plan($plan_name, $test_amount);

        if (isset($plan_response['error'])) {
            echo '❌ Plan Creation Error: ' . esc_html($plan_response['error']) . '<br>';
        } else {
            echo '✅ Plan Created Successfully:<br>';
            echo '<pre style="background: #f5f5f5; padding: 10px; overflow: auto;">';
            echo esc_html(json_encode($plan_response, JSON_PRETTY_PRINT));
            echo '</pre>';

            if (isset($plan_response['planId'])) {
                $plan_id = $plan_response['planId'];

                // Step 2: Create customer
                echo '<h4>Step 2: Creating Customer</h4>';
                $customer_response = $flow_api->create_customer($test_email, $test_name, 'Test Address 123', 'Santiago');

                if (isset($customer_response['error'])) {
                    echo '❌ Customer Creation Error: ' . esc_html($customer_response['error']) . '<br>';
                } else {
                    echo '✅ Customer Created Successfully:<br>';
                    echo '<pre style="background: #f5f5f5; padding: 10px; overflow: auto;">';
                    echo esc_html(json_encode($customer_response, JSON_PRETTY_PRINT));
                    echo '</pre>';

                    // Step 3: Create subscription (after customer is created)
                    echo '<h4>Step 3: Creating Subscription</h4>';
                    $customer_id = $customer_response['customerId'] ?? $test_email;
                    echo 'Using Customer ID: ' . esc_html($customer_id) . '<br>';
                    $subscription_response = $flow_api->create_subscription($plan_id, $customer_id);

                    if (isset($subscription_response['error'])) {
                        echo '❌ Subscription Creation Error: ' . esc_html($subscription_response['error']) . '<br>';
                    } else {
                        echo '✅ Subscription Created Successfully:<br>';
                        echo '<pre style="background: #f5f5f5; padding: 10px; overflow: auto;">';
                        echo esc_html(json_encode($subscription_response, JSON_PRETTY_PRINT));
                        echo '</pre>';

                        if (isset($subscription_response['subscriptionId'])) {
                            $subscription_id = $subscription_response['subscriptionId'];

                            // Step 4: Register credit card
                            echo '<h4>Step 4: Registering Credit Card</h4>';
                            $url_return = home_url('/wp-admin/admin.php?page=flow-payment-test&test_return=1');
                            echo 'Using Customer ID for card registration: ' . esc_html($customer_id) . '<br>';
                            $card_response = $flow_api->register_credit_card($customer_id, $url_return);

                            if (isset($card_response['error'])) {
                                echo '❌ Card Registration Error: ' . esc_html($card_response['error']) . '<br>';
                            } else {
                                echo '✅ Card Registration Initiated:<br>';
                                echo '<pre style="background: #f5f5f5; padding: 10px; overflow: auto;">';
                                echo esc_html(json_encode($card_response, JSON_PRETTY_PRINT));
                                echo '</pre>';

                                if (isset($card_response['url']) && isset($card_response['token'])) {
                                    echo '🎉 <strong>Subscription Flow Ready!</strong><br>';
                                    echo 'Subscription ID: ' . esc_html($subscription_id) . '<br>';
                                    echo 'Card Registration URL: <a href="' . esc_url($card_response['url']) . '" target="_blank">' . esc_html($card_response['url']) . '</a><br>';
                                    echo 'Token: ' . esc_html($card_response['token']) . '<br>';
                                    echo '<p><em>Note: Complete the card registration in Flow to activate the subscription.</em></p>';
                                }
                            }
                        }
                    }
                }
            }
        }

        // Test webhook URLs
        echo '<h3>3. Webhook URLs</h3>';
        echo 'Webhook URL: <code>' . esc_html(WC()->api_request_url('flow_webhook')) . '</code><br>';
        echo 'Return URL: <code>' . esc_html(WC()->api_request_url('flow_return')) . '</code><br>';

        // Gateway status
        echo '<h3>4. Gateway Status</h3>';
        if (class_exists('Flow_Payment_Gateway')) {
            echo 'Flow_Payment_Gateway class: ✅ Loaded<br>';
        } else {
            echo 'Flow_Payment_Gateway class: ❌ Not loaded<br>';
        }

        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['flow'])) {
                echo 'Gateway registered: ✅ Yes<br>';
                $gateway = $gateways['flow'];
                echo 'Gateway enabled: ' . ($gateway->enabled === 'yes' ? '✅ Yes' : '❌ No') . '<br>';
            } else {
                echo 'Gateway registered: ❌ No<br>';
            }
        }

        echo '<h3>5. Next Steps</h3>';
        echo '<ol>';
        echo '<li>If the subscription test was successful, the integration is working</li>';
        echo '<li>Configure the webhook URLs in your Flow dashboard</li>';
        echo '<li>Test with a real WooCommerce order to create subscriptions</li>';
        echo '<li>Configure recurring billing schedule using Flow cron jobs</li>';
        echo '<li>Switch to production API credentials when ready</li>';
        echo '</ol>';

        echo '</div>';
        exit;
    }
});

// Add to admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Payment Test',
        'Flow Payment Test',
        'manage_options',
        'flow-payment-test',
        function() {
            echo '<div class="wrap">';
            echo '<h1>Flow Subscription Integration Test</h1>';
            echo '<p>Test the Flow subscription gateway integration with a sample subscription.</p>';
            echo '<p><strong>Note:</strong> This will create a test subscription plan, customer and mandate in Flow sandbox.</p>';
            echo '<p><a href="' . admin_url('admin.php?flow_test_payment=1') . '" class="button-primary">Run Subscription Test</a></p>';

            echo '<h2>Requirements</h2>';
            echo '<ul>';
            echo '<li>Flow API credentials must be configured</li>';
            echo '<li>Gateway must be enabled in WooCommerce</li>';
            echo '<li>Test uses Flow sandbox environment</li>';
            echo '<li>Subscription endpoints must be available in Flow</li>';
            echo '</ul>';

            echo '</div>';
        }
    );
});
?>