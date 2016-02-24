<?php

use Omnipay\Common\CreditCard;

class AuthorizeCaptureService extends PaymentService{

	/**
	 * Initiate the authorisation process for on-site and off-site gateways.
	 * @param  array $data returnUrl/cancelUrl + customer creditcard and billing/shipping details.
	 * @return ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function authorize($data = array()) {

		// the following code is copied from the purchase method, from the PurchaseService class, and has been modded to fit authorize method

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

		$gatewaydata = array_merge($data,array(
			'card' => $this->getCreditCard($data),
			'amount' => (float) $this->payment->MoneyAmount,
			'currency' => $this->payment->MoneyCurrency,
			//set all gateway return/cancel/notify urls to PaymentGatewayController endpoint
			'returnUrl' => $this->getEndpointURL("complete", $this->payment->Identifier),
			'cancelUrl' => $this->getEndpointURL("cancel", $this->payment->Identifier),
			'notifyUrl' => $this->getEndpointURL("notify", $this->payment->Identifier)
		));

		if(!isset($gatewaydata['transactionId'])){
			$gatewaydata['transactionId'] = $this->payment->Identifier;
		}

		$request = $this->oGateway()->authorize($gatewaydata);

		$message = $this->createMessage('AuthorizeRequest', $request);
		$message->SuccessURL = $this->returnurl;
		$message->FailureURL = $this->cancelurl;
		$message->write();

		$gatewayresponse = $this->createGatewayResponse();
		try {
			$response = $this->response = $request->send();
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
				$gatewayresponse->setMessage("Authorization of payment successful");
				$this->payment->write();
				$this->payment->extend('onAuthorized', $gatewayresponse);
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

		return $gatewayresponse; // Authorize response
	}

	/**
	 * Complete authorisation, after off-site external processing.
	 * This is ususally only called by PaymentGatewayController.
	 * @return PaymentResponse encapsulated response info
	 */
	public function completeAuthorize() {

		// the following code is copied from the purchase method, from the PurchaseService class, and has been modded to fit authorize method

		$gatewayresponse = $this->createGatewayResponse();

		//set the client IP address, if not already set
		if(!isset($data['clientIp'])){
			$data['clientIp'] = Controller::curr()->getRequest()->getIP();
		}

		$gatewaydata = array_merge($data, array(
			'amount' => (float) $this->payment->MoneyAmount,
			'currency' => $this->payment->MoneyCurrency
		));

		$this->payment->extend('onBeforeCompleteAuthorize', $gatewaydata);

		$request = $this->oGateway()->completeAuthorize($gatewaydata);
		$this->createMessage('CompleteAuthorizeRequest', $request);
		$response = null;
		try {
			$response = $this->response = $request->send();
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
	}

	/**
	 * @return \Omnipay\Common\CreditCard
	 */
	protected function getCreditCard($data) {
		return new CreditCard($data);
	}

	public function cancelAuthorize(){
		$this->payment->Status = 'Void';
		$this->payment->write();
		$this->createMessage('VoidRequest', array(
			"Message" => "The payment was cancelled."
		));
	}

}
