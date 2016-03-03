<?php

class VoidService extends PaymentService{

	/**
	 * Call omnipay to cancel payment
	 */
	public function void($data = array()) {

		// get payment info and transactionreference
		// some use more messages for "complete" methods, while others, like Stripe, do it without "complete"
		$msg = $this->payment->Messages()->filter(array('ClassName' => 'AuthorizedResponse'))->Last();

		$gatewaydata = array_merge($data, array(
			'amount' => (float) $this->payment->MoneyAmount,
			'transactionId' => $this->payment->OrderID, // Why is this so neccesary to have? answer = omnipay wants it
			'notifyUrl' => $this->getEndpointURL("cancel", $this->payment->Identifier)
		));

		// get gateway
		// call cancel method on the gateway
		$request = $this->oGateway()->cancel($gatewaydata);

		$transactionRef = null;
		if($msg){
			$transactionRef = $msg->Reference;
		}
		if($transactionRef){
			$request->setTransactionReference($transactionRef);
		}

		$this->createMessage('VoidRequest', $request);

		$response = null;
		try {
			return $request->send();
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("CaptureError", $e);
		}
		return false;
	}

	/**
	 * Update our system that the payment was cancelled
	 *
	 */
	public function completeVoid($data = array()){

		$gatewayresponse = $this->createGatewayResponse();

		//set the client IP address, if not already set
		if(!isset($data['clientIp'])){
			$data['clientIp'] = Controller::curr()->getRequest()->getIP();
		}

		$gatewaydata = array_merge($data, array(
			'amount' => (float) $this->payment->MoneyAmount,
			'currency' => $this->payment->MoneyCurrency
		));

		$this->payment->extend('onBeforeCompleteCancel', $gatewaydata);
		$request = $this->oGateway()->completeCancel($gatewaydata);
		$this->payment->extend('onAfterCompleteCancel', $request);

		$this->createMessage('CompleteVoidRequest', $request);
		$response = null;
		try {
			$response = $this->response = $request->send();
			$this->extend('onAfterSendCompleteCancel', $request, $response);
			$gatewayresponse->setOmnipayResponse($response);
			if ($response->isSuccessful()) {
				$this->createMessage('VoidedResponse', $response);
				$this->payment->Status = "Void";
				$this->payment->write();

				$this->payment->extend('onVoid', $gatewayresponse);
			} else {
				// throw error msg
				$this->createMessage('VoidError', $response);
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("VoidError", $e);
		}

		return $gatewayresponse;

	}

}
