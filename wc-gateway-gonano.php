<?php
/**
 * Payment Gateway for Gonano on WooCommerce
 *
 * @package   WC-Gateway-Gonano
 * @author    Hector Chu
 * @copyright 2021 Hector Chu
 * @license   GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Payment Gateway for Gonano on WooCommerce
 * Plugin URI:  https://gonano.dev
 * Version:     0.1.7
 * Description: Accept payments in NANO via Gonano Payments
 * Author:      Hector Chu
 * Author URI:  https://github.com/hectorchu
 * License:     GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wc-gateway-gonano
 * Domain Path: /languages
 * Requires at least: 4.9
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

add_filter('woocommerce_payment_gateways', function($gateways) {
    $gateways[] = 'WC_Gateway_Gonano';
    return $gateways;
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $url = add_query_arg(array(
        'page'    => 'wc-settings',
        'tab'     => 'checkout',
        'section' => 'wc_gateway_gonano',
    ), admin_url('admin.php'));
    $text = __('Configure', 'wc-gateway-gonano');
    return array_merge(array("<a href=\"$url\">$text</a>"), $links);
});

add_action('plugins_loaded', function() {
    class WC_Gateway_Gonano extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                   = 'WC_Gateway_Gonano';
            $this->has_fields           = false;
            $this->method_title         = __('Gonano Payments', 'wc-gateway-gonano');
            $this->method_description   = __('Accept cryptocurrency payments in NANO.', 'wc-gateway-gonano');
            $this->order_button_text    = __('Pay with NANO', 'wc-gateway-gonano');
            $this->view_transaction_url = 'https://nanolooker.com/block/%s';

            $this->icon = apply_filters('woocommerce_gateway_icon', plugin_dir_url(__FILE__) . 'assets/icon.png');

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->api_url     = $this->get_option('api_url', 'https://gonano.dev');
            $this->account     = $this->get_option('account', '');
            $this->multiplier  = $this->get_option('multiplier', '1.00');

            add_action("woocommerce_update_options_payment_gateways_$this->id", array($this, 'process_admin_options'));
            add_action('woocommerce_api_' . strtolower($this->id), array($this, 'payment_callback'));
            add_action('woocommerce_order_status_failed', array($this, 'cancel_payment'));
            add_action('woocommerce_order_status_cancelled', array($this, 'cancel_payment'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
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
                'api_url' => array(
                    'title'       => __('API URL', 'wc-gateway-gonano'),
                    'type'        => 'text',
                    'description' => __('The payment server API base URL.', 'wc-gateway-gonano'),
                    'default'     => 'https://gonano.dev',
                    'desc_tip'    => true,
                ),
                'account' => array(
                    'title'       => __('NANO Account', 'wc-gateway-gonano'),
                    'type'        => 'text',
                    'description' => __('Account for receiving any sent NANO', 'wc-gateway-gonano'),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'multiplier' => array(
                    'title'       => __('Price Multiplier', 'wc-gateway-gonano'),
                    'type'        => 'text',
                    'description' => __('Apply a discount/markup at checkout', 'wc-gateway-gonano'),
                    'default'     => '1.00',
                    'desc_tip'    => true,
                ),
            );
        }

        private function get_data($url) {
            $resp = wp_remote_get($url);
            $body = '';
            $err = '';

            if (is_wp_error($resp)) {
                $err = "Error making request to $url: " . $resp->get_error_message();
            } else {
                $resp_code = wp_remote_retrieve_response_code($resp);
                $body = wp_remote_retrieve_body($resp);

                if ($resp_code != 200) {
                    $err = "Error making request to $url: $body";
                }
            }
            return array($body, $err);
        }

        private function post_data($url, $body) {
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
            $amount = $order->get_total();
            $currency = get_woocommerce_currency();

            $this->cancel_payment($order_id);

            if ($currency != 'NANO') {
                list($result, $err) = $this->get_data(add_query_arg(array(
                    'amount'   => $amount,
                    'currency' => $currency,
                ), "$this->api_url/rates/"));

                if ($err) {
                    $order->update_status('failed', $err);
                    return array('result' => 'failure');
                }
                $amount = (string)round($result, 6);
            }
            $amount = (string)round($amount * $this->multiplier, 6);

            list($result, $err) = $this->post_data("$this->api_url/payment/new",
                                  array('account' => $this->account, 'amount' => $amount));
            if ($err) {
                $order->update_status('failed', $err);
                return array('result' => 'failure');
            }

            $order->update_meta_data('_gonano_account', $this->account);
            $order->update_meta_data('_gonano_amount', $amount);
            $order->update_meta_data('_gonano_payment_id', $result->id);
            $order->save_meta_data();

            $callback = add_query_arg('key', $order->get_order_key(), home_url("wc-api/$this->id"));

            return array(
                'result'   => 'success',
                'redirect' => add_query_arg(array(
                    'api_url'    => urlencode($this->api_url),
                    'title'      => urlencode($this->title),
                    'account'    => $result->account,
                    'amount'     => $amount,
                    'currency'   => $currency,
                    'payment_id' => $result->id,
                    'on_success' => urlencode($callback),
                    'on_error'   => urlencode($callback),
                ), "$this->api_url/checkout/")
            );
        }

        public function payment_callback() {
            $order_id = wc_get_order_id_by_order_key(sanitize_key($_GET['key']));
            if (!$order_id) return;
            $order = wc_get_order($order_id);
            $payment_id = $order->get_meta('_gonano_payment_id');

            $err = sanitize_text_field($_GET['err']);
            if (!$err) {
                list($result, $err) = $this->post_data("$this->api_url/payment/status", array('id' => $payment_id));
                if (!$err && !$result->block_hash) {
                    $err = 'Payment not fulfilled';
                }
            }

            if ($err) {
                $order->update_status('failed', $err);
            } else {
                $order->payment_complete($result->block_hash);
            }
            wp_redirect($this->get_return_url($order));
        }

        public function cancel_payment($order_id) {
            $order = wc_get_order($order_id);
            $payment_id = $order->get_meta('_gonano_payment_id');

            if (isset($payment_id)) {
                $this->post_data("$this->api_url/payment/cancel", array('id' => $payment_id));
            }
        }
    }
});

add_filter('woocommerce_currencies', function($currencies) {
    $currencies['NANO'] = 'NANO';
    return $currencies;
});

add_filter('woocommerce_currency_symbol', function($currency_symbol, $currency) {
    if ($currency == 'NANO') return 'NANO ';
    return $currency_symbol;
}, 10, 2);
