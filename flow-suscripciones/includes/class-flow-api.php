<?php
if (!defined('ABSPATH')) exit;

class Flow_API {
    private $api_key;
    private $secret_key;
    private $base_url;

    public function __construct() {
        $this->api_key = get_option('flow_api_key');
        $this->secret_key = get_option('flow_secret_key');

        // Set base URL based on environment setting
        $environment = get_option('flow_environment', 'sandbox');
        if ($environment === 'production') {
            $this->base_url = 'https://www.flow.cl/api/';
        } else {
            $this->base_url = 'https://sandbox.flow.cl/api/';
        }

        error_log('Flow API: Environment: ' . $environment);
        error_log('Flow API: Initialized with base URL: ' . $this->base_url);
        error_log('Flow API: API Key configured: ' . (!empty($this->api_key) ? 'Yes' : 'No'));
        error_log('Flow API: Secret Key configured: ' . (!empty($this->secret_key) ? 'Yes' : 'No'));
    }

    /**
     * Get current environment information
     */
    public function get_environment_info() {
        $environment = get_option('flow_environment', 'sandbox');
        return array(
            'environment' => $environment,
            'base_url' => $this->base_url,
            'is_production' => $environment === 'production',
            'api_key_configured' => !empty($this->api_key),
            'secret_key_configured' => !empty($this->secret_key)
        );
    }

    /**
     * Make API request to Flow
     */
    private function request($endpoint, $params, $method = 'POST') {
        error_log('Flow API: Making request to endpoint: ' . $endpoint . ' with method: ' . $method);
        error_log('Flow API: Request params (before signature): ' . json_encode($params));

        if (!$this->api_key || !$this->secret_key) {
            error_log('Flow API: Missing API credentials');
            return ['error' => 'Faltan credenciales Flow'];
        }

        $params['apiKey'] = $this->api_key;
        ksort($params);
        $params['s'] = hash_hmac('sha256', urldecode(http_build_query($params)), $this->secret_key);

        error_log('Flow API: Request params (with signature): ' . json_encode($params));

        if ($method === 'GET') {
            $url = $this->base_url . $endpoint . '?' . http_build_query($params);
            error_log('Flow API: Making GET request to: ' . $url);
            $response = wp_remote_get($url, ['timeout' => 45]);
        } else {
            $url = $this->base_url . $endpoint;
            error_log('Flow API: Making POST request to: ' . $url);
            $response = wp_remote_post($url, [
                'body' => $params,
                'timeout' => 45
            ]);
        }

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            error_log('Flow API: Request failed with error: ' . $error_msg);
            return ['error' => $error_msg];
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        error_log('Flow API: Response code: ' . $response_code . ', Response body: ' . $response_body);

        $decoded_response = json_decode($response_body, true);
        error_log('Flow API: Decoded response: ' . json_encode($decoded_response));

        return $decoded_response;
    }

    /**
     * Create or get existing plan
     */
    public function create_plan($name, $amount = 1000, $url_callback = null) {
        error_log('Flow API: Creating/getting plan - Name: ' . $name . ', Amount: ' . $amount . ', URL Callback: ' . $url_callback);

        $plan_id = $this->create_plan_id($name);
        error_log('Flow API: Generated plan ID: ' . $plan_id);

        // Try to get existing plan first
        error_log('Flow API: Checking for existing plan: ' . $plan_id);
        $existing_plan = $this->request('plans/get', ['planId' => $plan_id], 'GET');

        if (empty($existing_plan['code']) && empty($existing_plan['message']) && !empty($existing_plan['planId'])) {
            error_log('Flow API: Found existing plan: ' . $plan_id);
            return $existing_plan;
        }

        error_log('Flow API: Creating new plan: ' . $plan_id);
        // Create new plan
        $plan_params = [
            'planId' => $plan_id,
            'name' => $name,
            'amount' => $amount,
            'currency' => 'CLP',
            'interval' => 3
        ];

        // Add URL callback if provided
        if (!empty($url_callback)) {
            $plan_params['urlCallback'] = $url_callback;
            error_log('Flow API: Added URL callback to plan: ' . $url_callback);
        }

        return $this->request('plans/create', $plan_params);
    }

    /**
     * Register Credit Card for customer
     */
    public function register_credit_card($customer_id, $url_return) {
        error_log('Flow API: Registering credit card - Customer ID: ' . $customer_id . ', Return URL: ' . $url_return);
        return $this->request('customer/register', [
            'customerId' => $customer_id,
            'url_return' => $url_return
        ]);
    }

    /**
     * Get Register Results
     */
    public function get_register_results($token) {
        error_log('Flow API: Getting registration results for token: ' . $token);
        return $this->request('customer/getRegisterStatus', [
            'token' => $token
        ], 'GET');
    }


    /**
     * Create customer in Flow
     */
    public function create_customer($email, $name, $address, $city) {
        error_log('Flow API: Creating/getting customer - Email: ' . $email . ', Name: ' . $name);

        // Try to get existing customer first
        error_log('Flow API: Checking for existing customer: ' . $email);
        $existing_customer = $this->request('customer/get', ['customerId' => $email], 'GET');

        if (empty($existing_customer['code']) && empty($existing_customer['message']) && !empty($existing_customer['customerId'])) {
            error_log('Flow API: Found existing customer: ' . $email);
            return $existing_customer;
        }

        error_log('Flow API: Creating new customer: ' . $email);
        // Create new customer
        return $this->request('customer/create', [
            'name' => $name,
            'email' => $email,
            'externalId' => uniqid('cust_')
        ]);
    }

    /**
     * Create subscription
     */
    public function create_subscription($plan_id, $customer_id) {
        error_log('Flow API: Creating subscription - Plan ID: ' . $plan_id . ', Customer ID: ' . $customer_id);
        return $this->request('subscription/create', [
            'planId' => $plan_id,
            'customerId' => $customer_id
        ]);
    }

    /**
     * Generate plan ID from name
     */
    private function create_plan_id($name) {
        return strtolower(str_replace(' ', '_', $name));
    }

    /**
     * Charge customer mandate
     */
    public function charge_customer($flow_customer_id, $amount, $subject, $commerce_order) {
        return $this->request('customer/charge', [
            'customerId' => $flow_customer_id,
            'amount' => $amount,
            'subject' => $subject,
            'commerceOrder' => $commerce_order
        ]);
    }

    /**
     * Get webhook URL for Flow configuration
     */
    public function get_webhook_url() {
        return rest_url('flow/v1/webhook');
    }

    /**
     * Get return URL for Flow configuration
     */
    public function get_return_url() {
        return rest_url('flow/v1/return');
    }

    /**
     * Get payment callback URL for Flow plan configuration
     */
    public function get_payment_callback_url() {
        return rest_url('flow/v1/payment-callback');
    }

    /**
     * Get payment status from Flow using token
     */
    public function get_payment_status($token) {
        error_log('Flow API: Getting payment status for token: ' . $token);
        return $this->request('payment/getStatus', [
            'token' => $token
        ], 'GET');
    }
}