<?php
/**
 * Flow Blocks Support Class
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) exit;

/**
 * Flow payment method integration for WooCommerce Blocks
 */
final class Flow_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Payment method name/id/slug.
     */
    protected $name = 'flow';

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_flow_settings', []);
    }

    /**
     * Returns if this payment method should be active.
     */
    public function is_active() {
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        return isset($payment_gateways['flow']) && $payment_gateways['flow']->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     */
    public function get_payment_method_script_handles() {
        // For now, we'll register a simple script
        wp_register_script(
            'flow-blocks-integration',
            plugins_url('flow-blocks.js', __FILE__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            '1.0.0',
            true
        );

        return ['flow-blocks-integration'];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     */
    public function get_payment_method_data() {
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        $gateway = isset($payment_gateways['flow']) ? $payment_gateways['flow'] : null;

        return [
            'title' => $gateway ? $gateway->title : 'Flow Suscripciones',
            'description' => $gateway ? $gateway->description : 'Paga de forma segura con Flow',
            'supports' => array_filter($gateway ? $gateway->supports : [], [$gateway, 'supports']),
        ];
    }
}
?>