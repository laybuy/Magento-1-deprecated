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
    
    const LAYBUY_LIVE_URL       = 'https://api.laybuy.com';
    const LAYBUY_SANDBOX_URL    = 'https://sandbox-api.laybuy.com';
    const LAYBUY_RETURN_SUCCESS = 'laybuypayments/success';
    const LAYBUY_RETURN_FAIL    = 'laybuypayments/fail';
    const LAYBUY_LOG_FILENAME   = 'laybuy_debug.log';
    
    /** @var  $quote  */
    protected $quote;

    
    // The response action is triggered when Laybuy sends back a response after processing the customer's payment
    //  GET /laybuypayments/payment/response/?status=SUCCESS&token=z8jFQf31BbRN3fEmjUbrxYZhQ6bwTtNNXoyCTpjo
    
    public function responseAction() {
        $session = Mage::getSingleton('checkout/session');
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
        if($status == 'SUCCESS') {
    
            Mage::log("LAYBUY: CUSTOMER RETURNS WITH SUCCESSFUL STATUS");
            
            // setup our client to talk with Laybuy
            $client = $this->getLaybuyClient();
            
            // finalise the order
            $laybuy = new stdClass();
            $laybuy->token = $this->getRequest()->getParam('token');
            
            //Mage::log(json_encode($laybuy));
            
            // finiase order
            $response = $client->restPost('/order/confirm', json_encode($laybuy));
            $body = json_decode($response->getBody());
    
            //Mage::log($body);
            
            // laybuy confirmed our order
            // we can setup teh invoice and mark the order as processing
            if ($body->result == 'SUCCESS') {
    
                Mage::log("LAYBUY: ORDER SUCCESSFULLY CONFIRMED");
                
                $layby_order_id = $body->orderId;
    
                $response = $client->restGet('/order/'. $layby_order_id );
                $laybuy_order  = json_decode($response->getBody());
    
                //Mage::log($laybuy_order);
                
                $order_id = $laybuy_order->merchantReference;
    
                $session->setLastOrderId($order_id)
                        ->setLastRealOrderId((string) $order_id);
                
                /* @var $order \Mage_Sales_Model_Order */
                $order = Mage::getModel('sales/order');
                $order->loadByIncrementId($order_id);
                
                if($order->isEmpty()){
                    $order_id = $session->getLastRealOrderId();
                    $order->loadByIncrementId($order_id);
    
                    if($order->isEmpty()) {
                        throw Mage::exception('Laybuy_Payments', "Order can not be retrieved: " . $order_id);
                    }
                }
                
                
                
                $order->addStatusHistoryComment("Laybuy payment approved." , FALSE);
    
                $order->sendNewOrderEmail();
                $order->setEmailSent(TRUE);
    
    
                $invoice = $order->prepareInvoice();
    
                if ($invoice->getTotalQty()) { // incase its a zero order? not sure what Laybuy would do here
                    
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                    
                    $invoice->register();
                    
                    Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder())->save();
        
                    $invoice->sendEmail(TRUE); //Convert this into a config option? DOes this get sent if Mage is set not to send?
        
                }
    
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, TRUE, 'Laybuy has approved payment, with Laybuy order id: ' . $layby_order_id );
                
                $order->save();
                
    
                Mage::getSingleton('checkout/session')->unsQuoteId();
                Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', ['_secure' => FALSE]);
    
            }
            
            
        }
        
        // USER cncelled their payment process at Laybuy, they may wont to continue
        elseif($status == 'CANCELLED'){
            //user cancel, let them try again?
            
            Mage::log("LAYBUY: CUSTOMER CANCELLED");

            if ($this->getLastQuoteId()) {
                
                $lastQuoteId = $this->getLastQuoteId();
                $quote = Mage::getModel('sales/quote')->load($lastQuoteId);
                $quote->setIsActive(TRUE)->save();
                
    
                // Let customer know what's happened
                Mage::getSingleton('core/session')->addError("Your Laybuy payment has been cancelled.");
                
                $this->_redirect('checkout/onepage'); //Redirect to cart
                return;
            }
            
            // could not get a valid quote, send customer to fail
            Mage::getSingleton('core/session')->addError("Your Laybuy payment has been cancelled, sorry we could not find your cart.");
            
            $this->_redirect('checkout/onepage/failure', ['_secure' => TRUE] );
            
        }
        else {
            
            Mage::log("LAYBUY: PAYMENT DELCINED OR CREDIT CHECK FAILED");
          
           
            if ($this->getLastQuoteId()) {
                
                $lastQuoteId = $this->getLastQuoteId();
                $quote = Mage::getModel('sales/quote')->load($lastQuoteId);
                $quote->setIsActive(TRUE)->save();
                
    
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
    
        Mage::log("LAYBUY: CUSTOMER CANCELLED");
    
        if ($this->getLastQuoteId()) {
        
            $lastQuoteId = $this->getLastQuoteId();
            $quote       = Mage::getModel('sales/quote')->load($lastQuoteId);
            $quote->setIsActive(TRUE)->save();
        
        
            // Let customer know what's happened
            Mage::getSingleton('core/session')->addError("Your Laybuy payment has been cancelled.");
        
            $this->_redirect('checkout/onepage'); //Redirect to cart
            return;
        }
    
        // could not get a valid quote, send customer to fail
        Mage::getSingleton('core/session')->addError("Your Laybuy payment has been cancelled, sorry we could not find your cart.");
    
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
            $client = new Zend_Rest_Client( $url );
            $client->getHttpClient()->setAuth(
                        $laybuy_merchantid,
                        $laybuy_apikey,
                        Zend_Http_Client::AUTH_BASIC);
        
        } catch (Exception $e) {
        
            Mage::logException($e);
            //Mage::helper('checkout')->sendPaymentFailedEmail($this->getOnepage()->getQuote(), $e->getMessage());
        
            Mage::log(__METHOD__ . ': LAYBUY CLIENT FAILED: ' . $laybuy_merchantid . ":< apikey >");
        
            $result['success']        = FALSE;
            $result['error']          = TRUE;
            $result['error_messages'] = $this->__('There was an error processing your order. Please contact us or try again later. [Laybuy connect]');
    
            // Let customer know its real bad
            Mage::getSingleton('core/session')->addError($result['error_messages'] );
        }
    
        return $client;
        
    
    }
    
    private function getConfigData($field, $storeId = NULL) {
        
        $path = 'payment/laybuy_payments/' . $field;
        return Mage::getStoreConfig($path, $storeId);
        
    }
    
    /**
     * Get quote of checkout session
     *
     * @return Mage_Sales_Model_Quote
     */
    private function getQuote() {
        if (is_null($this->quote)) {
            $this->quote = Mage::getSingleton('checkout/session')->getQuote();
        }
        return $this->quote;
    }
    
}