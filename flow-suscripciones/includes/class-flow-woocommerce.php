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
        // Load payment gateway class first when plugins are loaded
        add_action('plugins_loaded', [$this, 'load_payment_gateway'], 11);

        // Register Flow payment gateway after it's loaded
        add_filter('woocommerce_payment_gateways', [$this, 'add_flow_gateway']);

        // Additional hook to ensure registration on WooCommerce init
        add_action('woocommerce_init', [$this, 'ensure_gateway_registration']);

        // Sync credentials on admin init
        add_action('admin_init', [$this, 'sync_flow_credentials']);
    }

    /**
     * Ensure gateway is registered when WooCommerce initializes
     */
    public function ensure_gateway_registration() {
        // Make sure our gateway class is loaded
        $this->load_payment_gateway();

        // Force add our gateway if it's not already there
        if (class_exists('Flow_Payment_Gateway') && function_exists('WC')) {
            $payment_gateways = WC()->payment_gateways();
            if ($payment_gateways) {
                $gateways = $payment_gateways->payment_gateways();
                if (!isset($gateways['flow'])) {
                    $gateways['flow'] = new Flow_Payment_Gateway();
                    // Update the gateways array
                    if (property_exists($payment_gateways, 'payment_gateways')) {
                        $payment_gateways->payment_gateways = $gateways;
                    }
                }
            }
        }
    }

    /**
     * Load Flow payment gateway class
     */
    public function load_payment_gateway() {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        // Only load if not already loaded
        if (!class_exists('Flow_Payment_Gateway')) {
            $gateway_file = plugin_dir_path(__FILE__) . 'class-flow-payment-gateway.php';
            if (file_exists($gateway_file)) {
                require_once $gateway_file;
            }
        }
    }

    /**
     * Add Flow payment gateway to WooCommerce
     */
    public function add_flow_gateway($gateways) {
        if (class_exists('Flow_Payment_Gateway')) {
            $gateways[] = 'Flow_Payment_Gateway';

            // Add admin notice on successful registration (only once)
            static $notice_added = false;
            if (!$notice_added && is_admin()) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p><strong>Flow Suscripciones:</strong> Método de pago registrado exitosamente en WooCommerce.</p>';
                    echo '</div>';
                });
                $notice_added = true;
            }
        }
        return $gateways;
    }

    /**
     * Sync Flow API credentials with payment gateway
     */
    public function sync_flow_credentials() {
        $flow_api_key = get_option('flow_api_key');
        $flow_secret_key = get_option('flow_secret_key');

        if ($flow_api_key && $flow_secret_key) {
            // Update payment gateway settings
            $gateway_settings = get_option('woocommerce_flow_settings', []);
            $gateway_settings['api_key'] = $flow_api_key;
            $gateway_settings['secret_key'] = $flow_secret_key;

            // Enable gateway if credentials are available
            if (empty($gateway_settings['enabled'])) {
                $gateway_settings['enabled'] = 'yes';
            }

            update_option('woocommerce_flow_settings', $gateway_settings);
        }
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

        // Check which columns exist in wc_customer_lookup table
        $columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}wc_customer_lookup");
        error_log("WooCommerce customer lookup columns: " . print_r($columns, true));

        // Prepare data with only columns that exist in the table
        $customer_data = [
            'user_id' => 0, // Guest customer
            'email' => $email,
            'date_registered' => current_time('mysql'),
            'date_last_active' => current_time('mysql')
        ];

        // Add optional columns only if they exist
        if (in_array('username', $columns)) {
            $customer_data['username'] = sanitize_user(current(explode('@', $email)));
        }
        if (in_array('city', $columns)) {
            $customer_data['city'] = $city;
        }
        if (in_array('first_name', $columns)) {
            $customer_data['first_name'] = $first_name;
        }
        if (in_array('last_name', $columns)) {
            $customer_data['last_name'] = $last_name;
        }
        if (in_array('flow_customer_id', $columns)) {
            $customer_data['flow_customer_id'] = $flow_customer_id;
        }

        error_log("WooCommerce customer data to insert: " . print_r($customer_data, true));

        // Insert directly into wc_customer_lookup table
        $result = $wpdb->insert($wpdb->prefix . 'wc_customer_lookup', $customer_data);

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

        return $wpdb->update(
            $wpdb->prefix . 'wc_customer_lookup',
            [
                'date_last_active' => current_time('mysql')
            ],
            ['email' => $email]
        );
    }

    /**
     * Create WooCommerce customer and order for subscription
     */
    public function create_subscription_order($email, $plan_name, $amount, $customer_name = '', $customer_city = '', $customer_address = '', $product_id = null, $variation_id = null, $formato = null, $molienda = null) {
        error_log("Flow Debug: Creating subscription order - Email: {$email}, Plan: {$plan_name}, Amount: {$amount}, Product ID: {$product_id}, Variation ID: {$variation_id}");

        if (!class_exists('WC_Order')) {
            error_log('Flow Debug: WC_Order class not found');
            return false;
        }

        // Get or create WooCommerce customer first
        $customer_id = $this->get_or_create_wc_customer($email, $customer_name, $customer_city, $customer_address);
        error_log("Flow Debug: Customer ID obtained: {$customer_id}");

        // Create new order
        $order = wc_create_order();

        // Set customer if created/found
        if ($customer_id > 0) {
            $order->set_customer_id($customer_id);
        }

        // Set billing details from customer or provided data
        $order->set_billing_email($email);

        if ($customer_id > 0) {
            // Use WooCommerce customer data
            $wc_customer = new WC_Customer($customer_id);
            $order->set_billing_first_name($wc_customer->get_first_name());
            $order->set_billing_last_name($wc_customer->get_last_name());
            $order->set_billing_city($wc_customer->get_billing_city());
            $order->set_billing_address_1($wc_customer->get_billing_address_1());
            $order->set_billing_state($wc_customer->get_billing_state());
            $order->set_billing_country('CL');
        } else {
            // Fallback to provided data for guest customers
            $name_parts = explode(' ', $customer_name, 2);
            $order->set_billing_first_name($name_parts[0] ?? '');
            $order->set_billing_last_name($name_parts[1] ?? '');
            $order->set_billing_city($customer_city);
            $order->set_billing_country('CL');
            if (!empty($customer_address)) {
                $order->set_billing_address_1($customer_address);
            }
        }

        // Get or create WooCommerce product for this subscription plan
        $selected_product_id = null;
        $selected_variation_id = null;

        if ($variation_id && wc_get_product($variation_id)) {
            // Use the provided variation_id if it exists and is valid
            $selected_variation_id = $variation_id;
            $variation_product = wc_get_product($variation_id);
            $selected_product_id = $variation_product->get_parent_id();
            error_log("Flow Debug: Using provided variation ID: {$variation_id} with parent product ID: {$selected_product_id}");
        } elseif ($product_id && wc_get_product($product_id)) {
            // Use the provided product_id if it exists and is valid
            $selected_product_id = $product_id;
            error_log("Flow Debug: Using provided product ID: {$product_id}");
        } else {
            // Fallback to creating/finding subscription product
            $selected_product_id = $this->get_or_create_subscription_product($plan_name, $plan_name, $amount);
            error_log("Flow Debug: Created/found subscription product ID: {$selected_product_id}");
        }

        if ($selected_product_id > 0) {
            // Add actual WooCommerce product or variation to order
            $product_to_use = $selected_variation_id ? wc_get_product($selected_variation_id) : wc_get_product($selected_product_id);
            error_log("Flow Debug: Product loaded: " . ($product_to_use ? 'Success' : 'Failed'));

            // Use the variation price if we have a variation, otherwise use the provided amount
            $final_amount = $amount;
            if ($selected_variation_id && $product_to_use && $product_to_use->is_type('variation')) {
                $variation_price = $product_to_use->get_price();
                if ($variation_price) {
                    $final_amount = intval(floatval($variation_price));
                    error_log("Flow Debug: Using variation price: {$variation_price} instead of provided amount: {$amount}");
                }
            }

            $item = new WC_Order_Item_Product();
            $item->set_product($product_to_use);
            $item->set_name($product_to_use->get_name());
            $item->set_product_id($selected_product_id);
            $item->set_variation_id($selected_variation_id ?? 0);
            $item->set_quantity(1);
            $item->set_subtotal($final_amount);
            $item->set_total($final_amount);

            // Add product meta data to order item
            $item->add_meta_data('_flow_plan_id', $plan_name, true);
            $item->add_meta_data('_flow_subscription_payment', 'yes', true);

            // Add variation meta data if available
            if ($selected_variation_id) {
                if ($formato) {
                    $item->add_meta_data('formato', $formato, true);
                }
                if ($molienda) {
                    $item->add_meta_data('molienda', $molienda, true);
                }

                // Also add variation attributes from the product
                $variation_product = wc_get_product($selected_variation_id);
                if ($variation_product && $variation_product->is_type('variation')) {
                    $variation_attributes = $variation_product->get_variation_attributes();
                    foreach ($variation_attributes as $attribute_name => $attribute_value) {
                        // Clean up attribute name for display
                        $clean_name = str_replace(['attribute_', 'pa_'], '', $attribute_name);
                        $item->add_meta_data($clean_name, $attribute_value, true);
                    }
                }
            }

            $order->add_item($item);
            error_log("Flow Debug: Added product to order with variation data");
        } else {
            // Fallback to generic item if product creation fails
            error_log("Flow Debug: Using fallback generic item");
            $item = new WC_Order_Item_Product();
            $item->set_name($plan_name . ' - Subscription');
            $item->set_quantity(1);
            $item->set_subtotal($amount);
            $item->set_total($amount);
            $item->add_meta_data('_flow_plan_id', $plan_name, true);
            $item->add_meta_data('_flow_subscription_payment', 'yes', true);

            // Add variation meta data if available
            if ($formato) {
                $item->add_meta_data('formato', $formato, true);
            }
            if ($molienda) {
                $item->add_meta_data('molienda', $molienda, true);
            }

            $order->add_item($item);
        }

        // Calculate totals
        $order->calculate_totals();
        error_log("Flow Debug: Order totals calculated");

        // Set payment method and title for Flow Suscripcion
        $order->set_payment_method('flow');
        $order->set_payment_method_title('Flow Suscripcion');
        error_log("Flow Debug: Payment method set to Flow Suscripcion");

        // Set order status
        $order->set_status('processing');
        error_log("Flow Debug: Order status set to processing");

        // Add order note
        $order->add_order_note('Orden creada automáticamente por Flow Suscripciones - Pago de suscripción');

        // Save order
        $order_id = $order->save();
        error_log("Flow Debug: Order saved with ID: {$order_id}");

        if (!$order_id) {
            error_log("Flow Debug: ERROR - Order save failed!");
            return false;
        }

        // Update customer stats if using lookup table
        if ($this->is_woocommerce_available()) {
            $lookup_customer = $this->get_customer_by_email($email);
            if ($lookup_customer) {
                $this->update_customer_stats($email, 1, $amount);
                error_log("Flow Debug: Customer stats updated");
            }
        }

        error_log("Flow Debug: Order creation completed successfully - Order ID: {$order_id}");
        return $order_id;
    }

    /**
     * Get or create WooCommerce product for subscription plan
     */
    public function get_or_create_subscription_product($plan_id, $plan_name, $amount, $description = '') {
        error_log("Flow Debug: Creating product for plan: {$plan_id}, name: {$plan_name}, amount: {$amount}");

        if (!class_exists('WC_Product')) {
            error_log('Flow Debug: WC_Product class not found');
            return 0;
        }

        // Check if product already exists by SKU (using plan_id as SKU)
        $sku = 'flow-subscription-' . $plan_id;
        $product_id = wc_get_product_id_by_sku($sku);
        error_log("Flow Debug: Checking existing product with SKU: {$sku}, found ID: {$product_id}");

        if ($product_id > 0) {
            error_log("Flow Debug: Found existing product with ID: {$product_id}");
            return $product_id;
        }

        // Create new subscription product
        error_log("Flow Debug: Creating new product");
        $product = new WC_Product_Simple();

        // Set basic product information
        $product->set_name($plan_name . ' - Subscription');
        $product->set_slug(sanitize_title('flow-subscription-' . $plan_id));
        $product->set_sku('flow-subscription-' . $plan_id);

        // Set product description
        if (empty($description)) {
            $description = "Suscripción del plan {$plan_name} procesada a través de Flow.";
        }
        $product->set_description($description);
        $product->set_short_description("Plan de suscripción: {$plan_name}");

        // Set pricing
        $product->set_regular_price($amount);
        $product->set_price($amount);

        // Set product properties
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden'); // Hide from catalog
        $product->set_virtual(true); // Virtual product (no shipping)
        $product->set_downloadable(false);
        $product->set_sold_individually(true); // One per order

        // Set stock management
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');

        // Set categories - create/find subscription category
        $category_id = $this->get_or_create_subscription_category();
        if ($category_id > 0) {
            $product->set_category_ids([$category_id]);
        }

        // Add meta data for Flow integration
        $product->add_meta_data('_flow_plan_id', $plan_id, true);
        $product->add_meta_data('_flow_subscription_product', 'yes', true);
        $product->add_meta_data('_flow_created_date', current_time('mysql'), true);

        // Save product
        $product_id = $product->save();

        if ($product_id > 0) {
            error_log("Created WooCommerce product ID: {$product_id} for Flow plan: {$plan_id}");
        }

        return $product_id;
    }

    /**
     * Get or create subscription product category
     */
    private function get_or_create_subscription_category() {
        $category_name = 'Suscripciones Flow';
        $category_slug = 'flow-subscriptions';

        // Check if category exists
        $category = get_term_by('slug', $category_slug, 'product_cat');

        if ($category) {
            return $category->term_id;
        }

        // Create new category
        $category_data = wp_insert_term(
            $category_name,
            'product_cat',
            [
                'slug' => $category_slug,
                'description' => 'Productos de suscripción procesados a través de Flow'
            ]
        );

        if (is_wp_error($category_data)) {
            error_log('Failed to create subscription category: ' . $category_data->get_error_message());
            return 0;
        }

        return $category_data['term_id'];
    }

    /**
     * Update product information
     */
    public function update_subscription_product($product_id, $plan_name, $amount, $description = '') {
        if (!$product_id || !class_exists('WC_Product')) {
            return false;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        // Update product information
        $product->set_name($plan_name . ' - Suscripción');
        $product->set_regular_price($amount);
        $product->set_price($amount);

        if (!empty($description)) {
            $product->set_description($description);
        }

        $product->add_meta_data('_flow_updated_date', current_time('mysql'), true);

        $result = $product->save();

        if ($result > 0) {
            error_log("Updated WooCommerce product ID: {$product_id} for plan: {$plan_name}");
        }

        return $result > 0;
    }

    /**
     * Get subscription products
     */
    public function get_subscription_products() {
        $products = wc_get_products([
            'meta_key' => '_flow_subscription_product',
            'meta_value' => 'yes',
            'limit' => -1,
            'status' => 'publish'
        ]);

        return $products;
    }

    /**
     * Get product by Flow plan ID
     */
    public function get_product_by_plan_id($plan_id) {
        $products = wc_get_products([
            'meta_key' => '_flow_plan_id',
            'meta_value' => $plan_id,
            'limit' => 1,
            'status' => 'publish'
        ]);

        return !empty($products) ? $products[0] : null;
    }

    /**
     * Sync all subscription plans as WooCommerce products
     */
    public function sync_subscription_products() {
        $subscription_db = new Flow_Database();
        $subscriptions = $subscription_db->get_all_subscriptions();

        $synced_products = [];
        $existing_plans = [];

        foreach ($subscriptions as $subscription) {
            if (in_array($subscription->plan_id, $existing_plans)) {
                continue; // Skip if already processed this plan
            }

            $product_id = $this->get_or_create_subscription_product(
                $subscription->plan_id,
                $subscription->plan_id,
                $subscription->amount,
                "Plan de suscripción {$subscription->plan_id}"
            );

            if ($product_id > 0) {
                $synced_products[] = [
                    'plan_id' => $subscription->plan_id,
                    'product_id' => $product_id,
                    'amount' => $subscription->amount
                ];
            }

            $existing_plans[] = $subscription->plan_id;
        }

        return $synced_products;
    }

    /**
     * Get subscription product statistics
     */
    public function get_subscription_product_stats() {
        $products = $this->get_subscription_products();

        $stats = [
            'total_products' => count($products),
            'total_orders' => 0,
            'total_revenue' => 0,
            'products' => []
        ];

        foreach ($products as $product) {
            $plan_id = $product->get_meta('_flow_plan_id');

            // Get orders for this product
            $orders = wc_get_orders([
                'meta_query' => [
                    [
                        'key' => '_flow_plan_id',
                        'value' => $plan_id,
                        'compare' => '='
                    ]
                ],
                'limit' => -1,
                'status' => ['processing', 'completed']
            ]);

            $product_revenue = 0;
            foreach ($orders as $order) {
                $product_revenue += $order->get_total();
            }

            $stats['products'][] = [
                'product_id' => $product->get_id(),
                'plan_id' => $plan_id,
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'orders_count' => count($orders),
                'revenue' => $product_revenue
            ];

            $stats['total_orders'] += count($orders);
            $stats['total_revenue'] += $product_revenue;
        }

        return $stats;
    }

    /**
     * Clean up orphaned subscription products
     */
    public function cleanup_orphaned_products() {
        $products = $this->get_subscription_products();
        $subscription_db = new Flow_Database();
        $active_plan_ids = [];

        // Get all active plan IDs from subscriptions
        $subscriptions = $subscription_db->get_all_subscriptions();
        foreach ($subscriptions as $subscription) {
            $active_plan_ids[] = $subscription->plan_id;
        }

        $deleted_products = [];

        foreach ($products as $product) {
            $plan_id = $product->get_meta('_flow_plan_id');

            // If product's plan ID is not in active subscriptions
            if (!in_array($plan_id, $active_plan_ids)) {
                // Check if product has any orders
                $orders = wc_get_orders([
                    'meta_query' => [
                        [
                            'key' => '_flow_plan_id',
                            'value' => $plan_id,
                            'compare' => '='
                        ]
                    ],
                    'limit' => 1
                ]);

                // If no orders exist, safely delete the product
                if (empty($orders)) {
                    $product->delete(true); // Force delete
                    $deleted_products[] = [
                        'product_id' => $product->get_id(),
                        'plan_id' => $plan_id,
                        'name' => $product->get_name()
                    ];
                }
            }
        }

        return $deleted_products;
    }

    /**
     * Get or create WooCommerce customer
     */
    public function get_or_create_wc_customer($email, $name = '', $city = '', $address = '') {
        error_log("Flow Debug: Creating customer for email: {$email}, name: {$name}, city: {$city}, address: {$address}");

        if (!class_exists('WC_Customer')) {
            error_log('Flow Debug: WC_Customer class not found');
            return 0;
        }

        // Check if user already exists by email
        $user = get_user_by('email', $email);

        if ($user) {
            return $user->ID;
        }

        // Parse name
        $name_parts = explode(' ', trim($name), 2);
        $first_name = $name_parts[0] ?? '';
        $last_name = $name_parts[1] ?? '';

        // Generate username from email
        $username = sanitize_user(current(explode('@', $email)));

        // Make sure username is unique
        $original_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
        }

        // Create new user/customer
        $user_data = [
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => wp_generate_password(),
            'first_name' => $first_name,
            'last_name' => $last_name,
            'role' => 'customer'
        ];

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            error_log('Failed to create WC customer: ' . $user_id->get_error_message());
            return 0;
        }

        // Set customer billing details
        if ($user_id > 0 && class_exists('WC_Customer')) {
            $customer = new WC_Customer($user_id);
            $customer->set_billing_first_name($first_name);
            $customer->set_billing_last_name($last_name);
            $customer->set_billing_email($email);
            $customer->set_billing_country('CL');
            if ($city) {
                $customer->set_billing_city($city);
            }
            if ($address) {
                $customer->set_billing_address_1($address);
            }
            $customer->save();

            // Also create entry in lookup table for compatibility
            $this->create_customer_lookup($name, $email, $city, $email);

            error_log("Created new WooCommerce customer with ID: {$user_id} for email: {$email}");
        }

        return $user_id;
    }

    /**
     * Check if WooCommerce is active and table exists
     */
    public function is_woocommerce_available() {
        error_log('Flow Debug: Checking WooCommerce availability');

        if (!class_exists('WooCommerce')) {
            error_log('Flow Debug: WooCommerce class not found');
            return false;
        }

        if (!class_exists('WC_Order')) {
            error_log('Flow Debug: WC_Order class not found');
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_customer_lookup';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        error_log("Flow Debug: WooCommerce available - Table exists: " . ($table_exists ? 'Yes' : 'No'));

        return $table_exists;
    }

    /**
     * Test method to manually create customer and order
     */
    public function test_create_customer_and_order($email = 'test@example.com', $name = 'Test User', $plan = 'test-plan', $amount = 1000) {
        error_log("Flow Debug: TEST - Starting manual test creation");

        if (!$this->is_woocommerce_available()) {
            error_log("Flow Debug: TEST - WooCommerce not available");
            return false;
        }

        // Test customer creation
        $customer_id = $this->get_or_create_wc_customer($email, $name, 'Santiago', 'Test Address 123');
        error_log("Flow Debug: TEST - Customer creation result: {$customer_id}");

        // Test order creation
        $order_id = $this->create_subscription_order($email, $plan, $amount, $name, 'Santiago', 'Test Address 123');
        error_log("Flow Debug: TEST - Order creation result: {$order_id}");

        return [
            'customer_id' => $customer_id,
            'order_id' => $order_id,
            'success' => ($customer_id > 0 && $order_id > 0)
        ];
    }
}