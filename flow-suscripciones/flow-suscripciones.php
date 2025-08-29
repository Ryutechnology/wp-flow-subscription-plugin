<?php
/*
Plugin Name: Flow Suscripciones Completo
Description: Suscripciones recurrentes vía Flow con formulario público (Nombre, Email, Dirección, Ciudad) y panel admin.
Version: 1.0
Author: Rudyard Fuster
*/

if (!defined('ABSPATH')) exit;

// -----------------------------
// Activación: crear tabla personalizada
// -----------------------------
register_activation_hook(__FILE__, function(){
    global $wpdb;
    $table = $wpdb->prefix . 'flow_subscriptions';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        name VARCHAR(190) NOT NULL,
        address VARCHAR(255) NOT NULL,
        city VARCHAR(190) NOT NULL,
        plan_id VARCHAR(100) NOT NULL,
        mandato_id VARCHAR(100) DEFAULT NULL,
        amount INT NOT NULL,
        status VARCHAR(20) DEFAULT 'pendiente',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Add flow_customer_id column to WooCommerce customer lookup table
    $wc_customer_lookup_table = $wpdb->prefix . 'wc_customer_lookup';
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = 'flow_customer_id'",
        $wc_customer_lookup_table
    ));
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE {$wc_customer_lookup_table} ADD COLUMN flow_customer_id VARCHAR(100) DEFAULT NULL");
    }
});

// -----------------------------
// Admin: Ajustes y suscriptores
// -----------------------------
add_action('admin_menu', function(){
    add_menu_page('Flow Suscripciones','Flow Suscripciones','manage_options','flow-suscripciones','flow_admin_page');
    add_submenu_page('flow-suscripciones','Suscriptores','Suscriptores','manage_options','flow-suscriptores','flow_admin_list');
});

function flow_admin_page(){
    if(isset($_POST['flow_api_key'])){
        update_option('flow_api_key', sanitize_text_field($_POST['flow_api_key']));
        update_option('flow_secret_key', sanitize_text_field($_POST['flow_secret_key']));
        echo '<div class="updated"><p>Guardado.</p></div>';
    }
    echo '<div class="wrap"><h1>Ajustes Flow</h1><form method="post">';
    echo '<p><label>API Key: <input type="text" name="flow_api_key" value="'.esc_attr(get_option('flow_api_key')).'"/></label></p>';
    echo '<p><label>Secret Key: <input type="text" name="flow_secret_key" value="'.esc_attr(get_option('flow_secret_key')).'"/></label></p>';
    submit_button();
    echo '</form></div>';
}

function flow_admin_list(){
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}flow_subscriptions ORDER BY created_at DESC");
    echo '<div class="wrap"><h1>Suscriptores</h1><table class="widefat"><thead><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Dirección</th><th>Ciudad</th><th>Monto</th><th>Status</th><th>Mandato</th><th>Creado</th></tr></thead><tbody>';
    foreach($rows as $r){
        echo '<tr><td>'.$r->id.'</td><td>'.$r->name.'</td><td>'.$r->email.'</td><td>'.$r->address.'</td><td>'.$r->city.'</td><td>'.$r->amount.'</td><td>'.$r->status.'</td><td>'.$r->mandato_id.'</td><td>'.$r->created_at.'</td></tr>';
    }
    echo '</tbody></table></div>';
}

// -----------------------------
// Shortcode: Formulario público
// -----------------------------
add_shortcode('flow_suscripcion', function($atts){
    $a = shortcode_atts([ 'plan'=>'Plan Básico','amount'=>5000 ], $atts);
    ob_start();

    if($_POST && isset($_POST['flow_email'])){
        if(!wp_verify_nonce($_POST['flow_nonce'],'flow_form')){ wp_die('Error de seguridad');}
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'flow_subscriptions', [
            'email'=>sanitize_email($_POST['flow_email']),
            'name'=>sanitize_text_field($_POST['flow_name']),
            'address'=>sanitize_text_field($_POST['flow_address']),
            'city'=>sanitize_text_field($_POST['flow_city']),
            'plan_id'=>sanitize_title($a['plan']),
            'amount'=>intval($a['amount']),
            'status'=>'pendiente'
        ]);
        $id = $wpdb->insert_id;

        // Crear plan y mandato en Flow
        $plan = flow_create_plan($a['plan'],$a['amount']);
        $subscription = flow_create_subscription($plan['planId'],$_POST['flow_email'],$_POST['flow_name']);
        $customer = flow_create_customer($_POST['flow_email'],$_POST['flow_name'],$_POST['flow_address'],$_POST['flow_city']);

        // Guardar mandato_id
        $wpdb->update($wpdb->prefix.'flow_subscriptions',['mandato_id'=>$subscription['subscriptionId'],'status'=>'activo'],['id'=>$id]);

        if(!empty($subscription['subscriptionId'])){
            echo '<p>Suscripción registrada exitosamente!!</p>';
        } else {
            echo '<p>Suscripción registrada, pero no se pudo generar la URL de Flow.</p>';
        }
    } else {
        ?>
        <form method="post">
            <?php wp_nonce_field('flow_form','flow_nonce'); ?>
            <input type="text" name="flow_name" placeholder="Nombre completo" required><br>
            <input type="email" name="flow_email" placeholder="Email" required><br>
            <input type="text" name="flow_address" placeholder="Dirección" required><br>
            <input type="text" name="flow_city" placeholder="Ciudad" required><br>
            <button type="submit">Suscribirme al <?php echo esc_html($a['plan']); ?> (<?php echo esc_html($a['amount']); ?> CLP)</button>
        </form>
        <?php
    }
    return ob_get_clean();
});

