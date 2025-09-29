<?php
if (!defined('ABSPATH')) exit;

class Flow_Shortcode {

    private $flow_api;
    private $flow_db;
    private $wc_integration;

    public function __construct() {
        add_shortcode('flow_suscripcion', [$this, 'render_subscription_form']);
        add_shortcode('flow_success', [$this, 'render_success_page']);
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

        $plan = $this->get_flow_api()->create_plan($_SESSION['flow_plan_info']['plan'], $_SESSION['flow_plan_info']['amount']);
        if (!empty($plan['code'])) {
            echo '<p class="error">Error al crear plan en Flow: ' . esc_html($plan['message']) . '</p>';
            return;
        }

        // Create credit card registration URL
        $url_return = rest_url('/flow/v1/return?planId='.$plan['planId']);
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

    /**
     * Render subscription success page
     */
    public function render_success_page($atts) {
        $attributes = shortcode_atts([
            'subscription_id' => '',
            'plan' => '',
            'amount' => '',
            'email' => '',
            'name' => ''
        ], $atts);

        // Get subscription details from URL parameters or attributes
        $subscription_id = isset($_GET['subscription_id']) ? intval($_GET['subscription_id']) : $attributes['subscription_id'];
        $plan = isset($_GET['plan']) ? sanitize_text_field($_GET['plan']) : $attributes['plan'];
        $amount = isset($_GET['amount']) ? intval($_GET['amount']) : $attributes['amount'];
        $email = isset($_GET['email']) ? sanitize_email($_GET['email']) : $attributes['email'];
        $name = isset($_GET['name']) ? sanitize_text_field($_GET['name']) : $attributes['name'];

        // If we have a subscription ID, get details from database
        if ($subscription_id > 0) {
            $subscription = $this->get_flow_db()->get_subscription($subscription_id);
            if ($subscription) {
                $plan = $subscription->plan_id;
                $amount = $subscription->amount;
                $email = $subscription->email;
                $name = $subscription->name;
            }
        }

        ob_start();
        ?>
        <div class="flow-success-page">
            <div class="success-header">
                <div class="success-icon">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" fill="#00a32a"/>
                        <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h2>¡Suscripción Exitosa!</h2>
                <p class="success-subtitle">Tu suscripción ha sido creada correctamente</p>
            </div>

            <div class="subscription-details">
                <h3>Detalles de tu suscripción:</h3>

                <?php if ($plan): ?>
                <div class="detail-row">
                    <span class="label">Plan:</span>
                    <span class="value"><?php echo esc_html($plan); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($amount): ?>
                <div class="detail-row">
                    <span class="label">Monto mensual:</span>
                    <span class="value">$<?php echo esc_html(number_format($amount)); ?> CLP</span>
                </div>
                <?php endif; ?>

                <?php if ($email): ?>
                <div class="detail-row">
                    <span class="label">Email:</span>
                    <span class="value"><?php echo esc_html($email); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($name): ?>
                <div class="detail-row">
                    <span class="label">Nombre:</span>
                    <span class="value"><?php echo esc_html($name); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($subscription_id): ?>
                <div class="detail-row">
                    <span class="label">ID de suscripción:</span>
                    <span class="value">#<?php echo esc_html($subscription_id); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="next-steps">
                <h3>¿Qué sigue?</h3>
                <ul>
                    <li>✅ Tu suscripción ha sido activada</li>
                    <li>✅ Recibirás una confirmación por email</li>
                    <li>✅ Los cobros se realizarán automáticamente cada mes</li>
                    <li>✅ Puedes gestionar tu suscripción desde tu cuenta</li>
                </ul>
            </div>

            <div class="action-buttons">
                <a href="<?php echo esc_url(home_url()); ?>" class="btn btn-primary">
                    Volver al inicio
                </a>
                <a href="<?php echo esc_url(home_url('/mi-cuenta')); ?>" class="btn btn-secondary">
                    Ver mi cuenta
                </a>
            </div>
        </div>

        <style>
        .flow-success-page {
            max-width: 600px;
            margin: 40px auto;
            padding: 30px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .success-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .success-icon {
            margin-bottom: 20px;
        }

        .success-header h2 {
            color: #00a32a;
            font-size: 28px;
            margin: 10px 0;
            font-weight: 600;
        }

        .success-subtitle {
            color: #666;
            font-size: 16px;
            margin: 0;
        }

        .subscription-details {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .subscription-details h3 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
            border-bottom: 2px solid #00a32a;
            padding-bottom: 10px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row .label {
            font-weight: 600;
            color: #555;
        }

        .detail-row .value {
            color: #333;
            font-weight: 500;
        }

        .next-steps {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .next-steps h3 {
            color: white;
            font-size: 18px;
            margin-bottom: 15px;
        }

        .next-steps ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .next-steps li {
            padding: 8px 0;
            font-size: 14px;
        }

        .action-buttons {
            text-align: center;
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn {
            display: inline-block;
            padding: 12px 25px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #00a32a;
            color: white;
        }

        .btn-primary:hover {
            background: #008a24;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
            color: white;
        }

        @media (max-width: 600px) {
            .flow-success-page {
                margin: 20px;
                padding: 20px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                margin-bottom: 10px;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }
}