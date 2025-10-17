<?php
if (!defined('ABSPATH')) exit;

class Flow_Database {

    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'flow_subscriptions';
    }

    /**
     * Insert new subscription
     */
    public function insert_subscription($data) {
        global $wpdb;

        $defaults = [
            'status' => 'pendiente',
            'created_at' => current_time('mysql')
        ];

        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert($this->table_name, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    public function update_subscription($id, $data) {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            $data,
            ['id' => $id]
        );
    }

    public function get_subscription($id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
    }

    /**
     * Get subscription by email
     */
    public function get_subscription_by_email($email) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE email = %s",
            $email
        ));
    }

    /**
     * Get subscription by mandate ID
     */
    public function get_subscription_by_mandate_id($mandate_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE mandato_id = %s",
            $mandate_id
        ));
    }

    /**
     * Get subscription by Flow subscription ID
     */
    public function get_subscription_by_flow_subscription_id($flow_subscription_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE flow_subscription_id = %s",
            $flow_subscription_id
        ));
    }

    /**
     * Get subscription by WooCommerce order ID
     */
    public function get_subscription_by_wc_order_id($wc_order_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE wc_order_id = %d",
            $wc_order_id
        ));
    }

    /**
     * Get all subscriptions
     */
    public function get_all_subscriptions($status = null) {
        global $wpdb;
        
        if ($status) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE status = %s ORDER BY created_at DESC",
                $status
            ));
        }
        
        return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");
    }

    /**
     * Get active subscriptions for cron jobs
     */
    public function get_active_subscriptions() {
        return $this->get_all_subscriptions('activo');
    }

    /**
     * Delete subscription
     */
    public function delete_subscription($id) {
        global $wpdb;
        
        return $wpdb->delete(
            $this->table_name,
            ['id' => $id]
        );
    }

    /**
     * Get subscription statistics
     */
    public function get_subscription_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_results("
            SELECT 
                status,
                COUNT(*) as count,
                SUM(amount) as total_amount
            FROM {$this->table_name} 
            GROUP BY status
        ");
        
        $result = [
            'total' => 0,
            'activo' => 0,
            'pendiente' => 0,
            'cancelado' => 0,
            'total_revenue' => 0
        ];
        
        foreach ($stats as $stat) {
            $result['total'] += $stat->count;
            $result[$stat->status] = $stat->count;
            if ($stat->status === 'activo') {
                $result['total_revenue'] = $stat->total_amount;
            }
        }
        
        return $result;
    }
}