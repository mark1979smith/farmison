<?php
/**
 * MaxMind API
 * Used to determine a fraud score against a transaction
 *
 * @see http://dev.maxmind.com/minfraud/
 * @author Mark Smith
 *
 */
class FM_Model_Api_Maxmind
{
    /**
     * License Key
     * @var string
     */
    const LICENSE_KEY = 'XXX';

    /**
     * High Risk Threshold
     * @var integer
     */
    const HIGH_RISK_THRESHOLD = 5;

    /**
     * IP Address of the end user - $_SERVER['HTTP_HOST']
     * @var string
     */
    protected $_ipAddress = NULL;

    /**
     * Billing City
     * @var string
     */
    protected $_billingCity = NULL;

    /**
     * Billing Region
     * Not used because we do not collect Counties
     * @var string
     */
    protected $_billingRegion = '';

    /**
     * Billing Postal Code
     * @var string
     */
    protected $_billingPostalCode = NULL;

    /**
     * Billing Country
     * Default value set to 'GB'
     * @var string
     */
    protected $_billingCountry = 'GB';

    /**
     * Shipping Address
     * @var string
     */
    protected $_shippingAddress = NULL;

    /**
     * Shipping Town / City
     * @var string
     */
    protected $_shippingCity = NULL;

    /**
     * Shipping Postal Code
     * @var string
     */
    protected $_shippingPostalCode = NULL;

    /**
     * Shipping Country
     * Default set to 'GB'
     * @var string
     */
    protected $_shippingCountry = 'GB';

    /**
     * Email Domain
     * @var string
     */
    protected $_emailDomain = NULL;

    /**
     * Customer Landline Telephone Number
     * @var string
     */
    protected $_customerTelephone = NULL;

    /**
     * An MD5 hash of email address
     * @var string
     */
    protected $_emailMD5 = NULL;

    /**
     * The BIN number
     * The first 6 digits of the credit card number if paid by credit card
     * @var string
     */
    protected $_binNumber = NULL;

    /**
     * Session ID - session_id()
     * @var string
     */
    protected $_sessionId = NULL;

    /**
     * Browser User-Agent
     * @var string
     */
    protected $_userAgent = NULL;

    /**
     * Browser Accept-Language
     * @var string
     */
    protected $_acceptLanguage = NULL;

    /**
     * Txn Id - The order number
     * @var string
     */
    protected $_txnId = NULL;

    /**
     * Order Total
     * @var float
     */
    protected $_orderAmount = NULL;

    /**
     * Order Currency
     * @var string
     */
    protected $_orderCurrency = NULL;

    /**
     * Transaction Type
     * Can be one of the following: creditcard, debitcard, paypal
     * @var string
     */
    protected $_txnType = NULL;

    /**
     * CVN result
     * Either Y or N
     * M = Y, other value = N
     * @see https://resourcecentre.realexpayments.com/documents/pdf.html?id=137
     * @var string
     */
    protected $_cvvResult = NULL;

    /**
     * Forwarded IP - $_SERVER["HTTP_X_FORWARDED_FOR"]
     * @var unknown
     */
    protected $_forwardedIP = NULL;

    /**
     * Magic Setter/Getter methods
     * Obviously the downside to using __call() is that the IDE
     * wouldn't autocomplete method names for you.
     *
     * @todo Generate setters/getters
     *
     * @param string $name
     * @param array $attr
     * @return FM_Model_Api_Maxmind|string|boolean
     */
    public function __call($name, $attr)
    {
    	/**
    	 * Set a variable
    	 *
    	 * @return FM_Model_Api_Maxmind
    	 */
    	if (preg_match('/^set/i', $name))
    	{
        	$var = '_' . lcfirst(preg_replace('/^set/i', '', $name));
        	$this->{$var} = $attr[0];

        	return $this;
    	}
    	/**
    	 * Get a variable
    	 */
    	elseif (preg_match('/^get/i', $name))
    	{
        	$var = '_' . lcfirst(preg_replace('/^get/i', '', $name));
        	return $this->{$var};
    	}

    	return false;
    }

    /**
     * Get Score
     * Returns the percentile chance of the order being fraudulent
     * Look at self::HIGH_RISK_THRESHOLD to determine the reporting threshold.
     *
     * @return float
     */
    public function getScore()
    {
        $startTime = microtime(true);

        $minFraud = new Maxmind_CreditCardFraudDetection();
        $minFraud->input(array(
        	'license_key' => self::LICENSE_KEY,
            'i' => $this->getIpAddress(),
            'city' => $this->getBillingCity(),
            'region' => $this->getBillingRegion(),
            'postal' => $this->getBillingPostalCode(),
            'country' => $this->getBillingCountry(),
            'shipAddr' => $this->getShippingAddress(),
            'shipCity' => $this->getShippingCity(),
            'shipPostal' => $this->getShippingPostalCode(),
            'shipCountry' => $this->getShippingCountry(),
            'domain' => $this->getEmailDomain(),
            'custPhone' => $this->getCustomerTelephone(),
            'emailMD5' => $this->getEmailMD5(),
            'bin' => $this->getBinNumber(),
            'sessionID' => $this->getSessionId(),
            'user_agent' => $this->getUserAgent(),
            'accept_language' => $this->getAcceptLanguage(),
            'txnID' => $this->getTxnId(),
            'order_amount' => $this->getOrderAmount(),
            'order_currency' => $this->getOrderCurrency(),
            'txn_type' => $this->getTxnType(),
            'cvv_result' => $this->getCvvResult(),
            'forwardedIP' => $this->getForwardedIP()
        ));
        $minFraud->query();

        $response = $minFraud->output();

        $endTime = microtime(true);

        // Store Results
        $db = Zend_Registry::get('db');
        $db->insert('maxmind_api_queries', array(
        	'id' => $response['maxmindID'],
            'order_id' => FM_View_Helper_Ordernumber::unformat($this->getTxnId()),
            'ip_address' => $this->getIpAddress(),
            'score' => $response['riskScore'],
            'request' => serialize($minFraud),
            'results' => serialize($response),
            'timestamp' => new Zend_Db_Expr('NOW()'),
            '_authtimecallback' => ($endTime-$startTime)
        ));

        $fraudScore = (float) $response['riskScore'];

        if ($fraudScore >= self::HIGH_RISK_THRESHOLD)
        {
        	$email = new FM_Model_Email();
        	$email->sendSuspectFraudOrderNotification($fraudScore, FM_View_Helper_Ordernumber::format(FM_View_Helper_Ordernumber::unformat($this->getTxnId())), $response, (APPLICATION_ENV != 'production'));
        }

        return (float) $response['riskScore'];
    }
}
