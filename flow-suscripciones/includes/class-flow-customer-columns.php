<?php
if (!defined('ABSPATH')) exit;

class Flow_Customer_Columns {

    public function __construct() {
        global $wpdb;
        // Hook into WordPress init to create the database column
        add_action('init', array($this, 'create_customer_column'));

        // Add custom column to customers list (WordPress users table)
        add_filter('manage_users_columns', array($this, 'add_customer_column'));
        add_filter('manage_users_custom_column', array($this, 'show_customer_column_content'), 10, 3);

        // Force add columns on all admin pages that might show customers
        add_action('admin_menu', array($this, 'modify_woocommerce_customer_menu'));
        add_action('admin_head', array($this, 'inject_customer_column_styles'));

        // Ensure columns appear when accessing via WooCommerce menu
        add_action('load-users.php', array($this, 'force_customer_columns_on_wc_access'));
        add_action('current_screen', array($this, 'detect_woocommerce_customer_access'));

        // Add custom field to user profile
        add_action('show_user_profile', array($this, 'add_customer_profile_field'));
        add_action('edit_user_profile', array($this, 'add_customer_profile_field'));

        // Save custom field
        add_action('personal_options_update', array($this, 'save_customer_profile_field'));
        add_action('edit_user_profile_update', array($this, 'save_customer_profile_field'));

        // Add WooCommerce customer list columns (multiple hooks for different WooCommerce versions)
        add_filter('woocommerce_customer_list_columns', array($this, 'add_woocommerce_customer_columns'));
        add_filter('woocommerce_customer_list_column_content', array($this, 'show_woocommerce_customer_column_content'), 10, 3);

        // Add columns to WooCommerce Analytics customers table
        add_filter('woocommerce_admin_customers_list_table_columns', array($this, 'add_woocommerce_customer_columns'));
        add_action('woocommerce_admin_customers_list_table_column_content', array($this, 'show_woocommerce_analytics_column_content'), 10, 2);

        // Add columns to WooCommerce customers list in admin
        add_filter('manage_woocommerce_page_wc-customers_columns', array($this, 'add_woocommerce_customer_columns'));
        add_action('manage_woocommerce_page_wc-customers_custom_column', array($this, 'show_woocommerce_admin_column_content'), 10, 2);

        // Hook into WooCommerce customer list specifically
        add_action('admin_init', array($this, 'init_customer_list_hooks'));

        // Add hooks for different WooCommerce versions and contexts
        add_filter('woocommerce_customers_list_table_columns', array($this, 'add_woocommerce_customer_columns'));
        add_action('woocommerce_customers_list_table_column_content', array($this, 'show_woocommerce_analytics_column_content'), 10, 2);

        // Add hooks for WooCommerce Admin (React-based interface)
        add_action('admin_enqueue_scripts', array($this, 'enqueue_customer_admin_scripts'));

        // Try different WooCommerce hooks that might work
        add_action('woocommerce_loaded', array($this, 'init_woocommerce_hooks'));
        add_action('woocommerce_admin_loaded', array($this, 'init_woocommerce_admin_hooks'));

        // Hook into REST API responses for customer data
        add_filter('woocommerce_rest_customer_object_query', array($this, 'modify_customer_rest_query'), 10, 2);
        add_filter('woocommerce_rest_prepare_customer', array($this, 'add_flow_data_to_customer_rest'), 10, 3);

        // Add JavaScript for WooCommerce Admin React interface
        add_action('admin_enqueue_scripts', array($this, 'enqueue_wc_admin_scripts'));

        // Add to WordPress users list when viewing customers
        add_action('load-users.php', array($this, 'init_users_page_hooks'));


        // Add admin notice for debugging (only in development)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('admin_notices', array($this, 'show_debug_notice'));
        }

        add_filter( 'woocommerce_admin_report_columns', function( $columns, $context, $table_name ) {

            if ( $context === 'customers' ) { // solo para reporte de clientes
                $columns['flow_subscription_status'] = $table_name  . '.flow_subscription_status as flow_subscription_status';

                $columns['flow_subscription_id'] = $table_name  . '.flow_subscription_id as flow_subscription_id';
            }

            return $columns;

        }, 10, 3 );

        add_filter( 'woocommerce_admin_report_column_data', function( $value, $column, $item ) {

            if ( $column === 'flow_subscription_status' ) {
                return isset( $item['flow_subscription_status'] ) ? $item['flow_subscription_status'] : '';
            }

            if ( $column === 'flow_subscription_id' ) {
                return isset( $item['flow_subscription_id'] ) ? $item['flow_subscription_id'] : '';
            }

            return $value;

        }, 10, 3 );


