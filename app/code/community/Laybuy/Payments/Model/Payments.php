<?php

class Laybuy_Payments_Model_Payments extends Mage_Payment_Model_Method_Abstract {
    
    const LAYBUY_LIVE_URL = 'https://api.laybuy.com';
    
    const LAYBUY_SANDBOX_URL = 'https://sandbox-api.laybuy.com';
    
    const LAYBUY_RETURN_SUCCESS = 'laybuypayments/success';
    
    const LAYBUY_RETURN_FAIL = 'laybuypayments/fail';
    
    const LAYBUY_LOG_FILENAME = 'laybuy_debug.log';
    
    
    protected $_code = 'laybuy_payments'; // this modules name in its config.xml
    
    protected $_isInitializeNeeded = TRUE;
    
    protected $_formBlockType = 'payments/payment_form';
    
    protected $_infoBlockType = 'payments/payment_info';
    
    /**
     * this should probably be true if you're using this
     * method to take payments
     */
    protected $_isGateway = TRUE;
    
    /**
     * can this method authorise?
     */
    protected $_canAuthorize = TRUE;
    
    /**
     * can this method capture funds?
     */
    protected $_canCapture = TRUE;
    
    /**
     * can we capture only partial amounts?
     */
    protected $_canCapturePartial = FALSE;
    
    /**
     * can this method refund?
     */
    protected $_canRefund = FALSE;
    
    /**
     * can this method void transactions?
     */
    protected $_canVoid = TRUE;
    
    /**
     * can admins use this payment method?
     */
    protected $_canUseInternal = FALSE;
    
    /**
     * show this method on the checkout page
     */
    protected $_canUseCheckout = TRUE;
    
    /**
     * available for multi shipping checkouts?
     */
    protected $_canUseForMultishipping = FALSE;
    
    /**
     * can this method save cc info for later use?
     */
    protected $_canSaveCc = FALSE;
    
    /**
     * @var bool
     */
    protected $laybuy_sandbox = TRUE;
    
    /**
     * @var string
     */
    protected $laybuy_merchantid;
    
    /**
     * @var string
     */
    protected $laybuy_apikey;
    
    
    protected $restClient;
    
    protected $endpoint;
    
    
    public function _construct() {
        parent::_construct();
        
        
    }
    
    // main entry point
    public function getOrderPlaceRedirectUrl() {
        // from the frontend tag in the modules config.xml
        
        $order = $this->_makeLaybuyOrder();
        //Mage::log($order);
        
        return $this->getLaybuyRedirectUrl($order);
    }
    
    
    private function _makeLaybuyOrder() {
    
        $session = Mage::getSingleton('checkout/session');
       
        
        /* @var $quote \Mage_Sales_Model_Quote */
        $quote = $this->getInfoInstance()->getQuote();
        
        /* @var $customer \Mage_Customer_Model_Customer */
        $customer = Mage::getModel('customer/customer')->load( $quote->getCustomer()->getId() ); // this geta a fresh cop of teh customer, if tehy aare a guest this emans they will now have teh address
        // data
        
        /* @var $address \Mage_Customer_Model_Address */
        $address = $customer->getPrimaryBillingAddress();
        if(!$address){
            $address = $session->getQuote()->getBillingAddress();
        }
    
        //Mage::log($address);
        
        /* @var $shipping \Mage_Customer_Model_Address */
        $shipping = $customer->getShippingAddress();
        if (!$shipping) {
            $shipping = $session->getQuote()->getShippingAddress();
        }
        
        $order = new stdClass();
        
        $order->amount            = $quote->getGrandTotal();
        $order->currency          = "NZD";
        $order->returnUrl         = Mage::getUrl('laybuypayments/payment/response', ['_secure' => TRUE]);
        $order->merchantReference = $quote->getReservedOrderId();
        
        $order->customer            = new stdClass();
        $order->customer->firstName = $quote->getCustomerFirstname();
        $order->customer->lastName  = $quote->getCustomerLastname();
        $order->customer->email     = $quote->getCustomerEmail();
        $order->customer->phone     = $address->getTelephone();
        
        $street                          = $address->getStreet();
        $order->billingAddress           = new stdClass();
        $order->billingAddress->address1 = (isset($street[0])) ? $street[0] : '';
        $order->billingAddress->address2 = (isset($street[1])) ? $street[1] : '';
        $order->billingAddress->city     = $address->getCity();
        $order->billingAddress->postcode = $address->getPostcode();
        $order->billingAddress->country  = Mage::app()->getLocale()->getCountryTranslation($address->getCountry_id());
        
        $order->items = [];
        
        foreach ($quote->getAllVisibleItems() as $id => $item) {
            /* @var @item \Mage_Sales_Model_Quote_Item */
            
            $order->items[$id]              = new stdClass();
            $order->items[$id]->id          = $item->getId();
            $order->items[$id]->description = $item->getName();
            $order->items[$id]->quantity    = $item->getQty();
            $order->items[$id]->price       = $item->getPrice();
            
        }
        
        if ($shipping->getShippingAmount()) {
            $next                             = count($order->items); // count starts at 1
            $order->items[$next]              = new stdClass();
            $order->items[$next]->id          = 'SHIPPING';
            $order->items[$next]->description = $shipping->getShippingDescription();
            $order->items[$next]->quantity    = 1;
            $order->items[$next]->price       = $shipping->getShippingAmount();
        }
        
        return $order;
        
    }
    
    
    public function isAvailable($quote = NULL) {
        return TRUE;
    }
    
