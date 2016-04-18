<?php

/**
 * Wrapper for omnipay responses, which allow us to customise functionality
 *
 * @package payment
 */

use Omnipay\Common\Exception\RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse as HttpRedirectResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class GatewayResponse{

	/**
	 * @var Omnipay\Common\Message\AbstractResponse
	 */
	private $response;
	
	/**
	 * @var Payment
	 */
	private $payment;
	
	/**
	 * @var String Success message which can be exposed to the user
	 * if the payment was successful. Not persisted in database, so can't
	 * be used for offsite payment processing.
	 */
	private $message;
	
	/**
	 * @var String URL to an endpoint within SilverStripe that can process
	 * the response, usually {@link PaymentGatewayController}.
	 * This controller might further redirect the user, based on the
	 * $SuccessURL and $FailureURL messages in {@link GatewayRequestMessage}.
	 */
	private $redirect;

	public function __construct(Payment $payment) {
		$this->payment = $payment;
	}

	/**
	 * Check if the response indicates a successful gateway action
	 *
	 * @return boolean
	 */
	public function isSuccessful() {
		return $this->response && $this->response->isSuccessful();
	}

	/**
	 * Check if a redirect to an offsite gateway is required.
	 * Note that {@link redirect()} will still cause a redirect for onsite gateways,
	 * but in this case uses the provided {@link redirect} URL rather than asking the gateway
	 * on where to redirect.
	 * 
	 * @return boolean
	 */
	public function isRedirect() {
		return $this->response && $this->response->isRedirect();
	}

	/**
	 * @param Omnipay\Common\Message\AbstractResponse $response
	 */
	public function setOmnipayResponse(Omnipay\Common\Message\AbstractResponse $response) {
		$this->response = $response;

		return $this;
	}

	/**
	 * @return Omnipay\Common\Message\AbstractResponse
	 */
	public function getOmnipayResponse() {
		return $this->response;
	}

	/**
	 * See {@link $message}.
	 * 
	 * @param String $message
	 */
	public function setMessage($message) {
		$this->message = $message;

		return $this;
	}

	/**
	 * @return String
	 */
	public function getMessage() {
		return $this->message;
	}

	/**
	 * @return Payment
	 */
	public function getPayment() {
		return $this->payment;
	}

	/**
	 * See {@link $redirect}.
	 * 
	 * @param String $url
	 */
	public function setRedirectURL($url) {
		$this->redirect = $url;

		return $this;
	}

	/**
	 * Get the appropriate redirect url
	 */
	public function getRedirectURL() {
		return $this->redirect;
	}

	/**
	 * Do a straight redirect to the denoted {@link redirect} URL if the payment gateway is onsite.
	 * If the gateway is offsite, redirect the user to the gateway host instead.
	 * This redirect can take two forms: A straight URL with payment data transferred as GET parameters,
	 * or a self-submitting form with payment data transferred through POST.
	 *
	 * @return SS_HTTPResponse
	 */
	public function redirect() {
		if($this->response && $this->response->isRedirect()) {
			// Offsite gateway, use payment response to determine redirection,
			// either through GET with simep URL, or POST with a self-submitting form.
			$redirectOmnipayResponse = $this->getRedirectResponse();
			if($redirectOmnipayResponse instanceof Symfony\Component\HttpFoundation\RedirectResponse) {
				return Controller::curr()->redirect($redirectOmnipayResponse->getTargetUrl());	
			} else {
				return new SS_HTTPResponse((string)$redirectOmnipayResponse->getContent(), 200);
			}		
		} else {
			// Onsite gateway, redirect to application specific "completed" URL
			return Controller::curr()->redirect($this->getRedirectURL());
		}
	}

	/**
	 * Originally comes from the Omnipay module. See AbstractResponse
	 * We overwrite it here to customise html..
	 *
	 * @return HttpRedirectResponse
	 */
	public function getRedirectResponse()
	{
		if (!$this->response instanceof Omnipay\Common\Message\RedirectResponseInterface || !$this->response->isRedirect()) {
			throw new RuntimeException('This response does not support redirection.');
		}

		if ('GET' === $this->response->getRedirectMethod()) {
			return HttpRedirectResponse::create($this->response->getRedirectUrl());
		} elseif ('POST' === $this->response->getRedirectMethod()) {
			$hiddenFields = '';
			foreach ($this->response->getRedirectData() as $key => $value) {
				$hiddenFields .= sprintf(
						'<input type="hidden" name="%1$s" value="%2$s" />',
						htmlentities($key, ENT_QUOTES, 'UTF-8', false),
						htmlentities($value, ENT_QUOTES, 'UTF-8', false)
					)."\n";
			}

			$output = '<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>Viderestilling til betaling</title>
        <style>
        	body {
        		background: #FFFCF2;
        		text-align: center;
        		font-family: Verdana, sans-serif;
        	}
        	div, form {
        		position: absolute;
				left: 0;
				right: 0;
        	}
        	div {
        		top: 30%%;
				-webkit-transform: translateY(-30%%);
				-ms-transform: translateY(-30%%);
				transform: translateY(-30%%);
				z-index: 1;
        	}
        	i, img {
        		margin-bottom: 20px;
        	}
        	img {
        		max-width: 100%%;
        	}
        	i.fa {
        		font-size: 30px;
        	}
        	form {
        		z-index: 2;
				bottom: 0;
        	}
        	p {
        		font-size: 13px;
        	}
        	input{
				border: 0;
				background: none;
				color: #A79E7F;
				text-decoration: underline;
				margin-bottom: 20px;
				cursor: pointer;
        	}
        	input:hover {
        		text-decoration: none;
        	}
        </style>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.5.0/css/font-awesome.min.css">
    </head>
    <body onload="document.forms[0].submit();">
    	<div>
    		<img src="data:image/png;base64,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"/>
			<br/>
			<i class="fa fa-refresh fa-spin"></i>
			<br/>
			<p>Du viderestilles nu til betaling</p>
    	</div>
        <form action="%1$s" method="post">
			%2$s
			<input type="submit" value="Tryk her hvis du ikke viderestilles automatisk" />
        </form>
    </body>
</html>';
			$output = sprintf(
				$output,
				htmlentities($this->response->getRedirectUrl(), ENT_QUOTES, 'UTF-8', false),
				$hiddenFields
			);

			return HttpResponse::create($output);
		}

		throw new RuntimeException('Invalid redirect method "'.$this->response->getRedirectMethod().'".');
	}

}
