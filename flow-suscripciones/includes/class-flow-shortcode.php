<?php
if (!defined('ABSPATH')) exit;

class Flow_Shortcode {

    private $flow_api;
    private $flow_db;
    private $wc_integration;

    public function __construct() {
        add_shortcode('flow_suscripcion', [$this, 'render_subscription_form']);
    }

    /**
     * Get Flow API instance (lazy loading)
     */
    private function get_flow_api() {
        if (!$this->flow_api) {
            $this->flow_api = new Flow_API();
        }
        return $this->flow_api;
    }

    /**
     * Get Flow Database instance (lazy loading)
     */
    private function get_flow_db() {
        if (!$this->flow_db) {
            $this->flow_db = new Flow_Database();
        }
        return $this->flow_db;
    }

    /**
     * Get WooCommerce integration instance (lazy loading)
     */
    private function get_wc_integration() {
        if (!$this->wc_integration) {
            $this->wc_integration = new Flow_WooCommerce();
        }
        return $this->wc_integration;
    }

    /**
     * Render subscription form shortcode
     */
    public function render_subscription_form($atts) {
        $attributes = shortcode_atts([
            'plan' => 'Plan Básico',
            'amount' => 5000
        ], $atts);

        ob_start();

        if ($_POST && isset($_POST['flow_email'])) {
            $this->process_subscription_form($attributes);
        } else {
            $this->display_subscription_form($attributes);
        }

        return ob_get_clean();
    }

    /**
     * Process subscription form submission
     */
    private function process_subscription_form($attributes) {
        // Verify nonce
        if (!wp_verify_nonce($_POST['flow_nonce'], 'flow_form')) {
            wp_die('Error de seguridad');
        }

        // Sanitize input
        $email = sanitize_email($_POST['flow_email']);
        $name = sanitize_text_field($_POST['flow_name']);
        $address = sanitize_text_field($_POST['flow_address']);
        $city = sanitize_text_field($_POST['flow_city']);

        // Insert subscription into database
        $subscription_id = $this->get_flow_db()->insert_subscription([
            'email' => $email,
            'name' => $name,
            'address' => $address,
            'city' => $city,
            'plan_id' => sanitize_title($attributes['plan']),
            'amount' => intval($attributes['amount'])
        ]);

        if (!$subscription_id) {
            echo '<p class="error">Error al guardar la suscripción.</p>';
            return;
        }

        // Create customer in Flow
        $customer = $this->get_flow_api()->create_customer($email, $name, $address, $city);
        if (!empty($customer['code'])) {
            echo '<p class="error">Error al crear cliente en Flow: ' . esc_html($customer['message']) . '</p>';
            return;
        }

        // Store plan info in session before redirecting to Flow
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flow_plan_info'] = [
            'plan' => sanitize_title($attributes['plan']),
            'amount' => intval($attributes['amount']),
            'subscription_id' => $subscription_id,
            'customer_id' => $customer['customerId'],
            'timestamp' => time()
        ];

        // Create credit card registration URL
        $url_return = rest_url('/flow/v1/return');
        $card_registration = $this->get_flow_api()->register_credit_card($customer['customerId'], $url_return);
        if (!empty($card_registration['code'])) {
            echo '<p class="error">Error al registrar tarjeta en Flow: ' . esc_html($card_registration['message']) . '</p>';
            return;
        }
        if (!empty($card_registration['url'])) {
            $base_url = $card_registration['url'];
            $token = $card_registration['token'];
            $redirect_url = add_query_arg(array('token'=>$token), $base_url);
            wp_redirect($redirect_url);
            exit;
        }

        // Update subscription with Flow customer ID
        $this->get_flow_db()->update_subscription($subscription_id, [
            'flow_customer_id' => $customer['customerId']
        ]);

        // Create WooCommerce customer lookup entry
        $this->get_wc_integration()->create_customer_lookup($name, $email, $city, $customer['customerId']);

        // Update subscription with mandate ID and set as active
        $this->get_flow_db()->update_subscription($subscription_id, [
            'mandato_id' => $mandate['id'],
            'status' => 'activo'
        ]);

        echo '<div class="flow-success">';
        echo '<p>¡Suscripción creada exitosamente!</p>';
        echo '<p>Plan: ' . esc_html($attributes['plan']) . '</p>';
        echo '<p>Monto: $' . esc_html(number_format($attributes['amount'])) . ' CLP</p>';
        echo '</div>';
    }

    /**
     * Display subscription form
     */
    private function display_subscription_form($attributes) {
        ?>
        <div class="flow-subscription-form">
            <h3>Suscríbete al <?php echo esc_html($attributes['plan']); ?></h3>
            <p class="plan-amount">Monto: $<?php echo esc_html(number_format($attributes['amount'])); ?> CLP mensual</p>
            
            <form method="post" class="flow-form">
                <?php wp_nonce_field('flow_form', 'flow_nonce'); ?>
                
                <div class="form-group">
                    <label for="flow_name">Nombre completo *</label>
                    <input type="text" name="flow_name" id="flow_name" placeholder="Tu nombre completo" required>
                </div>
                
                <div class="form-group">
                    <label for="flow_email">Email *</label>
                    <input type="email" name="flow_email" id="flow_email" placeholder="tu@email.com" required>
                </div>
                
                <div class="form-group">
                    <label for="flow_address">Dirección *</label>
                    <input type="text" name="flow_address" id="flow_address" placeholder="Tu dirección completa" required>
                </div>
                
                <div class="form-group">
                    <label for="flow_city">Ciudad *</label>
                    <input type="text" name="flow_city" id="flow_city" placeholder="Tu ciudad" required>
                </div>
                
                <button type="submit" class="flow-submit-btn">
                    Suscribirme al <?php echo esc_html($attributes['plan']); ?> 
                    ($<?php echo esc_html(number_format($attributes['amount'])); ?> CLP)
                </button>
            </form>
        </div>
        
        <style>
        .flow-subscription-form {
            max-width: 500px;
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .flow-form .form-group {
            margin-bottom: 15px;
        }
        .flow-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .flow-form input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }
        .flow-submit-btn {
            background: #0073aa;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 16px;
        }
        .flow-submit-btn:hover {
            background: #005a87;
        }
        .plan-amount {
            font-size: 18px;
            color: #0073aa;
            font-weight: bold;
        }
        .error {
            color: #d63638;
            background: #fcf0f1;
            padding: 10px;
            border-radius: 3px;
        }
        .flow-success {
            color: #00a32a;
            background: #f0f6fc;
            padding: 15px;
            border-radius: 3px;
            border-left: 4px solid #00a32a;
        }
        </style>
        <?php
    }
}