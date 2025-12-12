<?php
if (!defined('ABSPATH')) exit;

class Flow_Subscription {
    private $database;
    private $api;

    public function __construct() {
        $this->database = new Flow_Database();
        $this->api = new Flow_API();
    }

    /**
     * Create a new subscription
     */
    public function create_subscription($data) {
        // Validate required fields
        $required_fields = ['email', 'name', 'address', 'city', 'plan_id', 'amount'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => "Campo requerido: {$field}"];
            }
        }

        // Sanitize data
        $clean_data = [
            'email' => sanitize_email($data['email']),
            'name' => sanitize_text_field($data['name']),
            'address' => sanitize_textarea_field($data['address']),
            'city' => sanitize_text_field($data['city']),
            'plan_id' => sanitize_text_field($data['plan_id']),
            'amount' => intval($data['amount']),
            'status' => 'pendiente'
        ];
        
        // Validate email
        if (!is_email($clean_data['email'])) {
            return ['success' => false, 'message' => 'Email inválido'];
        }
        
        // Check if subscription already exists
        if ($this->database->get_subscription_by_email($clean_data['email'])) {
            return ['success' => false, 'message' => 'Ya existe una suscripción con este email'];
        }
        
        // Create customer in Flow
        $customer_result = $this->api->create_customer(
            $clean_data['email'], 
            $clean_data['name'], 
            $clean_data['address'], 
            $clean_data['city']
        );
        
        if (!empty($customer_result['code'])) {
            return ['success' => false, 'message' => 'Error al crear cliente en Flow: ' . $customer_result['message']];
        }
        
        $clean_data['flow_customer_id'] = $customer_result['customerId'] ?? $clean_data['email'];
        
        // Insert subscription into database
        $subscription_id = $this->database->insert_subscription($clean_data);
        
        if (!$subscription_id) {
            return ['success' => false, 'message' => 'Error al guardar suscripción en base de datos'];
        }
        
        // Create plan in Flow with callback URL
        $callback_url = rest_url('flow/v1/payment-callback');
        $plan_result = $this->api->create_plan($clean_data['plan_id'], $clean_data['amount'], $callback_url);

        if (!empty($plan_result['code'])) {
            // Delete subscription if plan creation fails
            $this->database->delete_subscription($subscription_id);
            return ['success' => false, 'message' => 'Error al crear plan en Flow: ' . $plan_result['message']];
        }

        // Create WooCommerce customer and product when subscription is created
        error_log("Flow Debug: Starting WooCommerce integration for subscription creation");
        $wc_integration = new Flow_WooCommerce();
        if ($wc_integration->is_woocommerce_available()) {
            error_log("Flow Debug: WooCommerce is available, proceeding with customer/product creation");
            // Create customer
            $customer_id = $wc_integration->get_or_create_wc_customer(
                $clean_data['email'],
                $clean_data['name'],
                $clean_data['city'],
                $clean_data['address']
            );
            if ($customer_id > 0) {
                error_log("WooCommerce customer created/found with ID: {$customer_id} for subscription - Email: {$clean_data['email']}");
            }

            // Create product for this subscription plan
            $product_id = $wc_integration->get_or_create_subscription_product(
                $clean_data['plan_id'],
                $clean_data['plan_id'],
                $clean_data['amount'],
                "Plan de suscripción {$clean_data['plan_id']} - {$clean_data['name']}"
            );
            if ($product_id > 0) {
                error_log("WooCommerce product created/found with ID: {$product_id} for plan: {$clean_data['plan_id']}");
            }
        }
        
        // Register credit card
        $url_return = site_url('/flow-return');
        $register_result = $this->api->register_credit_card($clean_data['flow_customer_id'], $url_return);
        
        if (!empty($register_result['code'])) {
            // Delete subscription if registration fails
            $this->database->delete_subscription($subscription_id);
            return ['success' => false, 'message' => 'Error al registrar tarjeta: ' . $register_result['message']];
        }

        return [
            'success' => true,
            'subscription_id' => $subscription_id,
            'flow_url' => $register_result['url'] ?? null,
            'token' => $register_result['token'] ?? null
        ];
    }

    /**
     * Activate a subscription
     */
    public function activate_subscription($subscription_id, $mandate_id = null) {
        $subscription = $this->database->get_subscription($subscription_id);

        if (!$subscription) {
            return ['success' => false, 'message' => 'Suscripción no encontrada'];
        }

        $update_data = ['status' => 'activo'];
        if ($mandate_id) {
            $update_data['mandato_id'] = $mandate_id;
        }

        $result = $this->database->update_subscription($subscription_id, $update_data);

        if ($result === false) {
            return ['success' => false, 'message' => 'Error al activar suscripción'];
        }

        do_action('flow_subscription_activated', $subscription_id, $subscription);

        return ['success' => true, 'message' => 'Suscripción activada exitosamente'];
    }

    /**
     * Cancel a subscription
     */
    public function cancel_subscription($subscription_id) {
        $subscription = $this->database->get_subscription($subscription_id);

        if (!$subscription) {
            return ['success' => false, 'message' => 'Suscripción no encontrada'];
        }

        $result = $this->database->update_subscription($subscription_id, ['status' => 'cancelado']);

        if ($result === false) {
            return ['success' => false, 'message' => 'Error al cancelar suscripción'];
        }

        do_action('flow_subscription_cancelled', $subscription_id, $subscription);

        return ['success' => true, 'message' => 'Suscripción cancelada exitosamente'];
    }

    /**
     * Process recurring payment
     */
    public function process_recurring_payment($subscription_id) {
        $subscription = $this->database->get_subscription($subscription_id);

        if (!$subscription) {
            return ['success' => false, 'message' => 'Suscripción no encontrada'];
        }

        if ($subscription->status !== 'activo') {
            return ['success' => false, 'message' => 'Suscripción no está activa'];
        }

        if (empty($subscription->mandato_id)) {
            return ['success' => false, 'message' => 'No hay mandato asociado a la suscripción'];
        }

        $description = "Pago recurrente - {$subscription->plan_id} - {$subscription->email}";

        $charge_result = $this->api->charge_mandate(
            $subscription->mandato_id,
            $subscription->amount,
            $description
        );

        if (!empty($charge_result['code'])) {
            return ['success' => false, 'message' => 'Error al procesar pago: ' . $charge_result['message']];
        }

        // Create WooCommerce order for successful payment
        error_log("Flow Debug: Starting WooCommerce order creation for recurring payment");
        $wc_integration = new Flow_WooCommerce();
        if ($wc_integration->is_woocommerce_available()) {
            error_log("Flow Debug: WooCommerce available for recurring payment order creation");
            $order_id = $wc_integration->create_subscription_order(
                $subscription->email,
                $subscription->plan_id,
                $subscription->amount,
                $subscription->name,
                $subscription->city,
                $subscription->address,
                $subscription->product_id ?? null,
                $subscription->variation_id ?? null,
                $subscription->formato ?? null,
                $subscription->molienda ?? null
            );

            if ($order_id) {
                error_log("WooCommerce order #{$order_id} created for recurring payment - Email: {$subscription->email}");
            }
        }

        do_action('flow_recurring_payment_processed', $subscription_id, $subscription, $charge_result);

        return [
            'success' => true,
            'message' => 'Pago procesado exitosamente',
            'charge_id' => $charge_result['flowOrder'] ?? null
        ];
    }
    
    /**
     * Get subscription details
     */
    public function get_subscription($subscription_id) {
        return $this->database->get_subscription($subscription_id);
    }
    
    /**
     * Get subscription by email
     */
    public function get_subscription_by_email($email) {
        return $this->database->get_subscription_by_email($email);
    }

    /**
     * Get subscription by Flow subscription ID
     */
    public function get_subscription_by_flow_subscription_id($flow_subscription_id) {
        return $this->database->get_subscription_by_flow_subscription_id($flow_subscription_id);
    }

    /**
     * Get subscription by WooCommerce order ID
     */
    public function get_subscription_by_wc_order_id($wc_order_id) {
        return $this->database->get_subscription_by_wc_order_id($wc_order_id);
    }
    
    /**
     * Get all subscriptions
     */
    public function get_all_subscriptions($status = null) {
        return $this->database->get_all_subscriptions($status);
    }
    
    /**
     * Get active subscriptions
     */
    public function get_active_subscriptions() {
        return $this->database->get_active_subscriptions();
    }
    
    /**
     * Get subscription statistics
     */
    public function get_subscription_stats() {
        return $this->database->get_subscription_stats();
    }
    
    /**
     * Update subscription
     */
    public function update_subscription($subscription_id, $data) {
        // Sanitize data
        $allowed_fields = ['email', 'name', 'address', 'city', 'plan_id', 'amount', 'status'];
        $clean_data = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                switch ($key) {
                    case 'email':
                        $clean_data[$key] = sanitize_email($value);
                        break;
                    case 'amount':
                        $clean_data[$key] = intval($value);
                        break;
                    case 'address':
                        $clean_data[$key] = sanitize_textarea_field($value);
                        break;
                    default:
                        $clean_data[$key] = sanitize_text_field($value);
                }
            }
        }
        
        if (empty($clean_data)) {
            return ['success' => false, 'message' => 'No hay datos válidos para actualizar'];
        }
        
        $result = $this->database->update_subscription($subscription_id, $clean_data);
        
        if ($result === false) {
            return ['success' => false, 'message' => 'Error al actualizar suscripción'];
        }
        
        do_action('flow_subscription_updated', $subscription_id, $clean_data);
        
        return ['success' => true, 'message' => 'Suscripción actualizada exitosamente'];
    }
    
    /**
     * Delete subscription
     */
    public function delete_subscription($subscription_id) {
        $subscription = $this->database->get_subscription($subscription_id);
        
        if (!$subscription) {
            return ['success' => false, 'message' => 'Suscripción no encontrada'];
        }
        
        $result = $this->database->delete_subscription($subscription_id);
        
        if ($result === false) {
            return ['success' => false, 'message' => 'Error al eliminar suscripción'];
        }
        
        do_action('flow_subscription_deleted', $subscription_id, $subscription);
        
        return ['success' => true, 'message' => 'Suscripción eliminada exitosamente'];
    }
    
    /**
     * Process subscription webhook
     */
    public function process_webhook($webhook_data) {
        if (!isset($webhook_data['type'])) {
            return ['success' => false, 'message' => 'Tipo de webhook no especificado'];
        }
        
        switch ($webhook_data['type']) {
            case 'subscription_created':
                return $this->handle_subscription_created_webhook($webhook_data);

            case 'payment_completed':
                return $this->handle_payment_completed_webhook($webhook_data);

            case 'payment_failed':
                return $this->handle_payment_failed_webhook($webhook_data);

            case 'subscription_cancelled':
                return $this->handle_subscription_cancelled_webhook($webhook_data);

            default:
                return ['success' => false, 'message' => 'Tipo de webhook no reconocido: ' . $webhook_data['type']];
        }
    }
    
    /**
     * Handle subscription created webhook
     */
    private function handle_subscription_created_webhook($data) {
        if (empty($data['subscription_id'])) {
            return ['success' => false, 'message' => 'ID de suscripción no proporcionado'];
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'flow_subscriptions';
        
        $result = $wpdb->update(
            $table,
            ['status' => 'activo'],
            ['mandato_id' => $data['subscription_id']],
            ['%s'],
            ['%s']
        );
        
        do_action('flow_subscription_created_webhook', $data);
        
        return ['success' => true, 'message' => 'Webhook de suscripción creada procesado'];
    }
    
    /**
     * Handle payment completed webhook
     */
    private function handle_payment_completed_webhook($data) {
        // Find subscription by mandate ID or other identifier
        $subscription = null;
        if (isset($data['mandate_id'])) {
            $subscription = $this->database->get_subscription_by_mandate_id($data['mandate_id']);
        } elseif (isset($data['subscription_id'])) {
            $subscription = $this->database->get_subscription($data['subscription_id']);
        } elseif (isset($data['email'])) {
            $subscription = $this->database->get_subscription_by_email($data['email']);
        }

        // Create WooCommerce order if subscription found and WooCommerce is available
        if ($subscription) {
            $wc_integration = new Flow_WooCommerce();
            if ($wc_integration->is_woocommerce_available()) {
                $order_id = $wc_integration->create_subscription_order(
                    $subscription->email,
                    $subscription->plan_id,
                    $subscription->amount,
                    $subscription->name,
                    $subscription->city,
                    $subscription->address,
                    $subscription->product_id ?? null,
                    $subscription->variation_id ?? null,
                    $subscription->formato ?? null,
                    $subscription->molienda ?? null
                );

                if ($order_id) {
                    error_log("WooCommerce order #{$order_id} created for subscription payment - Email: {$subscription->email}");
                }
            }
        }

        do_action('flow_payment_completed_webhook', $data);

        return ['success' => true, 'message' => 'Webhook de pago completado procesado'];
    }

    /**
     * Handle payment failed webhook
     */
    private function handle_payment_failed_webhook($data) {
        if (empty($data['subscription_id'])) {
            return ['success' => false, 'message' => 'ID de suscripción no proporcionado'];
        }

        $subscription_id = sanitize_text_field($data['subscription_id']);
        $subscription = $this->database->get_subscription_by_flow_id($subscription_id);

        if (!$subscription) {
            error_log("Payment failed webhook: Subscription not found for Flow ID: {$subscription_id}");
            return ['success' => false, 'message' => 'Suscripción no encontrada'];
        }

        // Extract error information from webhook
        $error_message = $data['error_message'] ?? 'Error en el procesamiento del pago';
        $error_code = $data['error_code'] ?? 'payment_failed';

        // Get current failed attempts
        $failed_attempts = get_option("flow_failed_attempts_{$subscription->id}", 0);
        $failed_attempts++;
        update_option("flow_failed_attempts_{$subscription->id}", $failed_attempts);

        // Send customer notification with failure page link
        $this->send_webhook_payment_failure_notification($subscription, $error_message, $error_code, $failed_attempts);

        // Mark subscription as failed after 3 attempts
        if ($failed_attempts >= 3) {
            $this->database->update_subscription($subscription->id, [
                'status' => 'suspendido'
            ]);

            // Send final suspension notification
            $this->send_subscription_suspension_notification($subscription);

            // Clear failed attempts counter
            delete_option("flow_failed_attempts_{$subscription->id}");
        }

        do_action('flow_payment_failed_webhook', $data, $subscription);

        return ['success' => true, 'message' => 'Webhook de pago fallido procesado'];
    }

    /**
     * Handle subscription cancelled webhook
     */
    private function handle_subscription_cancelled_webhook($data) {
        if (empty($data['subscription_id'])) {
            return ['success' => false, 'message' => 'ID de suscripción no proporcionado'];
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'flow_subscriptions';
        
        $result = $wpdb->update(
            $table,
            ['status' => 'cancelado'],
            ['mandato_id' => $data['subscription_id']],
            ['%s'],
            ['%s']
        );
        
        do_action('flow_subscription_cancelled_webhook', $data);
        
        return ['success' => true, 'message' => 'Webhook de suscripción cancelada procesado'];
    }

    /**
     * Send webhook payment failure notification to customer
     */
    private function send_webhook_payment_failure_notification($subscription, $error_message, $error_code, $failed_attempts) {
        $customer_email = $subscription->email;
        $subject = 'Error en el Pago de tu Suscripción - Pace Coffee Roasters';

        // Generate failure page URL with details
        $failure_url = add_query_arg([
            'error' => 'Error en el pago de tu suscripción: ' . $error_message,
            'error_code' => $error_code,
            'plan' => $subscription->plan_id,
            'amount' => $subscription->amount,
            'retry_url' => home_url('/mi-cuenta/suscripciones/')
        ], home_url('/suscripcion-fallo/'));

        $message = sprintf(
            "Hola %s,\n\n" .
            "Hemos tenido problemas para procesar el pago de tu suscripción:\n\n" .
            "Plan: %s\n" .
            "Monto: $%s CLP\n" .
            "Error: %s\n" .
            "Intento: %d/3\n\n" .
            "Por favor, actualiza tu información de pago visitando:\n%s\n\n" .
            "Si necesitas ayuda, responde a este correo o contacta con soporte.\n\n" .
            "Gracias,\n" .
            "Equipo de Pace Coffee Roasters",
            $subscription->name,
            $subscription->plan_id,
            number_format($subscription->amount),
            $error_message,
            $failed_attempts,
            $failure_url
        );

        // Send email to customer
        wp_mail($customer_email, $subject, $message);

        // Log notification sent
        error_log("Webhook payment failure notification sent to {$customer_email} for subscription {$subscription->id}");
    }

    /**
     * Send subscription suspension notification
     */
    private function send_subscription_suspension_notification($subscription) {
        $customer_email = $subscription->email;
        $admin_email = get_option('admin_email');
        $subject = 'Suscripción Suspendida - Pace Coffee Roasters';

        // Generate failure page URL for customer
        $failure_url = add_query_arg([
            'error' => 'Tu suscripción ha sido suspendida debido a múltiples fallas en el pago',
            'error_code' => 'subscription_suspended',
            'plan' => $subscription->plan_id,
            'amount' => $subscription->amount,
            'retry_url' => home_url('/contacto/')
        ], home_url('/suscripcion-fallo/'));

        // Customer message
        $customer_message = sprintf(
            "Hola %s,\n\n" .
            "Lamentamos informarte que tu suscripción ha sido suspendida debido a múltiples fallas en el procesamiento del pago.\n\n" .
            "Plan: %s\n" .
            "Monto: $%s CLP\n\n" .
            "Para reactivar tu suscripción, por favor contacta con nuestro equipo de soporte:\n%s\n\n" .
            "Estamos aquí para ayudarte a resolver cualquier problema.\n\n" .
            "Gracias,\n" .
            "Equipo de Pace Coffee Roasters",
            $subscription->name,
            $subscription->plan_id,
            number_format($subscription->amount),
            $failure_url
        );

        // Admin message
        $admin_message = sprintf(
            "Suscripción suspendida:\n\n" .
            "ID: %d\n" .
            "Cliente: %s (%s)\n" .
            "Plan: %s\n" .
            "Monto: $%s CLP\n" .
            "Motivo: Múltiples fallas en el pago\n\n" .
            "Se requiere intervención manual para reactivar.",
            $subscription->id,
            $subscription->name,
            $subscription->email,
            $subscription->plan_id,
            number_format($subscription->amount)
        );

        // Send emails
        wp_mail($customer_email, $subject, $customer_message);
        wp_mail($admin_email, 'Suscripción Suspendida - Flow Suscripciones', $admin_message);

        // Log notifications sent
        error_log("Suspension notifications sent for subscription {$subscription->id}");
    }

    /**
     * Get Flow API instance
     */
    public function get_api() {
        return $this->api;
    }
    
    /**
     * Get Database instance
     */
    public function get_database() {
        return $this->database;
    }
}