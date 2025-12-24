<?php
if ( !defined( 'ABSPATH' ) ) exit;

require_once FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'includes/class-flow-api.php';
require_once FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'includes/class-flow-woocommerce.php';

class Flow_Activator {
    private $flow_api;

    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . 'flow_subscriptions';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(190) NOT NULL,
            address VARCHAR(255) NOT NULL,
            city VARCHAR(190) NOT NULL,
            plan_id VARCHAR(100) NOT NULL,
            mandato_id VARCHAR(100) DEFAULT NULL,
            amount INT NOT NULL,
            status VARCHAR(20) DEFAULT 'pendiente',
            flow_customer_id VARCHAR(100) DEFAULT NULL,
            product_id INT DEFAULT NULL,
            variation_id INT DEFAULT NULL,
            formato VARCHAR(100) DEFAULT NULL,
            molienda VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset;";

        // Check and add new columns if they don't exist
        $columns_to_check = [
            'product_id' => 'INT DEFAULT NULL',
            'variation_id' => 'INT DEFAULT NULL',
            'formato' => 'VARCHAR(100) DEFAULT NULL',
            'molienda' => 'VARCHAR(100) DEFAULT NULL',
            'flow_subscription_id' => 'VARCHAR(100) DEFAULT NULL',
            'wc_order_id' => 'BIGINT DEFAULT NULL'
        ];

