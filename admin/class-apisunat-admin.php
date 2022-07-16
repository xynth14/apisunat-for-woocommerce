<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://apisunat.com/
 * @since      1.0.0
 *
 * @package    Apisunat
 * @subpackage Apisunat/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Apisunat
 * @subpackage Apisunat/admin
 * @author     Heikel Villar <heikelvillar@gmail.com>
 */
class Apisunat_Admin
{
    const API_WC_URL = 'https://ecommerces-api.apisunat.com/v1.1/woocommerce';
    const API_URL = 'https://back.apisunat.com';

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string $plugin_name The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string $version The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @param string $plugin_name The name of this plugin.
     * @param string $version The version of this plugin.
     * @since    1.0.0
     */
    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action('admin_menu', array($this, 'add_apisunat_admin_menu'), 10);
        add_action('admin_init', array($this, 'register_and_build_fields'));
        add_action('add_meta_boxes', array($this, 'apisunat_meta_boxes'));
        add_action('admin_init', array($this, 'add_apisunat_document_modal'));
        add_action('admin_init', array($this, 'apisunat_forma_envio_facturas'));
        add_action('wp_ajax_void_apisunat_order', array($this, 'void_apisunat_order'), 11, 1);
        add_filter('manage_edit-shop_order_columns', array($this, 'apisunat_custom_order_column'), 11);
        add_action('manage_shop_order_posts_custom_column', array($this, 'apisunat_custom_orders_list_column_content'), 10, 2);

    }

    function apisunat_custom_order_column($columns): array
    {
        $reordered_columns = array();

        foreach ($columns as $key => $column) {
            $reordered_columns[$key] = $column;
            if ($key == 'order_status') {
                $reordered_columns['apisunat_document_status'] = 'APISUNAT Status';
            }
        }
        return $reordered_columns;
    }

    function apisunat_custom_orders_list_column_content($column, $post_id)
    {
        if ('apisunat_document_status' == $column) {
            $status = get_post_meta($post_id, 'apisunat_document_status', true);
            if (!empty($status))
                echo $status;

            if (empty($status))
                echo '<small>(<em>no enviado</em>)</small>';
        }
    }

    public function apisunat_check_status_on_schedule()
    {

        $orders = wc_get_orders(array(
            'limit' => -1, // Query all orders
            'meta_key' => 'apisunat_document_status', // The postmeta key field
            'meta_value' => 'PENDIENTE', // The postmeta key field
            'meta_compare' => '=', // The comparison argument
        ));

        foreach ($orders as $order) {

            if ($order->meta_exists('apisunat_document_id')) {
                if ($order->get_meta('apisunat_document_status') == 'PENDIENTE') {
                    $request = wp_remote_get(self::API_URL . '/documents/' . $order->get_meta('apisunat_document_id') . '/getById');
                    $data = json_decode(wp_remote_retrieve_body($request), true);
                    $status = $data['status'];

                    $order->add_order_note(" El documento se encuentra en estado: " . $status);
                    update_post_meta($order->get_id(), 'apisunat_document_status', $status);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function apisunat_forma_envio_facturas(): void
    {
        if (get_option('apisunat_forma_envio') == 'auto') {
            add_action('woocommerce_order_status_completed', array($this, 'send_apisunat_order'), 10, 1);
            add_action('wp_ajax_send_apisunat_order', array($this, 'send_apisunat_order'), 10, 1);
        } else {
            add_action('wp_ajax_send_apisunat_order', array($this, 'send_apisunat_order'), 10, 1);
        }
    }

    function send_apisunat_order($order_id)
    {

        $orderId = isset($_POST['order_value']) ? intval($_POST['order_value']) : $order_id;

        /**
         * Obtener datos de la orden y el tipo de documento
         */
        $order = wc_get_order($orderId);

        if ($order->meta_exists('apisunat_document_status')) {
            if ($order->get_meta('apisunat_document_status') == 'PENDIENTE' || $order->get_meta('apisunat_document_status') == 'ACEPTADO') {
                return;
            }
        }

        $send_data = [];
        $send_data['plugin_data']['personaId'] = get_option('apisunat_personal_id');
        $send_data['plugin_data']['personaToken'] = get_option('apisunat_personal_token');
        $send_data['plugin_data']['serie01'] = get_option('apisunat_serie_factura');
        $send_data['plugin_data']['serie03'] = get_option('apisunat_serie_boleta');
        $send_data['plugin_data']['affectation'] = get_option('apisunat_tipo_tributo');
        $send_data['plugin_data']['personaRUC'] = get_option('apisunat_ruc');
        $send_data['plugin_data']['personaName'] = get_option('apisunat_company_name');
        $send_data['plugin_data']['personaAddress'] = get_option('apisunat_company_address');
        $send_data['plugin_data']['issueTime'] = get_option('apisunat_include_time');
        $send_data['plugin_data']['shipping_cost'] = get_option('apisunat_shipping_cost');
        $send_data['plugin_data']['debug'] = get_option('apisunat_debug_mode');
        $send_data['plugin_data']['custom_meta_data'] = get_option('apisunat_custom_checkout');



        $send_data['plugin_data']['meta_data_mapping']['_billing_apisunat_document_type'] = [
            'key' => get_option('apisunat_key_tipo_comprobante', '_billing_apisunat_document_type'),
            'value_01' => get_option('apisunat_key_value_factura', '01'),
            'value_03' => get_option('apisunat_key_value_boleta', '03'),
        ];

        $send_data['plugin_data']['meta_data_mapping']['_billing_apisunat_customer_id_type'] = [
            'key' => get_option('apisunat_key_tipo_documento', '_billing_apisunat_customer_id_type'),
            'value_1' => get_option('apisunat_key_value_dni', '1'),
            'value_4' => get_option('apisunat_key_value_ce', '4'),
            'value_6' => get_option('apisunat_key_value_ruc', '6'),
            'value_7' => get_option('apisunat_key_value_pasaporte', '7')
        ];

        $send_data['plugin_data']['meta_data_mapping']['_billing_apisunat_customer_id'] = [
            'key' => get_option('apisunat_key_numero_documento', '_billing_apisunat_customer_id')
        ];

        $send_data['order_data'] = $order->get_data();

        foreach ($order->get_items() as $item) {
            $item_data = [
                'item' => $item->get_data(),
                'product' => $item->get_product()->get_data()
            ];
            $send_data['items_data'][] = $item_data;
        }

        $args = array(
            'method' => 'POST',
            'timeout' => 45,
            'body' => json_encode($send_data),
            'headers' => array(
                'content-type' => 'application/json',
            )
        );

        $response = wp_remote_post(self::API_WC_URL, $args);

        //si es un error de WP
        if (is_wp_error($response)) {
            $errorResponse = $response->get_error_message();
            $msg = $errorResponse;
        } else {
            $apisunat_response = json_decode($response['body'], true);
            update_post_meta($orderId, 'apisunat_document_status', $apisunat_response['status']);

            if ($apisunat_response['status'] == "ERROR") {
                $msg = $apisunat_response['error']['message'];
            } else {
                update_post_meta($orderId, 'apisunat_document_id', $apisunat_response['documentId']);
                update_post_meta($orderId, 'apisunat_document_filename', $apisunat_response['fileName']);

                $msg = 'Los datos se han enviado a APISUNAT';
            }
        }
        $order->add_order_note($msg);
    }

    function void_apisunat_order()
    {
        $order_id = intval($_POST['order_value']);

        $order = wc_get_order($order_id);

        $body = [
            'personaId' => get_option('apisunat_personal_id'),
            'personaToken' => get_option('apisunat_personal_token'),
            'documentId' => $order->get_meta('apisunat_document_id'),
            'reason' => $_POST['reason']
        ];

        $args = array(
            'method' => 'POST',
            'timeout' => 45,
            'body' => json_encode($body),
            'headers' => array(
                'content-type' => 'application/json',
            )
        );

        $response = wp_remote_post(self::API_URL . '/personas/v1/voidBill', $args);

        if (is_wp_error($response)) {
            $errorResponse = $response->get_error_message();
            $msg = $errorResponse;
        } else {
            $apisunat_response = json_decode($response['body'], true);

            $msg = $apisunat_response;

        }
        $order->add_order_note($msg);

    }

    /**
     * Obtener el ultimo documento según el tipo
     */
    public function apisunat_get_last_document($document_type)
    {
        $serie = "";
        switch ($document_type) {
            case '01':
                $serie = get_option('apisunat_serie_factura');
                break;
            case '03':
                $serie = get_option('apisunat_serie_boleta');
                break;
        }

        $query_vars = array(
            'personaId' => get_option('apisunat_personal_id', ''),
            'personaToken' => get_option('apisunat_personal_token', ''),
            'order' => 'DESC',
            'limit' => '1',
            'type' => $document_type,
            'serie' => $serie
        );

        $args = array('timeout' => 45);
        $request = wp_remote_get(self::API_URL . '/documents/getAll?' . http_build_query($query_vars), $args);

        $data = json_decode(wp_remote_retrieve_body($request), true);
        $filename = $data[0]['fileName'];
        $serie_correlative = $this->apisunat_extract_serie_get_correlative($filename);
        $data['extra'] = $serie_correlative;

        return $data;
    }

    /*
     * Get serie and calculate consecutive from the last document filename
     */
    function apisunat_extract_serie_get_correlative($filename): array
    {
        $temp = explode('-', $filename); //split filename by '-' character
        $data = array();
        $data['serie'] = $temp[2];
        $number = $temp[3];
        $number = str_pad(intval($number) + 1, strlen($number), '0', STR_PAD_LEFT); //increase string number
        $data['correlative'] = $number;

        return $data;
    }

    function apisunat_meta_boxes()
    {
        add_meta_box(
            'woocommerce-order-apisunat',
            __('APISUNAT'),
            array($this, 'order_meta_box_apisunat'),
            'shop_order',
            'side',
            'default'
        );
    }

    function order_meta_box_apisunat($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order->meta_exists('apisunat_document_status')) {
            {
                $option_name = get_option('apisunat_key_tipo_comprobante');

                switch ($order->get_meta($option_name)) {
                    case '01':
                        $tipo = 'Factura';
                        break;
                    case '03':
                        $tipo = 'Boleta';
                        break;
                }

                $number = explode('-', $order->get_meta('apisunat_document_filename'));

                printf("<p>Status: <strong> %s</strong></p>", $order->get_meta('apisunat_document_status'));

                if ($order->meta_exists('apisunat_document_id')) {
                    printf("<p>Numero %s: <strong> %s</strong></p>", $tipo, $number[2] . '-' . $number[3]);
                    printf("<p><a href=https://back.apisunat.com/documents/%s/getPDF/A4/%s.pdf target='_blank'>Imprimir</a></p>",
                        $order->get_meta('apisunat_document_id'), $order->get_meta('apisunat_document_filename'
                        ));
                }


                //TODO: mostar el documento en un modal con un boton
                //echo '<a href="#" id="apisunatButton" class="button">Ver Documento</a>';
            }
        } else {
            echo '<p>No se ha enviado la factura a APISUNAT</p>';
        }

        echo '<input type="hidden" id="orderId" name="orderId" value="' . $order->get_id() . '">';
        echo '<input type="hidden" id="orderStatus" name="orderStatus" value="' . $order->get_status() . '">';


        if (get_option('apisunat_forma_envio') == 'auto') {

            if ($order->get_meta('apisunat_document_status') == 'ERROR' ||
                $order->get_meta('apisunat_document_status') == 'EXCEPCION' ||
                $order->get_meta('apisunat_document_status') == 'RECHAZADO') {

                echo '<a id="apisunatSendData" class="button-primary">Enviar Comprobante</a> ';
                echo '<div id="apisunatLoading" class="mt-3 mx-auto" style="display:none;">
                        <img src="images/loading.gif"/>
                    </div>';
            }

        } elseif (get_option('apisunat_forma_envio') == 'manual') {

            if (!$order->get_meta('apisunat_document_status') ||
                $order->get_meta('apisunat_document_status') == 'ERROR' ||
                $order->get_meta('apisunat_document_status') == 'EXCEPCION' ||
                $order->get_meta('apisunat_document_status') == 'RECHAZADO') {

                echo '<a id="apisunatSendData" class="button-primary">Enviar Comprobante</a> ';
                echo '<div id="apisunatLoading" class="mt-3 mx-auto" style="display:none;">
                        <img src="images/loading.gif"/>
                    </div>';
            }

        }

        //TODO: preparar anular orden
//        if ($order->get_meta('apisunat_document_status') == 'ACEPTADO') {
//
//            echo '<p><a href="#" id="apisunat_show_anular">Anular?</a></p>';
//
//            echo '<div id="apisunat_reason" style="display: none;">';
//            echo '<textarea rows="5" id="apisunat_nular_reason" placeholder="Razon por la que desea anular" minlength="3" maxlength="100"></textarea>';
//            echo '<a href="#" id="apisunatAnularData" class="button-primary">Anular con NC</a> ';
//            echo '<div id="apisunatLoading2" class="mt-3 mx-auto" style="display:none;">
//                        <img src="images/loading.gif"/>
//                    </div>';
//            echo '</div>';
//        }

    }

    public function add_apisunat_document_modal()
    {
        /**
         * Initialize the class and set its properties.
         */
        function apisunat_pop_up()
        { ?>
            <div id="apisunatModal" class="apisunat-modal">
                <!-- Modal content -->
                <div class="apisunatmodal-content">
                    <span class="apisunatmodal-close" id="apisunatModalClose">&times;</span>
                    <div class="apisunatmodal-modal-header">
                        <h4 class="apisunatmodal-modal-title">Document</h4>
                    </div>
                </div>
            </div>
            <?php
        }

        add_action('edit_form_advanced', 'apisunat_pop_up');
    }

    /**
     * Agregar la entrada para la pagina de configuraciones
     */
    public function add_apisunat_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'APISUNAT',
            'APISUNAT',
            'manage_woocommerce',
            'apisunat',
            array($this, 'display_apisunat_admin_settings'),
            16
        );
    }

    /**
     * Display settings
     */
    public function display_apisunat_admin_settings()
    {
        require_once 'partials/' . $this->plugin_name . '-admin-display.php';
    }

    /**
     * Fields
     */
    public function register_and_build_fields()
    {
        /**
         * First, we add_settings_section. This is necessary since all future settings must belong to one.
         * Second, add_settings_field
         * Third, register_setting
         */
        add_settings_section(
            'apisunat_general_section',
            'Datos de acceso',
            array($this, 'apisunat_display_general_account'),
            'apisunat_general_settings'
        );

        add_settings_section(
            'apisunat_data_section',
            '',
            array($this, 'apisunat_display_data'),
            'apisunat_general_settings'
        );

        add_settings_section(
            'apisunat_advanced_section',
            '',
            array($this, 'apisunat_display_advanced'),
            'apisunat_general_settings'
        );

        unset($args);

        $args = array(
            array(
                'title' => 'Personal ID (personalId): ',
                'type' => 'input',
                'id' => 'apisunat_personal_id',
                'name' => 'apisunat_personal_id',
                'required' => 'true',
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_general_section',
            ),
            array(
                'title' => 'Personal Token (personalToken): ',
                'type' => 'input',
                'id' => 'apisunat_personal_token',
                'name' => 'apisunat_personal_token',
                'required' => 'true',
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_general_section',
            ),
            array(
                'title' => 'RUC: ',
                'type' => 'input',
                'id' => 'apisunat_ruc',
                'name' => 'apisunat_ruc',
                'required' => 'true',
                'pattern' => '[12][0567]\d{9}',
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_general_section',
            ),
            array(
                'title' => 'Nombre de la empresa: ',
                'type' => 'input',
                'id' => 'apisunat_company_name',
                'name' => 'apisunat_company_name',
                'required' => 'true',
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_general_section',
            ),
            array(
                'title' => 'Dirección de la empresa: ',
                'type' => 'input',
                'name' => 'apisunat_company_address',
                'id' => 'apisunat_company_address',
                'required' => 'true',
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_general_section',
            ),
            array(
                'title' => 'Envio de facturas de forma: ',
                'type' => 'select',
                'name' => 'apisunat_forma_envio',
                'id' => 'apisunat_forma_envio',
                'required' => true,
                'options' => array(
                    'manual' => 'MANUAL',
                    'auto' => 'AUTOMATICO'
                ),
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_data_section',
            ),
            array(
                'title' => 'Número de serie para Facturas: ',
                'type' => 'input',
                'name' => 'apisunat_serie_factura',
                'id' => 'apisunat_serie_factura',
                'default' => 'F001',
                'required' => true,
                'pattern' => '^[F][A-Z\d]{3}$',
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_data_section',
            ),
            array(
                'title' => 'Número de serie para Boletas: ',
                'type' => 'input',
                'name' => 'apisunat_serie_boleta',
                'id' => 'apisunat_serie_boleta',
                'default' => 'B001',
                'required' => true,
                'pattern' => '^[B][A-Z\d]{3}$',
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_data_section',
            ),
            array(
                'title' => 'Afectación al IGV: ',
                'type' => 'select',
                'name' => 'apisunat_tipo_tributo',
                'id' => 'apisunat_tipo_tributo',
                'required' => true,
                'options' => array(
                    '10' => 'GRAVADO',
                    '20' => 'EXONERADO'
                ),
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_data_section',
            ),
            array(
                'title' => 'Facturar costo de envío: ',
                'type' => 'select',
                'name' => 'apisunat_shipping_cost',
                'id' => 'apisunat_shipping_cost',
                'required' => true,
                'options' => array(
                    "false" => 'NO',
                    "true" => 'SI'
                ),
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_data_section',
            ),
            array(
                'title' => 'Incluir hora en el documento: ',
                'type' => 'select',
                'name' => 'apisunat_include_time',
                'id' => 'apisunat_include_time',
                'required' => true,
                'options' => array(
                    "false" => 'NO',
                    "true" => 'SI'
                ),
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_data_section',
            ),
            array(
                'title' => 'Habilitar Campos Personalizados: ',
                'type' => 'select',
                'name' => 'apisunat_custom_checkout',
                'id' => 'apisunat_custom_checkout',
                'required' => true,
                'options' => array(
                    "false" => 'NO',
                    "true" => 'SI'
                ),
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'key para Tipo de Comprobante: ',
                'type' => 'input',
                'name' => 'apisunat_key_tipo_comprobante',
                'id' => 'apisunat_key_tipo_comprobante',
                'default' => '_billing_apisunat_document_type',
                'required' => true,
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'value para FACTURA: ',
                'type' => 'input',
                'name' => 'apisunat_key_value_factura',
                'id' => 'apisunat_key_value_factura',
                'default' => '01',
                'required' => true,
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'value para BOLETA: ',
                'type' => 'input',
                'name' => 'apisunat_key_value_boleta',
                'id' => 'apisunat_key_value_boleta',
                'default' => '03',
                'required' => true,
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'key para Tipo de Documento: ',
                'type' => 'input',
                'name' => 'apisunat_key_tipo_documento',
                'id' => 'apisunat_key_tipo_documento',
                'default' => '_billing_apisunat_customer_id_type',
                'required' => true,
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'value para DNI: ',
                'type' => 'input',
                'name' => 'apisunat_key_value_dni',
                'id' => 'apisunat_key_value_dni',
                'default' => '1',
                'required' => true,
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'value para CE: ',
                'type' => 'input',
                'name' => 'apisunat_key_value_ce',
                'id' => 'apisunat_key_value_ce',
                'default' => '4',
                'required' => true,
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'value para RUC: ',
                'type' => 'input',
                'name' => 'apisunat_key_value_ruc',
                'id' => 'apisunat_key_value_ruc',
                'default' => '6',
                'required' => true,
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'value para PASAPORTE: ',
                'type' => 'input',
                'name' => 'apisunat_key_value_pasaporte',
                'id' => 'apisunat_key_value_pasaporte',
                'default' => '7',
                'required' => true,
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'key para Número de Documento: ',
                'type' => 'input',
                'name' => 'apisunat_key_numero_documento',
                'id' => 'apisunat_key_numero_documento',
                'default' => '_billing_apisunat_customer_id',
                'required' => true,
                'class' => 'regular-text',
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
            array(
                'title' => 'Modo Debug: ',
                'type' => 'select',
                'name' => 'apisunat_debug_mode',
                'id' => 'apisunat_debug_mode',
                'required' => true,
                'options' => array(
                    "false" => 'NO',
                    "true" => 'SI'
                ),
                'group' => 'apisunat_general_settings',
                'section' => 'apisunat_advanced_section',
            ),
        );
        foreach ($args as $arg) {
            add_settings_field(
                $arg['id'],
                $arg['title'],
                array($this, 'apisunat_render_settings_field'),
                $arg['group'],
                $arg['section'],
                $arg
            );
            register_setting(
                $arg['group'],
                $arg['id']
            );
        }
    }

    /**
     * Message
     */
    public function apisunat_display_general_account()
    {
        ?>
        <h4>Asegúrate de susbscribirte a <a href="https://apisunat.com/" target="_blank">APISUNAT</a> y obtener los
            datos
            de acceso</h4>
        <hr>
        <?php
    }

    /**
     * Message
     */
    public function apisunat_display_data()
    {
        ?>
        <h3>Configuración para envio de datos</h3>
        <hr>
        <?php
    }

    /**
     * Message
     */
    public function apisunat_display_advanced()
    {
        ?>
        <h3>Configuración avanzada</h3>
        <hr>
        <?php
    }


    /**
     * Complete
     *
     * @param array $args Array or args.
     */
    public function apisunat_render_settings_field($args)
    {
        $required_attr = $args['required'] ? "required" : "";
        $pattern_attr = isset($args['pattern']) ? "pattern=" . $args['pattern'] : "";
        $default_value = $args['default'] ?? "";

        switch ($args['type']) {
            case 'input':
                printf(
                    '<input type="' . $args['type'] . '" id="' . $args['id'] . '" name="' . $args['name'] . '"class="' . $args['class'] . '"' . $required_attr . ' ' . $pattern_attr . '  value="%s" />',
                    // '<input type="' . $args['type'] . '" id="' . $args['id'] . '" name="' . $args['name'] . '"class="' . $args['class'] . '" required="' . $args['required'] . '" value="%s" />',
                    get_option($args['id']) ? esc_attr(get_option($args['id'])) : $default_value
                );
                break;
            case 'number':
                printf(
                    '<input type="' . $args['type'] . '" id="' . $args['id'] . '" name="' . $args['name'] . '" min="' . $args['min'] . '" max="' . $args['max'] . '" step="' . $args['step'] . '" value="%s"/>',
                    get_option($args['id']) ? esc_attr(get_option($args['id'])) : ''
                );
                break;
            case 'select':
                $option = get_option($args['id']);
                $items = $args['options'];
                echo '<select id="' . $args['id'] . '"name="' . $args['id'] . '">';
                foreach ($items as $key => $item) {
                    $selected = ($option == $key) ? 'selected="selected"' : '';
                    echo "<option value='" . $key . "' " . $selected . ">" . $item . "</option>";
                }
                echo '</select>';
                break;
            default:
                break;
        }
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles()
    {
        /**
         * This function is provided for demonstration purposes only.
         *
         * An instance of this class should be passed to the run() function
         * defined in Apisunat_Loader as all of the hooks are defined
         * in that particular class.
         *
         * The Apisunat_Loader will then create the relationship
         * between the defined hooks and the functions defined in this
         * class.
         */

        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/apisunat-admin.css', array(), $this->version, 'all');
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts($order_id)
    {

        /**
         * This function is provided for demonstration purposes only.
         *
         * An instance of this class should be passed to the run() function
         * defined in Apisunat_Loader as all of the hooks are defined
         * in that particular class.
         *
         * The Apisunat_Loader will then create the relationship
         * between the defined hooks and the functions defined in this
         * class.
         */
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/apisunat-admin.js', array('jquery'), $this->version, false);
        wp_localize_script($this->plugin_name, 'apisunat_ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    }

}