// -----------------------------
// Flow API helpers
// -----------------------------
function flow_api_request($endpoint,$params){
    $apiKey = get_option('flow_api_key');
    $secretKey = get_option('flow_secret_key');
    if(!$apiKey||!$secretKey) return ['error'=>'Faltan credenciales Flow'];

    $params['apiKey']=$apiKey;
    ksort($params);
    $params['s']=hash_hmac('sha256',urldecode(http_build_query($params)),$secretKey);

    $res = wp_remote_post('https://sandbox.flow.cl/api/'.$endpoint,['body'=>$params,'timeout'=>45]);
    if(is_wp_error($res)) return ['error'=>$res->get_error_message()];
    return json_decode(wp_remote_retrieve_body($res),true);
}

function flow_api_get_request($endpoint,$params){
    $apiKey = get_option('flow_api_key');
    $secretKey = get_option('flow_secret_key');
    if(!$apiKey||!$secretKey) return ['error'=>'Faltan credenciales Flow'];
    $params['apiKey']=$apiKey;
    ksort($params);
    $params['s']=hash_hmac('sha256',urldecode(http_build_query($params)),$secretKey);
    $url = 'https://sandbox.flow.cl/api/'.$endpoint.'?'.http_build_query($params);
    $res = wp_remote_get($url,['timeout'=>45]);
    if(is_wp_error($res)) return ['error'=>$res->get_error_message()];
    return json_decode(wp_remote_retrieve_body($res),true);
}

function flow_create_plan($name,$amount){
    $get_plan = flow_api_get_request('plans/get',['planId'=>create_plan_id($name)]);
    if(empty($get_plan['code']) && (empty($get_plan['message'])) && !empty($get_plan['planId'])) return $get_plan;
    return flow_api_request('plans/create',['planId'=>uniqid('plan_'),'name'=>$name,'amount'=>$amount,'currency'=>'CLP','interval'=>3,'intervalCount'=>1]);
}

function flow_create_subscription($planId,$email,$name){
    return flow_api_request('subscription/create',['planId'=>$planId,'customerId'=>$email]);
}

function flow_create_customer($email,$name,$address,$city){
    $customer = flow_get_customer_by_email($email);
    $get_customer = flow_api_get_request('customers/get',['customerId'=>$customer ? $customer->flow_customer_id : $email]);

    if(empty($get_customer['code']) && (empty($get_customer['message'])) && !empty($get_customer['customerId'])) return $get_customer;

    return flow_api_request('customers/create',['name'=>$name,'email'=>$email,'externalId'=>$email]);
}

// -----------------------------
// Herramientas
// -----------------------------

function create_plan_id($name){
    return strtolower(str_replace(' ','_',$name));
}

function flow_get_customer_by_email($email){
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}flow_subscriptions WHERE email = %s", $email));
}

// -----------------------------
// Cron diario para cobrar mandatos
// -----------------------------
if(!wp_next_scheduled('flow_cobros_diarios')) wp_schedule_event(time(),'daily','flow_cobros_diarios');
add_action('flow_cobros_diarios', function(){
    global $wpdb;
    $subs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}flow_subscriptions WHERE status='activo'");
    foreach($subs as $s){
        if(empty($s->mandato_id)) continue;
        // flow_charge_mandate($s->mandato_id,$s->amount,'Cobro suscripción');
    }
});
