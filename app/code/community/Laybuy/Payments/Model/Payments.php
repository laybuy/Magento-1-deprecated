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
    
    protected $order = NULL;
    
    protected $errors = [];
    
    
    public function _construct() {
        parent::_construct();
    }
    
    // main entry point
    public function getOrderPlaceRedirectUrl() {
        // from the frontend tag in the modules config.xml
        
        
        $laybuy_order = $this->_makeLaybuyOrder();
        $this->dbg('---------------- LAYBUY ORDER -------------------');
        $this->dbg($laybuy_order);
        $this->dbg('---------------- /LAYBUY ORDER ------------------');
        
        /** @var  $quote \Mage_Sales_Model_Quote */
        $quote = $this->getInfoInstance()->getQuote();
        $this->order = Mage::getModel('sales/order')->loadByIncrementId($laybuy_order->merchantReference);
        

        if($this->getConfigData('force_order_return')){
            $this->dbg('---------------- LAYBUY ORDER -------------------\n  Force order on return set, delete existing order');
            $this->orderDelete();
            
            // if for new order is set we use the cart/quote to create a new order on return
            $laybuy_order->merchantReference = $quote->getId();
        }
        
        
        if (count($this->errors)) {
            $message = join("\n", $this->errors);
            $this->dbg('---------------- LAYBUY ERRORS -------------------');
            $this->dbg($laybuy_order);
            $this->dbg('---------------- /LAYBUY ERRORS ------------------');
            
            // magento has already made the order, so remove it so we donr'tkeep any stock tied up
            $this->orderDelete();
            
            $quote = $this->getInfoInstance()->getQuote();
            $quote->setIsActive(TRUE)->save();
            
            Mage::throwException($message);
            //$this->_redirect('checkout/onepage'); //Redirect to cart & exists
            exit();
        }
        
        
        return $this->getLaybuyRedirectUrl($laybuy_order);
    }
    
    
    private function _makeLaybuyOrder() {
        
        $session = Mage::getSingleton('checkout/session');
        
        
        /* @var $quote \Mage_Sales_Model_Quote */
        $quote = $this->getInfoInstance()->getQuote();
        
        /* @var $customer \Mage_Customer_Model_Customer */
        $customer = Mage::getModel('customer/customer')->load($quote->getCustomer()->getId()); // this geta a fresh cop of teh customer, if tehy aare a guest this emans they will now have teh address
        // data
        
        /* @var $address \Mage_Customer_Model_Address */
        
        $address = $session->getQuote()->getBillingAddress();
        if (!$address) {
            $address = $customer->getPrimaryBillingAddress();
        }
        
        //Mage::log($address);
        
        /* @var $shipping \Mage_Customer_Model_Address */
        $shipping = $customer->getShippingAddress();
        if (!$shipping) {
            $shipping = $session->getQuote()->getShippingAddress();
        }
        
        //$this->errors[] = 'This is another error.';
        $order = new stdClass();
        
        $order->amount    = $quote->getGrandTotal();
        $order->currency  = "NZD";
        $order->returnUrl = Mage::getUrl('laybuypayments/payment/response', ['_secure' => TRUE]);
        // BS $order->merchantReference = $quote->getId();
        $order->merchantReference = $quote->getReservedOrderId();
        
        $order->customer            = new stdClass();
        $order->customer->firstName = $quote->getCustomerFirstname();
        $order->customer->lastName  = $quote->getCustomerLastname();
        $order->customer->email     = $quote->getCustomerEmail();
        
        $phone = $address->getTelephone();
        
        if ($phone == '' || strlen(preg_replace('/[^0-9+]/i', '', $phone)) <= 6) {
            $this->errors[] = 'Please provide a valid New Zealand phone number.';
        }
        
        $order->customer->phone = $phone;
        
        $street                          = $address->getStreet();
        $order->billingAddress           = new stdClass();
        $order->billingAddress->address1 = (isset($street[0])) ? $street[0] : '';
        $order->billingAddress->address2 = (isset($street[1])) ? $street[1] : '';
        $order->billingAddress->city     = $address->getCity();
        $order->billingAddress->postcode = $address->getPostcode();
        $order->billingAddress->country  = Mage::app()->getLocale()->getCountryTranslation($address->getCountry_id());
        
        $order->items = [];
        
        $totalOrderValue = 0;
    
        // make the order more like a normal gateway txn, we just make
        // an item that match the total order rather than try to get the orderitem to match the grandtotal
        // as there is lot magento will let modules do to the total compared to a simple calc of
        // the cart items
        
        $order->items[0]              = new stdClass();
        $order->items[0]->id          = 1;
        $order->items[0]->description = $this->getConfigData('order_description_text') ? $this->getConfigData('order_description_text') : "Purchase from ". Mage::app()->getStore()->getName();
        $order->items[0]->quantity    = 1;
        $order->items[0]->price       = $quote->getGrandTotal(); // this can nerver to incorrect now
        
        // foreach ($quote->getAllVisibleItems() as $id => $item) {
        //
        //     /* @var @item \Mage_Sales_Model_Quote_Item */
        //
        //     $order->items[$id]              = new stdClass();
        //     $order->items[$id]->id          = $item->getId();
        //     $order->items[$id]->description = $item->getName();
        //     $order->items[$id]->quantity    = $item->getQty();
        //     //$order->items[$id]->price = $item->getPrice();
        //
        //
        //     Mage::log(__METHOD__ . 'ITEM [' . $item->getId() . ']: price: ' . $item->getPrice() . " getPriceInclTax:" . $item->getPriceInclTax() . " getFinalPrice:" . $item->getFinalPrice() . "
        // getDiscountAmount:" . $item->getDiscountAmount());
        //
        //
        //     if ($item->getDiscountAmount()) {
        //         $price = $item->getPriceInclTax() - $item->getDiscountAmount();
        //     }
        //     else {
        //         $price = $item->getPriceInclTax();
        //     }
        //
        //     $order->items[$id]->price = $price;
        //     $totalOrderValue          += $price;
        //
        // }
        
        //Mage::log(__METHOD__ . ' items total: ' . $totalOrderValue);
        //$totalOrderValue += $shipping->getShippingInclTax(); // add shipping to total order value.
    
        // if (floatval($totalOrderValue) < floatval($quote->getGrandTotal())) {
        //     Mage::log(__METHOD__ . ' items total is LESS than getGrandTotal:  ' . $totalOrderValue . " < " . $quote->getGrandTotal());
        //     Mage::log('Add discount line...');
        //
        //     $id                             = count($order->items); // count starts at 1
        //     $order->items[$id]              = new stdClass();
        //     $order->items[$id]->id          = 'DISCOUNT';
        //     $order->items[$id]->description = 'Discount';
        //     $order->items[$id]->quantity    = 1;
        //     $order->items[$id]->price       = floatval($quote->getGrandTotal()) - floatval($totalOrderValue); // a make a negative value
        // }
        //
        // if ($shipping->getShippingInclTax()) {
        //     $next                             = count($order->items); // count starts at 1
        //     $order->items[$next]              = new stdClass();
        //     $order->items[$next]->id          = 'SHIPPING';
        //     $order->items[$next]->description = $shipping->getShippingDescription();
        //     $order->items[$next]->quantity    = 1;
        //     $order->items[$next]->price       = $shipping->getShippingInclTax();
        // }
        
        return $order;
        
    }
    
    
    public function isAvailable($quote = NULL) {
        
        return $this->getConfigData('active') ? TRUE : FALSE;
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
        
        $stateObject->setState($this->getConfigData('unpaid_order_status'));
        $stateObject->setStatus($this->getConfigData('unpaid_order_status'));
        $stateObject->setIsNotified(FALSE);
        $this->dbg(__METHOD__ . " " . $this->getConfigData('unpaid_order_status'));

    }
    
    private function setupLaybuy() {
        $this->dbg(__METHOD__ . ' sandbox? ' . $this->getConfigData('sandbox_mode'));
        $this->dbg(__METHOD__ . ' sandbox_merchantid? ' . $this->getConfigData('sandbox_merchantid'));
        
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
        $this->dbg(__METHOD__ . ' CLIENT INIT: ' . $this->laybuy_merchantid . ":" . $this->laybuy_apikey);
        $this->dbg(__METHOD__ . ' INITIALISED');
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
    
            $this->dbg(__METHOD__ . ' CLIENT FAILED: ' . $this->laybuy_merchantid . ":" . $this->laybuy_apikey);
            
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
        $this->dbg(print_r($body,1));
        
        /* stdClass Object
                (
                    [result] => SUCCESS
                    [token] => a5MbmNdAU6WNFIT4smlr45BiuM4Nrb4CiFRPmj0f
                    [paymentUrl] => https://sandbox-payment.laybuy.com/pay/a5MbmNdAU6WNFIT4smlr45BiuM4Nrb4CiFRPmj0f
                )
         */
        
        if ($body->result == 'SUCCESS') {
            if (!$body->paymentUrl) {
                $this->noLaybuyRedirectError($body);
            }
            return $body->paymentUrl;
        }
        else {
            
            $this->noLaybuyRedirectError($body);
            
        }
        
    }
    
    public function noLaybuyRedirectError($res) {
        
        // need to remove order that was temp made
    
        $this->dbg("LAYBUY: ORDER CREATE FAILED (" . $res->result . " -> " . $res->error . ") ");
        $quote = $this->getInfoInstance()->getQuote();
        
        $message = "Sorry there was an Error redirecting you to Laybuy: " . $res->result . "  (" . $res->error . ") ";
        
        if ($quote) {
            
            $quote->setIsActive(TRUE)->save();
    
            $this->dbg("LAYBUY: ORDER CREATE FAILED, found quote " . $quote->getId());
            
            /*Mage::log("-------------- Quote -----------------");
            Mage::log($quote->debug());
            Mage::log("--------------/Quote -----------------");*/
            
            // Let customer know what's happened
            Mage::getSingleton('core/session')->addError($message);
            
            $this->_redirect('checkout/onepage'); //Redirect to cart & exists
            
        }
        
        // could not get a valid quote, send customer to fail
        Mage::getSingleton('core/session')->addError($message);
    
        $this->dbg("LAYBUY: ORDER CREATE FAILED,  QUOTE NOT FOUND ");
        $this->_redirect('checkout/onepage/failure'); // exists
        
        
    }
    
    /**
     * Redirects the user from the One Checkout Page Ajax call
     *
     * @param $path
     */
    public function _redirect($path) {

        $result             = [];
        $result['redirect'] = Mage::getUrl($path);
        Mage::app()->getResponse()->setBody(Mage::helper('core')->jsonEncode($result))->sendResponse();
        die();
    }
    
    
    private function orderDelete(){
        if(!is_null($this->order)) {
            Mage::register('isSecureArea', TRUE);
            $this->order->delete();
            Mage::unregister('isSecureArea');
        }
    }
    
    private function dbg($message) {
        
        if ($this->getConfigData('show_debug')) {
            Mage::log($message);
        }
        
    }
    
    
}
    
