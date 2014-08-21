<?php

/**
 * Adyen Payment Module
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category	Adyen
 * @package	Adyen_Payment
 * @copyright	Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license	http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */
class Adyen_Payment_Model_Process extends Mage_Core_Model_Abstract {

    /**
     * Used in the ProcessController to handle all bussiness logic regarding Adyen to Magento
     * @since v008
     * @desc Update order status accordingly
     * @throws Exception
     */
    public function processResponse($soapItem = null) {

    	$response = (!empty($soapItem)) ? $soapItem : $this->getRequest()->getParams();
        Mage::getResourceModel('adyen/adyen_debug')->assignData($response);
		$actionName = $this->getRequest()->getActionName();
		$helper = Mage::helper('adyen');
		
        if (empty($response)) {
        	Mage::log('blanco on response, please check your webserver that the result url accepts parameters', Zend_Log::CRIT, "adyen_notification.log", true);
            return "401";
        }
        
        $varienObj = new Varien_Object();
        foreach ($response as $code => $value) {
            if ($code == 'amount') {
                if (is_object($value))
                    $value = $value->value;
                $code = 'value';
            }
            $varienObj->setData($code, $value);
        }

        // if version is added to notification url (?version=true) then only return the version of the plugin (only works from verion 1.0.0.8)
        if($varienObj->getData('version')) {
        	echo $helper->getExtensionVersion();
        	exit;
        }
      
        //authenticate
        $authStatus = Mage::getModel('adyen/authenticate')->authenticate($actionName, $varienObj);
        if (!$authStatus) {
            $this->_writeLog('authentification failure!');
            Mage::log('authentification failure!', Zend_Log::CRIT, "adyen_notification.log", true);
            return "401";
        }

        $incrementId = $varienObj->getData('merchantReference');
        
        try{ 
	
            //get order && payment objects
            $order = Mage::getModel('sales/order');
            
            //error
            $orderExist = $this->_incrementIdExist($incrementId);
            if (empty($orderExist)) {
                $this->_writeLog("unknown order : $incrementId");
                return false;
            }
            $order->loadByIncrementId($incrementId);

            //log
            $order->getPayment()->getMethodInstance()->writeLog($varienObj->debug());

            switch ($actionName) {
                case 'success':
                    $status = $this->_processPostSuccess($order, $varienObj);
                    break;
                default:
                    $status = $this->_processNotifications($order, $varienObj);
                    break;
            }
        }catch(Exception $e){
            // do nothing
        }

        return $status;
    }
    
    
    public function processPosResponse() {  
    	
    	$helper = Mage::helper('adyen');
    	$response = $_REQUEST;

    	
    	$varienObj = new Varien_Object();
    	foreach ($response as $code => $value) {
    		if ($code == 'amount') {
    			if (is_object($value))
    				$value = $value->value;
    			$code = 'value';
    		}
    		$varienObj->setData($code, $value);
    	}
    	
    	$actionName = $this->getRequest()->getActionName();
    	$result = $varienObj->getData('result');

    	// check if result comes from POS device comes form POS
    	if($actionName == "successPos" && $result != "") {

    		$checksum = $varienObj->getData('checksum');
    		
    		// for android checksum is called cs
    		if($checksum == "") {
    			$checksum = $varienObj->getData('cs');
    		}

    		$amount = $varienObj->getData('originalCustomAmount');
    		$currency = $varienObj->getData('originalCustomCurrency');
    		$session_id = $varienObj->getData('sessionId');
    		
    		
     		// for android sessionis is with low i
    		if($session_id == "") {
    			$session_id = $varienObj->getData('sessionid');
    		}
    		
    		// calculate amount checksum
    		$amount_checksum = 0;
    	
    		for($i=0;$i<strlen($amount);$i++)
    		{
    			// ASCII value use ord
    			$checksum_calc = ord($amount[$i]) - 48;
    			$amount_checksum += $checksum_calc;
    		}
    	
    		$currency_checksum = 0;
    		for($i=0;$i<strlen($currency);$i++)
    		{
	    		$checksum_calc = ord($currency[$i]) - 64;
	    		$currency_checksum += $checksum_calc;
    		}
    	
    		$result_checksum = 0;
    		for($i=0;$i<strlen($result);$i++)
    		{
	    		$checksum_calc = ord($result[$i]) - 64;
	    		$result_checksum += $checksum_calc;
    		}
    	 
	    	$session_id_checksum = 0;
	    	for($i=0;$i<strlen($session_id);$i++)
	    	{
		    	$checksum_calc = ord($session_id[$i]) - 48;
		    	$session_id_checksum += $checksum_calc;
	    	}
    		
	    	$total_result_checksum = (($amount_checksum + $currency_checksum + $result_checksum) * $session_id_checksum) % 100;

	    	// check if request is valid
	    	if($total_result_checksum == $checksum) {
	    		
		    	//get order && payment objects
		    	$order = Mage::getModel('sales/order');
		    	//$incrementId = $varienObj->getData('merchantReference');
		    	$incrementId = $varienObj->getData('originalCustomMerchantReference');
		    	
		    	//error
		    	$orderExist = $this->_incrementIdExist($incrementId);
		    	
		    	if (empty($orderExist)) {
		    		$this->_writeLog("unknown order : $incrementId");
		    	} else {
			    	$order->loadByIncrementId($incrementId);

			    	if($result == 'APPROVED') {
						// wait for notification to finish the order
										
						// set adyen event status on true
			    		$order->setAdyenEventCode(Adyen_Payment_Model_Event::ADYEN_EVENT_POSAPPROVED);

			    		$comment = Mage::helper('adyen')
			    				->__('%s <br /> Result: %s <br /> paymentMethod: %s', 'Adyen App Result URL Notification:', $result, 'POS');
			    		
			    		$order->addStatusHistoryComment($comment, false);

			    		try {
			    			$order->save();
			    		} catch (Exception $e) {
			    			Mage::logException($e);
			    		}
			    	} else {

			    		$isBankTransfer = Mage::getModel('adyen/event')
			    			->isBanktransfer($order->getIncrementId());
			    			//attempt to hold/cancel (exceptional to BankTransfer they stay in previous status/pending)
			    			 
			    		if (!$isBankTransfer) {
			    	
			    			$comment = Mage::helper('adyen')
			    				->__('%s <br /> Result: %s <br /> paymentMethod: %s', 'Adyen App Result URL Notification:', $result, 'POS');
			    			 
			    			$order->addStatusHistoryComment($comment, Mage_Sales_Model_Order::STATE_CANCELED);
			    			
			    			$order->setActionFlag(Mage_Sales_Model_Order::ACTION_FLAG_CANCEL, true);

			    			if (!$order->canCancel()) {
			    				$this->_writeLog('order can not be canceled', $order);
			    				$order->addStatusHistoryComment($helper->__('Order can not be canceled'), Mage_Sales_Model_Order::STATE_CANCELED);
			    				$order->save();
			    				
			    			} else {
			    				$order->cancel()->save();
			    			}
			    				
			    		} else {
			    			$this->_addStatusHistoryComment($order, $varienObj, $order->getStatus());
			    			$status = true;
			    		}
			    	}
		    	}
	    	}
    	}
    	// close the window
    	$html = "<html><body>
		    				<script type=\"text/javascript\">
								function closeWindow() {
									window.open('', '_self', '');
									window.close();
								}
								setTimeout(closeWindow, 500);
		    				</script>
		    		</body></html>";
    	
    	return $html;
    }
    
