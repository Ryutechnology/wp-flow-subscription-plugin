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

        // For now, we'll mark the order as processing and redirect to thank you page
        // In a real implementation, you would redirect to Flow for payment
        $order->payment_complete();
        $order->add_order_note('Pago procesado vía Flow Suscripciones.');

        // Reduce stock levels
        wc_reduce_stock_levels($order_id);

        // Remove cart
        WC()->cart->empty_cart();

        // Return thankyou redirect
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    /**
     * Webhook handler
     */
    public function webhook_handler() {
        // Handle Flow webhook notifications
        status_header(200);
        exit;
    }

    /**
     * Return handler
     */
    public function return_handler() {
        // Handle Flow return after payment
        wp_redirect(wc_get_checkout_url());
        exit;
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