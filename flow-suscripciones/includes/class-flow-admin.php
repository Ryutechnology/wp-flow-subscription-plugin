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
            update_option('flow_environment', sanitize_text_field($_POST['flow_environment']));
            echo '<div class="updated"><p>Configuración guardada exitosamente.</p></div>';
        }

        $current_environment = get_option('flow_environment', 'sandbox');

        echo '<div class="wrap"><h1>Ajustes Flow</h1>';
        echo '<form method="post">';

        echo '<table class="form-table">';

        // Environment setting
        echo '<tr>';
        echo '<th scope="row"><label for="flow_environment">Entorno</label></th>';
        echo '<td>';
        echo '<select name="flow_environment" id="flow_environment">';
        echo '<option value="sandbox"' . selected($current_environment, 'sandbox', false) . '>Sandbox (Pruebas)</option>';
        echo '<option value="production"' . selected($current_environment, 'production', false) . '>Producción</option>';
        echo '</select>';
        echo '<p class="description">Selecciona si deseas usar el entorno de pruebas (Sandbox) o producción.</p>';
        echo '</td>';
        echo '</tr>';

        // API Key
        echo '<tr>';
        echo '<th scope="row"><label for="flow_api_key">API Key</label></th>';
        echo '<td>';
        echo '<input type="text" name="flow_api_key" id="flow_api_key" value="' . esc_attr(get_option('flow_api_key')) . '" class="regular-text" />';
        echo '<p class="description">Tu clave API de Flow ' . ($current_environment === 'production' ? 'de producción' : 'de sandbox') . '.</p>';
        echo '</td>';
        echo '</tr>';

        // Secret Key
        echo '<tr>';
        echo '<th scope="row"><label for="flow_secret_key">Secret Key</label></th>';
        echo '<td>';
        echo '<input type="password" name="flow_secret_key" id="flow_secret_key" value="' . esc_attr(get_option('flow_secret_key')) . '" class="regular-text" />';
        echo '<p class="description">Tu clave secreta de Flow ' . ($current_environment === 'production' ? 'de producción' : 'de sandbox') . '.</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Environment info
        echo '<div class="notice notice-info">';
        echo '<p><strong>Entorno actual:</strong> ';
        if ($current_environment === 'production') {
            echo '<span style="color: #d63638;">🔴 PRODUCCIÓN</span> - Se procesarán pagos reales.';
        } else {
            echo '<span style="color: #00a32a;">🟢 SANDBOX</span> - Entorno de pruebas, no se procesarán pagos reales.';
        }
        echo '</p>';
        echo '<p><strong>URL Base:</strong> ' . ($current_environment === 'production' ? 'https://www.flow.cl/api/' : 'https://sandbox.flow.cl/api/') . '</p>';
        echo '</div>';

        submit_button('Guardar Configuración');
        echo '</form>';

        // Add JavaScript for environment switching
        echo '<script>
        jQuery(document).ready(function($) {
            $("#flow_environment").change(function() {
                var env = $(this).val();
                var isProduction = env === "production";

                // Update descriptions
                $("#flow_api_key").next(".description").html(
                    "Tu clave API de Flow " + (isProduction ? "de producción" : "de sandbox") + "."
                );
                $("#flow_secret_key").next(".description").html(
                    "Tu clave secreta de Flow " + (isProduction ? "de producción" : "de sandbox") + "."
                );

                // Update environment info
                $(".notice-info p:first").html(
                    "<strong>Entorno actual:</strong> " +
                    (isProduction ?
                        "<span style=\"color: #d63638;\">🔴 PRODUCCIÓN</span> - Se procesarán pagos reales." :
                        "<span style=\"color: #00a32a;\">🟢 SANDBOX</span> - Entorno de pruebas, no se procesarán pagos reales.")
                );
                $(".notice-info p:last").html(
                    "<strong>URL Base:</strong> " +
                    (isProduction ? "https://www.flow.cl/api/" : "https://sandbox.flow.cl/api/")
                );

                // Show warning when switching to production
                if (isProduction && !$("#production-warning").length) {
                    $("#flow_environment").closest("td").append(
                        "<div id=\"production-warning\" class=\"notice notice-warning\" style=\"margin-top: 10px; padding: 10px;\">" +
                        "<p><strong>⚠️ ADVERTENCIA:</strong> Estás cambiando al entorno de producción. " +
                        "Asegúrate de usar las credenciales correctas de producción antes de guardar.</p>" +
                        "</div>"
                    );
                } else if (!isProduction) {
                    $("#production-warning").remove();
                }
            });
        });
        </script>';

        echo '</div>';
    }

    /**
     * Página lista de suscriptores
     */
    public function render_subscribers_page() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}flow_subscriptions ORDER BY created_at DESC");

        $current_environment = get_option('flow_environment', 'sandbox');
        $base_dashboard_url = $current_environment === 'production'
            ? 'https://dashboard.flow.cl'
            : 'https://dashboard.sandbox.flow.cl';

        echo '<div class="wrap"><h1>Suscriptores</h1>';
        echo '<table class="widefat"><thead><tr>
                <th>ID</th><th>Nombre</th><th>Email</th><th>Dirección</th>
                <th>Ciudad</th><th>Monto</th><th>Status</th><th>FlowSubscriptionId</th><th>Creado</th><th>Acciones</th>
              </tr></thead><tbody>';

        if ($rows) {
            foreach ($rows as $r) {
                $flow_dashboard_link = '';
                if (!empty($r->flow_subscription_id)) {
                    $dashboard_url = $base_dashboard_url . '/private/suscripciones/subscriptions/details/?sus_id=' . urlencode($r->flow_subscription_id);
                    $flow_dashboard_link = '<a href="' . $dashboard_url . '" target="_blank" class="button button-secondary" title="Ver en Dashboard de Flow">Ver en Flow</a>';
                }

                echo '<tr>
                        <td>' . esc_html($r->id) . '</td>
                        <td>' . esc_html($r->name) . '</td>
                        <td>' . esc_html($r->email) . '</td>
                        <td>' . esc_html($r->address) . '</td>
                        <td>' . esc_html($r->city) . '</td>
                        <td>' . esc_html($r->amount) . '</td>
                        <td>' . esc_html($r->status) . '</td>
                        <td>' . esc_html($r->flow_subscription_id) . '</td>
                        <td>' . esc_html($r->created_at) . '</td>
                        <td>' . $flow_dashboard_link . '</td>
                      </tr>';
            }
        } else {
            echo '<tr><td colspan="10">No hay suscriptores aún.</td></tr>';
        }

        echo '</tbody></table></div>';
    }
}