        foreach ($columns_to_check as $column_name => $column_definition) {
            $col_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SHOW COLUMNS FROM $table LIKE %s",
                    $column_name
                )
            );

            if ( empty( $col_exists ) ) {
                // Si la columna no existe, la creamos
                $wpdb->query(
                    "ALTER TABLE $table ADD $column_name $column_definition"
                );
            }
        }

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        // Add Flow columns to WooCommerce customer lookup table
        $wc_customer_lookup_table = $wpdb->prefix . 'wc_customer_lookup';

        // Add flow_customer_id column
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = 'flow_customer_id'",
            $wc_customer_lookup_table
        ));
        if (empty($column_exists)) {
            $result = $wpdb->query("ALTER TABLE `$wc_customer_lookup_table` ADD COLUMN `flow_customer_id` VARCHAR(100) DEFAULT NULL");
            if ($result === false) {
                error_log('Flow Activator: Failed to add flow_customer_id column to ' . $wc_customer_lookup_table . '. Error: ' . $wpdb->last_error);
            } else {
                error_log('Flow Activator: Successfully added flow_customer_id column to ' . $wc_customer_lookup_table);
            }
        }

        // Add flow_subscription_id column
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = 'flow_subscription_id'",
            $wc_customer_lookup_table
        ));
        if (empty($column_exists)) {
            $result = $wpdb->query("ALTER TABLE `$wc_customer_lookup_table` ADD COLUMN `flow_subscription_id` VARCHAR(100) DEFAULT NULL");
            if ($result === false) {
                error_log('Flow Activator: Failed to add flow_subscription_id column to ' . $wc_customer_lookup_table . '. Error: ' . $wpdb->last_error);
            } else {
                error_log('Flow Activator: Successfully added flow_subscription_id column to ' . $wc_customer_lookup_table);
            }
        }

        // Add flow_subscription_status column
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = 'flow_subscription_status'",
            $wc_customer_lookup_table
        ));
        if (empty($column_exists)) {
            $result = $wpdb->query("ALTER TABLE `$wc_customer_lookup_table` ADD COLUMN `flow_subscription_status` VARCHAR(20) DEFAULT NULL");
            if ($result === false) {
                error_log('Flow Activator: Failed to add flow_subscription_status column to ' . $wc_customer_lookup_table . '. Error: ' . $wpdb->last_error);
            } else {
                error_log('Flow Activator: Successfully added flow_subscription_status column to ' . $wc_customer_lookup_table);
            }
        }

        // Sync existing Flow data to customer lookup table
        if (class_exists('Flow_WooCommerce')) {
            $wc_integration = new Flow_WooCommerce();
            if ($wc_integration->is_woocommerce_available()) {
                $synced_count = $wc_integration->sync_flow_data_to_customer_table();
                error_log("Flow Activator: Synchronized {$synced_count} customers during activation");
            }
        }
    }

    public static function activate_new_page() {
        // Create flow return page
        $page = get_page_by_path('flow-return');
        if (!$page) {
            wp_insert_post([
                'post_title'   => 'Flow Return',
                'post_name'    => 'flow-return',
                'post_content' => '[flow_return]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ]);
        }

        // Create flow success page
        $success_page = get_page_by_path('suscripcion-exitosa');
        if (!$success_page) {
            wp_insert_post([
                'post_title'   => 'Suscripción Exitosa',
                'post_name'    => 'suscripcion-exitosa',
                'post_content' => '[flow_success]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ]);
        }
    }

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);
    }

    private function get_plan_info_from_customer($customer_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'flow_subscriptions';

        // First try by flow_customer_id
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE flow_customer_id = %s ORDER BY created_at DESC LIMIT 1",
            $customer_id
        ), ARRAY_A);

        // If not found, try by email (customer_id might be the email)
        if (!$subscription && filter_var($customer_id, FILTER_VALIDATE_EMAIL)) {
            $subscription = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE email = %s ORDER BY created_at DESC LIMIT 1",
                $customer_id
            ), ARRAY_A);
        }

        // If still not found, get the most recent pending subscription
        if (!$subscription) {
            $subscription = $wpdb->get_row(
                "SELECT * FROM $table WHERE status = 'pendiente' ORDER BY created_at DESC LIMIT 1",
                ARRAY_A
            );
        }

        if (!$subscription) {
            error_log("Flow Debug: No subscription found for customer_id: $customer_id");
            return null;
        }

        error_log("Flow Debug: Found subscription ID {$subscription['id']} for customer: $customer_id");

        return [
            'plan' => $subscription['plan_id'],
            'amount' => $subscription['amount'],
            'subscription_id' => $subscription['id'],
            'customer_id' => $customer_id
        ];
    }

    private function get_flow_api() {
        if (!$this->flow_api) {
            $this->flow_api = new Flow_API();
        }
        return $this->flow_api;
    }

    public function register_rest_endpoints() {
        register_rest_route('flow/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_flow_webhook'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('flow/v1', '/return', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_flow_return_post'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('flow/v1', '/payment-callback', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_payment_callback'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function handle_flow_webhook(WP_REST_Request $request) {
        $body = $request->get_body();
        $data = json_decode($body, true);

        error_log('Flow Webhook received: ' . print_r($data, true));

        if (!$data) {
            return new WP_REST_Response(['error' => 'Invalid JSON'], 400);
        }

        try {
            if (isset($data['type'])) {
                switch ($data['type']) {
                    case 'subscription_created':
                        return $this->handle_subscription_created($data);
                    case 'payment_completed':
                        return $this->handle_payment_completed($data);
                    case 'subscription_cancelled':
                        return $this->handle_subscription_cancelled($data);
                    default:
                        error_log('Unknown webhook type: ' . $data['type']);
                        return new WP_REST_Response(['message' => 'Unknown event type'], 200);
                }
            }

            return new WP_REST_Response(['message' => 'Webhook processed'], 200);

        } catch (Exception $e) {
            error_log('Flow webhook error: ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Internal error'], 500);
        }
    }

    public function handle_flow_return_post(WP_REST_Request $request) {
        $params = $request->get_params();

        if (!isset($params['token'])) {
            // Redirect to failure page for missing token
            $failure_url = add_query_arg([
                'error' => 'Token de autenticación no proporcionado',
                'error_code' => 'missing_token'
            ], home_url('/suscripcion-fallo/'));

            return new WP_REST_Response([
                'redirect' => $failure_url
            ], 302);
        }

        $plan_id = sanitize_text_field($params['planId'] ?? '');
        $token = sanitize_text_field($params['token']);
        $result = $this->get_flow_api()->get_register_results($token);

        if (!empty($result['code'])) {
            // Redirect to failure page for Flow API errors
            $failure_url = add_query_arg([
                'error' => 'Error al obtener resultados de Flow: ' . $result['message'],
                'error_code' => $result['code'],
                'plan' => $plan_id
            ], home_url('/suscripcion-fallo/'));

            wp_redirect($failure_url);
            exit;
        }

        if ($result['status'] === '1') {

            $customer = $result['customerId'];
            error_log("Flow Debug POST: Customer ID from Flow: " . $customer);
            error_log("Flow Debug POST: Plan ID from params: " . $plan_id);

            // Get plan info from session or database with extensive debugging
            error_log("Flow Debug POST: Attempting to get plan info for customer: " . $customer);
            $plan_info = $this->get_plan_info_from_customer($customer);

            if (!$plan_info) {
                error_log("Flow Debug POST: Plan info not found in database, trying session");
                // Try to get from session as fallback
                if (session_status() == PHP_SESSION_NONE) {
                    session_start();
                }
                if (isset($_SESSION['flow_plan_info'])) {
                    $plan_info = $_SESSION['flow_plan_info'];
                    error_log("Flow Debug POST: Plan info retrieved from session: " . print_r($plan_info, true));
                } else {
                    error_log("Flow Debug POST: No session data found");
                }
            } else {
                error_log("Flow Debug POST: Plan info found in database: " . print_r($plan_info, true));
            }

            // If still no plan_info, try to create one based on available data
            if (!$plan_info) {
                error_log("Flow Debug POST: No plan info found anywhere, attempting to create from available data");

                // Try to find any subscription for this customer by different methods
                global $wpdb;
                $table = $wpdb->prefix . 'flow_subscriptions';

                // Try by email if customer is an email
                if (filter_var($customer, FILTER_VALIDATE_EMAIL)) {
                    $subscription = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $table WHERE email = %s ORDER BY created_at DESC LIMIT 1",
                        $customer
                    ), ARRAY_A);

                    if ($subscription) {
                        $plan_info = [
                            'plan' => $subscription['plan_id'],
                            'amount' => $subscription['amount'],
                            'subscription_id' => $subscription['id'],
                            'customer_id' => $customer
                        ];
                        error_log("Flow Debug POST: Created plan_info from email lookup: " . print_r($plan_info, true));
                    }
                }

                // Still no luck? Get the most recent subscription
                if (!$plan_info) {
                    $subscription = $wpdb->get_row(
                        "SELECT * FROM $table WHERE status IN ('pendiente', 'activo') ORDER BY created_at DESC LIMIT 1",
                        ARRAY_A
                    );

                    if ($subscription) {
                        $plan_info = [
                            'plan' => $subscription['plan_id'],
                            'amount' => $subscription['amount'],
                            'subscription_id' => $subscription['id'],
                            'customer_id' => $customer
                        ];
                        error_log("Flow Debug POST: Created plan_info from recent subscription: " . print_r($plan_info, true));
                    }
                }
            }

            if (!$plan_info) {
                error_log("Flow Debug POST: Still no plan_info found after all attempts");
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No se encontró información de la suscripción. Customer ID: ' . $customer . ', Plan ID: ' . $plan_id,
                    'debug' => [
                        'customer_id' => $customer,
                        'plan_id' => $plan_id,
                        'session_data' => $_SESSION ?? []
                    ]
                ], 400);
            }

            // First, find and get the WooCommerce order to create the Flow subscription
            global $wpdb;
            $table = $wpdb->prefix . 'flow_subscriptions';

            // Look for pending orders that need subscription creation
            $pending_orders = wc_get_orders(array(
                'limit' => 10,
                'status' => 'pending',
                'meta_query' => array(
                    array(
                        'key' => '_flow_subscription_pending',
                        'value' => 'yes',
                        'compare' => '='
                    ),
                    array(
                        'key' => '_flow_customer_id',
                        'value' => $customer,
                        'compare' => '='
                    )
                )
            ));

            $subscription_id = null;
            $order = null;

            if (!empty($pending_orders)) {
                $order = $pending_orders[0];
                $plan_id = $order->get_meta('_flow_plan_id');
                $customer_id = $order->get_meta('_flow_customer_id');

                // Now create the Flow subscription
                $flow_api = $this->get_flow_api();
                $subscription_response = $flow_api->create_subscription($plan_id, $customer_id);

                if (isset($subscription_response['code'])) {
                    error_log("Flow Return: Error creating subscription: " . $subscription_response['message']);
                    // Handle subscription creation failure
                    $failure_url = add_query_arg([
                        'error' => 'Error creando suscripción: ' . $subscription_response['message'],
                        'error_code' => $subscription_response['code']
                    ], home_url('/suscripcion-fallo/'));
                    wp_redirect($failure_url);
                    exit;
                }

                if (!isset($subscription_response['subscriptionId'])) {
                    error_log("Flow Return: Invalid subscription response: missing subscriptionId");
                    $failure_url = add_query_arg([
                        'error' => 'Respuesta inválida al crear suscripción',
                        'error_code' => 'invalid_subscription_response'
                    ], home_url('/suscripcion-fallo/'));
                    wp_redirect($failure_url);
                    exit;
                }

                $subscription_id = $subscription_response['subscriptionId'];

                // Update order with subscription ID
                $order->update_meta_data('_flow_subscription_id', $subscription_id);
                $order->delete_meta_data('_flow_subscription_pending');
                $order->save();

                error_log("Flow Return: Created Flow subscription {$subscription_id} for order {$order->get_id()}");
            }

            // Create subscription in database only after Flow subscription is created
            if ($subscription_id && $order) {
                $database = new Flow_Database();
                $db_subscription_data = array(
                    'email' => $order->get_billing_email(),
                    'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'address' => $order->get_billing_address_1(),
                    'city' => $order->get_billing_city(),
                    'plan_id' => $order->get_meta('_flow_plan_id'),
                    'amount' => intval($order->get_total()),
                    'status' => 'activo',
                    'flow_customer_id' => $customer,
                    'flow_subscription_id' => $subscription_id,
                    'wc_order_id' => $order->get_id(),
                    'mandato_id' => $subscription_id
                );

                $db_subscription_id = $database->insert_subscription($db_subscription_data);
                if ($db_subscription_id) {
                    $order->update_meta_data('_flow_db_subscription_id', $db_subscription_id);
                    $order->save();
                    error_log("Flow Return: Created database subscription {$db_subscription_id} for Flow subscription {$subscription_id}");
                }

                // Update plan_info for later use
                $plan_info = [
                    'plan' => $order->get_meta('_flow_plan_id'),
                    'amount' => intval($order->get_total()),
                    'subscription_id' => $db_subscription_id,
                    'customer_id' => $customer
                ];
            } else {
                error_log("Flow Return: Could not create subscription - missing subscription_id or order");
            }

            // Update WooCommerce customer and order when subscription is successful
            $wc_integration = new Flow_WooCommerce();
            if ($wc_integration->is_woocommerce_available() && $order) {
                // Use order data instead of subscription data since we just created it
                $subscription_data = (object) array(
                    'id' => $plan_info['subscription_id'] ?? null,
                    'email' => $order->get_billing_email(),
                    'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'city' => $order->get_billing_city(),
                    'address' => $order->get_billing_address_1(),
                    'wc_order_id' => $order->get_id()
                );

                if ($subscription_data->email) {
                    // Create customer
                    $customer_id = $wc_integration->get_or_create_wc_customer(
                        $subscription_data->email,
                        $subscription_data->name,
                        $subscription_data->city,
                        $subscription_data->address
                    );
                    if ($customer_id > 0) {
                        error_log("WooCommerce customer created for successful subscription - Email: {$subscription_data->email}");
                    }

                    // Store Flow subscription data in customer meta and WooCommerce customer table
                    if ($customer_id > 0) {
                        Flow_Customer_Columns::set_customer_flow_subscription_id($customer_id, $subscription_id);
                        Flow_Customer_Columns::set_customer_flow_customer_id($customer_id, $customer);

                        // Update WooCommerce customer lookup table
                        $wc_integration->update_customer_flow_data(
                            $customer_id,
                            $customer,
                            $subscription_id,
                            'active'
                        );

                        error_log("Flow Debug: Stored Flow subscription data for customer {$customer_id} - Subscription ID: {$subscription_id}, Customer ID: {$customer}");
                    }

                    // Find and update existing order instead of creating new one
                    if (isset($subscription_data->wc_order_id) && $subscription_data->wc_order_id > 0) {
                        $order = wc_get_order($subscription_data->wc_order_id);
                        if ($order) {
                            // Update order status to processing (payment successful)
                            $order->update_status('processing', 'Flow card registration successful. Subscription activated.');

                            // Update Flow data in order meta
                            $order->update_meta_data('_flow_subscription_id', $subscription_id);
                            $order->update_meta_data('_flow_customer_id', $customer);
                            $order->update_meta_data('_flow_payment_complete', 'yes');
                            $order->update_meta_data('_flow_card_registration_complete', current_time('mysql'));
                            $order->save();

                            error_log("Flow Debug: Updated existing order #{$subscription_data->wc_order_id} to processing status - Email: {$subscription_data->email}");
                        } else {
                            error_log("Flow Debug POST: Could not find order #{$subscription_data->wc_order_id} for subscription - Email: {$subscription_data->email}");
                        }
                    } else {
                        error_log("Flow Debug POST: No WooCommerce order ID found in subscription data - Email: {$subscription_data->email}");
                    }
                }
            }

            // Force redirect to success page
            echo('plan:'.esc_html($plan_info['plan']).' amount:'.esc_html($plan_info['amount']).' subscription_id:'.esc_html($plan_info['subscription_id']));
            $success_url = add_query_arg([
                'subscription_id' => $plan_info['subscription_id'],
                'plan' => $plan_info['plan'],
                'amount' => $plan_info['amount'],
                'email' => $subscription_data->email ?? '',
                'client_name' => $subscription_data->name ?? ''
            ], home_url('/suscripcion-exitosa/'));

            status_header(302);
            wp_redirect($success_url);
            exit;

        } else {
            // Redirect to failure page with error details
            $failure_url = add_query_arg([
                'error' => 'El registro no fue exitoso',
                'error_code' => $result['status'] ?? 'unknown',
                'plan' => $subscription_data->plan_id ?? '',
                'amount' => $subscription_data->amount ?? ''
            ], home_url('/suscripcion-fallo/'));

            wp_redirect($failure_url);
            exit;
        }
    }

    private function handle_subscription_created($data) {
        global $wpdb;

        $table = $wpdb->prefix . 'flow_subscriptions';
        $wpdb->update(
            $table,
            ['status' => 'activa'],
            ['mandato_id' => $data['subscription_id']],
            ['%s'],
            ['%s']
        );

        do_action('flow_subscription_created', $data);

        return new WP_REST_Response(['message' => 'Subscription created processed'], 200);
    }

    private function handle_payment_completed($data) {
        do_action('flow_payment_completed', $data);

        return new WP_REST_Response(['message' => 'Payment completed processed'], 200);
    }

    private function handle_subscription_cancelled($data) {
        global $wpdb;

        $table = $wpdb->prefix . 'flow_subscriptions';
        $wpdb->update(
            $table,
            ['status' => 'cancelada'],
            ['mandato_id' => $data['subscription_id']],
            ['%s'],
            ['%s']
        );

        do_action('flow_subscription_cancelled', $data);
        return new WP_REST_Response(['message' => 'Subscription cancelled processed'], 200);
    }

    public function handle_payment_callback(WP_REST_Request $request) {
        $params = $request->get_params();

        error_log('Flow Payment Callback received params: ' . print_r($params, true));

        // Extract token from request
        $token = sanitize_text_field($params['token'] ?? '');

        if (empty($token)) {
            error_log('Flow Payment Callback: Missing token');
            return new WP_REST_Response(['error' => 'Token is required'], 400);
        }

        try {
            // Get payment status from Flow using token
            $flow_api = $this->get_flow_api();
            $payment_status = $flow_api->get_payment_status($token);

            error_log('Flow Payment Callback: Payment status response: ' . json_encode($payment_status));

            // Check for Flow API errors
            if (isset($payment_status['code'])) {
                error_log('Flow Payment Callback: Flow API error: ' . $payment_status['message']);
                return new WP_REST_Response(['error' => 'Flow API error: ' . $payment_status['code']], 500);
            }

            if (isset($payment_status['code']) && isset($payment_status['message'])) {
                error_log('Flow Payment Callback: Flow error response - Code: ' . $payment_status['code'] . ', Message: ' . $payment_status['message']);
                return new WP_REST_Response(['error' => 'Flow error: ' . $payment_status['message']], 400);
            }

            // Extract payment information from Flow response
            $flow_order = $payment_status['commerceOrder'] ?? '';
            $payment_amount = $payment_status['amount'] ?? 0;
            $payment_id = $payment_status['flowOrder'] ?? '';
            $status = $payment_status['status'] ?? 0;
            $customer_email = $payment_status['payer'] ?? '';

            error_log("Flow Payment Callback: Commerce Order: {$flow_order}, Amount: {$payment_amount}, Status: {$status}, Payment ID: {$payment_id}, Payer: {$customer_email}");

            // Process payment based on status
            switch (intval($status)) {
                case 1: // Payment successful
                    $this->create_order_from_payment($payment_status, $token);
                    break;

                case 3: // Payment rejected
                    error_log("Flow Payment Callback: Payment rejected for token: {$token}");
                    break;

                case 4: // Payment cancelled
                    error_log("Flow Payment Callback: Payment cancelled for token: {$token}");
                    break;

                case 2: // Payment pending
                    error_log("Flow Payment Callback: Payment pending for token: {$token}");
                    break;

                default:
                    error_log("Flow Payment Callback: Unknown payment status: {$status} for token: {$token}");
                    break;
            }

            // Fire action for extensions
            do_action('flow_payment_callback_processed', $payment_status, $token);

            return new WP_REST_Response(['message' => 'Payment callback processed successfully'], 200);

        } catch (Exception $e) {
            error_log('Flow Payment Callback error: ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Internal error'], 500);
        }
    }

    private function create_order_from_payment($payment_status, $token) {
        $flow_order = $payment_status['commerceOrder'] ?? '';
        $payment_amount = intval($payment_status['amount'] ?? 0);
        $payment_id = $payment_status['flowOrder'] ?? '';
        $customer_email = $payment_status['payer'] ?? '';
        $payment_date = $payment_status['paymentData']['date'] ?? current_time('mysql');

        error_log("Flow Payment Callback: Creating order from payment - Flow Order: {$flow_order}, Amount: {$payment_amount}, Payment ID: {$payment_id}, Email: {$customer_email}");

        if (empty($customer_email)) {
            error_log('Flow Payment Callback: Cannot create order without customer email');
            return false;
        }

        try {
            // Check if this is a recurring payment by looking for existing subscription
            $subscription_orders = $this->find_subscription_by_customer($customer_email);
            $is_recurring = !empty($subscription_orders);

            if ($is_recurring) {
                // Create recurring payment order
                $original_order = $subscription_orders[0];
                $new_order = $this->create_recurring_order($original_order, $payment_status, $token);
                error_log("Flow Payment Callback: Created recurring order ID: {$new_order->get_id()} for original order: {$original_order->get_id()}");
            } else {
                // Create new customer order
                $new_order = $this->create_new_customer_order($payment_status, $token);
                error_log("Flow Payment Callback: Created new customer order ID: {$new_order->get_id()}");
            }

            // Fire action for successful payment
            do_action('flow_payment_callback_order_created', $new_order, $payment_status, $is_recurring);

            return $new_order;

        } catch (Exception $e) {
            error_log('Flow Payment Callback: Error creating order: ' . $e->getMessage());
            return false;
        }
    }

    private function find_subscription_by_customer($customer_email) {
        // Look for existing subscription orders for this customer
        return wc_get_orders([
            'billing_email' => $customer_email,
            'meta_query' => [
                [
                    'key' => '_flow_subscription_id',
                    'compare' => 'EXISTS'
                ]
            ],
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
    }

    private function create_recurring_order($original_order, $payment_status, $token) {
        $payment_amount = intval($payment_status['amount'] ?? 0);
        $payment_id = $payment_status['flowOrder'] ?? '';

        // Create a new order based on the original subscription order
        $recurring_order = wc_create_order([
            'customer_id' => $original_order->get_customer_id(),
            'status' => 'processing'
        ]);

        // Copy billing information from original order
        $recurring_order->set_billing_email($original_order->get_billing_email());
        $recurring_order->set_billing_first_name($original_order->get_billing_first_name());
        $recurring_order->set_billing_last_name($original_order->get_billing_last_name());
        $recurring_order->set_billing_address_1($original_order->get_billing_address_1());
        $recurring_order->set_billing_city($original_order->get_billing_city());
        $recurring_order->set_billing_phone($original_order->get_billing_phone());
        $recurring_order->set_billing_country($original_order->get_billing_country());
        $recurring_order->set_billing_postcode($original_order->get_billing_postcode());

        // Copy shipping information if available
        if ($original_order->get_shipping_address_1()) {
            $recurring_order->set_shipping_email($original_order->get_shipping_email());
            $recurring_order->set_shipping_first_name($original_order->get_shipping_first_name());
            $recurring_order->set_shipping_last_name($original_order->get_shipping_last_name());
            $recurring_order->set_shipping_address_1($original_order->get_shipping_address_1());
            $recurring_order->set_shipping_city($original_order->get_shipping_city());
            $recurring_order->set_shipping_country($original_order->get_shipping_country());
            $recurring_order->set_shipping_postcode($original_order->get_shipping_postcode());
        }

        // Copy items from original order
        foreach ($original_order->get_items() as $item) {
            $recurring_order->add_product($item->get_product(), $item->get_quantity());
        }

        // Set Flow payment data
        $recurring_order->update_meta_data('_flow_payment_id', $payment_id);
        $recurring_order->update_meta_data('_flow_payment_amount', $payment_amount);
        $recurring_order->update_meta_data('_flow_payment_token', $token);
        $recurring_order->update_meta_data('_flow_payment_type', 'recurring');
        $recurring_order->update_meta_data('_flow_original_order_id', $original_order->get_id());
        $recurring_order->update_meta_data('_flow_subscription_id', $original_order->get_meta('_flow_subscription_id'));
        $recurring_order->update_meta_data('_flow_customer_id', $original_order->get_meta('_flow_customer_id'));
        $recurring_order->update_meta_data('_flow_payment_callback_data', $payment_status);

        // Set payment method
        $recurring_order->set_payment_method('flow');
        $recurring_order->set_payment_method_title('Flow Suscripciones - Pago Recurrente');

        $recurring_order->calculate_totals();
        $recurring_order->payment_complete($payment_id);
        $recurring_order->add_order_note("Pago recurrente completado vía Flow. Payment ID: {$payment_id}, Amount: \${$payment_amount}");

        $recurring_order->save();

        return $recurring_order;
    }

    private function create_new_customer_order($payment_status, $token) {
        $payment_amount = intval($payment_status['amount'] ?? 0);
        $payment_id = $payment_status['flowOrder'] ?? '';
        $customer_email = $payment_status['payer'] ?? '';

        // Try to get or create WooCommerce customer
        $customer = get_user_by('email', $customer_email);
        $customer_id = $customer ? $customer->ID : 0;

        // Create new order
        $order = wc_create_order([
            'customer_id' => $customer_id,
            'status' => 'processing'
        ]);

        // Set billing information (we only have email from Flow)
        $order->set_billing_email($customer_email);

        // Try to extract name from email if no other data available
        $email_parts = explode('@', $customer_email);
        $username = $email_parts[0];
        $order->set_billing_first_name(ucfirst($username));

        // For recurring payments, we need to look up what product/service this is for
        // This would typically be stored in your subscription system
        // For now, create a generic subscription product
        $this->add_subscription_product_to_order($order, $payment_amount);

        // Set Flow payment data
        $order->update_meta_data('_flow_payment_id', $payment_id);
        $order->update_meta_data('_flow_payment_amount', $payment_amount);
        $order->update_meta_data('_flow_payment_token', $token);
        $order->update_meta_data('_flow_payment_type', 'initial');
        $order->update_meta_data('_flow_payment_callback_data', $payment_status);

        // Set payment method
        $order->set_payment_method('flow');
        $order->set_payment_method_title('Flow Suscripciones');

        $order->calculate_totals();
        $order->payment_complete($payment_id);
        $order->add_order_note("Pago inicial completado vía Flow callback. Payment ID: {$payment_id}, Amount: \${$payment_amount}");

        $order->save();

        return $order;
    }

    private function add_subscription_product_to_order($order, $amount) {
        // Look for a default subscription product or create a virtual one
        // This is a simplified approach - in production you'd want to match
        // the payment to a specific product/subscription plan

        // Try to find existing subscription products
        $subscription_products = get_posts([
            'post_type' => 'product',
            'meta_query' => [
                [
                    'key' => '_price',
                    'value' => $amount,
                    'compare' => '='
                ]
            ],
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'name',
                    'terms' => 'Suscripciones Flow'
                ]
            ],
            'posts_per_page' => 1
        ]);

        if (!empty($subscription_products)) {
            $product = wc_get_product($subscription_products[0]->ID);
            $order->add_product($product, 1);
        } else {
            // Create a virtual line item if no matching product found
            $item = new WC_Order_Item_Product();
            $item->set_name('Suscripción Flow - $' . $amount);
            $item->set_quantity(1);
            $item->set_total($amount);
            $item->set_subtotal($amount);
            $order->add_item($item);
        }
    }
}