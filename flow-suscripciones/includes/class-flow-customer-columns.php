<?php
if (!defined('ABSPATH')) exit;

class Flow_Customer_Columns {

    public function __construct() {
        // Hook into WordPress init to create the database column
        add_action('init', array($this, 'create_customer_column'));

        // Add custom column to customers list
        add_filter('manage_users_columns', array($this, 'add_customer_column'));
        add_filter('manage_users_custom_column', array($this, 'show_customer_column_content'), 10, 3);

        // Add custom field to user profile
        add_action('show_user_profile', array($this, 'add_customer_profile_field'));
        add_action('edit_user_profile', array($this, 'add_customer_profile_field'));

        // Save custom field
        add_action('personal_options_update', array($this, 'save_customer_profile_field'));
        add_action('edit_user_profile_update', array($this, 'save_customer_profile_field'));
    }

    /**
     * Create custom database column for Flow subscription ID
     */
    public function create_customer_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'usermeta';

        // Check if we need to add any database modifications
        // WooCommerce customer data is stored in usermeta, so we don't need to modify tables
        // We'll use meta fields instead

        error_log('Flow Customer: Database setup checked for Flow subscription ID storage');
    }

    /**
     * Add Flow Subscription columns to customers list
     */
    public function add_customer_column($columns) {
        // Only add to customers who have made orders (WooCommerce customers)
        if (is_admin() && isset($_GET['role']) && $_GET['role'] === 'customer') {
            $columns['flow_subscription_status'] = 'Subscription Status';
            $columns['flow_subscription_id'] = 'Flow Subscription ID';
        }

        return $columns;
    }

    /**
     * Show Flow Subscription data in customers list
     */
    public function show_customer_column_content($value, $column_name, $user_id) {
        if ($column_name === 'flow_subscription_status') {
            $subscription_status = $this->get_customer_subscription_status($user_id);

            switch ($subscription_status['status']) {
                case 'active':
                    $value = '<span style="color: #46b450; font-weight: bold;">✓ Activa</span>';
                    if ($subscription_status['last_payment']) {
                        $value .= '<br><small>Último pago: ' . esc_html($subscription_status['last_payment']) . '</small>';
                    }
                    break;
                case 'inactive':
                    $value = '<span style="color: #dc3232; font-weight: bold;">✗ Inactiva</span>';
                    break;
                case 'pending':
                    $value = '<span style="color: #ffb900; font-weight: bold;">⏳ Pendiente</span>';
                    break;
                default:
                    $value = '<span style="color: #999;">Sin suscripción</span>';
            }
        } elseif ($column_name === 'flow_subscription_id') {
            $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
            $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);

            if ($flow_subscription_id) {
                $value = '<strong>Sub ID:</strong> ' . esc_html($flow_subscription_id);
                if ($flow_customer_id) {
                    $value .= '<br><small><strong>Customer ID:</strong> ' . esc_html($flow_customer_id) . '</small>';
                }
            } else {
                $value = '<span style="color: #999;">Sin suscripción</span>';
            }
        }

        return $value;
    }

    /**
     * Get customer subscription status
     */
    private function get_customer_subscription_status($user_id) {
        $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
        $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);

        if (!$flow_subscription_id && !$flow_customer_id) {
            return array('status' => 'none');
        }

        // First check for Flow subscription status in the database
        if ($flow_subscription_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'flow_subscriptions';
            $subscription_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE mandato_id = %s OR plan_id = %s ORDER BY created_at DESC LIMIT 1",
                $flow_subscription_id,
                $flow_subscription_id
            ));

            if ($subscription_record && $subscription_record->status === 'activo') {
                return array(
                    'status' => 'active',
                    'last_payment' => date('d/m/Y', strtotime($subscription_record->created_at)),
                    'subscription_id' => $subscription_record->id
                );
            }
        }

        // Check for WooCommerce orders with Flow data (either from checkout or created after successful subscription)
        $meta_query = array('relation' => 'OR');

        if ($flow_subscription_id) {
            $meta_query[] = array(
                'key' => '_flow_subscription_id',
                'value' => $flow_subscription_id,
                'compare' => '='
            );
        }

        if ($flow_customer_id) {
            $meta_query[] = array(
                'key' => '_flow_customer_id',
                'value' => $flow_customer_id,
                'compare' => '='
            );
        }

        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'meta_query' => $meta_query,
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));

        // Also check for orders created by Flow return process (payment method = 'flow')
        if (empty($orders)) {
            $flow_orders = wc_get_orders(array(
                'customer_id' => $user_id,
                'payment_method' => 'flow',
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            if (!empty($flow_orders)) {
                $orders = $flow_orders;
            }
        }

        if (empty($orders)) {
            // If we have Flow subscription ID but no WooCommerce orders, check if subscription is recent
            if ($flow_subscription_id) {
                return array('status' => 'pending');
            }
            return array('status' => 'inactive');
        }

        $latest_order = $orders[0];
        $order_status = $latest_order->get_status();
        $order_date = $latest_order->get_date_created();

        // Determine status based on order status and age
        switch ($order_status) {
            case 'completed':
            case 'processing':
                // Check if the order is recent (within last 35 days for monthly subscriptions)
                $days_since_order = (time() - $order_date->getTimestamp()) / (24 * 60 * 60);

                if ($days_since_order <= 35) {
                    return array(
                        'status' => 'active',
                        'last_payment' => $order_date->format('d/m/Y'),
                        'order_id' => $latest_order->get_id()
                    );
                } else {
                    // Order is old, might be inactive
                    return array(
                        'status' => 'inactive',
                        'last_payment' => $order_date->format('d/m/Y'),
                        'order_id' => $latest_order->get_id()
                    );
                }
                break;

            case 'pending':
            case 'on-hold':
                return array(
                    'status' => 'pending',
                    'last_payment' => $order_date->format('d/m/Y'),
                    'order_id' => $latest_order->get_id()
                );
                break;

            case 'failed':
            case 'cancelled':
            case 'refunded':
                return array(
                    'status' => 'inactive',
                    'last_payment' => $order_date->format('d/m/Y'),
                    'order_id' => $latest_order->get_id()
                );
                break;

            default:
                return array('status' => 'inactive');
        }
    }

    /**
     * Add Flow fields to user profile
     */
    public function add_customer_profile_field($user) {
        // Only show for customers or if user has Flow data
        $flow_subscription_id = get_user_meta($user->ID, '_flow_subscription_id', true);
        $flow_customer_id = get_user_meta($user->ID, '_flow_customer_id', true);

        if (in_array('customer', $user->roles) || $flow_subscription_id || $flow_customer_id) {
            $subscription_status = $this->get_customer_subscription_status($user->ID);
            ?>
            <h3>Flow Suscripciones</h3>
            <table class="form-table">
                <?php if ($flow_subscription_id) : ?>
                <tr>
                    <th>Estado de Suscripción</th>
                    <td>
                        <?php
                        switch ($subscription_status['status']) {
                            case 'active':
                                echo '<span style="color: #46b450; font-weight: bold; font-size: 14px;">✓ Activa</span>';
                                if ($subscription_status['last_payment']) {
                                    echo '<br><small>Último pago: ' . esc_html($subscription_status['last_payment']) . '</small>';
                                }
                                break;
                            case 'inactive':
                                echo '<span style="color: #dc3232; font-weight: bold; font-size: 14px;">✗ Inactiva</span>';
                                if ($subscription_status['last_payment']) {
                                    echo '<br><small>Último pago: ' . esc_html($subscription_status['last_payment']) . '</small>';
                                }
                                break;
                            case 'pending':
                                echo '<span style="color: #ffb900; font-weight: bold; font-size: 14px;">⏳ Pendiente</span>';
                                if ($subscription_status['last_payment']) {
                                    echo '<br><small>Fecha: ' . esc_html($subscription_status['last_payment']) . '</small>';
                                }
                                break;
                            default:
                                echo '<span style="color: #999;">Sin suscripción</span>';
                        }
                        ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><label for="flow_subscription_id">Flow Subscription ID</label></th>
                    <td>
                        <input type="text" name="flow_subscription_id" id="flow_subscription_id"
                               value="<?php echo esc_attr($flow_subscription_id); ?>" class="regular-text" />
                        <p class="description">ID de la suscripción en Flow</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="flow_customer_id">Flow Customer ID</label></th>
                    <td>
                        <input type="text" name="flow_customer_id" id="flow_customer_id"
                               value="<?php echo esc_attr($flow_customer_id); ?>" class="regular-text" />
                        <p class="description">ID del cliente en Flow</p>
                    </td>
                </tr>
                <?php
                // Show related orders with Flow data
                $orders = wc_get_orders(array(
                    'customer_id' => $user->ID,
                    'meta_query' => array(
                        array(
                            'key' => '_flow_subscription_id',
                            'compare' => 'EXISTS'
                        )
                    ),
                    'limit' => 10
                ));

                if ($orders) {
                    ?>
                    <tr>
                        <th>Órdenes con Flow</th>
                        <td>
                            <?php foreach ($orders as $order) : ?>
                                <p>
                                    <a href="<?php echo admin_url('post.php?post=' . $order->get_id() . '&action=edit'); ?>">
                                        Orden #<?php echo $order->get_order_number(); ?>
                                    </a>
                                    - <?php echo $order->get_date_created()->format('d/m/Y'); ?>
                                    <br><small>
                                        Subscription ID: <?php echo esc_html($order->get_meta('_flow_subscription_id')); ?>
                                    </small>
                                </p>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </table>
            <?php
        }
    }

    /**
     * Save Flow fields from user profile
     */
    public function save_customer_profile_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }

        if (isset($_POST['flow_subscription_id'])) {
            $flow_subscription_id = sanitize_text_field($_POST['flow_subscription_id']);
            update_user_meta($user_id, '_flow_subscription_id', $flow_subscription_id);
            error_log('Flow Customer: Updated subscription ID for user ' . $user_id . ': ' . $flow_subscription_id);
        }

        if (isset($_POST['flow_customer_id'])) {
            $flow_customer_id = sanitize_text_field($_POST['flow_customer_id']);
            update_user_meta($user_id, '_flow_customer_id', $flow_customer_id);
            error_log('Flow Customer: Updated customer ID for user ' . $user_id . ': ' . $flow_customer_id);
        }
    }

    /**
     * Get Flow subscription ID for a customer
     */
    public static function get_customer_flow_subscription_id($user_id) {
        return get_user_meta($user_id, '_flow_subscription_id', true);
    }

    /**
     * Set Flow subscription ID for a customer
     */
    public static function set_customer_flow_subscription_id($user_id, $subscription_id) {
        error_log('Flow Customer: Setting subscription ID for user ' . $user_id . ': ' . $subscription_id);
        return update_user_meta($user_id, '_flow_subscription_id', $subscription_id);
    }

    /**
     * Get Flow customer ID for a customer
     */
    public static function get_customer_flow_customer_id($user_id) {
        return get_user_meta($user_id, '_flow_customer_id', true);
    }

    /**
     * Set Flow customer ID for a customer
     */
    public static function set_customer_flow_customer_id($user_id, $customer_id) {
        error_log('Flow Customer: Setting customer ID for user ' . $user_id . ': ' . $customer_id);
        return update_user_meta($user_id, '_flow_customer_id', $customer_id);
    }

    /**
     * Get all customers with Flow subscriptions
     */
    public static function get_customers_with_flow_subscriptions() {
        $users = get_users(array(
            'meta_query' => array(
                array(
                    'key' => '_flow_subscription_id',
                    'compare' => 'EXISTS'
                )
            ),
            'role' => 'customer'
        ));

        return $users;
    }

    /**
     * Get subscription status for a customer (static method)
     */
    public static function get_subscription_status($user_id) {
        $instance = new self();
        return $instance->get_customer_subscription_status($user_id);
    }

    /**
     * Get customers with active subscriptions
     */
    public static function get_customers_with_active_subscriptions() {
        $customers_with_subscriptions = self::get_customers_with_flow_subscriptions();
        $active_customers = array();

        foreach ($customers_with_subscriptions as $customer) {
            $status = self::get_subscription_status($customer->ID);
            if ($status['status'] === 'active') {
                $active_customers[] = $customer;
            }
        }

        return $active_customers;
    }

    /**
     * Get customers with inactive subscriptions
     */
    public static function get_customers_with_inactive_subscriptions() {
        $customers_with_subscriptions = self::get_customers_with_flow_subscriptions();
        $inactive_customers = array();

        foreach ($customers_with_subscriptions as $customer) {
            $status = self::get_subscription_status($customer->ID);
            if ($status['status'] === 'inactive') {
                $inactive_customers[] = $customer;
            }
        }

        return $inactive_customers;
    }
}