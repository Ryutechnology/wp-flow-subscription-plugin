<?php
if ( !defined( 'ABSPATH' ) ) exit;

require_once FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'includes/class-flow-api.php';

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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        // Add flow_customer_id column to WooCommerce customer lookup table
        $wc_customer_lookup_table = $wpdb->prefix . 'wc_customer_lookup';
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = 'flow_customer_id'",
            $wc_customer_lookup_table
        ));
        if (empty($column_exists)) {
            $result = $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN flow_customer_id VARCHAR(100) DEFAULT NULL", $wc_customer_lookup_table));
            if ($result === false) {
                error_log('Flow Activator: Failed to add flow_customer_id column to ' . $wc_customer_lookup_table);
            }
        }
    }

    public static function activate_new_page() {
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
    }

    public function __construct() {
        add_shortcode('flow_return', [$this, 'handle_flow_return']);
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);
    }
    
    public function handle_flow_return() {
        if (isset($_GET['token'])) {
            echo '<h2>Procesando retorno de Flow...</h2>';

            $token = sanitize_text_field($_GET['token']);
            $result = $this->get_flow_api()->get_register_results($token);

            if (!empty($result['code'])) {
                echo '<p class="error">Error al obtener resultados de registro: ' . esc_html($result['message']) . '</p>';
                return;
            }

            if ($result['status'] === '1') {
                $customer = $result['customerId'];
                
                // Get plan info from database using customer ID
                $plan_info = $this->get_plan_info_from_customer($customer);
                if (!$plan_info) {
                    echo '<p class="error">No se pudo obtener información del plan para el cliente: ' . esc_html($customer) . '</p>';
                    return;
                }
                
                $plan = $this->get_flow_api()->create_plan($plan_info['plan'], $plan_info['amount']);
                if (!empty($plan['code'])) {
                    echo '<p class="error">Error al crear plan en Flow: ' . esc_html($plan['message']) . '</p>';
                    return;
                }

                $subscription = $this->get_flow_api()->create_subscription($plan['planId'], $customer);
                if (!empty($subscription['code'])) {
                    echo '<p class="error">Error al crear suscripción en Flow: ' . esc_html($subscription['message']) . '</p>';
                    return;
                }
                
                // Update subscription status in database
                global $wpdb;
                $table = $wpdb->prefix . 'flow_subscriptions';
                $wpdb->update(
                    $table,
                    ['status' => 'activa', 'mandato_id' => $subscription['subscriptionId'] ?? null],
                    ['id' => $plan_info['subscription_id']],
                    ['%s', '%s'],
                    ['%d']
                );
                
                echo '<p class="success">Suscripción exitosa. </p>';
            } else {
                echo '<p class="error">El registro no fue exitoso. Estado: ' . esc_html($result['status']) . '</p>';
            }
        } else {
            echo '<h2>Retorno inválido.</h2>';
        }
    }
    
    private function get_plan_info_from_customer($customer_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'flow_subscriptions';
        
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE flow_customer_id = %s ORDER BY created_at DESC LIMIT 1",
            $customer_id
        ), ARRAY_A);
        
        if (!$subscription) {
            return null;
        }
        
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
            return new WP_REST_Response(['error' => 'Token required'], 400);
        }

        $token = sanitize_text_field($params['token']);
        $result = $this->get_flow_api()->get_register_results($token);

        if (!empty($result['code'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result['message']
            ], 400);
        }

        if ($result['status'] === '1') {
            $customer = $result['customerId'];
            
            $plan_info = $this->get_plan_info_from_customer($customer);
            if (!$plan_info) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No se pudo obtener información del plan para el cliente: ' . $customer
                ], 400);
            }
            
            $plan = $this->get_flow_api()->create_plan($plan_info['plan'], $plan_info['amount']);
            if (!empty($plan['code'])) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Error al crear plan en Flow: ' . $plan['message']
                ], 400);
            }

            $subscription = $this->get_flow_api()->create_subscription($plan['planId'], $customer);
            if (!empty($subscription['code'])) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Error al crear suscripción en Flow: ' . $subscription['message']
                ], 400);
            }
            
            // Update subscription status in database
            global $wpdb;
            $table = $wpdb->prefix . 'flow_subscriptions';
            $wpdb->update(
                $table,
                ['status' => 'activa', 'mandato_id' => $subscription['subscriptionId'] ?? null],
                ['id' => $plan_info['subscription_id']],
                ['%s', '%s'],
                ['%d']
            );
            
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Suscripción creada exitosamente',
                'subscription_id' => $subscription['subscriptionId'] ?? null
            ], 200);
            
        } else {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'El registro no fue exitoso. Estado: ' . $result['status']
            ], 400);
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