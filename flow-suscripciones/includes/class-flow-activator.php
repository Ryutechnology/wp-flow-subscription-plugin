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
            $result = $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN flow_customer_id VARCHAR(100) DEFAULT NULL", $wc_customer_lookup_table));
            if ($result === false) {
                error_log('Flow Activator: Failed to add flow_customer_id column to ' . $wc_customer_lookup_table);
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
            $result = $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN flow_subscription_id VARCHAR(100) DEFAULT NULL", $wc_customer_lookup_table));
            if ($result === false) {
                error_log('Flow Activator: Failed to add flow_subscription_id column to ' . $wc_customer_lookup_table);
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
            $result = $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN flow_subscription_status VARCHAR(20) DEFAULT NULL", $wc_customer_lookup_table));
            if ($result === false) {
                error_log('Flow Activator: Failed to add flow_subscription_status column to ' . $wc_customer_lookup_table);
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

            // Update subscription status in database
            global $wpdb;
            $table = $wpdb->prefix . 'flow_subscriptions';
            $wpdb->update(
                $table,
                [
                    'status' => 'activo',
                    'mandato_id' => $subscription['subscriptionId'] ?? null,
                    'flow_subscription_id' => $subscription['subscriptionId'] ?? null
                ],
                ['id' => $plan_info['subscription_id']],
                ['%s', '%s', '%s'],
                ['%d']
            );

            // Create WooCommerce customer and order when subscription is successful
            $wc_integration = new Flow_WooCommerce();
            if ($wc_integration->is_woocommerce_available()) {
                // Get subscription details for WooCommerce integration
                $subscription_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE id = %d",
                    $plan_info['subscription_id']
                ));

                if ($subscription_data) {
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
                        Flow_Customer_Columns::set_customer_flow_subscription_id($customer_id, $subscription['subscriptionId'] ?? '');
                        Flow_Customer_Columns::set_customer_flow_customer_id($customer_id, $customer);

                        // Update WooCommerce customer lookup table
                        $wc_integration->update_customer_flow_data(
                            $customer_id,
                            $customer,
                            $subscription['subscriptionId'] ?? '',
                            'active'
                        );

                        error_log("Flow Debug POST: Stored Flow subscription data for customer {$customer_id} - Subscription ID: " . ($subscription['subscriptionId'] ?? '') . ", Customer ID: {$customer}");
                    }

                    // Find and update existing order instead of creating new one
                    if (isset($subscription_data->wc_order_id) && $subscription_data->wc_order_id > 0) {
                        $order = wc_get_order($subscription_data->wc_order_id);
                        if ($order) {
                            // Update order status to processing (payment successful)
                            $order->update_status('processing', 'Flow card registration successful. Subscription activated.');

                            // Update Flow data in order meta
                            $order->update_meta_data('_flow_subscription_id', $subscription['subscriptionId'] ?? '');
                            $order->update_meta_data('_flow_customer_id', $customer);
                            $order->update_meta_data('_flow_payment_complete', 'yes');
                            $order->update_meta_data('_flow_card_registration_complete', current_time('mysql'));
                            $order->save();

                            error_log("Flow Debug POST: Updated existing order #{$subscription_data->wc_order_id} to processing status - Email: {$subscription_data->email}");
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
}