<?php
if (!class_exists('msPaymentInterface')) {
	require_once dirname(dirname(dirname(__FILE__))) . '/model/minishop2/mspaymenthandler.class.php';
}

class PayPal extends msPaymentHandler implements msPaymentInterface {

	function __construct(xPDOObject $object, $config = array()) {
		$this->modx = & $object->xpdo;

		$siteUrl = $this->modx->getOption('site_url');
		$assetsUrl = $this->modx->getOption('minishop2.assets_url', $config, $this->modx->getOption('assets_url').'components/minishop2/');
		$paymentUrl = $siteUrl . substr($assetsUrl, 1) . 'payment/paypal.php';

		$this->config = array_merge(array(
			'paymentUrl' => $paymentUrl
			,'apiUrl' => $this->modx->getOption('ms2_payment_paypal_api_url', null, 'https://api-3t.paypal.com/nvp')
			,'checkoutUrl' => $this->modx->getOption('ms2_payment_paypal_checkout_url', null, 'https://www.paypal.com/webscr?cmd=_express-checkout&token=')
			,'currency' => $this->modx->getOption('ms2_payment_paypal_currency', null, 'USD')
			,'user' => $this->modx->getOption('ms2_payment_paypal_user')
			,'password' => $this->modx->getOption('ms2_payment_paypal_pwd')
			,'signature' => $this->modx->getOption('ms2_payment_paypal_signature')
			,'json_response' => false
		), $config);
	}


	/* @inheritdoc} */
	public function send(msOrder $order) {
		$params = array(
			'METHOD' => 'SetExpressCheckout'
			,'PAYMENTREQUEST_0_CURRENCYCODE' => $this->config['currency']
			,'PAYMENTREQUEST_0_ITEMAMT' => $order->get('cart_cost')
			,'PAYMENTREQUEST_0_SHIPPINGAMT' => $order->get('delivery_cost')
			,'PAYMENTREQUEST_0_AMT' => $order->get('cost')
			,'RETURNURL' => $this->config['paymentUrl'] . '?action=success'
			,'CANCELURL' => $this->config['paymentUrl'] . '?action=cancel'
			,'PAYMENTREQUEST_0_INVNUM' => $order->get('id')
		);

		/* @var msOrderProduct $item */
		$i = 0;
		if ($this->modx->getOption('ms2_payment_paypal_order_details', null, true)) {
			$products = $order->getMany('Products');
			foreach ($products as $item) {
				/* @var msProduct $product */
				if ($product = $item->getOne('Product')) {
					$params['L_PAYMENTREQUEST_0_NAME'.$i] = $product->get('pagetitle');
					$params['L_PAYMENTREQUEST_0_AMT'.$i] = $item->get('price');
					$params['L_PAYMENTREQUEST_0_QTY'.$i] = $item->get('count');
					$i++;
				}
			}
		}

		$response = $this->request($params);
		if (is_array($response) && !empty($response['ACK']) && $response['ACK'] == 'Success') {
			$token = $response['TOKEN'];
			return $this->success('', array('redirect' => $this->config['checkoutUrl'] . urlencode($token)));
		}
		else {
			$this->modx->log(modX::LOG_LEVEL_ERROR, '[miniShop2] Payment error while request. Request: ' . print_r($params, 1) .', response: ' . print_r($response, 1));
			return $this->success('', array('msorder' => $order->get('id')));
		}
	}


	/* @inheritdoc} */
	public function receive(msOrder $order, $params = array()) {
		/* @var miniShop2 $miniShop2 */
		$miniShop2 = $this->modx->getService('miniShop2');
		if (!empty($params['PAYERID'])) {
			$params = array(
				'METHOD' => 'DoExpressCheckoutPayment'
				,'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale'
				,'PAYMENTREQUEST_0_AMT' => $params['PAYMENTREQUEST_0_AMT']
				,'PAYERID' => $params['PAYERID']
				,'TOKEN' => $params['TOKEN']
			);
			$response = $this->request($params);

			if (!empty($response['ACK']) && $response['ACK'] == 'Success') {
				$miniShop2->changeOrderStatus($order->get('id'), 2); // Setting status "paid"
			}
			else {
				$this->modx->log(modX::LOG_LEVEL_ERROR, '[miniShop2] Could not finalize operation: Request: ' . print_r($params, 1) .', response: ' . print_r($response, 1));
			}
		}
		else if ($this->modx->getOption('ms2_payment_paypal_cancel_order', null, false)) {
			$miniShop2->changeOrderStatus($order->get('id'), 4); // Setting status "cancelled"
		}

		return true;
	}


	/**
	 * Building query
	 *
	 * @param array $params Query params
	 * @return array/boolean
	 */
	public function request($params = array()) {
		$requestParams = array_merge(array(
			'USER' => $this->config['user']
			,'PWD' => $this->config['password']
			,'SIGNATURE' => $this->config['signature']
			,'VERSION' => '74.0'
		), $params);

		$request = http_build_query($requestParams);
		$curlOptions = array (
			CURLOPT_URL => $this->config['apiUrl']
			,CURLOPT_VERBOSE => 1
			,CURLOPT_SSL_VERIFYPEER => true
			,CURLOPT_SSL_VERIFYHOST => 2
			,CURLOPT_CAINFO => dirname(__FILE__) . '/lib/paypal/cacert.pem'
			,CURLOPT_RETURNTRANSFER => 1
			,CURLOPT_POST => 1
			,CURLOPT_POSTFIELDS => $request
		);

		$ch = curl_init();
		curl_setopt_array($ch, $curlOptions);

		$response = curl_exec($ch);
		if (curl_errno($ch)) {
			$result = curl_error($ch);
		}
		else {
			$result = array();
			parse_str($response, $result);
		}

		curl_close($ch);
		return $result;
	}
}