    public function processCashResponse()
    {
    	$response = $_REQUEST;

    	$varienObj = new Varien_Object();
    	foreach ($response as $code => $value) {
    		if ($code == 'amount') {
    			if (is_object($value))
    				$value = $value->value;
    			$code = 'value';
    		}
    		$varienObj->setData($code, $value);
    	}
    	
    	$pspReference = $varienObj->getData('pspReference');
    	$merchantReference = $varienObj->getData('merchantReference');
    	$skinCode =  $varienObj->getData('skinCode');
    	$paymentAmount = $varienObj->getData('paymentAmount');
    	$currencyCode = $varienObj->getData('currencyCode');
    	$customPaymentMethod = $varienObj->getData('c_cash'); 
    	$paymentMethod = $varienObj->getData('paymentMethod');
    	$merchantSig = $varienObj->getData('merchantSig');
    	    	
    	$sign = $pspReference .
		        $merchantReference .
		        $skinCode .
		        $paymentAmount .
		        $currencyCode .
    	 		$customPaymentMethod . $paymentMethod;
    	
    	$secretWord = $this->_getSecretWord();
    	$signMac = Zend_Crypt_Hmac::compute($secretWord, 'sha1', $sign);
    	$calMerchantSig = base64_encode(pack('H*', $signMac));
    	
    	// check if signatures are the same
    	if($calMerchantSig == $merchantSig) {

    		//get order && payment objects
    		$order = Mage::getModel('sales/order');
    		 
    		//error
    		$orderExist = $this->_incrementIdExist($merchantReference);
    		 
    		if (empty($orderExist)) {
    			$this->_writeLog("unknown order : $merchantReference");
    		} else {
    			$order->loadByIncrementId($merchantReference);
    		
	    		$comment = Mage::helper('adyen')
	    		->__('Adyen Cash Result URL Notification: <br /> pspReference: %s <br /> paymentMethod: %s', $pspReference, $paymentMethod);
	    		 
	    		$status = true;
	    		
	    		$history = Mage::getModel('sales/order_status_history')
	    		->setStatus($status)
	    		->setComment($comment)
	    		->setEntityName("order")
	    		->setOrder($order);
	    		$history->save();
	    		
	    		return $status;
    		}
    	}
    	return false;
    }
    
