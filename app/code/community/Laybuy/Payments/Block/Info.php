<?php
/**
 * Created by PhpStorm.
 * User: carl
 * Date: 8/07/17
 * Time: 16:50
 */
class Laybuy_Payments_Block_Payment_Info extends Mage_Payment_Block_Info {
    
    protected function _construct() {
        parent::_construct();
        $this->setTemplate('laybuy/payments/info.phtml');
    }
    
}
