<?php

class RefundService extends PaymentService{

	/**
	 * Call omnipay to refund payment
	 */
	public function refund($data = array()) {

		// get payment info and transactionreference
		// some use more messages for "complete" methods, while others, like Stripe, do it without "complete"
		$msg = $this->payment->Messages()->filter(array('ClassName' => 'AuthorizedResponse'))->Last();

		$gatewaydata = array_merge($data, array(
			'amount' => (float) $this->payment->MoneyAmount,
			'transactionId' => $this->payment->OrderID // Why is this so neccesary to have? answer = omnipay wants it
		));
		if(!isset($gatewaydata['notifyUrl'])){
			$gatewaydata['notifyUrl'] = $this->getEndpointURL("refund", $this->payment->Identifier);
		}

		// get gateway
		// call cancel method on the gateway
		$request = $this->oGateway()->refund($gatewaydata);

		$transactionRef = null;
		if($msg){
			$transactionRef = $msg->Reference;
		}
		if($transactionRef){
			$request->setTransactionReference($transactionRef);
		}

		$this->createMessage('RefundRequest', $request);

		$response = null;
		try {
			return $request->send();
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("RefundError", $e);
		}
		return false;
	}

	/**
	 * Update our system that the payment was refunded
	 *
	 */
	public function completeRefund($data = array()){

		$gatewayresponse = $this->createGatewayResponse();

		//set the client IP address, if not already set
		if(!isset($data['clientIp'])){
			$data['clientIp'] = Controller::curr()->getRequest()->getIP();
		}

		$gatewaydata = array_merge($data, array(
			'amount' => (float) $this->payment->MoneyAmount,
			'currency' => $this->payment->MoneyCurrency
		));

		$this->payment->extend('onBeforeCompleteRefund', $gatewaydata);
		$request = $this->oGateway()->completeRefund($gatewaydata);
		$this->payment->extend('onAfterCompleteRefund', $request);

		$this->createMessage('CompleteRefundRequest', $request);
		$response = null;
		try {
			$response = $this->response = $request->send();
			$this->extend('onAfterSendCompleteRefund', $request, $response);
			$gatewayresponse->setOmnipayResponse($response);
			if ($response->isSuccessful()) {
				$this->createMessage('RefundedResponse', $response);
				$this->payment->Status = "Refunded";
				$this->payment->write();

				$this->payment->extend('onRefund', $response);
			} else {
				// throw error msg
				$this->createMessage('RefundError', $response);
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("RefundError", $e);
		}

		return $gatewayresponse;

	}

}
