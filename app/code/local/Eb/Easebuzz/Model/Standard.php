<?php
class Eb_Easebuzz_Model_Standard extends Mage_Payment_Model_Method_Abstract {
	protected $_code = 'easebuzz';
	
	protected $_isInitializeNeeded      = true;
	protected $_canUseInternal          = true;
	protected $_canUseForMultishipping  = false;
	
	public function getOrderPlaceRedirectUrl() {
//                return Mage::getUrl("index.php");
		return Mage::getUrl('easebuzz/payment/redirect', array('_secure' => true));
	}
}
?>