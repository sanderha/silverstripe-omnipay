<?php

use Omnipay\Common\CreditCard;

class AuthorizeCaptureService extends PaymentService{

	/**
	 * Initiate the authorisation process for on-site and off-site gateways.
	 * @param  array $data returnUrl/cancelUrl + customer creditcard and billing/shipping details.
	 * @return ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function authorize($data = array()) {
		if ($this->payment->Status !== "Created") {
			return null; //could be handled better? send payment response?
		}
		if (!$this->payment->isInDB()) {
			$this->payment->write();
		}
		//update success/fail urls
		$this->update($data);

		//set the client IP address, if not already set
		if(!isset($data['clientIp'])){
			$data['clientIp'] = Controller::curr()->getRequest()->getIP();
		}

		$gatewaydata = array_merge($data, array(
			'amount' => (float) $this->payment->MoneyAmount,
			'currency' => $this->payment->MoneyCurrency,
			//set all gateway return/cancel/notify urls to PaymentGatewayController endpoint
			'returnUrl' => $this->getEndpointURL("complete", $this->payment->Identifier),
			'cancelUrl' => $this->getEndpointURL("cancel", $this->payment->Identifier),
			'notifyUrl' => $this->getEndpointURL("notify", $this->payment->Identifier)
		));

		// Often, the shop will want to pass in a transaction ID (order #, etc), but if there's
		// not one we need to set it as Ominpay requires this.
		if(!isset($gatewaydata['transactionId'])){
			$gatewaydata['transactionId'] = $this->payment->Identifier;
		}

		// We only look for a card if we aren't already provided with a token
		// Increasingly we can expect tokens or nonce's to be more common (e.g. Stripe and Braintree)
		if (empty($gatewaydata['token'])) {
			$gatewaydata['card'] = $this->getCreditCard($data);
		}

		$this->extend('onBeforeAuthorize', $gatewaydata);
		$request = $this->oGateway()->authorize($gatewaydata);
		$this->extend('onAfterAuthorize', $request);

		$message = $this->createMessage('AuthorizeRequest', $request);
		$message->SuccessURL = $this->returnurl;
		$message->FailureURL = $this->cancelurl;
		$message->write();

		$gatewayresponse = $this->createGatewayResponse();
		try {
			$response = $this->response = $request->send();
			$this->extend('onAfterSendAuthorize', $request, $response);
			$gatewayresponse->setOmnipayResponse($response);
			//update payment model
			if (GatewayInfo::is_manual($this->payment->Gateway)) {
				//initiate manual payment
				$this->createMessage('AuthorizedResponse', $response);
				$this->payment->Status = 'Authorized';
				$this->payment->write();
				$gatewayresponse->setMessage("Manual payment authorised");
			} elseif ($response->isSuccessful()) {
				//successful payment
				$this->createMessage('AuthorizedResponse', $response);
				$this->payment->Status = 'Authorized';
				$gatewayresponse->setMessage("Payment authorized");
				$this->payment->write();

			} elseif ($response->isRedirect()) {
				// redirect to off-site payment gateway
				// Make sure payment is just set to pending, because we havent handled callbacks yet
				$this->createMessage('AuthorizeRedirectResponse', $response);
				$this->payment->Status = 'Pending Authorization';
				$this->payment->write();
				$gatewayresponse->setMessage("Redirecting to gateway");
			} else {
				//handle error
				$this->createMessage('AuthorizeError', $response);
				$gatewayresponse->setMessage(
					"Error (".$response->getCode()."): ".$response->getMessage()
				);
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage('AuthorizeError', $e);
			$gatewayresponse->setMessage($e->getMessage());
		}
		$gatewayresponse->setRedirectURL($this->getRedirectURL());

		return $gatewayresponse;
	}

	/**
	 * Complete authorisation, after off-site external processing.
	 * This is ususally only called by PaymentGatewayController.
	 * @return PaymentResponse encapsulated response info
	 */
	public function completeAuthorize($data = array()) {
		$gatewayresponse = $this->createGatewayResponse();

		//set the client IP address, if not already set
		if(!isset($data['clientIp'])){
			$data['clientIp'] = Controller::curr()->getRequest()->getIP();
		}

		$gatewaydata = array_merge($data, array(
			'amount' => (float) $this->payment->MoneyAmount,
			'currency' => $this->payment->MoneyCurrency
		));

		$this->payment->extend('onBeforeCompletePurchase', $gatewaydata);
		$request = $this->oGateway()->completeAuthorize($gatewaydata);
		$this->payment->extend('onAfterCompletePurchase', $request);

		$this->createMessage('CompleteAuthorizeRequest', $request);
		$response = null;
		try {
			$response = $this->response = $request->send();
			$this->extend('onAfterSendCompletePurchase', $request, $response);
			$gatewayresponse->setOmnipayResponse($response);
			if ($response->isSuccessful()) {
				$this->createMessage('AuthorizedResponse', $response);
				$this->payment->Status = 'Authorized';
				$this->payment->write();

				$this->payment->extend('onAuthorized', $response);
			} else {
				if($this->payment->Status != 'Authorized'){
					$this->createMessage('AuthorizedResponse', $response);
					$this->payment->Status = 'Pending Authorization';
					$this->payment->write();

					$this->payment->extend('onPendingAuthorize', $response);
				}
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("CompleteAuthorizeError", $e);
			$gatewayresponse->setMessage($e->getMessage());
		}

		return $gatewayresponse;
	}

	/**
	 * Do the capture of money on authorised credit card. Money exchanges hands.
	 * @return ResponseInterface encapsulated response info
	 */
	public function capture($data = array()) {

		$gatewayresponse = $this->createGatewayResponse();

		// get payment info and transactionreference
		// some use more messages for "complete" methods, while others, like Stripe, do it without "complete"
		$msg = $this->payment->Messages()->filter(array('ClassName' => 'AuthorizedResponse'))->Last();

		// make sure that values form $data can overwrite when merging arrays
		$gatewaydata = array_merge(array(
			'amount' => (float) $this->payment->MoneyAmount,
			'transactionId' => $this->payment->OrderID, // Why is this so neccesary to have?
			'notifyUrl' => $this->getEndpointURL("capture", $this->payment->Identifier)
		), $data);

		// get gateway
		// call capture method on the gateway
		$request = $this->oGateway()->capture($gatewaydata);

		$transactionRef = null;
		if($msg){
			$transactionRef = $msg->Reference;
		}
		if($transactionRef){
			$request->setTransactionReference($transactionRef);	
		}

		$this->createMessage('CaptureRequest', $request);
		// get response to make sure it is paid.
		$response = null;
		try {
			$response = $this->response = $request->send();

		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("CaptureError", $e);
			$gatewayresponse->setMessage($e->getMessage());
		}

		return $gatewayresponse;
	}

	/**
	 * Complete capture, after a callback returns from the payment processor.
	 * This is ususally only called by PaymentGatewayController.
	 * @return PaymentResponse encapsulated response info
	 */
	public function completeCapture($data = array()) {
		$gatewayresponse = $this->createGatewayResponse();

		//set the client IP address, if not already set
		if(!isset($data['clientIp'])){
			$data['clientIp'] = Controller::curr()->getRequest()->getIP();
		}

		$gatewaydata = $data;

		// completecapture exists to varify the callback.
		$this->payment->extend('onBeforeCompleteCapture', $gatewaydata);
		$request = $this->oGateway()->completeCapture($gatewaydata);
		$this->payment->extend('onAfterCompleteCapture', $request);

		$this->createMessage('CompleteCaptureRequest', $request);
		$response = null;
		try {
			$response = $this->response = $request->send();
			$this->extend('onAfterSendCompleteCapture', $request, $response);
			$gatewayresponse->setOmnipayResponse($response);
			if ($response->isSuccessful()) {
				$this->createMessage('CompleteCaptureResponse', $response);

				$this->payment->Status = 'Captured';
				$this->payment->write();

				// we want direct access to the $response here. $gatewayresponse doesnt give all the methods on that specific driver
				$this->payment->extend('onCaptured', $response);
			} else {
				// throw error msg
				$this->createMessage('CaptureError', $response);
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("CaptureError", $e);
			$gatewayresponse->setMessage($e->getMessage());
		}

		return $gatewayresponse;
	}

	/**
	 * @return \Omnipay\Common\CreditCard
	 */
	protected function getCreditCard($data) {
		return new CreditCard($data);
	}

	public function cancelAuthorize(){
		// connect to Quickpay and cancel payment
		$this->payment->Status = 'Void';
		$this->payment->write();
		$this->createMessage('VoidRequest', array(
			"Message" => "The payment was cancelled."
		));
	}

}