    /**
     * Instantiate state and set it to state object
     *
     * @param string $paymentAction
     * @param        Varien_Object
     *
     * @return void
     */
    public function initialize($paymentAction, $stateObject) {
        $stateObject->setState($this->getConfigData('unpaid_order_status') );
        $stateObject->setStatus($this->getConfigData('unpaid_order_status') );
        $stateObject->setIsNotified(FALSE);
        Mage::log(__METHOD__ ." " . $this->getConfigData('unpaid_order_status') );
        Mage::log(__METHOD__ . ' INITIALISED');
        
    }
    
    private function setupLaybuy() {
        Mage::log(__METHOD__ . ' sandbox? ' . $this->getConfigData('sandbox_mode'));
        Mage::log(__METHOD__ . ' sandbox_merchantid? ' . $this->getConfigData('sandbox_merchantid'));
        
        $this->laybuy_sandbox = $this->getConfigData('sandbox_mode') == 1;
        
        if ($this->laybuy_sandbox) {
            $this->endpoint          = self::LAYBUY_SANDBOX_URL;
            $this->laybuy_merchantid = $this->getConfigData('sandbox_merchantid');
            $this->laybuy_apikey     = $this->getConfigData('sandbox_apikey');
        }
        else {
            $this->endpoint          = self::LAYBUY_LIVE_URL;
            $this->laybuy_merchantid = $this->getConfigData('live_merchantid');
            $this->laybuy_apikey     = $this->getConfigData('live_apikey');
        }
        Mage::log(__METHOD__ . ' CLIENT INIT: ' . $this->laybuy_merchantid . ":" . $this->laybuy_apikey);
        Mage::log(__METHOD__ . ' INITIALISED');
    }
    
    private function getRestClient() {
        
        if (is_null($this->laybuy_merchantid)) { // ?? just do it anyway?
            $this->setupLaybuy();
        }
        
        try {
            $this->restClient = new Zend_Rest_Client($this->endpoint);
            $this->restClient->getHttpClient()->setAuth($this->laybuy_merchantid, $this->laybuy_apikey, Zend_Http_Client::AUTH_BASIC);
            
        } catch (Exception $e) {
            
            Mage::logException($e);
            Mage::helper('checkout')->sendPaymentFailedEmail($this->getOnepage()->getQuote(), $e->getMessage());
            
            Mage::log(__METHOD__ . ' CLIENT FAILED: ' . $this->laybuy_merchantid . ":" . $this->laybuy_apikey);
            
            $result['success']        = FALSE;
            $result['error']          = TRUE;
            $result['error_messages'] = $this->__('[Laybuy connect] There was an error processing your order. Please contact us or try again later.');
            // TODOD this error needs to go back to the user
        }
        
        return $this->restClient;
        
    }
    
    private function getLaybuyRedirectUrl($order) {
        
        if (is_null($this->restClient)) {
            $this->getRestClient();
        }
        
        $client = $this->restClient;
        
        // wrap in try?
        $response = $client->restPost('/order/create', json_encode($order));
        
        $body = json_decode($response->getBody());
        Mage::log($body);
        
        /* stdClass Object
                (
                    [result] => SUCCESS
                    [token] => a5MbmNdAU6WNFIT4smlr45BiuM4Nrb4CiFRPmj0f
                    [paymentUrl] => https://sandbox-payment.laybuy.com/pay/a5MbmNdAU6WNFIT4smlr45BiuM4Nrb4CiFRPmj0f
                )
         */
        
        if ($body->result == 'SUCCESS') {
            if (!$body->paymentUrl) {
                throw new Exception("Laybuy Payment method is not available.");
            }
            return $body->paymentUrl;
        }
        else {
            // TODO
            return NULL;
        }
        
        
    }
}
    
