<?php
if (!defined('ABSPATH')) exit;

// Only define the class if WooCommerce is available
if (class_exists('WC_Payment_Gateway')) {

class Flow_Payment_Gateway extends WC_Payment_Gateway {

    /**
     * Gateway API credentials and settings
     */
    public $api_key;
    public $secret_key;
    public $sandbox;

    public function __construct() {
        $this->id = 'flow';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Flow Suscripciones';
        $this->method_description = 'Procesa pagos de suscripciones recurrentes usando Flow.';

        // Declare support for various features including checkout blocks
        $this->supports = array(
            'products',
            'refunds'
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title', 'Flow Suscripciones');
        $this->description = $this->get_option('description', 'Paga con Flow - Suscripciones recurrentes seguras.');
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->api_key = $this->get_option('api_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->sandbox = $this->get_option('sandbox', 'yes') === 'yes';

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_flow_webhook', array($this, 'webhook_handler'));
        add_action('woocommerce_api_flow_return', array($this, 'return_handler'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Habilitar/Deshabilitar',
                'type'        => 'checkbox',
                'label'       => 'Habilitar Flow Suscripciones',
                'default'     => 'yes'
            ),
            'title' => array(
                'title'       => 'Título',
                'type'        => 'text',
                'description' => 'Título que verán los usuarios durante el checkout.',
                'default'     => 'Flow Suscripciones',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Descripción',
                'type'        => 'textarea',
                'description' => 'Descripción que verán los usuarios durante el checkout.',
                'default'     => 'Paga con Flow - Suscripciones recurrentes seguras.',
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => 'API Key',
                'type'        => 'text',
                'description' => 'Tu API Key de Flow.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'secret_key' => array(
                'title'       => 'Secret Key',
                'type'        => 'password',
                'description' => 'Tu Secret Key de Flow.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'sandbox' => array(
                'title'       => 'Modo Sandbox',
                'type'        => 'checkbox',
                'label'       => 'Habilitar modo sandbox (pruebas)',
                'default'     => 'yes',
                'description' => 'Usar el entorno de pruebas de Flow.',
                'desc_tip'    => true,
            )
        );
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice('Error: No se pudo procesar el pedido.', 'error');
            return array('result' => 'fail');
        }

        // Initialize Flow API
        $flow_api = new Flow_API();

        // Get order details
        $amount = intval($order->get_total());
        $order_number = $order->get_order_number();
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $customer_address = $order->get_billing_address_1();
        $customer_city = $order->get_billing_city();

        // Step 1: Create or get subscription plan
        $plan_name = 'Suscripción ' . get_bloginfo('name') . ' - $' . $amount;
        $plan_response = $flow_api->create_plan($plan_name, $amount);

        if (isset($plan_response['error'])) {
            $order->add_order_note('Error creating Flow plan: ' . $plan_response['error']);
            wc_add_notice('Error creando plan de suscripción: ' . $plan_response['error'], 'error');
            return array('result' => 'fail');
        }

        if (!isset($plan_response['planId'])) {
            $order->add_order_note('Invalid Flow plan response: missing planId');
            wc_add_notice('Error: Respuesta inválida al crear plan de suscripción.', 'error');
            return array('result' => 'fail');
        }

        $plan_id = $plan_response['planId'];

        // Step 2: Create subscription and customer
        $subscription_response = $flow_api->create_subscription($plan_id, $customer_email);

        if (isset($subscription_response['error'])) {
            $order->add_order_note('Error creating Flow subscription: ' . $subscription_response['error']);
            wc_add_notice('Error creando suscripción: ' . $subscription_response['error'], 'error');
            return array('result' => 'fail');
        }

        if (!isset($subscription_response['subscriptionId'])) {
            $order->add_order_note('Invalid Flow subscription response: missing subscriptionId');
            wc_add_notice('Error: Respuesta inválida al crear suscripción.', 'error');
            return array('result' => 'fail');
        }

        $subscription_id = $subscription_response['subscriptionId'];

        // Step 3: Register credit card for the customer
        $url_return = WC()->api_request_url('flow_return') . '?order_id=' . $order_id;
        $card_registration_response = $flow_api->register_credit_card($customer_email, $url_return);

        if (isset($card_registration_response['error'])) {
            $order->add_order_note('Error registering credit card: ' . $card_registration_response['error']);
            wc_add_notice('Error registrando tarjeta de crédito: ' . $card_registration_response['error'], 'error');
            return array('result' => 'fail');
        }

        if (!isset($card_registration_response['url']) || !isset($card_registration_response['token'])) {
            $order->add_order_note('Invalid Flow card registration response: missing URL or token');
            wc_add_notice('Error: Respuesta inválida del registro de tarjeta.', 'error');
            return array('result' => 'fail');
        }

        // Store Flow subscription data in order meta
        $order->update_meta_data('_flow_token', $card_registration_response['token']);
        $order->update_meta_data('_flow_plan_id', $plan_id);
        $order->update_meta_data('_flow_subscription_id', $subscription_id);
        $order->update_meta_data('_flow_customer_email', $customer_email);
        $order->update_meta_data('_flow_card_registration_data', $card_registration_response);
        $order->update_meta_data('_flow_subscription_amount', $amount);

        // Mark order as pending credit card registration
        $order->update_status('pending', 'Esperando registro de tarjeta de crédito vía Flow. Token: ' . $card_registration_response['token']);
        $order->save();

        // Clear cart
        WC()->cart->empty_cart();

        // Redirect to Flow credit card registration page
        return array(
            'result' => 'success',
            'redirect' => $card_registration_response['url']
        );
    }

    /**
     * Create Flow payment
     */
    private function create_flow_payment($payment_data) {
        // Get API credentials
        $api_key = $this->api_key ?: get_option('flow_api_key');
        $secret_key = $this->secret_key ?: get_option('flow_secret_key');

        if (empty($api_key) || empty($secret_key)) {
            return array('error' => 'Credenciales Flow no configuradas');
        }

        // Prepare parameters for Flow API
        $params = array(
            'apiKey' => $api_key,
            'commerceOrder' => $payment_data['commerceOrder'],
            'subject' => $payment_data['subject'],
            'amount' => $payment_data['amount'],
            'currency' => $payment_data['currency'],
            'email' => $payment_data['email'],
            'urlConfirmation' => $payment_data['urlConfirmation'],
            'urlReturn' => $payment_data['urlReturn']
        );

        // Sort parameters for signature
        ksort($params);

        // Create signature
        $params['s'] = hash_hmac('sha256', urldecode(http_build_query($params)), $secret_key);

        // Determine API URL based on sandbox mode
        $api_url = $this->sandbox ? 'https://sandbox.flow.cl/api/payment/create' : 'https://www.flow.cl/api/payment/create';

        // Make API request
        $response = wp_remote_post($api_url, array(
            'body' => $params,
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            )
        ));

        if (is_wp_error($response)) {
            return array('error' => 'Error de conexión: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('error' => 'Respuesta JSON inválida de Flow');
        }

        return $data;
    }

    /**
     * Webhook handler
     */
    public function webhook_handler() {
        // Get Flow webhook data
        $token = sanitize_text_field($_POST['token'] ?? '');

        if (empty($token)) {
            status_header(400);
            exit('Invalid token');
        }

        // Find order by token
        $orders = wc_get_orders(array(
            'meta_query' => array(
                array(
                    'key' => '_flow_token',
                    'value' => $token,
                    'compare' => '='
                )
            ),
            'limit' => 1
        ));

        if (empty($orders)) {
            error_log('Flow webhook: Order not found for token ' . $token);
            status_header(404);
            exit('Order not found');
        }

        $order = $orders[0];

        // Initialize Flow API to get credit card registration status
        $flow_api = new Flow_API();
        $registration_status = $flow_api->get_register_results($token);

        if (isset($registration_status['error'])) {
            error_log('Flow webhook error: ' . $registration_status['error']);
            $order->add_order_note('Error getting card registration status: ' . $registration_status['error']);
            status_header(500);
            exit('Error getting card registration status');
        }

        // Process card registration based on status
        if (isset($registration_status['status'])) {
            switch ($registration_status['status']) {
                case 1: // Pending
                    $order->update_status('pending', 'Registro de tarjeta pendiente en Flow');
                    break;
                case 2: // Completed - card registered successfully
                    $this->handle_card_registration_completion($order, $registration_status);
                    break;
                case 3: // Rejected
                    $order->update_status('failed', 'Registro de tarjeta rechazado en Flow');
                    break;
                case 4: // Cancelled
                    $order->update_status('cancelled', 'Registro de tarjeta cancelado en Flow');
                    break;
                default:
                    $order->add_order_note('Estado de registro de tarjeta desconocido en Flow: ' . $registration_status['status']);
            }
        }

        status_header(200);
        exit('OK');
    }

    /**
     * Handle credit card registration completion
     */
    private function handle_card_registration_completion($order, $registration_status) {
        // Get stored subscription data
        $subscription_id = $order->get_meta('_flow_subscription_id');
        $customer_email = $order->get_meta('_flow_customer_email');

        if (!$subscription_id || !$customer_email) {
            $order->add_order_note('Error: Missing subscription ID or customer email');
            return;
        }

        // Store credit card registration data
        $order->update_meta_data('_flow_card_id', $registration_status['cardId'] ?? '');
        $order->update_meta_data('_flow_registration_status', $registration_status);

        // Mark order as completed - subscription is now active with registered card
        $order->payment_complete($subscription_id);
        $order->add_order_note('Suscripción activada exitosamente. Subscription ID: ' . $subscription_id . ', Card ID: ' . ($registration_status['cardId'] ?? 'N/A'));

        $order->save();
    }

    /**
     * Return handler
     */
    public function return_handler() {
        $order_id = intval($_GET['order_id'] ?? 0);
        $token = sanitize_text_field($_GET['token'] ?? '');

        if (!$order_id) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // If we have a token, verify the credit card registration status
        if ($token) {
            $flow_api = new Flow_API();
            $registration_status = $flow_api->get_register_results($token);

            if (!isset($registration_status['error']) && isset($registration_status['status'])) {
                switch ($registration_status['status']) {
                    case 2: // Completed - card registered successfully
                        // Handle card registration completion
                        $this->handle_card_registration_completion($order, $registration_status);

                        // Add success message
                        wc_add_notice('¡Suscripción activada exitosamente! Tu tarjeta ha sido registrada y la suscripción está activa.', 'success');
                        wp_redirect($this->get_return_url($order));
                        exit;

                    case 3: // Rejected
                    case 4: // Cancelled
                        $order->update_status('failed', 'Registro de tarjeta no completado en Flow');
                        wc_add_notice('El registro de la tarjeta no se pudo completar. Por favor intenta nuevamente.', 'error');
                        wp_redirect(wc_get_checkout_url());
                        exit;

                    case 1: // Still pending
                        $order->add_order_note('Cliente regresó con registro de tarjeta aún pendiente');
                        wc_add_notice('El registro de tu tarjeta está en proceso. Te notificaremos cuando esté listo.', 'notice');
                        wp_redirect($this->get_return_url($order));
                        exit;
                }
            } else {
                // Error getting registration status
                $order->add_order_note('Error verificando estado del registro de tarjeta en el retorno: ' . ($registration_status['error'] ?? 'Unknown error'));
            }
        }

        // Default redirect to thank you page if order exists
        wp_redirect($this->get_return_url($order));
        exit;
    }

    /**
     * Get Flow payment status
     */
    private function get_flow_payment_status($token) {
        // Get API credentials
        $api_key = $this->api_key ?: get_option('flow_api_key');
        $secret_key = $this->secret_key ?: get_option('flow_secret_key');

        if (empty($api_key) || empty($secret_key)) {
            return array('error' => 'Credenciales Flow no configuradas');
        }

        // Prepare parameters for Flow API
        $params = array(
            'apiKey' => $api_key,
            'token' => $token
        );

        // Sort parameters for signature
        ksort($params);

        // Create signature
        $params['s'] = hash_hmac('sha256', urldecode(http_build_query($params)), $secret_key);

        // Determine API URL based on sandbox mode
        $api_url = $this->sandbox ? 'https://sandbox.flow.cl/api/payment/getStatus' : 'https://www.flow.cl/api/payment/getStatus';

        // Make API request
        $response = wp_remote_get($api_url . '?' . http_build_query($params), array(
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array('error' => 'Error de conexión: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('error' => 'Respuesta JSON inválida de Flow');
        }

        return $data;
    }

    /**
     * Check if this gateway is available in the user's country
     */
    public function is_available() {
        // Basic availability check
        if ($this->enabled === 'no') {
            return false;
        }

        // For testing purposes, make it more permissive
        // You can tighten these requirements later
        return true;
    }

    /**
     * Admin Panel Options
     */
    public function admin_options() {
        ?>
        <h3><?php echo $this->method_title; ?></h3>
        <p><?php echo $this->method_description; ?></p>

        <?php
        // Show sync status
        $flow_api_key = get_option('flow_api_key');
        $flow_secret_key = get_option('flow_secret_key');

        if ($flow_api_key && $flow_secret_key) {
            echo '<div class="notice notice-success inline"><p><strong>✓ Credenciales Flow sincronizadas automáticamente</strong></p></div>';

            // Auto-sync credentials if they're different
            if ($this->api_key !== $flow_api_key || $this->secret_key !== $flow_secret_key) {
                $this->update_option('api_key', $flow_api_key);
                $this->update_option('secret_key', $flow_secret_key);
                echo '<div class="notice notice-info inline"><p>Credenciales actualizadas automáticamente desde la configuración principal de Flow.</p></div>';
            }
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>⚠ Configura las credenciales Flow en Flow Suscripciones > Configuración</strong></p></div>';
        }
        ?>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>

        <h4>URLs para configurar en Flow</h4>
        <table class="form-table">
            <tr>
                <th>URL de Webhook:</th>
                <td><code><?php echo home_url('/wc-api/flow_webhook/'); ?></code></td>
            </tr>
            <tr>
                <th>URL de Retorno:</th>
                <td><code><?php echo home_url('/wc-api/flow_return/'); ?></code></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Process admin options and sync with Flow settings
     */
    public function process_admin_options() {
        $saved = parent::process_admin_options();

        // Auto-sync with Flow plugin settings if they exist
        $flow_api_key = get_option('flow_api_key');
        $flow_secret_key = get_option('flow_secret_key');

        if (empty($this->get_option('api_key')) && $flow_api_key) {
            $this->update_option('api_key', $flow_api_key);
        }

        if (empty($this->get_option('secret_key')) && $flow_secret_key) {
            $this->update_option('secret_key', $flow_secret_key);
        }

        return $saved;
    }
}

} // End class_exists check
?>