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

        // Enhanced WordPress Users page support
        add_action('admin_head-users.php', array($this, 'inject_users_page_styles'));
        add_action('admin_footer-users.php', array($this, 'inject_users_page_javascript'));

        // Proper script enqueuing for WooCommerce customers
        add_action('admin_enqueue_scripts', array($this, 'enqueue_flow_customer_scripts'));

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

        // Enhanced WooCommerce Customers page support (traditional interface)
        add_filter('manage_woocommerce_page_wc-customers_columns', array($this, 'add_woocommerce_customer_columns'));
        add_action('manage_woocommerce_page_wc-customers_custom_column', array($this, 'show_woocommerce_admin_column_content'), 10, 2);

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
        // add_action('admin_enqueue_scripts', array($this, 'enqueue_customer_admin_scripts')); // DISABLED: Causes conflicts

        // Try different WooCommerce hooks that might work
        add_action('woocommerce_loaded', array($this, 'init_woocommerce_hooks'));
        add_action('woocommerce_admin_loaded', array($this, 'init_woocommerce_admin_hooks'));

        // Add support for modern WooCommerce customer management
        add_action('admin_init', array($this, 'init_modern_wc_customers'));
        add_action('current_screen', array($this, 'detect_wc_customer_screen'));

        // Aggressive React hooks - try all possible variations
        add_action('init', array($this, 'add_aggressive_react_hooks'));
        add_action('wp_loaded', array($this, 'add_aggressive_react_hooks'));
        add_action('plugins_loaded', array($this, 'add_aggressive_react_hooks'));

        // Force JavaScript injection on all admin pages
        // add_action('admin_footer', array($this, 'inject_flow_javascript')); // DISABLED: Causes conflicts with external scripts

        // Add AJAX endpoints for DOM script
        add_action('wp_ajax_flow_get_customer_data', array($this, 'ajax_get_customer_data'));
        add_action('wp_ajax_nopriv_flow_get_customer_data', array($this, 'ajax_get_customer_data'));

        // Hook into REST API responses for customer data
        add_filter('woocommerce_rest_customer_object_query', array($this, 'modify_customer_rest_query'), 10, 2);
        add_filter('woocommerce_rest_prepare_customer', array($this, 'add_flow_data_to_customer_rest'), 10, 3);

        // Add JavaScript for WooCommerce Admin React interface
        // add_action('admin_enqueue_scripts', array($this, 'enqueue_wc_admin_scripts')); // DISABLED: Causes conflicts

        // Add to WordPress users list when viewing customers
        add_action('load-users.php', array($this, 'init_users_page_hooks'));

        // WooCommerce Admin API hooks for React interface
        add_filter( 'woocommerce_admin_report_columns', array($this, 'add_flow_report_columns'), 10, 3 );
        add_filter( 'woocommerce_admin_report_column_data', array($this, 'add_flow_report_column_data'), 10, 3 );

        // Hook into WooCommerce Admin REST API
        add_action( 'rest_api_init', array($this, 'register_flow_customer_api_fields') );

        // Hook into WooCommerce Admin customer reports
        add_filter( 'woocommerce_admin_customers_report_columns', array($this, 'add_customers_report_columns') );
        add_filter( 'woocommerce_admin_customers_report_column_data', array($this, 'add_customers_report_column_data'), 10, 3 );

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

        // Commented out - these DELETE queries were breaking WooCommerce admin
        // $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_admin%'" );
        // $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_admin%'" );

        // Add AJAX handlers for Flow customer data
        add_action('wp_ajax_get_flow_customer_data', array($this, 'ajax_get_flow_customer_data'));
        add_action('wp_ajax_nopriv_get_flow_customer_data', array($this, 'ajax_get_flow_customer_data'));

        // Add test AJAX handler for debugging
        add_action('wp_ajax_test_flow_ajax', array($this, 'test_ajax_handler'));
        add_action('wp_ajax_nopriv_test_flow_ajax', array($this, 'test_ajax_handler'));

        // Add debug version of Flow customer data handler (less strict)
        add_action('wp_ajax_debug_flow_customer_data', array($this, 'debug_ajax_get_flow_customer_data'));
        add_action('wp_ajax_nopriv_debug_flow_customer_data', array($this, 'debug_ajax_get_flow_customer_data'));

        // Auto-sync customer data when user meta is updated
        add_action('updated_user_meta', array($this, 'auto_sync_customer_data'), 10, 4);
        add_action('added_user_meta', array($this, 'auto_sync_customer_data'), 10, 4);
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
        // Always show Flow columns on WordPress Users page in admin
        if (is_admin()) {
            error_log('Flow Customer Columns: Adding columns to WordPress Users page');

            $columns['flow_subscription_status'] = 'Flow Status';
            $columns['flow_subscription_id'] = 'Flow Subscription ID';
            $columns['flow_subscription_url'] = 'Flow Dashboard';

            error_log('Flow Customer Columns: Added columns to users table');
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
                    $value = '<span class="flow-subscription-status-active">✓ Activa</span>';
                    if ($subscription_status['last_payment']) {
                        $value .= '<br><small>Último pago: ' . esc_html($subscription_status['last_payment']) . '</small>';
                    }
                    break;
                case 'inactive':
                    $value = '<span class="flow-subscription-status-inactive">✗ Inactiva</span>';
                    break;
                case 'pending':
                    $value = '<span class="flow-subscription-status-pending">⏳ Pendiente</span>';
                    break;
                default:
                    $value = '<span style="color: #999;">Sin suscripción</span>';
            }
        } elseif ($column_name === 'flow_subscription_id') {
            $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
            $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);

            if ($flow_subscription_id) {
                $value = '<span class="flow-subscription-id">' . esc_html($flow_subscription_id) . '</span>';
                if ($flow_customer_id) {
                    $value .= '<br><small>Cust: ' . esc_html($flow_customer_id) . '</small>';
                }
            } else {
                $value = '<span style="color: #999;">-</span>';
            }
        } elseif ($column_name === 'flow_subscription_url') {
            $subscription_url = $this->get_flow_subscription_url($user_id);

            if ($subscription_url) {
                $value = '<a href="' . esc_url($subscription_url) . '" target="_blank" class="flow-dashboard-link">
                    📊 Dashboard
                </a>';
            } else {
                $value = '<span style="color: #999;">-</span>';
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
     * Auto-sync Flow customer data when user meta is updated
     */
    public function auto_sync_customer_data($meta_id, $user_id, $meta_key, $meta_value) {
        // Only sync for Flow-related meta keys
        if (!in_array($meta_key, ['_flow_subscription_id', '_flow_customer_id', '_flow_subscription_status'])) {
            return;
        }

        error_log('Flow: Auto-syncing customer data for user ' . $user_id . ', meta_key: ' . $meta_key);

        // Load Flow WooCommerce integration
        if (class_exists('Flow_WooCommerce')) {
            $flow_wc = new Flow_WooCommerce();

            if ($flow_wc->is_woocommerce_available()) {
                // Get user data
                $user = get_user_by('ID', $user_id);
                if ($user) {
                    $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);
                    $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
                    $flow_status = get_user_meta($user_id, '_flow_subscription_status', true) ?: 'inactive';

                    // Update WooCommerce customer lookup table
                    $result = $flow_wc->update_customer_flow_data_by_email(
                        $user->user_email,
                        $flow_customer_id,
                        $flow_subscription_id,
                        $flow_status
                    );

                    if ($result) {
                        error_log('Flow: Successfully synced customer data for user ' . $user_id);
                    } else {
                        error_log('Flow: Failed to sync customer data for user ' . $user_id);
                    }
                }
            }
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
        if (!is_admin() || !function_exists('WC')) {
            return;
        }

        error_log('Flow Customer Columns: Checking script loading on ' . $hook_suffix);

        // Always load on all admin pages to ensure React interface gets it
        // Enqueue the DOM manipulation script (primary approach)
        wp_enqueue_script(
            'flow-wc-admin-dom',
            FLOW_SUSCRIPCIONES_PLUGIN_URL . 'assets/js/flow-dom-columns.js',
            array(),
            FLOW_SUSCRIPCIONES_VERSION . '-dom-v1',
            true
        );

        // Keep the simple script as backup
        wp_enqueue_script(
            'flow-wc-admin-customers-simple',
            FLOW_SUSCRIPCIONES_PLUGIN_URL . 'assets/js/flow-simple-columns.js',
            array('wp-hooks'),
            FLOW_SUSCRIPCIONES_VERSION . '-simple-v1',
            true
        );

        // Localize script with API data for DOM script
        wp_localize_script('flow-wc-admin-dom', 'flowCustomerData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('flow_customer_data'),
            'restUrl' => rest_url('wc/v3/customers'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'isProduction' => defined('FLOW_ENVIRONMENT') && FLOW_ENVIRONMENT === 'production',
            'debug' => WP_DEBUG,
            'hookSuffix' => $hook_suffix,
            'currentUrl' => $_SERVER['REQUEST_URI'] ?? ''
        ));

        // Also localize for simple script
        wp_localize_script('flow-wc-admin-customers-simple', 'flowCustomerData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('flow_customer_data'),
            'isProduction' => defined('FLOW_ENVIRONMENT') && FLOW_ENVIRONMENT === 'production',
        ));

        // Add inline script that will DEFINITELY load
        $inline_script = "
        console.log('🚀 Flow: Inline script loaded on " . $hook_suffix . "');
        console.log('🌍 Current URL:', window.location.href);

        // Test if we can add filters
        function flowTestFilters() {
            if (typeof wp !== 'undefined' && wp.hooks && wp.hooks.addFilter) {
                console.log('✅ Flow: wp.hooks available, adding test filters');

                wp.hooks.addFilter(
                    'woocommerce_admin_customers_report_columns',
                    'flow-inline/test-customers',
                    function(columns) {
                        console.log('🎯 Flow: Customers report columns filter triggered', columns);
                        columns.push({
                            key: 'flow_subscription_status',
                            label: 'Flow Status',
                            isLeftAligned: true,
                            required: false,
                            isSortable: false,
                        });
                        columns.push({
                            key: 'flow_subscription_id',
                            label: 'Flow Subscription ID',
                            isLeftAligned: true,
                            required: false,
                            isSortable: false,
                        });
                        columns.push({
                            key: 'flow_subscription_url',
                            label: 'Flow Dashboard',
                            isLeftAligned: true,
                            required: false,
                            isSortable: false,
                        });
                        console.log('✅ Flow: Added Flow columns', columns);
                        return columns;
                    }
                );

                // Try different filter names
                ['woocommerce_admin_report_columns', 'wc_admin_customers_report_columns', 'woocommerce_analytics_customers_report_columns'].forEach(function(filterName) {
                    wp.hooks.addFilter(filterName, 'flow-inline/' + filterName, function(columns, context) {
                        console.log('🎯 Flow: Filter triggered:', filterName, 'Context:', context, 'Columns:', columns);
                        if (context === 'customers' || filterName.includes('customers')) {
                            if (Array.isArray(columns)) {
                                columns.push({key: 'flow_subscription_status', label: 'Flow Status'});
                                columns.push({key: 'flow_subscription_id', label: 'Flow Subscription ID'});
                                columns.push({key: 'flow_subscription_url', label: 'Flow Dashboard'});
                            } else if (typeof columns === 'object') {
                                columns['flow_subscription_status'] = 'Flow Status';
                                columns['flow_subscription_id'] = 'Flow Subscription ID';
                                columns['flow_subscription_url'] = 'Flow Dashboard';
                            }
                        }
                        return columns;
                    });
                });

                console.log('✅ Flow: All filters added successfully');
            } else {
                console.log('❌ Flow: wp.hooks not available, will retry...');
                setTimeout(flowTestFilters, 1000);
            }
        }

        // Try immediately and with delays
        flowTestFilters();
        setTimeout(flowTestFilters, 2000);
        setTimeout(flowTestFilters, 5000);
        ";

        wp_add_inline_script('flow-wc-admin-customers', $inline_script);

        error_log('Flow Customer Columns: Enqueued scripts and inline script for WooCommerce Admin React interface');
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
     * Initialize hooks specifically for WooCommerce Admin interface
     */
    public function init_wc_admin_hooks() {
        error_log('Flow Customer Columns: Initializing WC Admin hooks');

        // Multiple hooks for different WooCommerce Admin versions
        add_filter('woocommerce_admin_customers_list_table_columns', array($this, 'add_woocommerce_customer_columns'));
        add_action('woocommerce_admin_customers_list_table_column_content', array($this, 'show_woocommerce_analytics_column_content'), 10, 2);

        add_filter('woocommerce_admin_customers_list_columns', array($this, 'add_woocommerce_customer_columns'));
        add_action('woocommerce_admin_customers_list_column_content', array($this, 'show_woocommerce_analytics_column_content'), 10, 2);

        // Try direct table class hooks if available
        if (class_exists('Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore')) {
            add_filter('woocommerce_analytics_customers_data', array($this, 'modify_analytics_customers_data'));
        }

        // Enqueue scripts for the React interface
        // add_action('admin_enqueue_scripts', array($this, 'enqueue_wc_admin_scripts')); // DISABLED: Causes conflicts
    }

    /**
     * Modify analytics customers data to include Flow information
     */
    public function modify_analytics_customers_data($customers) {
        if (!is_array($customers)) return $customers;

        error_log('Flow Customer Columns: Modifying analytics customers data for ' . count($customers) . ' customers');

        foreach ($customers as &$customer) {
            $customer_id = $customer['id'] ?? ($customer['customer_id'] ?? 0);
            if (!$customer_id) continue;

            // Get Flow data from customer lookup table
            global $wpdb;
            $flow_data = $wpdb->get_row($wpdb->prepare(
                "SELECT flow_subscription_id, flow_customer_id, flow_subscription_status
                 FROM {$wpdb->prefix}wc_customer_lookup
                 WHERE customer_id = %d LIMIT 1",
                $customer_id
            ), ARRAY_A);

            if ($flow_data) {
                $customer['flow_subscription_id'] = $flow_data['flow_subscription_id'];
                $customer['flow_customer_id'] = $flow_data['flow_customer_id'];
                $customer['flow_subscription_status'] = $flow_data['flow_subscription_status'];
                $customer['flow_subscription_url'] = $this->generate_flow_dashboard_url($flow_data['flow_subscription_id']);
            }
        }

        return $customers;
    }

    /**
     * Generate Flow dashboard URL
     */
    private function generate_flow_dashboard_url($subscription_id) {
        if (!$subscription_id) return null;

        $is_production = defined('FLOW_ENVIRONMENT') && FLOW_ENVIRONMENT === 'production';
        $base_url = $is_production ? 'https://dashboard.flow.cl' : 'https://dashboard.sandbox.flow.cl';

        return $base_url . '/private/suscripciones/subscriptions/details/?sus_id=' . urlencode($subscription_id);
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
     * Initialize modern WooCommerce customer support
     */
    public function init_modern_wc_customers() {
        // Hook into various WooCommerce customer page types
        if (is_admin() && function_exists('WC')) {
            // For WooCommerce Admin Analytics (React-based)
            // add_action('admin_enqueue_scripts', array($this, 'enqueue_wc_admin_scripts')); // DISABLED: Causes conflicts

            // For traditional WordPress list tables
            add_action('load-users.php', array($this, 'init_users_page_hooks'));

            // Hook into any admin page that might show customers
            add_action('current_screen', array($this, 'add_dynamic_customer_hooks'));
        }
    }

    /**
     * Detect WooCommerce customer screen and add appropriate hooks
     */
    public function detect_wc_customer_screen() {
        $screen = get_current_screen();
        if (!$screen) return;

        error_log('Flow Customer Columns: Current screen - ID: ' . $screen->id . ', Base: ' . $screen->base . ', Parent: ' . ($screen->parent_base ?? 'none'));

        // Handle different customer screen types
        if (strpos($screen->id, 'woocommerce') !== false || strpos($screen->base, 'woocommerce') !== false) {

            // Modern WooCommerce Admin (wc-admin)
            if (strpos($screen->id, 'wc-admin') !== false ||
                (isset($_GET['page']) && $_GET['page'] === 'wc-admin') ||
                (isset($_GET['path']) && strpos($_GET['path'], 'customers') !== false)) {

                error_log('Flow Customer Columns: Detected WooCommerce Admin customers page');
                $this->init_wc_admin_hooks();
            }

            // Traditional WooCommerce customers page
            if (strpos($screen->id, 'customers') !== false ||
                (isset($_GET['page']) && strpos($_GET['page'], 'customers') !== false)) {

                error_log('Flow Customer Columns: Detected traditional WooCommerce customers page');
                add_filter('manage_' . $screen->id . '_columns', array($this, 'add_woocommerce_customer_columns'));
                add_action('manage_' . $screen->id . '_custom_column', array($this, 'show_woocommerce_admin_column_content'), 10, 2);
            }
        }

        // WordPress Users page when accessed for customers
        if ($screen->id === 'users') {
            if (isset($_GET['role']) && $_GET['role'] === 'customer') {
                error_log('Flow Customer Columns: Detected WordPress users page with customer filter');
                add_filter('manage_users_columns', array($this, 'force_add_customer_columns'), 999);
            }
        }
    }

    /**
     * Add dynamic hooks based on current context
     */
    public function add_dynamic_customer_hooks() {
        $screen = get_current_screen();
        if (!$screen) return;

        // Check URL parameters for customer-related pages
        $is_customer_context = false;

        if (isset($_GET['page'])) {
            $page = $_GET['page'];
            if (strpos($page, 'customer') !== false ||
                strpos($page, 'wc-admin') !== false ||
                $page === 'wc-customers') {
                $is_customer_context = true;
            }
        }

        if (isset($_GET['path']) && strpos($_GET['path'], 'customers') !== false) {
            $is_customer_context = true;
        }

        if ($is_customer_context) {
            error_log('Flow Customer Columns: Customer context detected, adding hooks');

            // Add all possible hooks
            add_filter('woocommerce_admin_customers_list_table_columns', array($this, 'add_woocommerce_customer_columns'));
            add_action('woocommerce_admin_customers_list_table_column_content', array($this, 'show_woocommerce_analytics_column_content'), 10, 2);

            add_filter('woocommerce_customers_list_table_columns', array($this, 'add_woocommerce_customer_columns'));
            add_action('woocommerce_customers_list_table_column_content', array($this, 'show_woocommerce_analytics_column_content'), 10, 2);

            // Screen-specific hooks
            add_filter('manage_' . $screen->id . '_columns', array($this, 'add_woocommerce_customer_columns'));
            add_action('manage_' . $screen->id . '_custom_column', array($this, 'show_woocommerce_admin_column_content'), 10, 2);
        }
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

    /**
     * Add Flow columns to WooCommerce Admin report columns (React interface)
     */
    public function add_flow_report_columns($columns, $context, $table_name) {
        if ($context === 'customers') {
            error_log('Flow Customer Columns: Adding report columns for customers context');
            $columns['flow_subscription_status'] = $table_name . '.flow_subscription_status as flow_subscription_status';
            $columns['flow_subscription_id'] = $table_name . '.flow_subscription_id as flow_subscription_id';
        }
        return $columns;
    }

    /**
     * Add Flow column data to WooCommerce Admin reports (React interface)
     */
    public function add_flow_report_column_data($value, $column, $item) {
        if ($column === 'flow_subscription_status') {
            return isset($item['flow_subscription_status']) ? $item['flow_subscription_status'] : '';
        }

        if ($column === 'flow_subscription_id') {
            return isset($item['flow_subscription_id']) ? $item['flow_subscription_id'] : '';
        }

        return $value;
    }

    /**
     * Add Flow columns to customers report (React interface)
     */
    public function add_customers_report_columns($columns) {
        error_log('Flow Customer Columns: Adding customers report columns');
        $columns['flow_subscription_status'] = 'Flow Status';
        $columns['flow_subscription_id'] = 'Flow Subscription ID';
        $columns['flow_subscription_url'] = 'Flow Dashboard';
        return $columns;
    }

    /**
     * Add Flow column data to customers report (React interface)
     */
    public function add_customers_report_column_data($value, $column, $customer) {
        if ($column === 'flow_subscription_status') {
            $status = $customer['flow_subscription_status'] ?? '';
            return $this->format_status_for_react($status);
        }

        if ($column === 'flow_subscription_id') {
            return $customer['flow_subscription_id'] ?? '';
        }

        if ($column === 'flow_subscription_url') {
            $subscription_id = $customer['flow_subscription_id'] ?? '';
            return $subscription_id ? $this->generate_flow_dashboard_url($subscription_id) : '';
        }

        return $value;
    }

    /**
     * Format status for React interface
     */
    private function format_status_for_react($status) {
        switch (strtolower($status)) {
            case 'active':
            case 'activa':
                return 'Activa';
            case 'inactive':
            case 'inactiva':
                return 'Inactiva';
            case 'pending':
            case 'pendiente':
                return 'Pendiente';
            default:
                return 'Sin suscripción';
        }
    }

    /**
     * Register Flow fields in WooCommerce REST API for React interface
     */
    public function register_flow_customer_api_fields() {
        error_log('Flow Customer Columns: Registering REST API fields');

        // Register fields for customers endpoint
        register_rest_field('customer', 'flow_subscription_id', array(
            'get_callback' => array($this, 'get_customer_flow_subscription_id_api'),
            'schema' => array(
                'description' => 'Flow subscription ID',
                'type' => 'string',
            ),
        ));

        register_rest_field('customer', 'flow_subscription_status', array(
            'get_callback' => array($this, 'get_customer_flow_status_api'),
            'schema' => array(
                'description' => 'Flow subscription status',
                'type' => 'string',
            ),
        ));

        register_rest_field('customer', 'flow_subscription_url', array(
            'get_callback' => array($this, 'get_customer_flow_url_api'),
            'schema' => array(
                'description' => 'Flow dashboard URL',
                'type' => 'string',
            ),
        ));
    }

    /**
     * Get Flow subscription ID for REST API
     */
    public function get_customer_flow_subscription_id_api($object) {
        $customer_id = $object['id'];

        global $wpdb;
        $flow_subscription_id = $wpdb->get_var($wpdb->prepare(
            "SELECT flow_subscription_id FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d",
            $customer_id
        ));

        return $flow_subscription_id ?: '';
    }

    /**
     * Get Flow subscription status for REST API
     */
    public function get_customer_flow_status_api($object) {
        $customer_id = $object['id'];

        global $wpdb;
        $flow_status = $wpdb->get_var($wpdb->prepare(
            "SELECT flow_subscription_status FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d",
            $customer_id
        ));

        return $this->format_status_for_react($flow_status ?: '');
    }

    /**
     * Get Flow dashboard URL for REST API
     */
    public function get_customer_flow_url_api($object) {
        $customer_id = $object['id'];

        global $wpdb;
        $flow_subscription_id = $wpdb->get_var($wpdb->prepare(
            "SELECT flow_subscription_id FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d",
            $customer_id
        ));

        return $flow_subscription_id ? $this->generate_flow_dashboard_url($flow_subscription_id) : '';
    }

    /**
     * Aggressive React hooks to catch all possible WooCommerce variations
     */
    public function add_aggressive_react_hooks() {
        if (!is_admin() || !function_exists('WC')) return;

        error_log('Flow Customer Columns: Adding aggressive React hooks');

        // All possible filter combinations
        $column_filters = [
            'woocommerce_admin_report_columns',
            'woocommerce_admin_customers_report_columns',
            'woocommerce_analytics_report_columns',
            'wc_admin_report_columns',
            'wc_admin_customers_report_columns'
        ];

        $data_filters = [
            'woocommerce_admin_report_column_data',
            'woocommerce_admin_customers_report_column_data',
            'woocommerce_analytics_report_column_data',
            'wc_admin_report_column_data',
            'wc_admin_customers_report_column_data'
        ];

        foreach ($column_filters as $filter) {
            if (!has_filter($filter)) {
                add_filter($filter, array($this, 'add_flow_report_columns'), 10, 3);
                error_log("Flow Customer Columns: Added filter $filter");
            }
        }

        foreach ($data_filters as $filter) {
            if (!has_filter($filter)) {
                add_filter($filter, array($this, 'add_flow_report_column_data'), 10, 3);
                error_log("Flow Customer Columns: Added filter $filter");
            }
        }

        // Analytics data filters
        $analytics_filters = [
            'woocommerce_analytics_customers_data',
            'wc_admin_customers_data',
            'woocommerce_admin_customers_data'
        ];

        foreach ($analytics_filters as $filter) {
            if (!has_filter($filter)) {
                add_filter($filter, array($this, 'modify_analytics_customers_data'), 10, 1);
                error_log("Flow Customer Columns: Added analytics filter $filter");
            }
        }

        // Table-specific hooks
        $table_hooks = [
            'woocommerce_admin_customers_list_table_columns',
            'woocommerce_customers_list_table_columns',
            'wc_admin_customers_list_table_columns'
        ];

        foreach ($table_hooks as $hook) {
            if (!has_filter($hook)) {
                add_filter($hook, array($this, 'add_woocommerce_customer_columns'), 10, 1);
                error_log("Flow Customer Columns: Added table hook $hook");
            }
        }

        // Action hooks for content
        $content_actions = [
            'woocommerce_admin_customers_list_table_column_content',
            'woocommerce_customers_list_table_column_content',
            'wc_admin_customers_list_table_column_content'
        ];

        foreach ($content_actions as $action) {
            if (!has_action($action)) {
                add_action($action, array($this, 'show_woocommerce_analytics_column_content'), 10, 2);
                error_log("Flow Customer Columns: Added action $action");
            }
        }
    }

    /**
     * Force inject JavaScript on all admin pages (fallback method)
     */
    public function inject_flow_javascript() {
        if (!is_admin()) {
            return;
        }

        // Always inject on all admin pages to ensure it works
        echo "<!-- Flow: Admin footer injection starting -->\n";
        error_log('Flow: Admin footer injection running on ' . ($_SERVER['REQUEST_URI'] ?? 'unknown URL'));

        ?>
        <script>
        console.log('🔧 Flow: Admin Footer Script Starting');
        console.log('🌍 Current URL:', window.location.href);

        // Set up flowCustomerData if not already available
        window.flowCustomerData = window.flowCustomerData || {
            ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('flow_customer_data'); ?>',
            isProduction: <?php echo defined('FLOW_ENVIRONMENT') && FLOW_ENVIRONMENT === 'production' ? 'true' : 'false'; ?>
        };

        // Complete DOM manipulation script inline
        (function() {
            console.log('🎯 Flow DOM: Starting direct table manipulation (inline)');

            let isProcessing = false;
            let processedRows = new Set();
            let customerDataCache = {};

            // Add Flow columns to table header
            function addFlowColumnsToHeader() {
                try {
                    const tables = document.querySelectorAll('table');
                    console.log('🔍 Flow DOM: Found ' + tables.length + ' tables');

                    for (let table of tables) {
                        const headerRow = table.querySelector('thead tr, tr:first-child');
                        if (!headerRow) continue;

                        const headerText = headerRow.textContent.toLowerCase();
                        console.log('📋 Flow DOM: Header text:', headerText.substring(0, 100));

                        // More aggressive table detection
                        if (!headerText.includes('name') && !headerText.includes('email') &&
                            !headerText.includes('customer') && !headerText.includes('usuario') &&
                            !headerText.includes('orders') && !headerText.includes('date') &&
                            !headerText.includes('total')) {
                            continue;
                        }

                        // Check if Flow columns already exist
                        if (headerRow.querySelector('[data-flow-column]')) {
                            console.log('ℹ️ Flow DOM: Columns already added to this table');
                            continue;
                        }

                        console.log('✅ Flow DOM: Adding columns to table header');

                        // Add Flow columns to header
                        const flowStatusHeader = document.createElement('th');
                        flowStatusHeader.textContent = 'Flow Status';
                        flowStatusHeader.setAttribute('data-flow-column', 'status');
                        flowStatusHeader.style.padding = '8px';
                        flowStatusHeader.style.textAlign = 'left';
                        flowStatusHeader.style.backgroundColor = '#f1f1f1';

                        const flowIdHeader = document.createElement('th');
                        flowIdHeader.textContent = 'Flow ID';
                        flowIdHeader.setAttribute('data-flow-column', 'id');
                        flowIdHeader.style.padding = '8px';
                        flowIdHeader.style.textAlign = 'left';
                        flowIdHeader.style.backgroundColor = '#f1f1f1';

                        const flowUrlHeader = document.createElement('th');
                        flowUrlHeader.textContent = 'Flow Dashboard';
                        flowUrlHeader.setAttribute('data-flow-column', 'url');
                        flowUrlHeader.style.padding = '8px';
                        flowUrlHeader.style.textAlign = 'left';
                        flowUrlHeader.style.backgroundColor = '#f1f1f1';

                        headerRow.appendChild(flowStatusHeader);
                        headerRow.appendChild(flowIdHeader);
                        headerRow.appendChild(flowUrlHeader);

                        console.log('✅ Flow DOM: Added columns to header successfully');

                        // Process the data rows
                        processTableRows(table);
                        return true;
                    }
                } catch (error) {
                    console.error('❌ Flow DOM: Error adding columns to header:', error);
                }
                return false;
            }

            // Process data rows in the table
            function processTableRows(table) {
                if (isProcessing) return;
                isProcessing = true;

                try {
                    const rows = table.querySelectorAll('tbody tr, tr:not(:first-child)');
                    console.log('📊 Flow DOM: Processing ' + rows.length + ' table rows');

                    rows.forEach((row, index) => {
                        if (row.querySelector('[data-flow-column]')) {
                            return; // Already processed
                        }

                        console.log('📝 Flow DOM: Processing row ' + index);

                        // Add Flow columns to this row
                        const statusCell = document.createElement('td');
                        statusCell.setAttribute('data-flow-column', 'status');
                        statusCell.style.padding = '8px';
                        statusCell.innerHTML = '<span style="color: #999;">Loading...</span>';

                        const idCell = document.createElement('td');
                        idCell.setAttribute('data-flow-column', 'id');
                        idCell.style.padding = '8px';
                        idCell.innerHTML = '<span style="color: #999;">Loading...</span>';

                        const urlCell = document.createElement('td');
                        urlCell.setAttribute('data-flow-column', 'url');
                        urlCell.style.padding = '8px';
                        urlCell.innerHTML = '<span style="color: #999;">Loading...</span>';

                        row.appendChild(statusCell);
                        row.appendChild(idCell);
                        row.appendChild(urlCell);

                        // Extract customer info and fetch data
                        const customerInfo = extractCustomerInfo(row);
                        if (customerInfo && (customerInfo.id || customerInfo.email)) {
                            fetchFlowDataForCustomer(customerInfo, statusCell, idCell, urlCell);
                        } else {
                            updateCellsWithNoData(statusCell, idCell, urlCell);
                        }
                    });
                } catch (error) {
                    console.error('❌ Flow DOM: Error processing rows:', error);
                } finally {
                    isProcessing = false;
                }
            }

            // Extract customer information from a table row
            function extractCustomerInfo(row) {
                try {
                    const cells = row.querySelectorAll('td');
                    let customerId = null;
                    let customerEmail = null;

                    for (let cell of cells) {
                        const cellText = cell.textContent.trim();

                        // Look for email
                        if (cellText.includes('@') && !customerEmail) {
                            customerEmail = cellText;
                        }

                        // Look for customer ID in links
                        const links = cell.querySelectorAll('a');
                        for (let link of links) {
                            const href = link.getAttribute('href');
                            if (href) {
                                const userIdMatch = href.match(/user_id=(\d+)/);
                                const idMatch = href.match(/[?&]id=(\d+)/);
                                if (userIdMatch) customerId = userIdMatch[1];
                                if (idMatch && !customerId) customerId = idMatch[1];
                            }
                        }
                    }

                    return {
                        id: customerId,
                        email: customerEmail
                    };
                } catch (error) {
                    console.error('❌ Flow DOM: Error extracting customer info:', error);
                    return null;
                }
            }

            // Fetch Flow data via AJAX
            function fetchFlowDataForCustomer(customerInfo, statusCell, idCell, urlCell) {
                const cacheKey = customerInfo.id || customerInfo.email;

                // Check cache first
                if (customerDataCache[cacheKey]) {
                    updateCells(customerDataCache[cacheKey], statusCell, idCell, urlCell);
                    return;
                }

                console.log('📡 Flow DOM: Fetching data for:', cacheKey);

                // Make AJAX request
                const formData = new FormData();
                formData.append('action', 'flow_get_customer_data');
                formData.append('customer_id', customerInfo.id || '');
                formData.append('customer_email', customerInfo.email || '');
                formData.append('nonce', window.flowCustomerData?.nonce || '');

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    console.log('📡 Flow DOM: Received data:', data);

                    if (data.success && data.data) {
                        customerDataCache[cacheKey] = data.data;
                        updateCells(data.data, statusCell, idCell, urlCell);
                    } else {
                        updateCellsWithNoData(statusCell, idCell, urlCell);
                    }
                })
                .catch(error => {
                    console.error('❌ Flow DOM: AJAX error:', error);
                    updateCellsWithError(statusCell, idCell, urlCell);
                });
            }

            // Update cells with Flow data
            function updateCells(data, statusCell, idCell, urlCell) {
                try {
                    // Status cell
                    const status = data.status || 'no_subscription';
                    let statusColor = '#999';
                    let statusText = 'Sin suscripción';

                    switch (status.toLowerCase()) {
                        case 'active':
                        case 'activa':
                            statusColor = '#28a745';
                            statusText = 'Activa';
                            break;
                        case 'inactive':
                        case 'inactiva':
                            statusColor = '#dc3545';
                            statusText = 'Inactiva';
                            break;
                        case 'pending':
                        case 'pendiente':
                            statusColor = '#ffc107';
                            statusText = 'Pendiente';
                            break;
                    }

                    statusCell.innerHTML = '<span style="color: ' + statusColor + '; font-weight: bold;">' + statusText + '</span>';

                    // ID cell
                    if (data.flow_subscription_id) {
                        idCell.innerHTML = '<code style="background: #f1f1f1; padding: 2px 4px; font-size: 11px;">' + data.flow_subscription_id + '</code>';
                    } else {
                        idCell.innerHTML = '<span style="color: #999;">-</span>';
                    }

                    // URL cell
                    if (data.flow_subscription_id) {
                        const baseUrl = 'https://dashboard.sandbox.flow.cl';
                        const dashboardUrl = baseUrl + '/private/suscripciones/subscriptions/details/?sus_id=' +
                                           encodeURIComponent(data.flow_subscription_id);
                        urlCell.innerHTML = '<a href="' + dashboardUrl + '" target="_blank" style="color: #0073aa;">📊 Dashboard</a>';
                    } else {
                        urlCell.innerHTML = '<span style="color: #999;">-</span>';
                    }

                } catch (error) {
                    console.error('❌ Flow DOM: Error updating cells:', error);
                    updateCellsWithError(statusCell, idCell, urlCell);
                }
            }

            // Update cells when no data is available
            function updateCellsWithNoData(statusCell, idCell, urlCell) {
                statusCell.innerHTML = '<span style="color: #999;">Sin suscripción</span>';
                idCell.innerHTML = '<span style="color: #999;">-</span>';
                urlCell.innerHTML = '<span style="color: #999;">-</span>';
            }

            // Update cells when there's an error
            function updateCellsWithError(statusCell, idCell, urlCell) {
                statusCell.innerHTML = '<span style="color: #dc3545; font-size: 12px;">Error</span>';
                idCell.innerHTML = '<span style="color: #dc3545; font-size: 12px;">Error</span>';
                urlCell.innerHTML = '<span style="color: #dc3545; font-size: 12px;">Error</span>';
            }

            // Main scanning function
            function scanAndModifyTables() {
                console.log('🔍 Flow DOM: Scanning for customer tables...');
                if (addFlowColumnsToHeader()) {
                    console.log('✅ Flow DOM: Successfully modified table');
                } else {
                    console.log('ℹ️ Flow DOM: No suitable table found');
                }
            }

            // Initialize
            console.log('🚀 Flow DOM: Initializing inline script...');

            // Try multiple times
            setTimeout(scanAndModifyTables, 1000);
            setTimeout(scanAndModifyTables, 3000);
            setTimeout(scanAndModifyTables, 5000);
            setTimeout(scanAndModifyTables, 10000);

            // Set up observer
            if (typeof MutationObserver !== 'undefined') {
                const observer = new MutationObserver(function() {
                    setTimeout(scanAndModifyTables, 1000);
                });

                if (document.body) {
                    observer.observe(document.body, { childList: true, subtree: true });
                    console.log('✅ Flow DOM: Observer started');
                }
            }

            console.log('✅ Flow DOM: Inline script setup complete');
        })();

        // Aggressive approach - try to add filters every second
        let flowAttempts = 0;
        const maxAttempts = 30;

        function forceAddFlowFilters() {
            flowAttempts++;
            console.log(`🔄 Flow: Attempt ${flowAttempts}/${maxAttempts} to add filters`);

            if (typeof window.wp !== 'undefined' && window.wp.hooks && window.wp.hooks.addFilter) {
                console.log('✅ Flow: wp.hooks available, adding filters aggressively');

                // List of all possible filter names
                const filterNames = [
                    'woocommerce_admin_customers_report_columns',
                    'woocommerce_admin_report_columns',
                    'wc_admin_customers_report_columns',
                    'woocommerce_analytics_customers_report_columns',
                    'woocommerce_analytics_report_columns',
                    'wc_admin_report_columns'
                ];

                filterNames.forEach(function(filterName, index) {
                    try {
                        window.wp.hooks.addFilter(
                            filterName,
                            'flow-force/' + filterName,
                            function(columns, context, tableName) {
                                console.log(`🎯 Flow: Filter ${filterName} triggered!`, {columns, context, tableName});

                                // Handle different column structures
                                if (Array.isArray(columns)) {
                                    // React-style columns array
                                    const flowColumns = [
                                        {
                                            key: 'flow_subscription_status',
                                            label: 'Flow Status',
                                            isLeftAligned: true,
                                            required: false,
                                            isSortable: false
                                        },
                                        {
                                            key: 'flow_subscription_id',
                                            label: 'Flow Subscription ID',
                                            isLeftAligned: true,
                                            required: false,
                                            isSortable: false
                                        },
                                        {
                                            key: 'flow_subscription_url',
                                            label: 'Flow Dashboard',
                                            isLeftAligned: true,
                                            required: false,
                                            isSortable: false
                                        }
                                    ];

                                    // Add Flow columns if not already present
                                    const hasFlowColumns = columns.some(col => col.key && col.key.startsWith('flow_'));
                                    if (!hasFlowColumns) {
                                        columns.push(...flowColumns);
                                        console.log('✅ Flow: Added React-style columns to array', columns);
                                    }
                                } else if (typeof columns === 'object' && columns !== null) {
                                    // Object-style columns
                                    if (context === 'customers' || filterName.includes('customers') || !context) {
                                        columns['flow_subscription_status'] = tableName ? tableName + '.flow_subscription_status as flow_subscription_status' : 'Flow Status';
                                        columns['flow_subscription_id'] = tableName ? tableName + '.flow_subscription_id as flow_subscription_id' : 'Flow Subscription ID';
                                        console.log('✅ Flow: Added object-style columns', columns);
                                    }
                                }

                                return columns;
                            },
                            10
                        );

                        console.log(`✅ Flow: Added filter ${filterName}`);
                    } catch (error) {
                        console.error(`❌ Flow: Failed to add filter ${filterName}:`, error);
                    }
                });

                // Also try to add data filters
                const dataFilters = [
                    'woocommerce_admin_customers_report_column_data',
                    'woocommerce_admin_report_column_data',
                    'wc_admin_customers_report_column_data'
                ];

                dataFilters.forEach(function(filterName) {
                    try {
                        window.wp.hooks.addFilter(
                            filterName,
                            'flow-force-data/' + filterName,
                            function(value, column, item) {
                                console.log(`🎯 Flow: Data filter ${filterName} triggered!`, {value, column, item});

                                if (column === 'flow_subscription_status') {
                                    return item.flow_subscription_status || 'Sin suscripción';
                                }
                                if (column === 'flow_subscription_id') {
                                    return item.flow_subscription_id || '';
                                }
                                if (column === 'flow_subscription_url') {
                                    const subscriptionId = item.flow_subscription_id;
                                    if (subscriptionId) {
                                        const isProduction = window.flowCustomerData?.isProduction || false;
                                        const baseUrl = isProduction ? 'https://dashboard.flow.cl' : 'https://dashboard.sandbox.flow.cl';
                                        return baseUrl + '/private/suscripciones/subscriptions/details/?sus_id=' + encodeURIComponent(subscriptionId);
                                    }
                                    return '';
                                }

                                return value;
                            },
                            10
                        );
                    } catch (error) {
                        console.error(`❌ Flow: Failed to add data filter ${filterName}:`, error);
                    }
                });

                console.log('🎉 Flow: All filters added successfully!');
                return true; // Stop trying

            } else {
                console.log(`❌ Flow: wp.hooks not available yet (attempt ${flowAttempts})`);

                if (flowAttempts < maxAttempts) {
                    setTimeout(forceAddFlowFilters, 1000);
                } else {
                    console.log('🛑 Flow: Max attempts reached, stopping');
                }
                return false;
            }
        }

        // Start trying immediately and keep trying
        forceAddFlowFilters();

        // Also try when URL changes (for React Router) - with error handling
        try {
            let lastFlowUrl = location.href;
            const urlObserver = new MutationObserver(() => {
                try {
                    const url = location.href;
                    if (url !== lastFlowUrl) {
                        lastFlowUrl = url;
                        if (url.includes('customers')) {
                            console.log('🔄 Flow: URL changed to customers page, re-adding filters');
                            setTimeout(forceAddFlowFilters, 1000);
                        }
                    }
                } catch (error) {
                    console.error('❌ Flow: Error in URL observer:', error);
                }
            });

            if (document && document.body) {
                urlObserver.observe(document.body, {subtree: true, childList: true});
                console.log('✅ Flow: URL observer started');
            } else {
                console.log('⚠️ Flow: Document not ready for observer, will try later');
                setTimeout(() => {
                    if (document && document.body) {
                        urlObserver.observe(document.body, {subtree: true, childList: true});
                        console.log('✅ Flow: URL observer started (delayed)');
                    }
                }, 2000);
            }
        } catch (error) {
            console.error('❌ Flow: Error setting up URL observer:', error);
        }

        console.log('✅ Flow: Direct injection setup complete');
        </script>
        <?php
    }

    /**
     * Inject styles for WordPress Users page
     */
    public function inject_users_page_styles() {
        echo '<style>
            .flow-subscription-status-active { color: #46b450; font-weight: bold; }
            .flow-subscription-status-inactive { color: #dc3232; font-weight: bold; }
            .flow-subscription-status-pending { color: #ffb900; font-weight: bold; }
            .flow-subscription-id { font-family: monospace; font-size: 11px; background: #f1f1f1; padding: 2px 4px; border-radius: 3px; }
            .flow-dashboard-link { text-decoration: none; color: #0073aa; }
            .flow-dashboard-link:hover { color: #005177; }
        </style>';
    }

    /**
     * Inject JavaScript for WordPress Users page
     */
    public function inject_users_page_javascript() {
        ?>
        <script>
        console.log('✅ Flow: WordPress Users page JavaScript loaded');

        // Add Flow columns functionality if needed
        jQuery(document).ready(function($) {
            console.log('🎯 Flow: WordPress Users page ready');

            // Find Flow columns and enhance them
            $('th').each(function() {
                if ($(this).text().includes('Flow')) {
                    $(this).css('min-width', '120px');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Enqueue Flow customer scripts using admin_enqueue_scripts
     */
    public function enqueue_flow_customer_scripts($hook) {
        // Load on all admin pages to ensure it works everywhere
        if (!is_admin()) {
            return;
        }

        error_log('Flow: Loading script on hook: ' . $hook . ' with URL: ' . $_SERVER['REQUEST_URI']);

        // Enqueue the main Flow customer columns script
        $script_url = plugin_dir_url(__FILE__) . '../assets/js/flow-customer-columns.js';
        $script_version = filemtime(plugin_dir_path(__FILE__) . '../assets/js/flow-customer-columns.js');

        error_log('Flow: Script URL: ' . $script_url);
        error_log('Flow: Script version: ' . $script_version);

        wp_enqueue_script(
            'flow-customer-columns',
            $script_url,
            array('jquery'),
            $script_version,
            true // Load in footer
        );

        // Localize script with necessary data
        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('flow_customer_data'),
            'isProduction' => $this->is_production_environment(),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        );

        wp_localize_script('flow-customer-columns', 'flowCustomerData', $script_data);

        // Enqueue styles for Flow columns
        $this->enqueue_flow_column_styles();

        error_log('Flow Customer Columns: Enqueued script for page: ' . $hook . ' with data: ' . json_encode($script_data));
        error_log('Flow: Script enqueue completed successfully');
    }

    /**
     * Enqueue Flow column styles
     */
    public function enqueue_flow_column_styles() {
        // Add inline CSS for Flow columns
        $css = '
            .flow-subscription-status-active { color: #28a745; font-weight: bold; }
            .flow-subscription-status-inactive { color: #dc3545; font-weight: bold; }
            .flow-subscription-status-pending { color: #ffc107; font-weight: bold; }

            [data-flow-column="status"] { min-width: 120px; text-align: center; }
            [data-flow-column="id"] { min-width: 140px; text-align: center; font-family: monospace; }
            [data-flow-column="url"] { min-width: 100px; text-align: center; }

            .flow-subscription-id {
                font-family: monospace;
                font-size: 11px;
                background: #f8f9fa;
                padding: 2px 6px;
                border-radius: 3px;
                border: 1px solid #e9ecef;
            }

            .flow-dashboard-link {
                color: #007cba;
                text-decoration: none;
                font-weight: 500;
            }

            .flow-dashboard-link:hover {
                color: #005a87;
                text-decoration: underline;
            }

            /* Responsive adjustments */
            @media (max-width: 768px) {
                [data-flow-column] { min-width: 80px; font-size: 12px; }
            }
        ';

        // Add inline CSS directly to the page
        add_action('admin_head', function() use ($css) {
            echo '<style type="text/css">' . $css . '</style>';
        });
    }

    /**
     * Check if this is production environment
     */
    private function is_production_environment() {
        // Check various indicators of production environment
        $production_indicators = array(
            !defined('WP_DEBUG') || !WP_DEBUG,
            strpos(home_url(), '.com') !== false,
            strpos(home_url(), 'localhost') === false,
            strpos(home_url(), '.local') === false,
            !defined('WP_ENVIRONMENT_TYPE') || WP_ENVIRONMENT_TYPE === 'production'
        );

        return count(array_filter($production_indicators)) >= 3;
    }

    /**
     * AJAX endpoint to get customer Flow data for DOM script
     */
    public function ajax_get_customer_data() {
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'flow_customer_data')) {
                wp_send_json_error('Invalid nonce');
                return;
            }

            $customer_id = sanitize_text_field($_POST['customer_id'] ?? '');
            $customer_email = sanitize_email($_POST['customer_email'] ?? '');

            error_log("Flow AJAX: Getting data for customer ID: $customer_id, Email: $customer_email");

            if (!$customer_id && !$customer_email) {
                wp_send_json_error('No customer identifier provided');
                return;
            }

            global $wpdb;

            $flow_data = null;

            // Try to get from customer lookup table first
            if ($customer_id) {
                $flow_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT flow_customer_id, flow_subscription_id, flow_subscription_status
                     FROM {$wpdb->prefix}wc_customer_lookup
                     WHERE customer_id = %d",
                    $customer_id
                ), ARRAY_A);
            }

            // If not found and we have email, try to find customer by email
            if (!$flow_data && $customer_email) {
                $user = get_user_by('email', $customer_email);
                if ($user) {
                    $flow_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT flow_customer_id, flow_subscription_id, flow_subscription_status
                         FROM {$wpdb->prefix}wc_customer_lookup
                         WHERE customer_id = %d",
                        $user->ID
                    ), ARRAY_A);
                }
            }

            // Fallback to user meta if not found in lookup table
            if (!$flow_data) {
                $user_id = $customer_id;
                if (!$user_id && $customer_email) {
                    $user = get_user_by('email', $customer_email);
                    $user_id = $user ? $user->ID : null;
                }

                if ($user_id) {
                    $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
                    $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);

                    if ($flow_subscription_id || $flow_customer_id) {
                        // Determine status based on recent orders
                        $status = 'inactive';
                        if ($flow_subscription_id) {
                            $recent_orders = wc_get_orders([
                                'customer_id' => $user_id,
                                'limit' => 1,
                                'orderby' => 'date',
                                'order' => 'DESC',
                                'status' => ['completed', 'processing']
                            ]);

                            if (!empty($recent_orders)) {
                                $latest_order = $recent_orders[0];
                                $days_since_order = (time() - $latest_order->get_date_created()->getTimestamp()) / (24 * 60 * 60);
                                if ($days_since_order <= 35) {
                                    $status = 'active';
                                }
                            }
                        }

                        $flow_data = [
                            'flow_customer_id' => $flow_customer_id,
                            'flow_subscription_id' => $flow_subscription_id,
                            'flow_subscription_status' => $status
                        ];
                    }
                }
            }

            if ($flow_data && ($flow_data['flow_subscription_id'] || $flow_data['flow_customer_id'])) {
                $response = [
                    'status' => $flow_data['flow_subscription_status'] ?: 'inactive',
                    'flow_subscription_id' => $flow_data['flow_subscription_id'],
                    'flow_customer_id' => $flow_data['flow_customer_id']
                ];

                error_log("Flow AJAX: Sending response: " . json_encode($response));
                wp_send_json_success($response);
            } else {
                error_log("Flow AJAX: No Flow data found for customer");
                wp_send_json_success([
                    'status' => 'no_subscription',
                    'flow_subscription_id' => null,
                    'flow_customer_id' => null
                ]);
            }

        } catch (Exception $e) {
            error_log('Flow AJAX Error: ' . $e->getMessage());
            wp_send_json_error('Internal error: ' . $e->getMessage());
        }
    }
}