    protected function _getSecretWord($options = null) {
    	switch ($this->getConfigDataDemoMode()) {
    		case true:
    			$secretWord = trim($this->_getConfigData('secret_wordt', 'adyen_hpp'));
    			break;
    		default:
    			$secretWord = trim($this->_getConfigData('secret_wordp', 'adyen_hpp'));
    			break;
    	}
    	return $secretWord;
    }
    
    /**
     * Used via Payment method.Notice via configuration ofcourse Y or N
     * @return boolean true on demo, else false
     */
    public function getConfigDataDemoMode() {
    	if ($this->_getConfigData('demoMode') == 'Y') {
    		return true;
    	}
    	return false;
    }
    
    /**
     * @desc check order existance
     * @param type $incrementId
     * @return type 
     */
    protected function _incrementIdExist($incrementId) {
        return Mage::getResourceModel('adyen/order')->orderExist($incrementId);
    }

    /**
     * @desc Adyen attribute handling
     * @param Varien_Object $order
     * @param type $response 
     */
    protected function _addAdyenAttributes(Varien_Object $order, $response, $updateAdyenStatus = true) {
        $pspReference = $response->getData('pspReference');
        $eventCode = $response->getData('eventCode');
        $authResult = $response->getData('authResult');
        $incrementId = $response->getData('merchantReference');
        $paymentMethod = $response->getData('paymentMethod');
        $eventData = (!empty($eventCode)) ? $eventCode : $authResult;
        $paymentObj = $order->getPayment();
        
        $paymentObj->setLastTransId($incrementId)
	        ->setAdyenPaymentMethod($paymentMethod)
	        ->setCcType($paymentMethod)
	        ;

        // only update this when authroization notification is not yet processed
        Mage::log("AdyenEventCode in paymentobject order:".$order->getAdyenEventCode(), Zend_Log::DEBUG, "adyen_notification.log", true);
        Mage::log("paymentobject order authResult:".$authResult, Zend_Log::DEBUG, "adyen_notification.log", true);
         
        if(!(substr($order->getAdyenEventCode(), 0, 13) == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION && $authResult == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISED)){
        	Mage::log("update paymentobject eventcode with:".$eventData, Zend_Log::DEBUG, "adyen_notification.log", true);
        	$paymentObj->setAdyenEventCode($eventData);
        }
        
        //only original here
        if ($eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISED
                || $eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION)
        {
            $paymentObj->setAdyenPspReference($pspReference);
        }
  
        try {
        	//save all response data for a pure duplicate detection
	        Mage::getModel('adyen/event')
	                ->setPspReference($pspReference)
	                ->setAdyenEventCode($eventCode)
	                ->setAdyenEventResult($eventData)
	                ->setIncrementId($incrementId)
	                ->setPaymentMethod($paymentMethod)
	                ->setCreatedAt(now())
	                ->saveData($updateAdyenStatus) // don't update the adyen status
	        ;  
        } catch (Exception $e) {
                Mage::log($e->getMessage(), Zend_Log::DEBUG, "adyen_notification.log", true);
        }
    }

