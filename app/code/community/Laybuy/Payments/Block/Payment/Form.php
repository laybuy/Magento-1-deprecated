<?php
 /**
 * Netgo_Stripe module block
 *
 * @category    Netgo
 * @package     Netgo_Stripe
 * @author      Afroz Alam <afroz92@gmail.com>
 * @copyright   NetAttingo Technologies (http://www.netattingo.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Laybuy_Payments_Block_Payment_Form extends Mage_Payment_Block_Form {
    
    protected function _construct() {
        parent::_construct();
       
        $this->setTemplate('laybuy/payments/method.phtml');
    }
    
}
			