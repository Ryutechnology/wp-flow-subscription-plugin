<?php
if ( !defined( 'ABSPATH' ) ) exit;

class Flow_Admin {
    public function __construct() {
        // Enganchar los menús en admin
        add_action('admin_menu', [$this, 'register_menus']);
    }

    /**
     * Registrar menús y submenús en el admin
     */
    public function register_menus() {
        add_menu_page(
            'Flow Suscripciones',
            'Flow Suscripciones',
            'manage_options',
            'flow-suscripciones',
            [$this, 'render_settings_page'],
            'dashicons-money-alt'
        );

        add_submenu_page(
            'flow-suscripciones',
            'Suscriptores',
            'Suscriptores',
            'manage_options',
            'flow-suscriptores',
            [$this, 'render_subscribers_page']
        );
    }

    /**
     * Página principal (ajustes de API Keys)
     */
    public function render_settings_page() {
        if (isset($_POST['flow_api_key'])) {
            update_option('flow_api_key', sanitize_text_field($_POST['flow_api_key']));
            update_option('flow_secret_key', sanitize_text_field($_POST['flow_secret_key']));
            echo '<div class="updated"><p>Guardado.</p></div>';
        }

        echo '<div class="wrap"><h1>Ajustes Flow</h1><form method="post">';
        echo '<p><label>API Key: <input type="text" name="flow_api_key" value="' . esc_attr(get_option('flow_api_key')) . '"/></label></p>';
        echo '<p><label>Secret Key: <input type="text" name="flow_secret_key" value="' . esc_attr(get_option('flow_secret_key')) . '"/></label></p>';
        submit_button();
        echo '</form></div>';
    }

    /**
     * Página lista de suscriptores
     */
    public function render_subscribers_page() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}flow_subscriptions ORDER BY created_at DESC");

        echo '<div class="wrap"><h1>Suscriptores</h1>';
        echo '<table class="widefat"><thead><tr>
                <th>ID</th><th>Nombre</th><th>Email</th><th>Dirección</th>
                <th>Ciudad</th><th>Monto</th><th>Status</th><th>FlowSubscriptionId</th><th>Creado</th>
              </tr></thead><tbody>';

        if ($rows) {
            foreach ($rows as $r) {
                echo '<tr>
                        <td>' . esc_html($r->id) . '</td>
                        <td>' . esc_html($r->name) . '</td>
                        <td>' . esc_html($r->email) . '</td>
                        <td>' . esc_html($r->address) . '</td>
                        <td>' . esc_html($r->city) . '</td>
                        <td>' . esc_html($r->amount) . '</td>
                        <td>' . esc_html($r->status) . '</td>
                        <td>' . esc_html($r->mandato_id) . '</td>
                        <td>' . esc_html($r->created_at) . '</td>
                      </tr>';
            }
        } else {
            echo '<tr><td colspan="9">No hay suscriptores aún.</td></tr>';
        }

        echo '</tbody></table></div>';
    }
}