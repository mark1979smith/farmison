<?php
class FM_Model_Payment_Paypalexpress extends FM_Model_Payment implements FM_Model_Payment_Interface
{
    /**
     * Success Message from Paypal
     * @var string
     */
    const SUCCESS = 'Success';

    /**
     * Soap Endpoint
     * @var string
     */
    const API_ENDPOINT  = 'https://api-3t.paypal.com/nvp';

    /**
     * Credential - Username
     * @var string
     */
    const USERID    = 'XXX';

    /**
     * Credential - Password
     * @var string
     */
    const PASSWORD  = 'XXX';

    /**
     * Credential - Signature
     * @var string
     */
    const SIGNATURE = 'XXX';

    /**
     * API Version
     * @var float
     */
    const API_VERSION = 109.0;

    /**
     * Website Endpoint
     */
    const PAYPAL_PAYMENT_URL = 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=#TOKEN#';

    /**
     * Token to identify the checkout flow
     * @var string
     */
    protected $_token = NULL;

    /**
     * Transaction Id - ie. the order number formatted
     * @var string
     */
    protected $_transactionId = NULL;

    /**
     * Payer ID
     * The customers ID in Paypal
     * @var string
     */
    protected $_payerId = NULL;

    /**
     * Authorise Payment
     * @see FM_Model_Payment_Interface::authorisePayment()
     * @return boolean
     */
    public function authorisePayment()
    {
    	// Ensure Transaction Id is supplied
        if (is_null($this->_transactionId))
        {
            throw new Exception('Transaction Id is not set');
        }

        // Ensure Token is supplied
        if (is_null($this->getToken()))
        {
            throw new Exception('Token is not supplied');
        }

        // Ensure Payer Id is supplied
        if (is_null($this->getPayerId()))
        {
            throw new Exception('Payer ID is not provided');
        }

        // Get Order Total
        $sql = $this->_db->select()
            ->from('orders', array('total'))
            ->where($this->_db->quoteIdentifier('order_id') .' = ?', FM_View_Helper_Ordernumber::unformat($this->_transactionId));
        $orderTotal = $this->_db->fetchOne($sql);

        // Only process payment if $orderTotal > 0
        if ($orderTotal > 0)
        {
            // Get count of transactions - We do this as it is possible for 
            // multiple payment requests for the same order. ie. a declined 
            // transaction
            $sql = $this->_db->select()
            ->from('transaction_responses_paypal_express', array('COUNT(*)'))
            ->where($this->_db->quoteIdentifier('order_id') .' = ?', FM_View_Helper_Ordernumber::unformat($this->_transactionId));
            $transactionCount = $this->_db->fetchOne($sql);
            $transactionCount++;

            // Get count of authorised transactions
            $sql->where($this->_db->quoteIdentifier('status') .' = 1');
            $authorisedTransactionCount = $this->_db->fetchOne($sql);

            // Define Currency Object
            $currency = new Zend_Currency();
            
            // Define Http Client
            $client = new Zend_Http_Client();
            
            // Build Parameters
            $parameters = array(
        		'USER' => self::USERID,
        		'PWD' => self::PASSWORD,
        		'SIGNATURE' => self::SIGNATURE,
        		'VERSION' => self::API_VERSION,
        		'METHOD' => 'DoExpressCheckoutPayment',
        		'TOKEN' => $this->getToken(),
        		'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
        		'PAYMENTREQUEST_0_AMT' => $orderTotal,
        		'PAYMENTREQUEST_0_CURRENCYCODE' => $currency->getShortName(),
        		'PAYERID' => $this->getPayerId(),
                'PAYMENTREQUEST_0_INVNUM' => $this->_transactionId .'-'. str_pad($transactionCount, 4, '0', STR_PAD_LEFT),
                'PAYMENTREQUEST_0_NOTIFYURL' => 'http'. (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'] != '' ? 's' :'') .'://'. $_SERVER['HTTP_HOST'] .'/instant-payment-notification/paypal',
                'L_PAYMENTREQUEST_0_NAME0' => 'Website Order ('. $this->_transactionId .')',
                'L_PAYMENTREQUEST_0_AMT0' => $orderTotal,
                'PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD' => 'InstantPaymentOnly'
            );

            // Send Request
            $client
                ->setMethod(Zend_Http_Client::POST)
                ->setUri(self::API_ENDPOINT)
                ->setParameterPost($parameters);
            
            // Store Request
            $request = $client->request();

            // The response will be a URL query string
            // We use parse_str to turn this into an array $response
            parse_str($request->getBody(), $response);
            
			// Next we ensure that the payment was successfull
            $status = (array_key_exists('ACK', $response) && $response['ACK'] == self::SUCCESS && array_key_exists('PAYMENTINFO_0_ACK', $response) && $response['PAYMENTINFO_0_ACK'] == self::SUCCESS);

            // Just in case we have a duplicate transaction by customer's double clicking
            // we want to inform the office so they can refund.
            if ($authorisedTransactionCount && $status)
            {
                $email = new FM_Model_Email();
                // Send notice
                $email->sendDuplicatePaypalPayment(FM_View_Helper_Ordernumber::unformat($this->_transactionId));
            }
            
            // Store results in session
            $session = new Zend_Session_Namespace('remotePayment');
            $session->status = $status;
            foreach($response as $key => $value) :
                $session->{strtolower($key)} = $value;
            endforeach;

            // Insert response into database
            $this->_db->insert('transaction_responses_paypal_express', array(
                'order_id' => FM_View_Helper_Ordernumber::unformat($this->_transactionId),
                'status' => ($status ? 1 : 0),
                'request' => serialize($parameters),
                'response' => serialize($response)
            ));

            if ($status != true)
            {
            	// If status is not true, return an array with the message so we can return this to the user
                return  array('status' => $status, 'message' => '('. $response['L_ERRORCODE0'] .') '. $response['L_LONGMESSAGE0']);
            }
            else
            {
            	// We have an authorised payment
                // Get Fraud Score
                $this->_getFraudScore($orderTotal);
                
                // return true
                return true;
            }
        }
        else // if ($orderTotal > 0)
        {
        	// retrun true
            return true;
        }
    }

    /**
     * Set Express Checkout
     * Use this to get a Token to identify the checkout process
     * @return boolean
     */
    public function setExpressCheckout($currentOrderTotal)
    {
    	// Define Currency Object 
    	$currency = Zend_Currency();
    	// Define HTTP Client
        $client = new Zend_Http_Client();
        // Send Request
        $client
            ->setMethod(Zend_Http_Client::POST)
            ->setUri(self::API_ENDPOINT)
            ->setParameterPost(array(
        		'USER' => self::USERID,
        		'PWD' => self::PASSWORD,
        		'SIGNATURE' => self::SIGNATURE,
        		'VERSION' => self::API_VERSION,
         		'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
        		'PAYMENTREQUEST_0_AMT' => $currentOrderTotal->getValue(),
         		'PAYMENTREQUEST_0_CURRENCYCODE' => $currency->getShortCode(),
        		'METHOD' => 'SetExpressCheckout',
                'NOSHIPPING' => 1,
                'ALLOWNOTE' => 0,
        		'RETURNURL' => 'http'. (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'] != '' ? 's' :'') .'://'. $_SERVER['HTTP_HOST'] .'/checkout/confirmation',
        		'CANCELURL' => 'http'. (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'] != '' ? 's' :'') .'://'. $_SERVER['HTTP_HOST'] .'/checkout'
            ));
        // Store Request
        $request = $client->request();

        // The response will be a URL query string
        // We use parse_str to turn this into an array $response
        parse_str($request->getBody(), $response);

        // Store response in Database
        $this->_db->insert('paypal_api_responses', $response);

        // Check if response is successful
        $status = (array_key_exists('ACK', $response) && $response['ACK'] == self::SUCCESS);

        // If status is successful, then set the token 
        if ($status === true)
        {
            $this->setToken($response['TOKEN']);
        }

        return $status;
    }

    /**
     * Get the customer details from the supplied token
     * 
     * @throws Exception
     * @return array
     */
    public function getDetails($token)
    {
    	// Define Http Client
        $client = new Zend_Http_Client();
        // Send Request
        $client
            ->setMethod(Zend_Http_Client::POST)
            ->setUri(self::API_ENDPOINT)
            ->setParameterPost(array(
        		'USER' => self::USERID,
        		'PWD' => self::PASSWORD,
        		'SIGNATURE' => self::SIGNATURE,
        		'VERSION' => self::API_VERSION,
        		'METHOD' => 'GetExpressCheckoutDetails',
        		'TOKEN' => $token
            ));
        // Store Request
        $request = $client->request();

        // The response will be a URL query string
        // We use parse_str to turn this into an array $response
        parse_str($request->getBody(), $response);

        // Now we check that the response was successful and the PAYERID key exists
        if (array_key_exists('ACK', $response) && $response['ACK'] == self::SUCCESS && array_key_exists('PAYERID', $response))
        {
        	// Set the Payer Id
            $this->setPayerId($response['PAYERID']);

            return $response;
        }
        else
        {
            throw new Exception('Token is not correct');
        }
    }

    /**
     * Set the Paypal Token
     * @param string $token
     * @return FM_Model_Payment_Paypalexpress
     */
    public function setToken($token)
    {
        $this->_token = $token;

        return $this;
    }

    /**
     * Get the Paypal Token
     * @return string
     */
    public function getToken()
    {
        return $this->_token;
    }

    /**
     * Set the Transaction Id
     * @see FM_Model_Payment_Interface::setTransactionId()
     */
    public function setTransactionId($transactionId)
    {
    	$this->_transactionId = $transactionId;

    	return $this;
    }

    /**
     * Set the Payer ID
     * @param string $payerId
     * @return FM_Model_Payment_Paypalexpress
     */
    public function setPayerId($payerId)
    {
        $this->_payerId = $payerId;

        return $this;
    }

    /**
     * Get the Payer ID
     * @return string
     */
    public function getPayerId()
    {
        return $this->_payerId;
    }

    /**
     * Get Fraud Score
     * @param float $orderTotal
     */
    protected function _getFraudScore($orderTotal)
    {
    	// Set Session Access Points
    	$billingAddressSession = new Zend_Session_Namespace('checkoutPayment');
    	$deliveryAddressSession = new Zend_Session_Namespace('checkoutDeliveryAddress');
    	// Define local currency object
    	$currency = new Zend_Currency();
    	// Define User Model
    	$user = new FM_Model_User();
    	// If Email address has been specified and user account exists for the specified email address
    	if (array_key_exists('email_address', $deliveryAddressSession->values) && strlen($deliveryAddressSession->values['email_address']) > 0 && FM_Model_User::getUserIdFromEmailAddress($deliveryAddressSession->values['email_address']) > 0)
    	{
    		$userId = FM_Model_User::getUserIdFromEmailAddress($deliveryAddressSession->values['email_address']);
    	}
    	// If is a guest
    	elseif ($user->isGuest())
    	{
    		// Has email address been added?
    		if (array_key_exists('email_address', $deliveryAddressSession->values) && !empty($deliveryAddressSession->values['email_address']))
    		{
    			$client = new FM_Model_Client();
    			$userId = $client->create(array(
    					'email_address' => $deliveryAddressSession->values['email_address'],
    					'is_guest' => (!array_key_exists('internal_marketing', $deliveryAddressSession->values) || (array_key_exists('internal_marketing', $deliveryAddressSession->values) && $deliveryAddressSession->values['internal_marketing'] == 0))
    			));
    		}
    		else
    		{
    			$userId = NULL;
    		}
    	}
    	// else get the user id for the current logged in user
    	else
    	{
    		$userId = $user->getUserId();
    	}
    	// Specify Landline Telephone Numbers
    	$landLineNumbers = array_filter(array((array_key_exists('telephone_number', $deliveryAddressSession->values) ? $deliveryAddressSession->values['telephone_number'] : NULL), (array_key_exists('alt_telephone_number', $deliveryAddressSession->values) ? $deliveryAddressSession->values['alt_telephone_number'] : NULL)), create_function('$a', 'return !preg_match("/^07/", $a);'));
    	// Get Userdata
    	$userData = $user->getUserData($userId);

    	// Start FraudScore
    	$fraudScore = new FM_Model_Api_Maxmind();
    	$fraudScore
        	->setIpAddress($_SERVER['REMOTE_ADDR'])
        	->setBillingCity($billingAddressSession->values['city'])
        	->setBillingPostalCode($billingAddressSession->values['postcode'])
        	->setShippingAddress(implode(', ', array_filter(array($deliveryAddressSession->values['address_1'], $deliveryAddressSession->values['address_2'], $deliveryAddressSession->values['address_3'], $deliveryAddressSession->values['address_4']), 'strlen')))
        	->setShippingCity($deliveryAddressSession->values['city'])
        	->setShippingPostalCode($deliveryAddressSession->values['postcode'])
        	->setEmailDomain(substr($userData['email_address'], (strpos($userData['email_address'], '@')+1)))
        	->setCustomerTelephone((!empty($landLineNumbers) ? reset($landLineNumbers) : (array_key_exists('telephone_number', $deliveryAddressSession->values) ? $deliveryAddressSession->values['telephone_number'] : NULL)))
        	->setEmailMD5(md5($userData['email_address']))
        	->setSessionId(session_id())
        	->setUserAgent($_SERVER['HTTP_USER_AGENT'])
        	->setAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE'])
        	->setTxnId($this->_transactionId)
        	->setOrderAmount($orderTotal)
        	->setOrderCurrency($currency->getShortName())
        	->setTxnType('paypal');

    	// If forwarded IP exists, specify it here
    	if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER))
    	{
    		$fraudScore->setForwardedIP($_SERVER["HTTP_X_FORWARDED_FOR"]);
    	}

        // Get the Score
    	$fraudScore->getScore();
    }

}