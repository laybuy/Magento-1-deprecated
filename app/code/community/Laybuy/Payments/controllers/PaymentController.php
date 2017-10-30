<?php
/**
 * @package   Laybuy_Payments
 * @author    16hands <carl@16hands.co.nz>
 * @copyright Copyright (c) 2017 Laybuy (https://www.laybuy.com/)
 */

/**
 * Class Laybuy_Payments_PaymentController
 *
 * Controller for the Laybuy Payment Process
 *
 */
class Laybuy_Payments_PaymentController extends Mage_Core_Controller_Front_Action {
    
    const LAYBUY_LIVE_URL = 'https://api.laybuy.com';
    
    const LAYBUY_SANDBOX_URL = 'https://sandbox-api.laybuy.com';
    
    const LAYBUY_RETURN_SUCCESS = 'laybuypayments/success';
    
    const LAYBUY_RETURN_FAIL = 'laybuypayments/fail';
    
    const LAYBUY_LOG_FILENAME = 'laybuy_debug.log';
    
    /** @var $quote \Mage_Sales_Model_Quote */
    protected $quote = NULL;
    
    protected $session = NULL;
    
    /** @var $order \Mage_Sales_Model_Order */
    protected $order = NULL;
    
    protected $laybuy_order = NULL;
    
    
    // The response action is triggered when Laybuy sends back a response after processing the customer's payment
    //  GET /laybuypayments/payment/response/?status=SUCCESS&token=z8jFQf31BbRN3fEmjUbrxYZhQ6bwTtNNXoyCTpjo
    