    /**
     * @desc Process what happened on Adyen during Hpp
     * @param Varien_Object $params
     */
    protected function _processPostSuccess($order, $params) {

    	//set these attributes here
        $this->_addAdyenAttributes($order, $params, false);
        $status = false;
        $authResult = $params->getData('authResult');
        $pspReference = $params->getData('pspReference');
        switch ($authResult) {
            case Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISED:
            case Adyen_Payment_Model_Event::ADYEN_EVENT_PENDING:
            	Mage::log("PAYMENT POST URL BEFORE SAVING STATUS:".$order->getStatus(), Zend_Log::DEBUG, "adyen_notification.log", true);
            	
            	$type = "Adyen Result URL Notification(s):";
            	$pspReference = $params->getData('pspReference');
            	$paymentMethod = $params->getData('paymentMethod');
            	
            	$comment = Mage::helper('adyen')
            		->__('%s <br /> authResult: %s <br /> pspReference: %s <br /> paymentMethod: %s', $type, $authResult, $pspReference, $paymentMethod);
            	 
            	$history = Mage::getModel('sales/order_status_history')
	            	->setStatus($status)
	            	->setComment($comment)
	            	->setEntityName("order")
            		->setOrder($order);
            	$history->save();
            	$status = true;
            	// don't save the order because of interferrence with order status (set by notifications)
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CANCELLED:
                $isBankTransfer = Mage::getModel('adyen/event')
                        ->isBanktransfer($order->getIncrementId());
                //attempt to hold/cancel (exceptional to BankTransfer they stay in previous status/pending)
                if (!$isBankTransfer) {
                    $this->_addStatusHistoryComment($order, $params);
                    $this->holdCancelOrder($order, $params);
                } else {
                    $this->_addStatusHistoryComment($order, $params, $order->getStatus());
                    $status = true;
                }
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_REFUSED:
            case Adyen_Payment_Model_Event::ADYEN_EVENT_ERROR:
                $this->_addStatusHistoryComment($order, $params);
                //attempt to hold/cancel
                $this->holdCancelOrder($order, $params);
                break;
            default:
                $order->getPayment()->getMethodInstance()->writeLog('response not supported!');
                break;
        }
        return $status;
    }

    /**
     * @desc process notifications
     * @param type $order
     * @param type $response
     * @return type 
     */
    public function notificationHandler($order, $response) {
        $payment = $order->getPayment()->getMethodInstance();
        $pspReference = trim($response->getData('pspReference'));
        $success = trim($response->getData('success'));
        $eventCode = trim($response->getData('eventCode'));

        //handle duplicates
        $isDuplicate = Mage::getModel('adyen/event')
                ->isDuplicate($pspReference, $eventCode);
        if ($isDuplicate) {
            $payment->writeLog("#skipping duplicate notification pspReference:$pspReference && eventCode: $eventCode");
            return false; //hmt
        }

        //set these attributes here
        $this->_addAdyenAttributes($order, $response);
        
        //add comment to the order
        if (strcmp($success, 'false') == 0 || !$success) {
            $status = ($order->isCanceled() || ($order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)) ?
                    Mage_Sales_Model_Order::STATE_CANCELED : $order->getStatus();
            $this->_addStatusHistoryComment($order, $response, $status);
        } else {
            $this->_addStatusHistoryComment($order, $response, $order->getStatus());
        }

        //success failed
        if (strcmp($success, 'false') == 0 || !$success) {

            //attempt to hold/cancel
            $this->holdCancelOrder($order, $response);

            $payment->writeLog('success failed');
            //exit();
            return false; //hmt
        }
        return true;
    }

    /**
     * @desc process notifications
     * @param type $order
     * @param type $response 
     */
    protected function _processNotifications($order, $response) {
        $valid = $this->notificationHandler($order, $response); //hmt: added $valid
        
        if ($valid) {
        $eventCode = trim($response->getData('eventCode'));
        
        $success = (bool) trim($response->getData('success'));
        switch ($eventCode) {
            case Adyen_Payment_Model_Event::ADYEN_EVENT_REFUND:

                $this->refundOrder($order, $response);
                //refund completed
                $this->setRefundAuthorized($order, $success);
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_PENDING:
                //add comment to the order
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_HANDLEDEXTERNALLY:
            case Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION:
                //pre-authorise if success
            	$order->sendNewOrderEmail(); // send order email
            	
                $this->setPrePaymentAuthorized($order, $success);

                $this->createInvoice($order, $response);
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CAPTURE:
                $this->setPaymentAuthorized($order, $success);
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CANCELLATION:
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CANCELLED:
                $this->holdCancelOrder($order, $response);
                break;
            default:
                //@todo fix me cancel && error here
                $order->getPayment()->getMethodInstance()->writeLog('notification event not supported!');
                break;
        }
        }
    }

