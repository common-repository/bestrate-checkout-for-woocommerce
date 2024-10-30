<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once 'BR_token.php';

/**
 * @return string
 */
function BRWoo_api_endpoint()
{
    return 'https://api.bestrate.org/api/v2';
}

/**
 * @return string
 */
function BRWoo_endpoint()
{
    return 'https://bestrate.org';
}

/**
 * @return string
 */
function BRWoo_generateID()
{
    return md5(mktime());
}

/**
 * @param $params
 * @return string
 */
function BRWoo_generate_private_hash($params)
{
    return md5($params['amount']);
}

/**
 * @param $data
 * @param $secret
 * @param $timestamp
 * @return string
 */
function _BRWoo_generate_hash($data, $secret, $timestamp)
{
    return md5($data['amount'] . $data['currency'] . $data['shop_order_id'] . 'woocommerce' . $secret . $timestamp);
}

/**
 * @param $id
 * @param $key
 * @return string
 */
function _BRWoo_generate_redirect_url($id, $key)
{
    return BRWoo_endpoint() . "/pg-invoice/?" . http_build_query(
        array(
            'id' => $id,
            'key' => $key,
        )
    );
}

/**
 * @param $data
 * @return string
 * @throws Exception
 */
function BRWoo_create_redirect_url($data)
{

    $public = BRWoo_public_token();
    $secret = BRWoo_private_token();

    $timestamp = time();
    $id = md5($timestamp);

    $hash = _BRWoo_generate_hash($data, $secret, $timestamp);

    $params = array_merge(
        $data,
        array(
            'timestamp' => $timestamp,
        )
    );

    $get_currency_body = [
        'id' => $id,
        'jsonrpc' => '2.0',
        'method' => 'cabinet.payment-gateway.account.get',
        'params' => [
            'account_uuid' => 'ef9ce8d2-d959-4e3b-9c3f-faec23eb3324',
        ],
    ];
    $request_get_currency = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Public-Key' => $public,
            'Signature' => $hash,
            'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6NzgwOSwiY3JlYXRlZEF0IjoiMjAyMC0wNC0xMFQxNzo0MDo1NS4wMDBaIiwidXBkYXRlZEF0IjoiMjAyMC0wNC0xMFQxNzo0MTo0Mi4wMDBaIiwiaWF0IjoxNTk3ODMyNzk0fQ.zMslzHMrnLBv7dl5FcjYccy8dTTcDnXpR2gxBcvLa2Q',

        ],
        'body' => json_encode($get_currency_body),
        'method' => 'POST',
    ];

    $result_get_currency = wp_remote_post(
        BRWoo_api_endpoint(), $request_get_currency
    );

    if (is_wp_error($result_get_currency)) {
        $error_message = $result_get_currency->get_error_message();
        echo "Something went wrong: $error_message";
        throw new Exception($error_message);
    }

    if ($result_get_currency['response']['code'] !== 200) {
        throw new Exception(key_exists('message', $result['response']) ? $result_get_currency['response']['message'] : 'Unknown error');
    }
    $currency = '';
    if (key_exists('body', $result_get_currency)) {
        $responseBody = json_decode($result_get_currency['body'], true);
        $currency = $responseBody['result']['currency'];
    }

    $body = array(
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'payment-gateway.order.create',
        'params' => $params,
    );

    $request = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Public-Key' => $public,
            'Signature' => $hash,
        ),
        'body' => json_encode($body),
        'method' => 'POST',
    );

    // @TODO check settings
    // throw new Exception(__('BestRate gateways unavailable.', 'wc-bestrate'));

    $result = wp_remote_post(
        BRWoo_api_endpoint(), $request
    );

    if (!key_exists('response', $result)
        || !key_exists('code', $result['response'])
        || $result['response']['code'] !== 200) {
        throw new Exception(
            key_exists('message', $result['response'])
            ? $result['response']['message']
            : 'Something went wrong'
        );
    }

    if (key_exists('body', $result)) {

        $responseBody = json_decode($result['body'], true);

        if (key_exists('error', $responseBody)) {
            throw new Exception(
                key_exists('message', $responseBody['error'])
                ? $responseBody['error']['message']
                : 'Something went wrong'
            );
        }

        if ($currency == '1gold') {
            $params = array(
                'order_uuid' => $responseBody['result']['order_uuid'],
                'currency' => '1gold',
            );

            $body = array(
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'payment-gateway.order.pay',
                'params' => $params,
            );

            $request = array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Public-Key' => $public,
                    'Signature' => $hash,
                ),
                'body' => json_encode($body),
                'method' => 'POST',
            );

            $result = wp_remote_post(
                BRWoo_api_endpoint(), $request
            );

            if (key_exists('body', $result)) {
                $response_payment = json_decode($result['body'], true);
                if (key_exists('error', $response_payment)) {
                    throw new Exception(key_exists('message', $response_payment['error'])
                        ? $response_payment['error']['message']
                        : 'Something went wrong');
                }
                return "https://bestrate.org/transaction-info/?id=pg-" . $responseBody['result']['order_uuid'];

            }

        }

        if (key_exists('result', $responseBody) && key_exists('order_uuid', $responseBody['result'])) {
            return _BRWoo_generate_redirect_url($responseBody['result']['order_uuid'], $public);
        }

    }

    throw new Exception(
        key_exists('message', $result['response'])
        ? $result['response']['message']
        : 'Something went wrong'
    );

}
