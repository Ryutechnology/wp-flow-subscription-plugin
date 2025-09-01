<?php
if (!defined('ABSPATH')) exit;

class Flow_API {
    
    private $api_key;
    private $secret_key;
    private $base_url = 'https://sandbox.flow.cl/api/';

    public function __construct() {
        $this->api_key = get_option('flow_api_key');
        $this->secret_key = get_option('flow_secret_key');
    }

    /**
     * Make API request to Flow
     */
    private function request($endpoint, $params, $method = 'POST') {
        if (!$this->api_key || !$this->secret_key) {
            return ['error' => 'Faltan credenciales Flow'];
        }

        $params['apiKey'] = $this->api_key;
        ksort($params);
        $params['s'] = hash_hmac('sha256', urldecode(http_build_query($params)), $this->secret_key);

        if ($method === 'GET') {
            $url = $this->base_url . $endpoint . '?' . http_build_query($params);
            $response = wp_remote_get($url, ['timeout' => 45]);
        } else {
            $response = wp_remote_post($this->base_url . $endpoint, [
                'body' => $params,
                'timeout' => 45
            ]);
        }

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Create or get existing plan
     */
    public function create_plan($name, $amount) {
        $plan_id = $this->create_plan_id($name);
        
        // Try to get existing plan first
        $existing_plan = $this->request('plans/get', ['planId' => $plan_id], 'GET');
        
        if (empty($existing_plan['code']) && empty($existing_plan['message']) && !empty($existing_plan['planId'])) {
            return $existing_plan;
        }

        // Create new plan
        return $this->request('plans/create', [
            'planId' => $plan_id,
            'name' => $name,
            'amount' => $amount,
            'currency' => 'CLP',
            'interval' => 3
        ]);
    }

    /**
     * Register Credit Card for customer
     */
    public function register_credit_card($customer_id, $url_return) {
        return $this->request('customer/register', [
            'customerId' => $customer_id,
            'url_return' => $url_return
        ]);
    }

    /**
     * Get Register Results
     */
    public function get_register_results($token) {
        return $this->request('customer/getRegisterStatus', [
            'token' => $token
        ], 'GET');
    }

    /**
     * Create mandate for subscription
     */
    public function create_mandate($plan_id, $email, $name) {
        $url_return = rest_url('flow/v1/return');
        $url_confirmation = rest_url('flow/v1/webhook');
        
        return $this->request('subscription/createMandate', [
            'planId' => $plan_id,
            'email' => $email,
            'urlReturn' => $url_return,
            'urlConfirmation' => $url_confirmation
        ]);
    }

    /**
     * Create customer in Flow
     */
    public function create_customer($email, $name, $address, $city) {
        // Try to get existing customer first
        $existing_customer = $this->request('customer/get', ['customerId' => $email], 'GET');
        
        if (empty($existing_customer['code']) && empty($existing_customer['message']) && !empty($existing_customer['customerId'])) {
            return $existing_customer;
        }

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
     * Charge mandate (for cron jobs)
     */
    public function charge_mandate($mandate_id, $amount, $description) {
        return $this->request('mandate/charge', [
            'mandateId' => $mandate_id,
            'amount' => $amount,
            'description' => $description
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
}