    /**
     * @since v0.1.0.5
     * @param type $order
     * @param type $success 
     */
    public function setPaymentAuthorized($order, $success = false) {
        if ($success && !empty($order)) {
            $status = $this->_getConfigData('payment_authorized');
            $status = (!empty($status)) ? $status : $order->getStatus();
            $order->addStatusHistoryComment(Mage::helper('adyen')->__('Adyen Payment Successfully completed'), $status);
            $order->sendOrderUpdateEmail((bool) $this->_getConfigData('send_update_mail'));
            Mage::log("PAYMENT CAPTURE STATUS:".$order->getStatus(), Zend_Log::DEBUG, "adyen_notification.log", true);
            $order->save();
            return true;
        }
        return false;
    }

    /**
     * @since v0.1.0.5
     * @param type $order
     * @param type $success 
     */
    public function setPrePaymentAuthorized($order, $success = false) {
        if ($success && !empty($order)) {
            $status = $this->_getConfigData('payment_pre_authorized');
            $status = (!empty($status)) ? $status : $order->getStatus();
            $order->addStatusHistoryComment(Mage::helper('adyen')->__('Payment is pre authorised waiting for capture'), $status);
            $order->sendOrderUpdateEmail((bool) $this->_getConfigData('send_update_mail'));
            Mage::log("PAYMENT PRE AUTHORIZED STATUS:".$order->getStatus(), Zend_Log::DEBUG, "adyen_notification.log", true);
            $order->save();
            return true;
        }
        return false;
    }

    /**
     * @since v0.1.0.8
     * @param type $order
     * @param type $success 
     */
    public function setRefundAuthorized($order, $success = false) {
        if ($success && !empty($order)) {
            $status = $this->_getConfigData('refund_authorized');
            $status = (!empty($status)) ? $status : $order->getStatus();
            $order->addStatusHistoryComment(Mage::helper('adyen')->__('Adyen Refund Successfully completed'), $status);
            $order->sendOrderUpdateEmail((bool) $this->_getConfigData('send_update_mail'));
            $order->save();
            return true;
        }
        return false;
    }

    /**
     * @desc Determine wether to create invoice or not using notifications
     * @param Varien_Object $response
     * @return true or false
     * @notice ideal is exception here
     * @since 0.0.9.x
     */
    public function isAutoCapture($response) {
        $paymentMethod = trim($response->getData('paymentMethod'));
        $captureMode = trim($this->_getConfigData('capture_mode'));
        // payment method ideal and cash has direct capture
        if (strcmp($paymentMethod, 'ideal') === 0 || strcmp($paymentMethod, 'c_cash') === 0 ) {
            return true;
        }
        if (strcmp($captureMode, 'manual') === 0) {
            return false;
        }
        //online capture after delivery, use Magento backend to online invoice
        if (strcmp($paymentMethod, 'openinvoice') === 0) {
            return false;
        }
        return true;
    }

    /**
     * @desc Handle Refund here
     * @todo create credit memo && set order status to closed
     * @param Varien_Object $order
     * @param Varien_Object $response
     * @since 0.0.9.2
     */
    public function refundOrder($order, $response) {

        //skip orders with [refund-received]
        $pspReference = trim($response->getData('pspReference'));
        $result = Mage::getModel('adyen/event')
                ->getEvent($pspReference, '[refund-received]');
        if (!empty($result)) {
            $this->_writeLog("\nSkip refund process, as refund initiated via Magento id: {$order->getIncrementId()}");
            return false;
        }

        $_mail = (bool) $this->_getConfigData('send_update_mail');
        $amount = $response->getValue() / 100;

        if ($order->canCreditmemo()) {
            $service = Mage::getModel('sales/service_order', $order);
            $creditmemo = $service->prepareCreditmemo();
            $creditmemo->getOrder()->setIsInProcess(true);

            //set refund data on the order
            $creditmemo->setGrandTotal($amount);
            $creditmemo->setBaseGrandTotal($amount);
            $creditmemo->save();

            try {
                Mage::getModel('core/resource_transaction')
                        ->addObject($creditmemo)
                        ->addObject($creditmemo->getOrder())
                        ->save();
                //refund
                $creditmemo->refund();
                $transactionSave = Mage::getModel('core/resource_transaction')
                        ->addObject($creditmemo)
                        ->addObject($creditmemo->getOrder());
                if ($creditmemo->getInvoice()) {
                    $transactionSave->addObject($creditmemo->getInvoice());
                }
                $transactionSave->save();
                if ($_mail) {
                    $creditmemo->getOrder()->setCustomerNoteNotify(true);
                    $creditmemo->sendEmail();
                }
            } catch (Exception $e) {
                $this->_writeLog($e->getMessage());
            }
        } else {
            $this->_writeLog("\nOrder can not refund {$order->getIncrementId()}");
        }
    }

