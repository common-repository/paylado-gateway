<?php
/*
 * Plugin Name: Paylado Gateway
 * Plugin URI: https://www.paylado.com
 * Description: Accept paylado payments
 * Author: EPG Malta
 * Version: 1.0.0
*/

add_filter('woocommerce_payment_gateways', 'paylado_add_gateway_class');
function paylado_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Paylado_Gateway';
    return $gateways;
}


add_action('plugins_loaded', 'paylado_init_gateway_class');
function paylado_init_gateway_class()
{

    class WC_Paylado_Gateway extends WC_Payment_Gateway
    {

        public function __construct()
        {

            $this->id = 'paylado'; // payment gateway plugin ID
            $this->icon = 'https://pay.paylado.com/assets/payicon/paylado-400.png';
            $this->has_fields = true;
            $this->method_title = 'Paylado Gateway';
            $this->method_description = '<img src="'.$this->icon.'" title="paylado logo"/><br>In order to start accepting payments via paylado, you need to have a merchant account. <br>Please visit <a href="https://paylado.com" target="_blank">paylado.com</a> to get your merchant account.'; 
            $this->websiteURL= get_site_url();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->payladoDomain = $this->testmode ? 'https://pay-qs.paylado.com/' : 'https://pay.paylado.com/';
            $this->TransactionType = 'Sale';
            $this->Currency = 'EUR';
            $this->PayladoURL =$this->payladoDomain.'tokenizer/get';
            $this->PayladoCheckURL = $this->payladoDomain.'tokenizer/getresult';
            $this->ReturnUrl =    $this->websiteURL.'/wc-api/paylado_done'; 
            $this->TransactionUrl = 'https://paylado.com/transaction_url'; 
            $this->MerchantId = $this->testmode ? $this->get_option('test_merchantId') : $this->get_option('merchantId');
            $this->MerchantGuid = $this->testmode ? $this->get_option('test_merchantGuId') : $this->get_option('merchantGuId');
            $this->init_form_fields();
            $this->init_settings();
        
            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));


            add_action('woocommerce_api_paylado_done', array(
                $this,
                'webhook'
            ));

        }

        /**
         * Plugin options
         */
        public function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Paylado Gateway',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ) ,
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Paylado',
                    'desc_tip' => true,
                ) ,
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay with your paylado application via our payment gateway.',
                ) ,
                'testmode' => array(
                    'title' => 'Test mode',
                    'label' => 'Enable Test Mode',
                    'type' => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test credentials.',
                    'default' => 'yes',
                    'desc_tip' => true,
                ) ,
                'test_merchantId' => array(
                    'title' => 'Test MerchantId Key',
                    'type' => 'text'
                ) ,
                'test_merchantGuId' => array(
                    'title' => 'Test MerchantGuId Key',
                    'type' => 'text',
                ) ,
                'merchantId' => array(
                    'title' => 'Live MerchantId Key',
                    'type' => 'text'
                ) ,
                'merchantGuId' => array(
                    'title' => 'Live MerchantGuId Key',
                    'type' => 'text'
                ) ,
                // 'return_url' => array(
                //     'title'       => 'Return URL',
                //     'type'        => 'text',
                //     'description' => 'To which URL the user shall be taken after finishing or cancelling the payment',
                // ),
                // 'TransactionUrl' => array(
                //     'title' => 'Transaction URL',
                //     'type' => 'text',
                //     'description' => 'Which URL should be displayed on the paylado gateway',
                // ) ,
            );

        }


        /*
         * We're processing the payments here
        */
        public function process_payment($orderId)
        {
            $order = wc_get_order($orderId);
            $args = array(
                'method' => 'POST',
                'headers' => array(
                    'Content-type: application/x-www-form-urlencoded'
                ) ,
                'body' => array(
                    'MerchantId' => intval($this->MerchantId) ,
                    'MerchantGuid' => $this->MerchantGuid,
                    'ReturnUrl' => $this->ReturnUrl . '?id=' . $orderId,
                    'TransactionUrl' => 'Not needed',
                    'TransactionType' => $this->TransactionType,
                    'Currency' => $this->Currency,
                    'Amount' => $order->get_total() ,
                    'FirstName' => $order->get_billing_first_name() ,
                    'LastName' => $order->get_billing_last_name() ,
                    'Email' => $order->get_billing_email()
                )
            );
            $response = wp_remote_post($this->PayladoURL, $args);
            if (!is_wp_error($response))
            {
                $body = json_decode($response['body'], true);

                if ($body['ResultStatus'] == 'OK')
                {

                    return array(
                        'result' => 'success',
                        'redirect' => $body['RedirectUrl']
                    );

                }
                else
                {
                    wc_add_notice($body['ResultMessage'], 'error');
                    return;
                }

            }
            else
            {
                wc_add_notice('Connection error.', 'error');
                return;
            }

        }

        private function update_order($order, $token){
            $args = array(
                'method' => 'POST',
                'headers' => array(
                    'Content-type: application/x-www-form-urlencoded'
                ) ,
                'body' => array(
                    'MerchantId' => intval($this->MerchantId) ,
                    'MerchantGuid' => $this->MerchantGuid,
                    'Token' =>  $token,
                    'Format' => 'json'
                )
            );


            $response = wp_remote_post($this->PayladoCheckURL, $args);
        
            if (!is_wp_error($response))
            {
                $body = json_decode($response['body'], true);
                if ($body['ResultStatus'] == 'OK')
                {
                    $order->payment_complete();
                    $order->reduce_order_stock();
                    return wp_redirect($this->get_return_url($order));
                    exit;
                }
                else
                {
                    wc_add_notice($body['ResultMessage'], 'error');
                    exit;
                }
            }

        }

        public function webhook()
        {
            
            $order = wc_get_order(sanitize_text_field( $_GET['id'] ));
            $token = sanitize_text_field( $_GET['Token'] );
            $this->update_order($order, $token);
        }
    }
}