        add_filter( 'woocommerce_analytics_customers_data', function( $customers ) {
            global $wpdb;

            foreach ( $customers as &$customer ) {

                $customer_id = $customer['id'] ?? 0; // en Analytics API se llama 'id'
                if ( ! $customer_id ) continue;

                // Leer desde wc_customer_lookup
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT flow_subscription_status, flow_subscription_id
                        FROM {$wpdb->prefix}wc_customer_lookup
                        WHERE customer_id = %d LIMIT 1",
                        $customer_id
                    ),
                    ARRAY_A
                );

                // Inyectar tus campos
                $customer['flow_subscription_status'] = $row['flow_subscription_status'] ?? '';
                $customer['flow_subscription_id']     = $row['flow_subscription_id'] ?? '';
            }

            return $customers;
        } );

        add_filter( 'woocommerce_admin_customer_list_table_columns', 'agregar_columna_custom_cliente' );
        add_filter( 'woocommerce_admin_customer_list_table_column_value', 'mostrar_valor_columna_custom_cliente', 10, 3 );


        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_admin%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_admin%'" );

        // Add AJAX handlers for Flow customer data
        add_action('wp_ajax_get_flow_customer_data', array($this, 'ajax_get_flow_customer_data'));
        add_action('wp_ajax_nopriv_get_flow_customer_data', array($this, 'ajax_get_flow_customer_data'));

        // Add test AJAX handler for debugging
        add_action('wp_ajax_test_flow_ajax', array($this, 'test_ajax_handler'));
        add_action('wp_ajax_nopriv_test_flow_ajax', array($this, 'test_ajax_handler'));

        // Add debug version of Flow customer data handler (less strict)
        add_action('wp_ajax_debug_flow_customer_data', array($this, 'debug_ajax_get_flow_customer_data'));
        add_action('wp_ajax_nopriv_debug_flow_customer_data', array($this, 'debug_ajax_get_flow_customer_data'));
    }

    public function agregar_columna_custom_cliente( $columns ) {
        $columns['mi_columna'] = 'Mi Columna'; // ID => Título
        return $columns;
    }

    public function mostrar_valor_columna_custom_cliente( $value, $column, $customer ) {
        if ( 'mi_columna' === $column ) {
            // Ejemplo: mostrar el número de pedidos del cliente
            $value = $customer->get_order_count(); // o cualquier otro dato
        }
        return $value;
    }

    public function init_customer_list_hooks() {
        // Check if we're on the WooCommerce customers page
        if (is_admin() && function_exists('WC')) {
            // Log current page for debugging
            if (isset($_GET['page'])) {
                error_log('Flow Customer Columns: Current admin page: ' . $_GET['page']);
            }

            // Add hooks for all possible WooCommerce customer page variations
            add_action('current_screen', array($this, 'add_screen_specific_hooks'));

            // Try to hook into WooCommerce list table preparation
            add_action('admin_head', array($this, 'debug_current_context'));
        }
    }

    /**
     * Add hooks specific to the current admin screen
     */
    public function add_screen_specific_hooks() {
        $screen = get_current_screen();

        if (!$screen) {
            return;
        }

        // Log for debugging
        error_log('Flow Customer Columns: Current screen ID: ' . $screen->id);
        error_log('Flow Customer Columns: Screen base: ' . $screen->base);

        // For WooCommerce Analytics Customers page
        if (strpos($screen->id, 'woocommerce') !== false && strpos($screen->id, 'customers') !== false) {
            // Force add columns for this specific screen
            add_filter('manage_' . $screen->id . '_columns', array($this, 'add_woocommerce_customer_columns'));
            add_action('manage_' . $screen->id . '_custom_column', array($this, 'show_woocommerce_admin_column_content'), 10, 2);
        }

        // Check for various WooCommerce customer list implementations
        if ($screen->base === 'woocommerce_page_wc-customers' ||
            $screen->id === 'woocommerce_page_wc-customers' ||
            strpos($screen->id, 'wc-customers') !== false) {

            add_filter('manage_' . $screen->id . '_columns', array($this, 'add_woocommerce_customer_columns'));
            add_action('manage_' . $screen->id . '_custom_column', array($this, 'show_woocommerce_admin_column_content'), 10, 2);

            error_log('Flow Customer Columns: Added hooks for WooCommerce customers page');
        }
    }

    /**
     * Debug current admin context
     */
    public function debug_current_context() {
        $screen = get_current_screen();
        if ($screen && (strpos($screen->id, 'woocommerce') !== false || strpos($screen->id, 'users') !== false)) {
            error_log('Flow Customer Columns Debug - Screen ID: ' . $screen->id . ', Base: ' . $screen->base);
            if (isset($_GET['page'])) {
                error_log('Flow Customer Columns Debug - Page parameter: ' . $_GET['page']);
            }
        }
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
        // Add columns when viewing customers specifically or when no role filter is applied
        if (is_admin()) {
            $show_columns = false;

            // Show if specifically viewing customers
            if (isset($_GET['role']) && $_GET['role'] === 'customer') {
                $show_columns = true;
            }

            // Show if no role filter is applied (show for all users)
            if (!isset($_GET['role']) || empty($_GET['role'])) {
                $show_columns = true;
            }

            // Also show if accessed via WooCommerce context
            $referrer = wp_get_referer();
            if ($referrer && strpos($referrer, 'woocommerce') !== false) {
                $show_columns = true;
            }

            // Show if current screen suggests customer management
            $screen = get_current_screen();
            if ($screen && $screen->id === 'users') {
                $show_columns = true;
            }

            if ($show_columns) {
                $columns['flow_subscription_status'] = 'Flow Status';
                $columns['flow_subscription_id'] = 'Flow Subscription ID';
                $columns['flow_subscription_url'] = 'Flow Dashboard';
                error_log('Flow Customer Columns: Added columns to users table');
            }
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
        } elseif ($column_name === 'flow_subscription_url') {
            $subscription_url = $this->get_flow_subscription_url($user_id);

            if ($subscription_url) {
                $value = '<a href="' . esc_url($subscription_url) . '" target="_blank" style="color: #0073aa; text-decoration: none;">
                    <span class="dashicons dashicons-external" style="font-size: 16px; vertical-align: middle;"></span>
                    Ver Dashboard
                </a>';
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

    /**
     * Add Flow columns to WooCommerce customer list
     */
    public function add_woocommerce_customer_columns($columns) {
        // Always add columns when this function is called from WooCommerce hooks
        if (is_admin() && function_exists('WC')) {
            $columns['flow_subscription_status_wc'] = 'Flow Status';
            $columns['flow_subscription_id_wc'] = 'Flow Subscription';
            $columns['flow_subscription_url_wc'] = 'Flow Dashboard';
        }

        return $columns;
    }

    /**
     * Show Flow columns content in WooCommerce customer list
     */
    public function show_woocommerce_customer_column_content($value, $column_name, $customer_data) {
        if (!is_object($customer_data) || !isset($customer_data->customer_id)) {
            return $value;
        }

        $user_id = $customer_data->customer_id;

        if ($column_name === 'flow_subscription_status_wc') {
            // Get status from WooCommerce customer lookup table first, then fallback to user meta
            global $wpdb;
            $flow_status = $wpdb->get_var($wpdb->prepare(
                "SELECT flow_subscription_status FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d",
                $user_id
            ));

            if (!$flow_status) {
                // Fallback to calculating status from user meta and orders
                $subscription_status = $this->get_customer_subscription_status($user_id);
                $flow_status = $subscription_status['status'];
            }

            switch ($flow_status) {
                case 'active':
                    $value = '<span style="color: #46b450; font-weight: bold;">✓ Activa</span>';
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
        } elseif ($column_name === 'flow_subscription_id_wc') {
            // Get subscription ID from WooCommerce customer lookup table first, then fallback to user meta
            global $wpdb;
            $flow_subscription_id = $wpdb->get_var($wpdb->prepare(
                "SELECT flow_subscription_id FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d",
                $user_id
            ));

            if (!$flow_subscription_id) {
                // Fallback to user meta
                $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
            }

            if ($flow_subscription_id) {
                $value = '<code>' . esc_html($flow_subscription_id) . '</code>';
            } else {
                $value = '<span style="color: #999;">-</span>';
            }
        } elseif ($column_name === 'flow_subscription_url_wc') {
            $subscription_url = $this->get_flow_subscription_url($user_id);

            if ($subscription_url) {
                $value = '<a href="' . esc_url($subscription_url) . '" target="_blank" style="color: #0073aa; text-decoration: none;">
                    <span class="dashicons dashicons-external" style="font-size: 16px; vertical-align: middle;"></span>
                    Ver Dashboard
                </a>';
            } else {
                $value = '<span style="color: #999;">-</span>';
            }
        }

        return $value;
    }

    /**
     * Show Flow columns content in WooCommerce Analytics customer list
     */
    public function show_woocommerce_analytics_column_content($column_name, $customer_id) {
        if ($column_name === 'flow_subscription_status_wc') {
            echo $this->get_flow_status_display($customer_id);
        } elseif ($column_name === 'flow_subscription_id_wc') {
            echo $this->get_flow_subscription_id_display($customer_id);
        } elseif ($column_name === 'flow_subscription_url_wc') {
            echo $this->get_flow_subscription_url_display($customer_id);
        }
    }

    /**
     * Show Flow columns content in WooCommerce admin customer list
     */
    public function show_woocommerce_admin_column_content($column_name, $customer_id) {
        if ($column_name === 'flow_subscription_status_wc') {
            echo $this->get_flow_status_display($customer_id);
        } elseif ($column_name === 'flow_subscription_id_wc') {
            echo $this->get_flow_subscription_id_display($customer_id);
        } elseif ($column_name === 'flow_subscription_url_wc') {
            echo $this->get_flow_subscription_url_display($customer_id);
        }
    }

    /**
     * Get Flow subscription status display HTML
     */
    private function get_flow_status_display($user_id) {
        // Get status from WooCommerce customer lookup table first, then fallback to user meta
        global $wpdb;
        $flow_status = $wpdb->get_var($wpdb->prepare(
            "SELECT flow_subscription_status FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d",
            $user_id
        ));

        if (!$flow_status) {
            // Fallback to calculating status from user meta and orders
            $subscription_status = $this->get_customer_subscription_status($user_id);
            $flow_status = $subscription_status['status'];
        }

        switch ($flow_status) {
            case 'active':
                return '<span style="color: #46b450; font-weight: bold;">✓ Activa</span>';
            case 'inactive':
                return '<span style="color: #dc3232; font-weight: bold;">✗ Inactiva</span>';
            case 'pending':
                return '<span style="color: #ffb900; font-weight: bold;">⏳ Pendiente</span>';
            default:
                return '<span style="color: #999;">Sin suscripción</span>';
        }
    }

    /**
     * Get Flow subscription ID display HTML
     */
    private function get_flow_subscription_id_display($user_id) {
        // Get subscription ID from WooCommerce customer lookup table first, then fallback to user meta
        global $wpdb;
        $flow_subscription_id = $wpdb->get_var($wpdb->prepare(
            "SELECT flow_subscription_id FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d",
            $user_id
        ));

        if (!$flow_subscription_id) {
            // Fallback to user meta
            $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
        }

        if ($flow_subscription_id) {
            return '<code>' . esc_html($flow_subscription_id) . '</code>';
        } else {
            return '<span style="color: #999;">-</span>';
        }
    }

    /**
     * Get Flow subscription URL for a customer
     */
    private function get_flow_subscription_url($user_id) {
        // Get subscription ID from WooCommerce customer lookup table first, then fallback to user meta
        global $wpdb;
        $flow_subscription_id = $wpdb->get_var($wpdb->prepare(
            "SELECT flow_subscription_id FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d",
            $user_id
        ));

        if (!$flow_subscription_id) {
            // Fallback to user meta
            $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
        }

        if (!$flow_subscription_id) {
            return null;
        }

        // Determine if this is production or sandbox based on environment
        $is_production = defined('FLOW_ENVIRONMENT') && FLOW_ENVIRONMENT === 'production';

        if ($is_production) {
            $base_url = 'https://dashboard.flow.cl';
        } else {
            $base_url = 'https://dashboard.sandbox.flow.cl';
        }

        return $base_url . '/private/suscripciones/subscriptions/details/?sus_id=' . urlencode($flow_subscription_id);
    }

    /**
     * Get Flow subscription URL display HTML
     */
    private function get_flow_subscription_url_display($user_id) {
        $subscription_url = $this->get_flow_subscription_url($user_id);

        if ($subscription_url) {
            return '<a href="' . esc_url($subscription_url) . '" target="_blank" style="color: #0073aa; text-decoration: none;">
                <span class="dashicons dashicons-external" style="font-size: 16px; vertical-align: middle;"></span>
                Ver Dashboard
            </a>';
        } else {
            return '<span style="color: #999;">-</span>';
        }
    }

    /**
     * Initialize hooks for WordPress users page when viewing customers
     */
    public function init_users_page_hooks() {
        // Make sure we show columns for customers on the users page
        if (isset($_GET['role']) && $_GET['role'] === 'customer') {
            // Already handled by existing hooks
            return;
        }

        // If no role filter is set, we might still want to show Flow data for customers
        add_filter('manage_users_columns', array($this, 'add_customer_column'));
        add_filter('manage_users_custom_column', array($this, 'show_customer_column_content'), 10, 3);
    }

    /**
     * Enqueue scripts for WooCommerce Admin customer interface
     */
    public function enqueue_customer_admin_scripts($hook_suffix) {
        // Only load on WooCommerce admin pages
        if (strpos($hook_suffix, 'woocommerce') === false && strpos($hook_suffix, 'wc-admin') === false) {
            return;
        }

        // Add inline CSS for Flow subscription status styling
        $custom_css = "
            .flow-subscription-status {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-weight: bold;
                font-size: 11px;
            }
            .flow-status-active {
                background-color: #d4edda;
                color: #155724;
            }
            .flow-status-inactive {
                background-color: #f8d7da;
                color: #721c24;
            }
            .flow-status-pending {
                background-color: #fff3cd;
                color: #856404;
            }
        ";

        wp_add_inline_style('woocommerce-admin-style', $custom_css);
    }

    /**
     * Enqueue scripts specifically for WooCommerce Admin React interface
     */
    public function enqueue_wc_admin_scripts($hook_suffix) {
        // Check if we're on the WooCommerce Admin page
        if ($hook_suffix !== 'woocommerce_page_wc-admin') {
            return;
        }

        // Check if we're on the customers path
        if (!isset($_GET['path']) || strpos($_GET['path'], 'customers') === false) {
            return;
        }

        // Enqueue JavaScript to modify the React customer table
        wp_enqueue_script(
            'flow-wc-admin-customers',
            FLOW_SUSCRIPCIONES_PLUGIN_URL . 'assets/js/flow-customers-columns.js',
            array('wp-hooks', 'wp-i18n'),
            FLOW_SUSCRIPCIONES_VERSION,
            true
        );

        // Localize script with API data
        wp_localize_script('flow-wc-admin-customers', 'flowCustomerData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('flow_customer_data'),
            'restUrl' => rest_url('wc/v3/customers'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'isProduction' => defined('FLOW_ENVIRONMENT') && FLOW_ENVIRONMENT === 'production'
        ));

        error_log('Flow Customer Columns: Enqueued scripts for WooCommerce Admin customers page');
    }

    /**
     * Modify customer REST API query to include Flow data
     */
    public function modify_customer_rest_query($query_params, $request) {
        // This would be used to add Flow subscription data to REST API responses
        // For now, we'll just log that this hook was called
        error_log('Flow Customer Columns: REST API customer query hook called');
        return $query_params;
    }

    /**
     * Add Flow subscription data to customer REST API responses
     */
    public function add_flow_data_to_customer_rest($response, $customer, $request) {
        $data = $response->get_data();
        $user_id = $customer->get_id();

        // Get Flow subscription data
        $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
        $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);

        // Get subscription status
        $subscription_status = $this->get_customer_subscription_status($user_id);

        // Add Flow data to response
        $data['flow_subscription'] = array(
            'subscription_id' => $flow_subscription_id ?: null,
            'customer_id' => $flow_customer_id ?: null,
            'status' => $subscription_status['status'],
            'last_payment' => $subscription_status['last_payment'] ?? null,
            'status_display' => $this->get_flow_status_display_text($subscription_status['status'])
        );

        $response->set_data($data);
        return $response;
    }

    /**
     * Get Flow status display text for API responses
     */
    private function get_flow_status_display_text($status) {
        switch ($status) {
            case 'active':
                return 'Activa';
            case 'inactive':
                return 'Inactiva';
            case 'pending':
                return 'Pendiente';
            default:
                return 'Sin suscripción';
        }
    }

    /**
     * Show debug notice about where Flow columns are available
     */
    public function show_debug_notice() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Only show on customer-related pages
        if (strpos($screen->id, 'users') !== false || strpos($screen->id, 'woocommerce') !== false) {
            static $notice_shown = false;
            if ($notice_shown) return;
            $notice_shown = true;

            $available_locations = array(
                'WordPress Users (All Users)' => admin_url('users.php'),
                'WordPress Users (Customers Only)' => admin_url('users.php?role=customer'),
                'WooCommerce → Customers' => admin_url('admin.php?page=wc-customers'),
            );

            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>Flow Subscription Columns:</strong> Available in the following locations:</p>';
            echo '<ul>';
            foreach ($available_locations as $location => $url) {
                echo '<li><a href="' . esc_url($url) . '">' . esc_html($location) . '</a></li>';
            }
            echo '</ul>';
            echo '<p><em>Current page: ' . esc_html($screen->id) . '</em></p>';
            echo '</div>';
        }
    }

    /**
     * Initialize WooCommerce hooks after WooCommerce is loaded
     */
    public function init_woocommerce_hooks() {
        error_log('Flow Customer Columns: WooCommerce loaded, initializing additional hooks');

        // Check if this is WooCommerce's customer list table
        if (class_exists('WC_Admin_Customers_List_Table')) {
            add_filter('wc_customer_list_table_columns', array($this, 'add_woocommerce_customer_columns'));
            add_action('wc_customer_list_table_column_content', array($this, 'show_woocommerce_analytics_column_content'), 10, 2);
        }

        // Add hooks for legacy WooCommerce customer pages
        add_action('load-woocommerce_page_wc-customers', array($this, 'init_wc_customers_page'));
    }

    /**
     * Initialize WooCommerce Admin hooks after WooCommerce Admin is loaded
     */
    public function init_woocommerce_admin_hooks() {
        error_log('Flow Customer Columns: WooCommerce Admin loaded, initializing admin hooks');

        // For newer WooCommerce Admin interface
        add_filter('woocommerce_admin_customers_list_columns', array($this, 'add_woocommerce_customer_columns'));
        add_action('woocommerce_admin_customers_list_column_content', array($this, 'show_woocommerce_analytics_column_content'), 10, 2);
    }

    /**
     * Initialize hooks when loading WooCommerce customers page
     */
    public function init_wc_customers_page() {
        error_log('Flow Customer Columns: Loading WooCommerce customers page');

        // Add columns to this specific page
        add_filter('manage_woocommerce_page_wc-customers_columns', array($this, 'add_woocommerce_customer_columns'));
        add_action('manage_woocommerce_page_wc-customers_custom_column', array($this, 'show_woocommerce_admin_column_content'), 10, 2);

        // Also try the screen options filter
        add_filter('screen_options_show_screen', array($this, 'show_flow_column_options'), 10, 2);
    }

    /**
     * Add screen options for Flow columns
     */
    public function show_flow_column_options($show_screen, $screen) {
        if (strpos($screen->id, 'woocommerce') !== false && strpos($screen->id, 'customers') !== false) {
            // This would add screen options to show/hide Flow columns
            error_log('Flow Customer Columns: Screen options available for ' . $screen->id);
        }
        return $show_screen;
    }

    /**
     * Modify WooCommerce customer menu to ensure our hooks are applied
     */
    public function modify_woocommerce_customer_menu() {
        if (!function_exists('WC')) {
            return;
        }

        // Check if WooCommerce customer menu exists and redirect to users.php with customer filter
        global $submenu;

        if (isset($submenu['woocommerce'])) {
            foreach ($submenu['woocommerce'] as $key => $menu_item) {
                if (strpos($menu_item[0], 'Customer') !== false || strpos($menu_item[2], 'customer') !== false) {
                    // Log the customer menu item for debugging
                    error_log('Flow Customer Columns: Found WooCommerce customer menu: ' . $menu_item[0] . ' -> ' . $menu_item[2]);

                    // If WooCommerce points to users.php, our hooks should work
                    if (strpos($menu_item[2], 'users.php') !== false) {
                        error_log('Flow Customer Columns: WooCommerce customer menu uses WordPress users list');

                        // Ensure our hooks are active for this path
                        add_action('load-users.php', function() {
                            add_filter('manage_users_columns', array($this, 'force_add_customer_columns'), 999);
                        });
                    }
                    // If it points to a custom WooCommerce page
                    elseif (strpos($menu_item[2], 'wc-') !== false) {
                        error_log('Flow Customer Columns: WooCommerce customer menu uses custom page: ' . $menu_item[2]);

                        // Hook into this specific page
                        $page_hook = 'load-' . str_replace('admin.php?page=', '', $menu_item[2]);
                        add_action($page_hook, function() {
                            // Try to hook into this custom page
                            $this->init_custom_wc_page_hooks();
                        });
                    }
                }
            }
        }
    }

    /**
     * Inject CSS styles for Flow columns in customer lists
     */
    public function inject_customer_column_styles() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Add styles for customer-related pages
        if (strpos($screen->id, 'users') !== false || strpos($screen->id, 'woocommerce') !== false) {
            ?>
            <style type="text/css">
                .column-flow_subscription_status,
                .column-flow_subscription_status_wc {
                    width: 120px;
                }
                .column-flow_subscription_id,
                .column-flow_subscription_id_wc {
                    width: 150px;
                }
                .column-flow_subscription_url,
                .column-flow_subscription_url_wc {
                    width: 120px;
                }
                .flow-subscription-status {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-weight: bold;
                    font-size: 11px;
                }
                .flow-status-active {
                    background-color: #d4edda;
                    color: #155724;
                }
                .flow-status-inactive {
                    background-color: #f8d7da;
                    color: #721c24;
                }
                .flow-status-pending {
                    background-color: #fff3cd;
                    color: #856404;
                }
            </style>
            <?php
        }
    }

    /**
     * Force customer columns when accessing users.php through WooCommerce menu
     */
    public function force_customer_columns_on_wc_access() {
        // Check if we're coming from WooCommerce menu or if role=customer is set
        $referrer = wp_get_referer();
        $is_wc_referrer = $referrer && strpos($referrer, 'woocommerce') !== false;
        $is_customer_role = isset($_GET['role']) && $_GET['role'] === 'customer';

        if ($is_wc_referrer || $is_customer_role) {
            // Force add our columns with high priority
            add_filter('manage_users_columns', array($this, 'force_add_customer_columns'), 999);
            add_filter('manage_users_custom_column', array($this, 'show_customer_column_content'), 10, 3);

            error_log('Flow Customer Columns: Forced columns for WooCommerce customer access');
        }
    }

    /**
     * Detect when WooCommerce customer page is accessed
     */
    public function detect_woocommerce_customer_access() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Check if this is the users page with customer role or accessed via WooCommerce
        if ($screen->id === 'users' && (isset($_GET['role']) && $_GET['role'] === 'customer')) {
            // This is definitely the customer list - force our columns
            add_filter('manage_users_columns', array($this, 'force_add_customer_columns'), 999);
            error_log('Flow Customer Columns: Detected customer role access on users page');
        }

        // Also check for any WooCommerce context
        $referrer = wp_get_referer();
        if ($referrer && strpos($referrer, 'woocommerce') !== false && $screen->id === 'users') {
            add_filter('manage_users_columns', array($this, 'force_add_customer_columns'), 999);
            error_log('Flow Customer Columns: Detected WooCommerce referrer to users page');
        }
    }

    /**
     * Force add customer columns (used with high priority)
     */
    public function force_add_customer_columns($columns) {
        // Always add Flow columns when this method is called
        $columns['flow_subscription_status'] = 'Flow Status';
        $columns['flow_subscription_id'] = 'Flow Subscription ID';
        $columns['flow_subscription_url'] = 'Flow Dashboard';

        error_log('Flow Customer Columns: Force added columns - ' . json_encode(array_keys($columns)));
        return $columns;
    }

    /**
     * Initialize hooks for custom WooCommerce customer pages
     */
    public function init_custom_wc_page_hooks() {
        error_log('Flow Customer Columns: Initializing hooks for custom WooCommerce customer page');

        // Try various hook combinations that might work for custom WC pages
        $screen = get_current_screen();
        if ($screen) {
            // Try screen-specific hooks
            add_filter('manage_' . $screen->id . '_columns', array($this, 'add_woocommerce_customer_columns'));
            add_action('manage_' . $screen->id . '_custom_column', array($this, 'show_woocommerce_admin_column_content'), 10, 2);

            // Try table-specific hooks
            add_filter($screen->id . '_columns', array($this, 'add_woocommerce_customer_columns'));

            error_log('Flow Customer Columns: Added hooks for custom WC page: ' . $screen->id);
        }

        // Also try general WooCommerce list table hooks
        add_filter('woocommerce_customers_list_table_columns', array($this, 'add_woocommerce_customer_columns'));
        add_action('woocommerce_customers_list_table_column_content', array($this, 'show_woocommerce_analytics_column_content'), 10, 2);
    }

    /**
     * AJAX handler to get Flow customer data
     */
    public function ajax_get_flow_customer_data() {
        // Log all received data for debugging
        error_log('Flow AJAX: Request method: ' . $_SERVER['REQUEST_METHOD']);
        error_log('Flow AJAX: $_POST data: ' . print_r($_POST, true));
        error_log('Flow AJAX: $_REQUEST data: ' . print_r($_REQUEST, true));

        // Check if this is an AJAX request
        if (!wp_doing_ajax()) {
            error_log('Flow AJAX: Not doing AJAX check failed');
            wp_die('Invalid request');
        }

        // Verify nonce - check both $_POST and $_REQUEST
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '');

        if (!$nonce) {
            error_log('Flow AJAX: No nonce provided in request');
            wp_send_json_error('No security token provided');
            return;
        }

        if (!wp_verify_nonce($nonce, 'flow_customer_data')) {
            error_log('Flow AJAX: Nonce verification failed. Provided: ' . $nonce);
            wp_send_json_error('Security check failed');
            return;
        }

        // Get customer ID - check both $_POST and $_REQUEST
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : (isset($_REQUEST['customer_id']) ? intval($_REQUEST['customer_id']) : 0);
        if (!$customer_id) {
            error_log('Flow AJAX: Invalid customer ID provided. POST: ' . print_r($_POST, true));
            wp_send_json_error('Invalid customer ID');
            return;
        }

        error_log('Flow AJAX: Processing request for customer ID: ' . $customer_id);

        try {
            // Get Flow subscription data
            $flow_subscription_id = get_user_meta($customer_id, '_flow_subscription_id', true);
            $flow_customer_id = get_user_meta($customer_id, '_flow_customer_id', true);

            // Get subscription status
            $subscription_status = $this->get_customer_subscription_status($customer_id);

            // Prepare response
            $response = array(
                'customer_id' => $customer_id,
                'flow_subscription_id' => $flow_subscription_id ?: null,
                'flow_customer_id' => $flow_customer_id ?: null,
                'status' => $subscription_status['status'],
                'status_display' => $this->get_flow_status_display_text($subscription_status['status']),
                'last_payment' => $subscription_status['last_payment'] ?? null,
                'status_html' => $this->get_flow_status_display($customer_id)
            );

            error_log('Flow AJAX: Sending successful response: ' . json_encode($response));
            wp_send_json_success($response);

        } catch (Exception $e) {
            error_log('Flow AJAX: Exception occurred: ' . $e->getMessage());
            wp_send_json_error('Internal server error: ' . $e->getMessage());
        }
    }

    /**
     * Test AJAX handler for debugging
     */
    public function test_ajax_handler() {
        error_log('Flow AJAX: TEST handler called successfully!');
        error_log('Flow AJAX: POST data: ' . print_r($_POST, true));
        error_log('Flow AJAX: REQUEST data: ' . print_r($_REQUEST, true));

        wp_send_json_success(array(
            'message' => 'Test AJAX handler working!',
            'post_data' => $_POST,
            'request_data' => $_REQUEST,
            'time' => current_time('Y-m-d H:i:s')
        ));
    }

    /**
     * Debug version of AJAX handler - no security checks
     */
    public function debug_ajax_get_flow_customer_data() {
        error_log('Flow DEBUG AJAX: Handler called');
        error_log('Flow DEBUG AJAX: POST data: ' . print_r($_POST, true));
        error_log('Flow DEBUG AJAX: REQUEST data: ' . print_r($_REQUEST, true));

        // Get customer ID without strict validation
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) :
                      (isset($_REQUEST['customer_id']) ? intval($_REQUEST['customer_id']) : 1); // Default to 1 for testing

        error_log('Flow DEBUG AJAX: Processing customer ID: ' . $customer_id);

        try {
            // Get Flow subscription data
            $flow_subscription_id = get_user_meta($customer_id, '_flow_subscription_id', true);
            $flow_customer_id = get_user_meta($customer_id, '_flow_customer_id', true);

            // Get subscription status
            $subscription_status = $this->get_customer_subscription_status($customer_id);

            // Prepare response
            $response = array(
                'customer_id' => $customer_id,
                'flow_subscription_id' => $flow_subscription_id ?: null,
                'flow_customer_id' => $flow_customer_id ?: null,
                'status' => $subscription_status['status'],
                'status_display' => $this->get_flow_status_display_text($subscription_status['status']),
                'last_payment' => $subscription_status['last_payment'] ?? null,
                'status_html' => $this->get_flow_status_display($customer_id),
                'debug_info' => array(
                    'user_exists' => get_user_by('ID', $customer_id) !== false,
                    'user_meta_count' => count(get_user_meta($customer_id)),
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'has_woocommerce' => class_exists('WooCommerce')
                )
            );

            error_log('Flow DEBUG AJAX: Sending response: ' . json_encode($response));
            wp_send_json_success($response);

        } catch (Exception $e) {
            error_log('Flow DEBUG AJAX: Exception: ' . $e->getMessage());
            wp_send_json_error('Debug error: ' . $e->getMessage());
        }
    }
}