    /**
     * @desc Create invoice
     * @param type $order
     * @param type $response
     * @return type 
     */
    public function createInvoice($order, $response) {
        $payment = $order->getPayment()->getMethodInstance();
        $pspReference = trim($response->getData('pspReference'));
        $success = trim($response->getData('success'));
        $eventCode = trim($response->getData('eventCode'));
        $reason = trim($response->getData('reason'));
        $invoiceAutoMail = (bool) $this->_getConfigData('send_invoice_update_mail');
        $_status = $this->_getConfigData('order_status');
        $_mail = (bool) $this->_getConfigData('send_update_mail');
        $value = trim($response->getData('value'));
        
		//create invoice
        if (strcmp($order->getState(), Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW) == 0) {
            $order->setState(Mage_Sales_Model_Order::STATE_NEW);
        }

        //capture mode
        if (!$this->isAutoCapture($response)) {
        	$order->addStatusHistoryComment(Mage::helper('adyen')->__('Capture Mode set to Manual'));
        	$order->sendOrderUpdateEmail($_mail);
        	$order->save();
        	return false;
        }
        
        if ($order->canInvoice()) {
            $invoice = $order->prepareInvoice();
            $invoice->getOrder()->setIsInProcess(true);
            $invoice->register()->capture();
            try {
                Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder())
                        ->save();
            } catch (Exception $e) {
                $payment->writeLog($e->getMessage());
            }

            //selected adyen status
            $this->setPaymentAuthorized($order, $success);

            if ($invoiceAutoMail) {
                $invoice->sendEmail();
            }
        }
        $order->sendOrderUpdateEmail($_mail);
        $order->save();
    }

    /**
     * @desc order comments or history
     * @param type $order
     * @param Varien_Object $response 
     */
    protected function _addStatusHistoryComment($order, Varien_Object $response, $status = false) {
    	Mage::log("_addStatusHistoryComment", Zend_Log::DEBUG, "adyen_notification.log", true);
                	
        //notification
        $pspReference = $response->getData('pspReference');
        $success = trim($response->getData('success'));
        $success_result = (strcmp($success, 'false') == 0 || !$success) ? 'false' : 'true';
        $eventCode = $response->getData('eventCode');
        $reason = $response->getData('reason');
        $success = (!empty($reason)) ? "$success_result <br />reason:$reason" : $success_result;
        $klarnaReservationNumber = $response->getData('additionalData_additionalData_acquirerReference');

        //post
        $authResult = $response->getData('authResult');
        $pspReference = $response->getData('pspReference');

        //payment method
        $paymentMethod = $response->getData('paymentMethod');

        //data type
        $type = (!empty($authResult)) ? 'Adyen Result URL Notification(s):' : 'Adyen HTTP Notification(s):';
        switch ($type) {
            case 'Adyen Result URL Notification(s):':
/*PCD*/ // choose not to update the adyen_event_code in the order when the order is already on notification:Authorisation status and authresult = resultURL:Authorised
                if(!(substr($order->getAdyenEventCode(), 0, 13) == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION && $authResult == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISED)){
                	Mage::log("Adyen Result URL order authResult:".$authResult, Zend_Log::DEBUG, "adyen_notification.log", true);
                	 
                	$order->setAdyenEventCode($authResult);
                }
                $comment = Mage::helper('adyen')
                        ->__('%s <br /> authResult: %s <br /> pspReference: %s <br /> paymentMethod: %s', $type, $authResult, $pspReference, $paymentMethod);
                break;
            default:
            	Mage::log("default order authResult:".$eventCode . " : " . strtoupper($success_result), Zend_Log::DEBUG, "adyen_notification.log", true);
            	
                $order->setAdyenEventCode($eventCode . " : " . strtoupper($success_result));

                // if payment method is klarna or openinvoice/afterpay show the reservartion number
                if(($paymentMethod == "klarna" || $paymentMethod == "afterpay_default" || $paymentMethod == "openinvoice") && ($klarnaReservationNumber != null && $klarnaReservationNumber != "")) {
                    $klarnaReservationNumberText = "<br /> reservationNumber: " . $klarnaReservationNumber;
                } else {
                    $klarnaReservationNumberText = "";
                }

                $comment = Mage::helper('adyen')
                    ->__('%s <br /> eventCode: %s <br /> pspReference: %s <br /> paymentMethod: %s <br /> success: %s %s ', $type, $eventCode, $pspReference, $paymentMethod, $success, $klarnaReservationNumberText);

                break;
        }
        $order->addStatusHistoryComment($comment, $status);

/*PCD*/	$order->save();
    }

    /**
     * Handle order cancellation && success failure on notifications
     * Called for all failed notifications, even cancellations
     * @param unknown_type $order
     * @param unknown_type $response
     */
    public function holdCancelOrder($order, $response = null) {
        $eventCode = trim($response->getData('eventCode'));
        $orderStatus = $this->_getConfigData('payment_cancelled');
        switch ($eventCode) {
            case Adyen_Payment_Model_Event::ADYEN_EVENT_REFUND:
                $orderStatus = Mage_Sales_Model_Order::STATE_HOLDED;
                break;
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CANCELLATION:
            case Adyen_Payment_Model_Event::ADYEN_EVENT_CANCELLED:
                $orderStatus = Mage_Sales_Model_Order::STATE_CANCELED;
                break;
        }
        $_mail = (bool) $this->_getConfigData('send_update_mail');
        $helper = Mage::helper('adyen');
        switch ($orderStatus) {
            case Mage_Sales_Model_Order::STATE_HOLDED:
                $order->setActionFlag(Mage_Sales_Model_Order::ACTION_FLAG_HOLD, true);
                if (!$order->canHold()) {
                    $this->_writeLog('order can not hold', $order);
                    $order->addStatusHistoryComment($helper->__('Order can not Hold'), Mage_Sales_Model_Order::STATE_HOLDED);
                    $order->save();
                    return false;
                }
                $order->hold()->save();
                break;
            case Mage_Sales_Model_Order::STATE_CANCELED:
                $order->setActionFlag(Mage_Sales_Model_Order::ACTION_FLAG_CANCEL, true);
                if (!$order->canCancel()) {
                    $this->_writeLog('order can not be canceled', $order);
                    $order->addStatusHistoryComment($helper->__('Order can not be canceled'), Mage_Sales_Model_Order::STATE_CANCELED);
                    $order->save();
                    return false;
                }
                $order->cancel()->save();
                break;
        }
        $order->sendOrderUpdateEmail($_mail);
        $order->save();
        return true;
    }

    protected function _writeLog($str, $order = null) {
        if (!empty($order)) {
            $order->getPayment()->getMethodInstance()->writeLog($str);
        }
    }

    protected function _getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    protected function _getOrder() {
        return Mage::getModel('sales/order');
    }

    /**
     * @since 0.0.1
     * @desprecated since 0.0.2, over _getPayment
     */
    protected function _getHpp() {
        return Mage::getModel('adyen/adyen_hpp');
    }

    /**
     * @since 0.0.2
     * @param unknown_type $order
     */
    protected function _paymentMethodCode($order) {
        return $order->getPayment()->getMethod();
    }

    /**
     * @since 0.0.2
     * @param unknown_type $order
     */
    protected function _getPayment($order) {
        $_paymentCode = $this->_paymentMethodCode($order);
        //@todo strict $paymentMethodCode to known payment methods i.e adyen_hpp, adyen_cc,adyen_elv
        return Mage::getModel("adyen/$_paymentCode");
    }

    /**
     * @desc Give Default settings
     * @example $this->_getConfigData('demoMode','adyen_abstract')
     * @since 0.0.2
     * @param string $code
     */
    protected function _getConfigData($code, $paymentMethodCode = null, $storeId = null) {
        if (null === $storeId) {
            $storeId = Mage::app()->getStore()->getId();
        }
        if (empty($paymentMethodCode)) {
            return Mage::getStoreConfig("payment/adyen_abstract/$code", $storeId);
        }
        return Mage::getStoreConfig("payment/$paymentMethodCode/$code", $storeId);
    }

    public function getConfigData($code, $paymentMethodCode = null, $storeId = null) {
        return $this->_getConfigData($code, $paymentMethodCode, $storeId);
    }

    public function getRequest() {
        return Mage::app()->getRequest();
    }

}
