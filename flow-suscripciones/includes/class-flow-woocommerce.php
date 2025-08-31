<?php
if (!defined('ABSPATH')) exit;

class Flow_WooCommerce {

    public function __construct() {
        // Only initialize if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        add_action('init', [$this, 'init']);
    }

    /**
     * Initialize WooCommerce integration
     */
    public function init() {
        // Add any WooCommerce specific hooks here
    }

    /**
     * Create customer lookup entry in WooCommerce
     */
    public function create_customer_lookup($name, $email, $city, $flow_customer_id) {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        global $wpdb;
        
        // Check if customer already exists
        $existing_customer = $this->get_customer_by_email($email);
        if ($existing_customer) {
            return false;
        }

        $name_parts = explode(' ', $name, 2);
        $first_name = $name_parts[0];
        $last_name = isset($name_parts[1]) ? $name_parts[1] : '';

        // Insert directly into wc_customer_lookup table
        $result = $wpdb->insert($wpdb->prefix . 'wc_customer_lookup', [
            'user_id' => 0, // Guest customer
            'username' => sanitize_user(current(explode('@', $email))),
            'email' => $email,
            'date_registered' => current_time('mysql'),
            'date_last_active' => current_time('mysql'),
            'orders_count' => 0,
            'total_spent' => 0,
            'avg_order_value' => 0,
            'city' => $city,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'flow_customer_id' => $flow_customer_id
        ]);

        return $result !== false;
    }

    /**
     * Get customer by email from WooCommerce lookup table
     */
    public function get_customer_by_email($email) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wc_customer_lookup WHERE email = %s",
            $email
        ));
    }

    /**
     * Update customer's Flow ID in WooCommerce lookup table
     */
    public function update_customer_flow_id($email, $flow_customer_id) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'wc_customer_lookup',
            ['flow_customer_id' => $flow_customer_id],
            ['email' => $email]
        );
    }

    /**
     * Get customer by Flow customer ID
     */
    public function get_customer_by_flow_id($flow_customer_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wc_customer_lookup WHERE flow_customer_id = %s",
            $flow_customer_id
        ));
    }

    /**
     * Update customer statistics (orders, spending, etc.)
     */
    public function update_customer_stats($email, $order_count_increment = 1, $amount_spent = 0) {
        global $wpdb;
        
        $customer = $this->get_customer_by_email($email);
        if (!$customer) {
            return false;
        }

        $new_orders_count = $customer->orders_count + $order_count_increment;
        $new_total_spent = $customer->total_spent + $amount_spent;
        $new_avg_order_value = $new_orders_count > 0 ? $new_total_spent / $new_orders_count : 0;

        return $wpdb->update(
            $wpdb->prefix . 'wc_customer_lookup',
            [
                'orders_count' => $new_orders_count,
                'total_spent' => $new_total_spent,
                'avg_order_value' => $new_avg_order_value,
                'date_last_active' => current_time('mysql')
            ],
            ['email' => $email]
        );
    }

    /**
     * Create WooCommerce order for subscription
     */
    public function create_subscription_order($email, $plan_name, $amount) {
        if (!class_exists('WC_Order')) {
            return false;
        }

        $customer = $this->get_customer_by_email($email);
        if (!$customer) {
            return false;
        }

        // Create new order
        $order = wc_create_order();
        
        // Set customer
        if ($customer->user_id > 0) {
            $order->set_customer_id($customer->user_id);
        }

        // Set billing details
        $order->set_billing_email($email);
        $order->set_billing_first_name($customer->first_name);
        $order->set_billing_last_name($customer->last_name);
        $order->set_billing_city($customer->city);

        // Add subscription item
        $item = new WC_Order_Item_Product();
        $item->set_name($plan_name . ' - Suscripción');
        $item->set_quantity(1);
        $item->set_subtotal($amount);
        $item->set_total($amount);
        
        $order->add_item($item);
        
        // Calculate totals
        $order->calculate_totals();
        
        // Set order status
        $order->set_status('processing');
        
        // Add order note
        $order->add_order_note('Orden creada automáticamente por Flow Suscripciones');
        
        // Save order
        $order->save();
        
        // Update customer stats
        $this->update_customer_stats($email, 1, $amount);
        
        return $order->get_id();
    }

    /**
     * Check if WooCommerce is active and table exists
     */
    public function is_woocommerce_available() {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_customer_lookup';
        
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }
}