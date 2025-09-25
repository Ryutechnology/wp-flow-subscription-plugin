<?php
if (!defined('ABSPATH')) exit;

class Flow_Cron {

    private $flow_api;
    private $flow_db;
    private $wc_integration;

    public function __construct() {
        $this->init_cron();
    }

    /**
     * Get Flow API instance (lazy loading)
     */
    private function get_flow_api() {
        if (!$this->flow_api) {
            $this->flow_api = new Flow_API();
        }
        return $this->flow_api;
    }

    /**
     * Get Flow Database instance (lazy loading)
     */
    private function get_flow_db() {
        if (!$this->flow_db) {
            $this->flow_db = new Flow_Database();
        }
        return $this->flow_db;
    }

    /**
     * Get WooCommerce integration instance (lazy loading)
     */
    private function get_wc_integration() {
        if (!$this->wc_integration) {
            $this->wc_integration = new Flow_WooCommerce();
        }
        return $this->wc_integration;
    }

    /**
     * Initialize cron jobs
     */
    public function init_cron() {
        // Schedule daily charges if not already scheduled
        if (!wp_next_scheduled('flow_daily_charges')) {
            wp_schedule_event(time(), 'daily', 'flow_daily_charges');
        }

        // Hook the cron action
        add_action('flow_daily_charges', [$this, 'process_daily_charges']);

        // Hook deactivation to clear cron
        register_deactivation_hook(__FILE__, [$this, 'clear_cron']);
    }

    /**
     * Process daily subscription charges
     */
    public function process_daily_charges() {
        $active_subscriptions = $this->get_flow_db()->get_active_subscriptions();

        if (empty($active_subscriptions)) {
            return;
        }

        foreach ($active_subscriptions as $subscription) {
            if (empty($subscription->mandato_id)) {
                continue;
            }

            $this->process_subscription_charge($subscription);
        }
    }

    /**
     * Process individual subscription charge
     */
    private function process_subscription_charge($subscription) {
        // Check if it's time to charge (monthly billing)
        if (!$this->should_charge_today($subscription)) {
            return;
        }

        $description = "Cobro suscripción - {$subscription->name} ({$subscription->plan_id})";

        $charge_result = $this->get_flow_api()->charge_mandate(
            $subscription->mandato_id,
            $subscription->amount,
            $description
        );

        if (!empty($charge_result['error']) || !empty($charge_result['code'])) {
            // Log error
            $this->log_charge_error($subscription, $charge_result);

            // Optionally mark subscription as failed after X attempts
            $this->handle_failed_charge($subscription, $charge_result);
        } else {
            // Success - log and update stats
            $this->log_successful_charge($subscription, $charge_result);

            // Create WooCommerce order if integration is active
            if ($this->get_wc_integration()->is_woocommerce_available()) {
                $this->get_wc_integration()->create_subscription_order(
                    $subscription->email,
                    $subscription->plan_id,
                    $subscription->amount
                );
            }
        }
    }

    /**
     * Determine if subscription should be charged today
     */
    private function should_charge_today($subscription) {
        $created_date = new DateTime($subscription->created_at);
        $today = new DateTime();

        // Check if it's the monthly billing day
        $billing_day = $created_date->format('j'); // Day of month
        $current_day = $today->format('j');

        return $billing_day == $current_day;
    }

    /**
     * Log successful charge
     */
    private function log_successful_charge($subscription, $result) {
        $log_entry = sprintf(
            '[%s] SUCCESS: Charged $%d for subscription ID %d (Email: %s) - Flow Response: %s',
            current_time('mysql'),
            $subscription->amount,
            $subscription->id,
            $subscription->email,
            json_encode($result)
        );

        error_log($log_entry);

        // Update subscription with last charge date
        $this->get_flow_db()->update_subscription($subscription->id, [
            'last_charged' => current_time('mysql')
        ]);
    }

    /**
     * Log charge error
     */
    private function log_charge_error($subscription, $result) {
        $log_entry = sprintf(
            '[%s] ERROR: Failed to charge subscription ID %d (Email: %s) - Flow Response: %s',
            current_time('mysql'),
            $subscription->id,
            $subscription->email,
            json_encode($result)
        );

        error_log($log_entry);
    }

    /**
     * Handle failed charge attempts
     */
    private function handle_failed_charge($subscription, $result) {
        // Get current failed attempts
        $failed_attempts = get_option("flow_failed_attempts_{$subscription->id}", 0);
        $failed_attempts++;

        // Update failed attempts count
        update_option("flow_failed_attempts_{$subscription->id}", $failed_attempts);

        // After 3 failed attempts, mark as suspended
        if ($failed_attempts >= 3) {
            $this->get_flow_db()->update_subscription($subscription->id, [
                'status' => 'suspendido'
            ]);

            // Send notification email to admin
            $this->send_suspension_notification($subscription);
            
            // Clear failed attempts counter
            delete_option("flow_failed_attempts_{$subscription->id}");
        }
    }

    /**
     * Send suspension notification to admin
     */
    private function send_suspension_notification($subscription) {
        $admin_email = get_option('admin_email');
        $subject = 'Suscripción Suspendida - Flow Suscripciones';
        
        $message = sprintf(
            "La suscripción del cliente %s (%s) ha sido suspendida debido a fallos repetidos en el cobro.\n\n" .
            "Detalles:\n" .
            "- Plan: %s\n" .
            "- Monto: $%d CLP\n" .
            "- Fecha de suspensión: %s\n\n" .
            "Por favor, contacta al cliente para resolver el problema.",
            $subscription->name,
            $subscription->email,
            $subscription->plan_id,
            $subscription->amount,
            current_time('mysql')
        );

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Clear scheduled cron jobs on plugin deactivation
     */
    public function clear_cron() {
        wp_clear_scheduled_hook('flow_daily_charges');
    }

    /**
     * Manual trigger for testing (admin use)
     */
    public function manual_charge_test() {
        if (!current_user_can('manage_options')) {
            return false;
        }

        return $this->process_daily_charges();
    }
}