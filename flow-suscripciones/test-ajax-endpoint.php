<?php
/**
 * Test AJAX Endpoint for Flow Customer Data
 * Add this to debug AJAX issues
 */

if (!defined('ABSPATH')) exit;

// Add test page to admin menu
add_action('admin_menu', 'flow_test_ajax_menu');

function flow_test_ajax_menu() {
    add_submenu_page(
        'woocommerce',
        'Test Flow AJAX',
        'Test AJAX',
        'manage_options',
        'flow-test-ajax',
        'flow_test_ajax_page'
    );
}

function flow_test_ajax_page() {
    ?>
    <div class="wrap">
        <h1>Test Flow AJAX Endpoint</h1>

        <div id="test-results" style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">
            <p>Click the buttons below to test the AJAX endpoints:</p>
            <button id="test-simple-ajax-btn" class="button button-secondary">Test Simple AJAX (No nonce)</button>
            <button id="test-debug-ajax-btn" class="button button-secondary">Test Debug Customer AJAX (No nonce)</button>
            <button id="test-ajax-btn" class="button button-primary">Test Flow Customer AJAX (With nonce)</button>
            <div id="ajax-response" style="margin-top: 15px;"></div>
        </div>

        <h3>Test Customer IDs</h3>
        <p>Try these customer IDs (if they exist in your system):</p>
        <ul>
            <?php
            // Get some customer IDs for testing
            $users = get_users(array(
                'role' => 'customer',
                'number' => 5,
                'fields' => array('ID', 'user_email')
            ));

            foreach ($users as $user) {
                echo '<li>Customer ID: ' . $user->ID . ' (Email: ' . esc_html($user->user_email) . ') ';
                echo '<button class="button test-customer-btn" data-customer-id="' . $user->ID . '">Test This Customer</button></li>';
            }

            if (empty($users)) {
                echo '<li>No customers found in the system.</li>';
            }
            ?>
        </ul>

        <h3>AJAX Configuration</h3>
        <pre id="ajax-config"></pre>
    </div>

    <script>
        jQuery(document).ready(function($) {
            // Show current AJAX configuration
            if (window.flowCustomerData) {
                $('#ajax-config').text('flowCustomerData is available:\n' + JSON.stringify(window.flowCustomerData, null, 2));
            } else {
                $('#ajax-config').text('❌ flowCustomerData is NOT available');
            }

            // Test simple AJAX (no nonce required)
            $('#test-simple-ajax-btn').click(function() {
                testSimpleAjax();
            });

            // Test debug customer AJAX (no nonce required)
            $('#test-debug-ajax-btn').click(function() {
                testDebugAjax(1);
            });

            // Test AJAX with default customer ID 1
            $('#test-ajax-btn').click(function() {
                testAjaxRequest(1);
            });

            // Test with specific customer IDs
            $('.test-customer-btn').click(function() {
                var customerId = $(this).data('customer-id');
                testAjaxRequest(customerId);
            });

            function testSimpleAjax() {
                $('#ajax-response').html('<p>🔄 Testing simple AJAX...</p>');

                var data = {
                    action: 'test_flow_ajax',
                    test_data: 'Hello from test!'
                };

                console.log('Sending simple AJAX request:', data);

                $.ajax({
                    url: window.flowCustomerData ? window.flowCustomerData.ajaxUrl : '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: data,
                    success: function(response) {
                        console.log('Simple AJAX Success:', response);
                        $('#ajax-response').html(
                            '<h4 style="color: green;">✅ Simple AJAX Success:</h4>' +
                            '<pre>' + JSON.stringify(response, null, 2) + '</pre>'
                        );
                    },
                    error: function(xhr, status, error) {
                        console.error('Simple AJAX Error:', xhr, status, error);
                        $('#ajax-response').html(
                            '<h4 style="color: red;">❌ Simple AJAX Error:</h4>' +
                            '<p><strong>Status:</strong> ' + xhr.status + ' ' + xhr.statusText + '</p>' +
                            '<p><strong>Response:</strong></p>' +
                            '<pre>' + xhr.responseText + '</pre>'
                        );
                    }
                });
            }

            function testDebugAjax(customerId) {
                $('#ajax-response').html('<p>🔄 Testing debug customer AJAX for ID: ' + customerId + '...</p>');

                var data = {
                    action: 'debug_flow_customer_data',
                    customer_id: customerId
                };

                console.log('Sending debug AJAX request:', data);

                $.ajax({
                    url: window.flowCustomerData ? window.flowCustomerData.ajaxUrl : '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: data,
                    success: function(response) {
                        console.log('Debug AJAX Success:', response);
                        $('#ajax-response').html(
                            '<h4 style="color: green;">✅ Debug AJAX Success:</h4>' +
                            '<pre>' + JSON.stringify(response, null, 2) + '</pre>'
                        );
                    },
                    error: function(xhr, status, error) {
                        console.error('Debug AJAX Error:', xhr, status, error);
                        $('#ajax-response').html(
                            '<h4 style="color: red;">❌ Debug AJAX Error:</h4>' +
                            '<p><strong>Status:</strong> ' + xhr.status + ' ' + xhr.statusText + '</p>' +
                            '<p><strong>Response:</strong></p>' +
                            '<pre>' + xhr.responseText + '</pre>'
                        );
                    }
                });
            }

            function testAjaxRequest(customerId) {
                $('#ajax-response').html('<p>🔄 Testing AJAX request for customer ID: ' + customerId + '...</p>');

                if (!window.flowCustomerData) {
                    $('#ajax-response').html('<p style="color: red;">❌ Error: flowCustomerData not available</p>');
                    return;
                }

                var data = {
                    action: 'get_flow_customer_data',
                    customer_id: customerId,
                    nonce: window.flowCustomerData.nonce
                };

                console.log('Sending AJAX request:', data);

                $.ajax({
                    url: window.flowCustomerData.ajaxUrl,
                    type: 'POST',
                    data: data,
                    success: function(response) {
                        console.log('AJAX Success:', response);
                        $('#ajax-response').html(
                            '<h4 style="color: green;">✅ Success Response:</h4>' +
                            '<pre>' + JSON.stringify(response, null, 2) + '</pre>'
                        );
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', xhr, status, error);
                        $('#ajax-response').html(
                            '<h4 style="color: red;">❌ Error Response:</h4>' +
                            '<p><strong>Status:</strong> ' + xhr.status + ' ' + xhr.statusText + '</p>' +
                            '<p><strong>Response:</strong></p>' +
                            '<pre>' + xhr.responseText + '</pre>'
                        );
                    }
                });
            }
        });
    </script>
    <?php
}

// Enqueue the flowCustomerData on the test page
add_action('admin_enqueue_scripts', function($hook_suffix) {
    if ($hook_suffix === 'woocommerce_page_flow-test-ajax') {
        wp_localize_script('jquery', 'flowCustomerData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('flow_customer_data'),
            'restUrl' => rest_url('wc/v3/customers'),
            'restNonce' => wp_create_nonce('wp_rest')
        ));
    }
});
?>