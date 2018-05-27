<?php
/**
 *
 */

class Laybuy_Payments_Model_System_CurrencyList extends Mage_Adminhtml_Model_System_Config_Source_Currency {
    
    protected $_options;
    
    
    public function toOptionArray() {
        
        if (!$this->_options) {
            $payments = new Laybuy_Payments_Model_Payments();
            $this->_options = $payments->getCurrencyList();
        }
        
        return $this->_options;
    }
}