<?php
/**
 * Gonano Payment Gateway
 *
 * @package   Gonano-Payment-Gateway
 * @author    Hector Chu
 * @copyright 2021 Hector Chu
 * @license   MIT
 *
 * @wordpress-plugin
 * Plugin Name: Gonano Payment Gateway
 * Plugin URI:  https://gonano.dev
 * Description: Accept payments in NANO via Gonano Payments
 * Version:     0.1.0
 * Author:      Hector Chu
 * Author URI:  https://github.com/hectorchu
 * License:     MIT
 * Text Domain: gonano-payment-gateway
 * Domain Path: /languages
 */

defined('ABSPATH') or exit;

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

function wc_gonano_add_to_gateways($gateways) {
    $gateways[] = 'WC_Gateway_Gonano';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_gonano_add_to_gateways');

function wc_gateway_gonano_plugin_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_gateway_gonano') . '">' . __('Configure', 'wc-gateway-gonano') . '</a>'
    );
    return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_gateway_gonano_plugin_links');

add_action('plugins_loaded', 'wc_gateway_gonano_init');

function wc_gateway_gonano_init() {
    class WC_Gateway_Gonano extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'WC_Gateway_Gonano';
            $this->has_fields         = false;
            $this->method_title       = __('Gonano Payments', 'wc-gateway-gonano');
            $this->method_description = __('Accept cryptocurrency payments in NANO.', 'wc-gateway-gonano');
            $this->order_button_text  = __('Pay with NANO', 'wc-gateway-gonano');

            $this->init_form_fields();
            $this->init_settings();

            $this->title        = $this->get_option('title');
            $this->description  = $this->get_option('description');

            add_action("woocommerce_update_options_payment_gateways_$this->id", array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_gateway_gonano', array($this, 'payment_callback'));
        }

        public function init_form_fields() {
            $this->form_fields = apply_filters('wc_gonano_form_fields', array(
                'enabled' => array(
                    'title'       => __('Enable/Disable', 'wc-gateway-gonano'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable Gonano Payments', 'wc-gateway-gonano'),
                    'default'     => 'yes'
                ),
                'title' => array(
                    'title'       => __('Title', 'wc-gateway-gonano'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'wc-gateway-gonano'),
                    'default'     => __('Gonano Payments', 'wc-gateway-gonano'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'wc-gateway-gonano'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'wc-gateway-gonano'),
                    'default'     => __('Pay via Gonano.', 'wc-gateway-gonano'),
                    'desc_tip'    => true,
                ),
                'account' => array(
                    'title'       => __('NANO Account', 'wc-gateway-gonano'),
                    'type'        => 'text',
                    'description' => __('Account for receiving any sent NANO', 'wc-gateway-gonano'),
                    'default'     => __('', 'wc-gateway-gonano'),
                    'desc_tip'    => true,
                ),
            ));
        }

        private function post_data($url, $body) {
            $url = esc_url_raw($url);
            $resp = wp_remote_post($url, array('body' => wp_json_encode($body)));
            $err = '';

            if (is_wp_error($resp)) {
                $err = "Error making request to $url: " . $resp->get_error_message();
            } else {
                $resp_code = wp_remote_retrieve_response_code($resp);
                $body = wp_remote_retrieve_body($resp);

                if ($resp_code == 200) {
                    $body = json_decode($body);
                } else {
                    $err = "Error making request to $url: $body";
                }
            }
            return array($body, $err);
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            $title = $this->settings['title'];
            $account = $this->settings['account'];
            $amount = $order->get_total();

            list($result, $err) = $this->post_data('https://gonano.dev/payment/new',
                                  array('account' => $account, 'amount' => $amount));
            if ($err) {
                $order->update_status('failed', $err);
                return array('result' => 'failure');
            }

            $order->update_meta_data('_gonano_payment_id', $result->id);
            $order->save_meta_data();

            $callback = urlencode(home_url("/wc-api/WC_Gateway_Gonano/?order_id=$order_id"));

            return array(
                'result'   => 'success',
                'redirect' => "https://gonano.dev/checkout/?title=$title&account=$result->account&amount=$amount&payment_id=$result->id&on_success=$callback&on_error=$callback"
            );
        }

        public function payment_callback() {
            $order_id = $_GET['order_id'];
            $payment_id = $_GET['payment_id'];
            $err = $_GET['err'];

            $order = wc_get_order($order_id);

            if ($err) {
                $order->update_status('failed', $err);
                return wp_redirect($this->get_return_url($order));
            }

            if ($payment_id != $order->get_meta('_gonano_payment_id')) {
                $err = 'Payment ID mismatch';
            } else {
                list($result, $err) = $this->post_data('https://gonano.dev/payment/status', array('id' => $payment_id));
                if (!$err && !$result->block_hash) {
                    $err = 'Payment not fulfilled';
                }
            }

            if ($err) {
                $order->update_status('failed', $err);
                return wp_redirect($this->get_return_url($order));
            }

            $order->payment_complete();

            wp_redirect($this->get_return_url($order));
        }
    }
}

add_filter('woocommerce_currencies', 'wc_add_currency_nano');

function wc_add_currency_nano($currencies) {
    $currencies['NANO'] = __('NANO', 'woocommerce');
    return $currencies;
}

add_filter('woocommerce_currency_symbol', 'wc_add_currency_symbol_nano', 10, 2);

function wc_add_currency_symbol_nano($currency_symbol, $currency) {
    if ($currency == 'NANO') return 'NANO';
    return $currency_symbol;
}
