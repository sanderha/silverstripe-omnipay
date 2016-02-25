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

		$this->extend('onBeforePurchase', $gatewaydata);
		$request = $this->oGateway()->authorize($gatewaydata);
		$this->extend('onAfterPurchase', $request);

		$message = $this->createMessage('AuthorizeRequest', $request);
		$message->SuccessURL = $this->returnurl;
		$message->FailureURL = $this->cancelurl;
		$message->write();

		$gatewayresponse = $this->createGatewayResponse();
		try {
			$response = $this->response = $request->send();
			$this->extend('onAfterSendPurchase', $request, $response);
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
				$this->createMessage('AuthorizeRedirectResponse', $response);
				$this->payment->Status = 'Authorized';
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
	public function completeAuthorize() {
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
		$request = $this->oGateway()->completePurchase($gatewaydata);
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

				$this->payment->extend('onAuthorized', $gatewayresponse);
			} else {
				$this->createMessage('CompleteAuthorizeError', $response);
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("CompleteAuthorizeError", $e);
		}

		return $gatewayresponse;
	}

	/**
	 * Do the capture of money on authorised credit card. Money exchanges hands.
	 * @return PaymentResponse encapsulated response info
	 */
	public function capture() {
		//TODO

		$gatewayresponse = $this->createGatewayResponse();

		// get payment info and transactionreference
		$msg = $this->payment->Messages()->filter(array('ClassName' => 'AuthorizedResponse'))->First();
		$transactionRef = $msg->Reference;// reference field on GatewayMessage

		$gatewaydata = array(
			'amount' => (float) $this->payment->MoneyAmount,
			'transactionId' => $this->payment->OrderID // Why is this so neccesary to have?
		);

		// get gateway
		// call capture method on the gateway
		$request = $this->oGateway()->capture($gatewaydata)->setTransactionReference($transactionRef);

		$this->createMessage('CaptureRequest', $request);
		// get response to make sure it is paid.
		$response = null;
		try {
			$response = $this->response = $request->send();
			$gatewayresponse->setOmnipayResponse($response);
			if ($response->isSuccessful()) {
				// This $response should be CaptureResponse

				$this->createMessage('CapturedResponse', $response);
				$this->payment->Status = 'Captured';
				$this->payment->write();
				$this->payment->extend('onCaptured', $gatewayresponse);
			} else {
				$this->createMessage('CaptureError', $response);
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("CaptureError", $e);
		}

		return $gatewayresponse;


		// return reponse

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