    public function responseAction() {
        $this->session = Mage::getSingleton('checkout/session');
        
        /* @var  $laybuyPayment  \Laybuy_Payments_Model_Payments */
        $laybuyPayment = Mage::getSingleton('payments/payments'); // Laybuy_Payments_Model_Payments
        
        
        // we are always in session with Laybuy
        // Mage::log("response action ");
        
        //  GET /laybuypayments/payment/response/?status=SUCCESS&token=z8jFQf31BbRN3fEmjUbrxYZhQ6bwTtNNXoyCTpjo HTTP/1.1
        $status = $this->getRequest()->getParam('status');
        
        //
        // This action is used for all returns, handle each one
        //
        
        // returning from a successful payment
        //
        if ($status == 'SUCCESS') {
            
            $this->dbg("LAYBUY: CUSTOMER RETURNS WITH SUCCESSFUL STATUS");
            
            // setup our client to talk with Laybuy
            $client = $this->getLaybuyClient();
            
            // finalise the order
            $laybuy        = new stdClass();
            $laybuy->token = $this->getRequest()->getParam('token');
            
            //Mage::log(json_encode($laybuy));
            
            // finiase order
            $response = $client->restPost('/order/confirm', json_encode($laybuy));
            $body     = json_decode($response->getBody());
            
            //Mage::log($body);
            
            // laybuy confirmed our order
            // we can setup teh invoice and mark the order as processing
            if ($body->result == 'SUCCESS') {
    
                $this->dbg("LAYBUY: ORDER SUCCESSFULLY CONFIRMED");
                
                $layby_order_id = $body->orderId;
                
                $response     = $client->restGet('/order/' . $layby_order_id);
                $this->laybuy_order = json_decode($response->getBody());
                
                //Mage::log($laybuy_order);
                
                /** sets $this->order \Mage_Sales_Model_Order */
                $this->getOrder($this->laybuy_order->merchantReference);
              
                $payment       = $this->order->getPayment();
                $paymentMethod = $payment->getMethodInstance();
                $this->order->save();
                
                
                $this->order->addStatusHistoryComment("Laybuy payment approved.", FALSE);
                
                if ($this->getConfigData('send_email') && !$this->order->getEmailSent() && $paymentMethod->getConfigData('order_email')) {
                    $this->order->sendNewOrderEmail();
                    $this->order->setEmailSent(TRUE);
                }
                
                $invoice = $this->order->prepareInvoice();
                
                if ($invoice->getTotalQty()) {
                    
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                    
                    $invoice->register();
                    
                    Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder())->save();
                    
                    
                    if($this->getConfigData('send_email')){
                        $invoice->sendEmail(TRUE);
                    }
                    
                }
    
                $this->order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, TRUE, 'Laybuy has approved payment, with Laybuy order id: ' . $layby_order_id);
    
                $this->order->save();
                
                
                Mage::getSingleton('checkout/session')->unsQuoteId();
                
                // some onepage checkouts use a different success url
                if ($this->getConfigData('return_url')) {
                    $this->getResponse()->setRedirect(Mage::getUrl($this->getConfigData('return_url', ['_secure' => FALSE])));
                }
                else {
                    $this->_redirect('checkout/onepage/success', ['_secure' => FALSE]);
                }
            }
            
            
        }
        
        // USER cncelled their payment process at Laybuy, they may wont to continue
        // https://magento.dev/laybuypayments/payment/response/?status=CANCELLED&token=PXWlCHfjRGIV2AGXM6dsdTMuKPeOOQwXwRpAtZ3s
        elseif ($status == 'CANCELLED') {
            //user cancel, let them try again?
            
            $this->dbg("LAYBUY: CUSTOMER CANCELLED");
    
            // setup our client to talk with Laybuy
            $client = $this->getLaybuyClient();
    
            // finalise the order
            $laybuy        = new stdClass();
            $laybuy->token = $this->getRequest()->getParam('token');
    
            //Mage::log(json_encode($laybuy));
    
            // cancel order
            $response = $client->restGet('/order/cancel/' . $laybuy->token);
            
            $order_id = $this->session->getLastRealOrderId();
            $this->order = Mage::getModel('sales/order');
            $this->order->loadByIncrementId($order_id);
    
            $this->orderDelete();
            
            // recreate the cart (quote) if posiable
            if ($this->getLastQuoteId()) {
                
                /** sets $this->quote \Mage_Sales_Model_Quote */
                $this->setQuote($this->getLastQuoteId());
                
                // Let customer know what's happened
                Mage::getSingleton('core/session')->addError("Your Laybuy payment has been cancelled.");
                
                $this->_redirect('checkout/onepage'); //Redirect to checkout
                return;
            }
            
            // could not get a valid quote, send customer to fail
            Mage::getSingleton('core/session')->addError("Your Laybuy payment has been cancelled, sorry we could not find your cart.");
            
            $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
            
        }
        else {
    
            $this->dbg("LAYBUY: PAYMENT DELCINED OR CREDIT CHECK FAILED");
    
            // setup our client to talk with Laybuy
            $client = $this->getLaybuyClient();
    
            // finalise the order
            $laybuy        = new stdClass();
            $laybuy->token = $this->getRequest()->getParam('token');
    
            // cancel order
            $response = $client->restGet('/order/cancel/' . $laybuy->token);
            
            $order_id    = $this->session->getLastRealOrderId();
            $this->order = Mage::getModel('sales/order');
            $this->order->loadByIncrementId($order_id);
    
            $this->orderDelete();
            
            
            if ($this->getLastQuoteId()) {
    
                /** sets $this->quote \Mage_Sales_Model_Quote */
                $this->setQuote($this->getLastQuoteId());
    
                // Let customer know its bad
                Mage::getSingleton('core/session')->addError("Sorry, you have been declined a Laybuy.");
                
                $this->_redirect('checkout/onepage'); //Redirect to cart
                return;
            }
            
            Mage::getSingleton('core/session')->addError("Sorry, you have been declined a Laybuy and we could not find your cart.");
            $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
            
        }
        
        
    }
    
    /**
     * Get quote of checkout session
     *
     * @return Mage_Sales_Model_Quote
     */
    private function getLastQuoteId() {
        
        return Mage::getSingleton('checkout/session')->getLastQuoteId();
    }
    
    /**
     * Cancel order
     */
    public function cancelAction() {
        
        //user cancel, let them try again?
        
        $this->dbg("LAYBUY: CUSTOMER CANCELLED ACTION");
        
        // setup our client to talk with Laybuy
        $client = $this->getLaybuyClient();
    
        // finalise the order
        $laybuy        = new stdClass();
        $laybuy->token = $this->getRequest()->getParam('token');
    
        // cancel order
        $response = $client->restGet('/order/cancel/' . $laybuy->token);
    
        $order_id    = $this->session->getLastRealOrderId();
        $this->order = Mage::getModel('sales/order');
        $this->order->loadByIncrementId($order_id);
    
        $this->orderDelete();
    
    
        if ($this->getLastQuoteId()) {
        
            /** sets $this->quote \Mage_Sales_Model_Quote */
            $this->setQuote($this->getLastQuoteId());
        
            // Let customer know its bad
            Mage::getSingleton('core/session')->addError("Sorry, you have been declined a Laybuy.");
        
            $this->_redirect('checkout/onepage'); //Redirect to cart
            return;
        }
        
        // could not get a valid quote, send customer to fail
        Mage::getSingleton('core/session')->addError("Sorry, you have been declined a Laybuy and we could not find your cart.");
        $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE]);
  
    }
    
    
    private function getLaybuyClient() {
        
        $laybuy_sandbox = $this->getConfigData('sandbox_mode') == 1;
        
        if ($laybuy_sandbox) {
            $laybuy_merchantid = $this->getConfigData('sandbox_merchantid');
            $laybuy_apikey     = $this->getConfigData('sandbox_apikey');
            $url               = self::LAYBUY_SANDBOX_URL;
        }
        else {
            $laybuy_merchantid = $this->getConfigData('live_merchantid');
            $laybuy_apikey     = $this->getConfigData('live_apikey');
            $url               = self::LAYBUY_LIVE_URL;
        }
        
        
        try {
            $client = new Zend_Rest_Client($url);
            $client->getHttpClient()->setAuth($laybuy_merchantid, $laybuy_apikey, Zend_Http_Client::AUTH_BASIC);
            
        } catch (Exception $e) {
            
            Mage::logException($e);
            //Mage::helper('checkout')->sendPaymentFailedEmail($this->getOnepage()->getQuote(), $e->getMessage());
    
            $this->dbg(__METHOD__ . ': LAYBUY CLIENT FAILED: ' . $laybuy_merchantid . ":< apikey >");
            
            $result['success']        = FALSE;
            $result['error']          = TRUE;
            $result['error_messages'] = $this->__('There was an error processing your order. Please contact us or try again later. [Laybuy connect]');
            
            // Let customer know its real bad
            Mage::getSingleton('core/session')->addError($result['error_messages']);
        }
        
        return $client;
        
        
    }
    
    private function getConfigData($field, $storeId = NULL) {
        
        $path = 'payment/laybuy_payments/' . $field;
        return Mage::getStoreConfig($path, $storeId);
        
    }
    
    
    private function dbg($message) {
        
        if($this->getConfigData('show_debug')){
            Mage::log( $message);
        }
        
    }
    
    
    /**
     * Get quote of checkout session
     * @param $quote_id int
     * @return Mage_Sales_Model_Quote
     */
    private function setQuote($quote_id = NULL) {
        if (is_null($this->quote)) {
            if($quote_id){
                $this->quote = Mage::getModel('sales/quote')->load($quote_id);
            }
            else {
                $this->quote = Mage::getSingleton('checkout/session')->getQuote();
            }
            
            $this->quote->setIsActive(TRUE)->save();
        }
        return $this->quote;
    }
    
    /**
     * Get quote of checkout session
     *
     * @param $quote_id int
     *
     * @return Mage_Sales_Model_Quote
     */
    private function getQuote($quote_id=NULL) {
        if (is_null($this->quote)) {
           $this->setQuote($quote_id);
        }
        return $this->quote;
    }
    
    private function orderDelete() {
        if (!is_null($this->order)) {
            Mage::register('isSecureArea', TRUE);
            $this->order->delete();
            Mage::unregister('isSecureArea');
        }
    }
    
    
    private function getOrder($merchantReference){
    
    
        if ($this->getConfigData('force_order_return')) {
            // this is also needed for Retails Express
        
            $quote_id = $merchantReference;
           
            $quote = $this->getQuote($quote_id);
        
            // construct an order based on the quote
            $quote_ship_method = $quote->getShippingAddress()->getShippingMethod();
        
            $this->dbg(__METHOD__ . " QUOTE shipping method: " . $quote_ship_method);
        
            $quote->getShippingAddress()->setShippingMethod($quote_ship_method);
            $quote->save();
            
            // for some reason on some installs this can be a non-sticky
            if (!$quote->getShippingAddress()->getShippingMethod()) {
                $quote->getShippingAddress()->setShippingMethod($quote_ship_method);
                $this->dbg(__METHOD__ . " NO SHIPPING METHOD from quote shipping Method, setting to: " . $quote_ship_method);
            }
            
            //$quote->setBillingAddress()
            $quote->collectTotals();
            $quote->save();
        
            /* @var $service \Mage_Sales_Model_Service_Quote */
            $service = Mage::getModel('sales/service_quote', $quote);
            $service->getQuote()->getShippingAddress()->setShippingMethod($quote_ship_method);
            
    
            try {
                $service->submitAll();
                $quote->save();
            
            } catch (\Exception $e) {
                Mage::log(__METHOD__ . " ERROR IN Quote service submitAll " . $e->getMessage());
            }
        
            $this->order = $service->getOrder();
            $order_id    = $this->order->getId();
        
            //ensure that Grand Total is not doubled
            $this->order->setBaseGrandTotal($quote->getBaseGrandTotal());
            $this->order->setGrandTotal($quote->getGrandTotal());
        
        
            if ($this->order->getId()) {
                $profiles = $service->getRecurringPaymentProfiles();
                if ($profiles) {
                    $ids = [];
                    foreach ($profiles as $profile) {
                        $ids[] = $profile->getId();
                    }
                    $this->session->setLastRecurringProfileIds($ids);
                }
                //ensure the order amount due is 0
                $this->order->setTotalDue(0);
            }
        
        }
        else {
        
            // normal order, which already exits
            $order_id = $merchantReference;
            $this->session->setLastOrderId($order_id)->setLastRealOrderId((string) $order_id);
        
            /* @var $order \Mage_Sales_Model_Order */
            $this->order = Mage::getModel('sales/order');
            $this->order->loadByIncrementId($order_id);
        
        
            if ($this->order->isEmpty()) {
                $order_id = $this->session->getLastRealOrderId();
                $this->order->loadByIncrementId($order_id);
            
                if ($this->order->isEmpty()) {
                    throw Mage::exception('Laybuy_Payments', "Order can not be retrieved: " . $order_id);
                }
            }
        
        }
        
        
    }
    
    
}