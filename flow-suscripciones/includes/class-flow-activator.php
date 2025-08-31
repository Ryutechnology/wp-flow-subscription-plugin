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
                
                // Get plan info from session or database instead of undefined $attributes
                $plan_info = $this->get_plan_info_from_session();
                if (!$plan_info) {
                    echo '<p class="error">No se pudo obtener información del plan.</p>';
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
                
                // Clear session data after successful processing
                unset($_SESSION['flow_plan_info']);
                
                echo '<p class="success">Suscripción exitosa. </p>';
            } else {
                echo '<p class="error">El registro no fue exitoso. Estado: ' . esc_html($result['status']) . '</p>';
            }
        } else {
            echo '<h2>Retorno inválido.</h2>';
        }
    }
    
    private function get_plan_info_from_session() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['flow_plan_info'])) {
            return null;
        }
        
        $plan_info = $_SESSION['flow_plan_info'];
        
        // Validate session data hasn't expired (24 hours)
        if (isset($plan_info['timestamp']) && (time() - $plan_info['timestamp']) > 86400) {
            unset($_SESSION['flow_plan_info']);
            return null;
        }
        
        return $plan_info;
    }

    private function get_flow_api() {
        if (!$this->flow_api) {
            $this->flow_api = new Flow_API();
        }
        return $this->flow_api;
    }
}