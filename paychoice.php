<?php
/**
 * @plugin VMPayment - Paychoice
 * @Website : http://www.paychoice.com.au
 * @version $Id:
 * @package VirtueMart
 * @subpackage Plugins - payment
 * @author Paychoice
 * @copyright Copyright (C) 2012 Paychoice - All rights reserved.
 * @license license.txt Proprietary License. This code belongs to alatak.net
 * You are not allowed to distribute or sell this code.
 * You are not allowed to modify this code.
 * http://www.paychoice.com.au
 */

if (!defined('_JEXEC')) die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('Creditcard')) require_once(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'creditcard.php');
if (!class_exists('vmPSPlugin')) require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmpaymentPaychoice extends vmPSPlugin
{
	// instance of class
	public static $_this = false;
	private $_cc_name = '';
	private $_cc_type = '';
	private $_cc_name_on_card = '';
	private $_cc_number = '';
	private $_cc_cvv = '';
	private $_cc_expire_month = '';
	private $_cc_expire_year = '';
	private $_cc_valid = false;
	private $_errormessage = array();
	public $approved;
	public $declined;
	public $error;

	const APPROVED = 1;
	const DECLINED = 2;
	const ERROR = 3;
	const HELD = 4;
	
    // instance of class
    function __construct(& $subject, $config)
	{

		parent::__construct($subject, $config);

		$this->_loggable = true;
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$this->tableFields = array_keys($this->getTableSQLFields());
		$varsToPush = array(
			'paychoice_username' => array('', 'char'),
			'paychoice_password' => array('', 'char'),
			'paychoice_verified_status' => array('C', 'char'),
			'paychoice_invalid_status' => array('P', 'char'),
			'paychoice_test_request' => array(1, 'int'),
			'paychoice_process_type' => array('', 'char'),
			'payment_logos' => array('', 'char'),
			'countries' => array(0, 'char'),
			'min_amount' => array(0, 'int'),
			'max_amount' => array(0, 'int'),
			'tax_id' => array(0, 'int')
		);

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

    protected function getVmPluginCreateTableSQL() { return $this->createTableSQL('Payment Paychoice Table'); }

	function getTableSQLFields()
	{
		$SQLfields = array(
			'id' => ' int(1) unsigned NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => ' int(1) UNSIGNED DEFAULT NULL',
			'order_number' => ' char(32) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => ' mediumint(1) UNSIGNED DEFAULT NULL',
			'payment_name' => 'varchar(5000)',
			'return_context' => ' char(255) NOT NULL DEFAULT \'\' ',
			'tax_id' => ' smallint(1) DEFAULT NULL',
			'paychoice_response_response_code' => '  varchar(50) DEFAULT NULL',
			'paychoice_response_transaction_id' => '  varchar(50) DEFAULT NULL',
			'paychoice_response_error_message' => ' text DEFAULT NULL',
			'paychoice_response_raw' => ' text DEFAULT NULL',
		);

		return $SQLfields;
	}

    /**
     * This shows the plugin for choosing in the payment list of the checkout process.
     *
     * @author Valerie Cartan Isaksen
     */
	function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected=0, &$htmlIn)
	{
		JHTML::_('behavior.tooltip');

		if ($this->getPluginMethods($cart->vendorId) === 0) {
			if (empty($this->_name)) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(JText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
				return false;
			}
			else
			{
				return false;
			}
		}

		$html = array();
		$method_name = $this->_psType . '_name';

		JHTML::script('vmcreditcard.js', 'components/com_virtuemart/assets/js/', false);
		JFactory::getLanguage()->load('com_virtuemart');
		vmJsApi::jCreditCard();
		$htmla = '';
		$html = array();
		foreach ($this->methods as $method)
		{
			if ($this->checkConditions($cart, $method, $cart->pricesUnformatted))
			{
				$methodSalesPrice = $this->calculateSalesPrice($cart, $method, $cart->pricesUnformatted);
				$method->$method_name = $this->renderPluginName($method);
				$html = $this->getPluginHtml($method, $selected, $methodSalesPrice);
				if ($selected == $method->virtuemart_paymentmethod_id)
				{
					$this->_getPaychoiceIntoSession();
				}
				else
				{
					$this->_cc_type = '';
					$this->_cc_name_on_card = '';
					$this->_cc_number = '';
					$this->_cc_cvv = '';
					$this->_cc_expire_month = '';
					$this->_cc_expire_year = '';
				}
				//$creditCards = $method->creditcards;
				$creditCards = array('Visa','Mastercard','AmericanExpress','Diners');
				$creditCardList = '';
				if ($creditCards)
				{
					$creditCardList = ($this->_renderCreditCardList($creditCards, $this->_cc_type, $method->virtuemart_paymentmethod_id, false));
				}
				$sandbox_msg = "";
				if ($method->paychoice_test_request)
				{
					$sandbox_msg .= '<br />' . JText::_('VMPAYMENT_PAYCHOICE_SANDBOX_TEST_NUMBERS');
				}

				$html .= '<br /><span class="vmpayment_cardinfo">' . JText::_('VMPAYMENT_PAYCHOICE_COMPLETE_FORM') . $sandbox_msg . '
					<table border="0" cellspacing="0" cellpadding="2" width="100%">
					<tr valign="top">
						<td nowrap width="10%" align="right"><label for="creditcardtype">' . JText::_('VMPAYMENT_PAYCHOICE_CCTYPE') . ':</label>&nbsp;</td>
						<td>' . $creditCardList .'</td>
					</tr>
					<tr valign="top">
						<td nowrap width="10%" align="right"><label for="cc_cvv">' . JText::_('VMPAYMENT_PAYCHOICE_NAMEONCARD') . ':</label>&nbsp;</td>
						<td><input type="text" class="inputbox" id="cc_name_on_card_' . $method->virtuemart_paymentmethod_id . '" name="cc_name_on_card_' . $method->virtuemart_paymentmethod_id . '" value="' . $this->_cc_name_on_card . '" autocomplete="off" /></td>
					</tr>

					<tr valign="top">
						<td nowrap width="10%" align="right"><label for="cc_type">' . JText::_('VMPAYMENT_PAYCHOICE_CCNUM') . ':</label>&nbsp;</td>
						<td><input type="text" class="inputbox" id="cc_number_' . $method->virtuemart_paymentmethod_id . '" name="cc_number_' . $method->virtuemart_paymentmethod_id . '" value="' . $this->_cc_number . '"    autocomplete="off"   onchange="ccError=razCCerror(' . $method->virtuemart_paymentmethod_id . ');
							CheckCreditCardNumber(this . value, ' . $method->virtuemart_paymentmethod_id . ');
							if (!ccError) {this.value=\'\';}" />
							<div id="cc_cardnumber_errormsg_' . $method->virtuemart_paymentmethod_id . '"></div>
						</td>
					</tr>
					<tr valign="top">
						<td nowrap width="10%" align="right"><label for="cc_cvv">' . JText::_('VMPAYMENT_PAYCHOICE_CVV2') . ':</label>&nbsp;</td>
						<td><input type="text" class="inputbox" id="cc_cvv_' . $method->virtuemart_paymentmethod_id . '" name="cc_cvv_' . $method->virtuemart_paymentmethod_id . '" maxlength="4" size="5" value="' . $this->_cc_cvv . '" autocomplete="off" />
							<span class="hasTip" title="' . JText::_('VMPAYMENT_PAYCHOICE_WHATISCVV') . '::' . JText::sprintf("VMPAYMENT_PAYCHOICE_WHATISCVV_TOOLTIP", '') . ' ">'.JText::_('VMPAYMENT_PAYCHOICE_WHATISCVV') . '</span>
						</td>
					</tr>
					<tr><td nowrap width="10%" align="right">' . JText::_('VMPAYMENT_PAYCHOICE_EXDATE') . ':&nbsp;</td>
					<td> ';
				$html .= shopfunctions::listMonths('cc_expire_month_' . $method->virtuemart_paymentmethod_id, $this->_cc_expire_month);
				$html .= " / ";

				$html .= shopfunctions::listYears('cc_expire_year_' . $method->virtuemart_paymentmethod_id, $this->_cc_expire_year, null, null, "onchange=\"var month = document.getElementById('cc_expire_month_'.$method->virtuemart_paymentmethod_id); if(!CreditCardisExpiryDate(month.value,this.value, '.$method->virtuemart_paymentmethod_id.')){this.value='';month.value='';}\" ");
				$html .='<div id="cc_expiredate_errormsg_' . $method->virtuemart_paymentmethod_id . '"></div>';
				$html .= '</td>  </tr>  	</table></span>';

				$htmla[] = $html;
			}
		}
		$htmlIn[] = $htmla;

		return true;
	}

    /**
     * Check if the payment conditions are fulfilled for this payment method
     * @author: Valerie Isaksen
     *
     * @param $cart_prices: cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
	protected function checkConditions($cart, $method, $cart_prices)
	{
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount = $cart_prices['salesPrice'];
		$amount_cond = ( ($amount >= $method->min_amount AND $amount <= $method->max_amount) || ($amount >= $method->min_amount AND ($method->max_amount == 0) ));
		if (!$amount_cond) return false;

		$countries = array();
		if (!empty($method->countries))
		{
			if (!is_array($method->countries)) $countries[0] = $method->countries;
			else $countries = $method->countries;
		}

		// probably did not gave his BT:ST address
		if (!is_array($address))
		{
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) $address['virtuemart_country_id'] = 0;
		if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0)
		{
			return true;
		}

		return false;
	}

    function _setPaychoiceIntoSession()
	{
		$session = JFactory::getSession();
		$data = new stdClass();
		// card information
		$data->cc_type = $this->_cc_type;
		$data->cc_name_on_card = $this->_cc_name_on_card;
		$data->cc_number = $this->_cc_number;
		$data->cc_cvv = $this->_cc_cvv;
		$data->cc_expire_month = $this->_cc_expire_month;
		$data->cc_expire_year = $this->_cc_expire_year;
		$data->cc_valid = $this->_cc_valid;
		$session->set('paychoice', serialize($data), 'vm');
	}

	function _getPaychoiceIntoSession()
	{
		$session = JFactory::getSession();
		$paychoiceSession = $session->get('paychoice', 0, 'vm');

		if (!empty($paychoiceSession))
		{
			$data = unserialize($paychoiceSession);
			$this->_cc_type = $data->cc_type;
			$this->_cc_name_on_card = $data->cc_name_on_card;
			$this->_cc_number = $data->cc_number;
			$this->_cc_cvv = $data->cc_cvv;
			$this->_cc_expire_month = $data->cc_expire_month;
			$this->_cc_expire_year = $data->cc_expire_year;
			$this->_cc_valid = $data->cc_valid;
		}
	}

    /**
     * This is for checking the input data of the payment method within the checkout
     *
     * @author Valerie Cartan Isaksen
     */
	function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart)
	{
		if (!$this->selectedThisByMethodId( $cart->virtuemart_paymentmethod_id))
		{
			return null; // Another method was selected, do nothing
		}
		$this->_getPaychoiceIntoSession();
		return $this->_validate_creditcard_data(true);
	}

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
	{
	return parent::onStoreInstallPluginTable(  $jplugin_id);
	}

    /**
     * This is for adding the input data of the payment method to the cart, after selecting
     *
     * @author Valerie Isaksen
     *
     * @param VirtueMartCart $cart
     * @return null if payment not selected; true if card infos are correct; string containing the errors id cc is not valid
     */
	function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
	{
		if (!$this->selectedThisByMethodId(  $cart->virtuemart_paymentmethod_id))
		{
			return null; // Another method was selected, do nothing
		}

		$this->_cc_type = JRequest::getVar('cc_type_' . $cart->virtuemart_paymentmethod_id, '');
		$this->_cc_name_on_card = JRequest::getVar('cc_name_on_card_' . $cart->virtuemart_paymentmethod_id, '');
		$this->_cc_number = str_replace(" ","",JRequest::getVar('cc_number_' . $cart->virtuemart_paymentmethod_id, ''));
		$this->_cc_cvv = JRequest::getVar('cc_cvv_' . $cart->virtuemart_paymentmethod_id, '');
		$this->_cc_expire_month = JRequest::getVar('cc_expire_month_' . $cart->virtuemart_paymentmethod_id, '');
		$this->_cc_expire_year = JRequest::getVar('cc_expire_year_' . $cart->virtuemart_paymentmethod_id, '');

		if (!$this->_validate_creditcard_data(true))
		{
			return false; // returns string containing errors
		}
		$this->_setPaychoiceIntoSession();
		
		return true;
	}

	public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, $payment_name)
	{
		if (!($method = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id)))
		{
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element))
		{
			return false;
		}

		$this->_getPaychoiceIntoSession();
		$cart_prices['payment_tax_id'] = 0;
		$cart_prices['payment_value'] = 0;

		if (!$this->checkConditions($cart, $method, $cart_prices))
		{
			return false;
		}
		$payment_name = $this->renderPluginName($method);

		$this->setCartPrices($cart, $cart_prices, $method);

		return true;
	}
	
	/*
     * @param $plugin plugin
     */
	protected function renderPluginName($plugin)
	{
		$return = '';
		$plugin_name = $this->_psType . '_name';
		$plugin_desc = $this->_psType . '_desc';
		$description = '';
		$logosFieldName = $this->_psType . '_logos';
		$logos = $plugin->$logosFieldName;
		if (!empty($logos)) $return = $this->displayLogos($logos) . ' ';

		if (!empty($plugin->$plugin_desc)) $description = '<span class="' . $this->_type . '_description">' . $plugin->$plugin_desc . '</span>';

		$this->_getPaychoiceIntoSession();
		$extrainfo=$this->getExtraPluginNameInfo();

		$pluginName = $return . '<span class="' . $this->_type . '_name">' . $plugin->$plugin_name . '</span>' . $description. $extrainfo ;
		
		return $pluginName;
	}

	/**
     * Display stored payment data for an order
     * @see components/com_virtuemart/helpers/vmPaymentPlugin::plgVmOnShowOrderPaymentBE()
     */
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
	{
		if (!$this->selectedThisByMethodId( $virtuemart_payment_id))
		{
			return null; // Another method was selected, do nothing
		}
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject()))
		{
			JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}

		// <todo>
		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('PAYCHOICE_PAYMENT_NAME', $paymentTable->payment_name);
		$code = "paychoice_response_";
		foreach ($paymentTable as $key => $value)
		{
			if($key=='paychoice_response_raw') continue;
			if (substr($key, 0, strlen($code)) == $code)
			{
				$html .= $this->getHtmlRowBE($key, $value);
			}
		}
		$html .= '</table>' . "\n";
		
		// </todo>
		return $html;
	}

    /**
     * Reimplementation of vmPaymentPlugin::plgVmOnConfirmedOrder()
     *
     * @link http://www.paychoice.com.au/Developer/Testing/
     * Credit Cards Test Number: 4444333322221111
     */
	function plgVmConfirmedOrder($cart, $order)
	{
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)))
		{
			return null; // Another method was selected, do nothing
		}
		
		if (!$this->selectedThisElement($method->payment_element))
		{
			return false;
		}

		$usrBT = $order['details']['BT'];
		$usrST = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);
		$session = JFactory::getSession();
		$return_context = $session->getId();
		$transaction_key = $this->get_passkey();
		if ($transaction_key === false)
		{
			return false;
		}

		// Prepare data that should be stored in the database
		$dbValues = array();
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
		$dbValues['payment_method_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['return_context'] = $return_context;
		$dbValues['payment_name'] = parent::renderPluginName($method);
		$this->storePSPluginInternalData($dbValues);

		// send a request
		$vendorId = 1;
		$vendorModel = VmModel::getModel('vendor');
		$vendorName = $vendorModel->getVendorName($vendorId);
		
		$params = (object) array(
			'custid'=>$this->_getCustID($method),
			'invoice_ref'=>$order['details']['BT']->order_number,
			'nameoncard'=>$this->_cc_name_on_card,
			'cc_number'=>$this->_cc_number,
			'expirymonth'=>$this->_cc_expire_month,
			'expiryyear'=>substr($this->_cc_expire_year,2,2),  // We need to show the year with two digits only
			'cvv'=>$this->_cc_cvv,
			'trans_number'=>uniqid( "paychoice_" ),
			'order_number'=>$order['details']['BT']->order_number,
			'order_total'=>$order['details']['BT']->order_total, // WE need the $order_total in cents!
		);

		$response = $this->_doPayment($method,$params);
		$html = $this->_handleResponse($response, $order, $dbValues['payment_name']);

		if ($this->error)
		{
			JRequest::setVar('html', $html);
			return; // will not process the order
		}
		else if ($this->approved)
		{
			$new_status = $method->paychoice_verified_status;
		}
		else if ($this->declined)
		{
			$new_status = $method->paychoice_invalid_status;
		}
		$this->_clearPaychoiceSession();
		
		return $this->processConfirmedOrderPaymentResponse(1, $cart, $order, $html,$dbValues['payment_name'], $new_status);
    }

    function _handleResponse($response, $order, $payment_name)
	{
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($response->order_number);
		if (!$virtuemart_order_id)
		{
			$this->approved = false;
			$this->error = true;
			$this->logInfo(JText::sprintf('VMPAYMENT_PAYCHOICE_ERROR_NO_ORDER_NUMBER', $response->order_number), 'ERROR');
			$this->sendEmailToVendorAndAdmins(JText::sprintf('VMPAYMENT_PAYCHOICE_ERROR_NO_ORDER_NUMBER', $response->order_number), JText::sprintf('VMPAYMENT_PAYCHOICE_ERROR_WHILE_PROCESSING_PAYMENT', $response->order_number));
			$html = Jtext::sprintf('VMPAYMENT_PAYCHOICE_ERROR', $response->errormsg, $response->error) . "<br />";
			$this->logInfo($html, 'PAYMENT DECLINED');
			return $html; // the transaction has been submitted, we don't want to delete the order	    }
		}
		
		if( $response->response == 'accepted' )
		{
			$this->approved = true;
			$this->error = false;
			$response_fields = array();
			$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
			$response_fields['invoice_number'] = $response->order_number;
			$response_fields['paychoice_response_response_code'] = $response->response;
			$response_fields['paychoice_response_transaction_id'] = $response->paychoiceTrxnNumber;
			$response_fields['paychoice_response_error_message'] = $response->errormsg;
			$response_fields['paychoice_response_raw'] = $response->raw;
			$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);
		}
		elseif($response->response == 'declined')
		{
			$this->approved = false;
			$this->declined = true;
			$html = Jtext::sprintf('VMPAYMENT_PAYCHOICE_ERROR', $response->errormsg, $response->error) . "<br />";
			$this->logInfo($html, 'PAYMENT DECLINED');

			$response_fields = array();
			$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
			$response_fields['invoice_number'] = $response->order_number;
			$response_fields['paychoice_response_response_code'] = $response->response;
			$response_fields['paychoice_response_transaction_id'] = $response->paychoiceTrxnNumber;
			$response_fields['paychoice_response_error_message'] = $response->errormsg;
			$response_fields['paychoice_response_raw'] = $response->raw;
			$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);

			return $html; // the transaction has been submitted, we don't want to delete the order

		}
        else
		{
			$this->approved = false;
			$this->error = true;
			$this->logInfo(JText::_('VMPAYMENT_PAYCHOICE_ERROR_CONNECTING'), 'ERROR');
			$this->sendEmailToVendorAndAdmins(JText::_('VMPAYMENT_PAYCHOICE_ERROR_EMAIL_SUBJECT'), JText::_('VMPAYMENT_PAYCHOICE_ERROR_CONNECTING'));

			$response_fields = array();
			$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
			$response_fields['invoice_number'] = $response->order_number;
			$response_fields['paychoice_response_response_code'] = $response->response;
			$response_fields['paychoice_response_transaction_id'] = $response->paychoiceTrxnNumber;
			$response_fields['paychoice_response_error_message'] = $response->errormsg;
			$response_fields['paychoice_response_raw'] = null;
			$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);

			return JText::_('VMPAYMENT_PAYCHOICE_ERROR_CONNECTING');
		}


		$currencyModel = VmModel::getModel('Currency');
		$currency = $currencyModel->getCurrency($order['details']['BT']->user_currency_id);

		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('PAYCHOICE_PAYMENT_NAME', $payment_name);
		$html .= $this->getHtmlRow('PAYCHOICE_ORDER_NUMBER', $response->order_number);
		$html .= $this->getHtmlRow('PAYCHOICE_AMOUNT', $response->paychoiceReturnAmount . ' ' . $currency->currency_name);
		$html .= $this->getHtmlRow('PAYCHOICE_RESPONSE_TRANSACTION_ID', $response->paychoiceTrxnNumber);
		$html .= '</table>' . "\n";
		$this->logInfo('Order Number' . $response->order_number . ' payment approved', 'message');
		return $html;
	}
	
	function plgVmGetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
	{
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
		{
			return null; // Another method was selected, do nothing
		}
		
		if (!$this->selectedThisElement($method->payment_element))
		{
			return false;
		}

		if (!class_exists('VirtueMartModelVendor')) require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'vendor.php');
		$vendorId = 1; //VirtueMartModelVendor::getLoggedVendor();
		$db = JFactory::getDBO();

		$q = 'SELECT   `virtuemart_currency_id` FROM `#__virtuemart_currencies` WHERE `currency_code_3`= "USD"' ;
		$db->setQuery($q);
		$paymentCurrencyId = $db->loadResult();
	}

	function _clearPaychoiceSession()
	{
		$session = JFactory::getSession();
		$session->clear('paychoice', 'vm');
	}

    /**
     * renderPluginName
     * Get the name of the payment method
     *
     * @author Valerie Isaksen
     * @param  $payment
     * @return string Payment method name
     */
	function getExtraPluginNameInfo() {
		$creditCardInfos = '';
		if ($this->_validate_creditcard_data(false)) {
			$cc_number = "**** **** **** " . substr($this->_cc_number, -4);
			$creditCardInfos .= '<br /><span class="vmpayment_cardinfo">' . JText::_('VMPAYMENT_PAYCHOICE_CCTYPE').': ' . $this->_cc_type . '<br />';
			$creditCardInfos .=JText::_('VMPAYMENT_PAYCHOICE_CCNUM').': ' . $cc_number . '<br />';
			$creditCardInfos .= JText::_('VMPAYMENT_PAYCHOICE_CVV2').': ' . '****' . '<br />';
			$creditCardInfos .= JText::_('VMPAYMENT_PAYCHOICE_EXDATE').': ' . $this->_cc_expire_month . '/' . $this->_cc_expire_year;
			$creditCardInfos .="</span>";
		}
		return $creditCardInfos;
	}

    /**
     * Creates a Drop Down list of available Creditcards
     *
     * @author Valerie Isaksen
     */
	function _renderCreditCardList($creditCards, $selected_cc_type, $paymentmethod_id, $multiple = false, $attrs='') {

		$idA = $id = 'cc_type_' . $paymentmethod_id;
		if (!is_array($creditCards)) $creditCards = (array) $creditCards;
		foreach ($creditCards as $creditCard) $options[] = JHTML::_('select.option', $creditCard, JText::_('VMPAYMENT_PAYCHOICE_' . strtoupper($creditCard)));
		if ($multiple) {
			$attrs = 'multiple="multiple"';
			$idA .= '[]';
		}
		return JHTML::_('select.genericlist', $options, $idA, $attrs, 'value', 'text', $selected_cc_type);
	}

    /*
     * validate_creditcard_data
     * @author Valerie isaksen
     */
	function _validate_creditcard_data($enqueueMessage=true) {

		$html = '';
		$this->_cc_valid = true;

		if (!Creditcard::validate_credit_card_number($this->_cc_type, $this->_cc_number)) {
			$this->_errormessage[] = 'VMPAYMENT_PAYCHOICE_CARD_NUMBER_INVALID';
			$this->_cc_valid = false;
		}

		if (!Creditcard::validate_credit_card_cvv($this->_cc_type, $this->_cc_cvv)) {
			$this->_errormessage[] = 'VMPAYMENT_PAYCHOICE_CARD_CVV_INVALID';
			$this->_cc_valid = false;
		}
		if (!Creditcard::validate_credit_card_date($this->_cc_type, $this->_cc_expire_month, $this->_cc_expire_year)) {
			$this->_errormessage[] = 'VMPAYMENT_PAYCHOICE_CARD_CVV_INVALID';
			$this->_cc_valid = false;
		}
		if (!$this->_cc_valid) {
			foreach ($this->_errormessage as $msg) $html .= Jtext::_($msg) . "<br/>";
		}
		if (!$this->_cc_valid && $enqueueMessage) {
			$app = & JFactory::getApplication();
			$app->enqueueMessage($html);
		}

		return $this->_cc_valid;
	}

	function _getfield($string, $length) { return substr($string, 0, $length); }
	function _getCustID($method) { return $method->paychoice_test_request ? '87654321' : $method->paychoice_custid; }


	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) { return parent::onCheckAutomaticSelected($cart, $cart_prices); }
	protected function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) { $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name); }
	function plgVmOnShowOrderPrintPayment($order_number, $method_id) { return parent::onShowOrderPrint($order_number, $method_id); }
	function plgVmDeclarePluginParamsPayment($name, $id, &$data) { return $this->declarePluginParams('payment', $name, $id, $data); }
	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) { return $this->setOnTablePluginParams($name, $id, $table); }



	function _doPayment($method,$params) {

		// Instantiate the paychoice http client
		$paychoiceClient = new PaychoiceProxy();

		// Use the sandbox endpoint if undefined
		$useSandbox = $method->paychoice_test_request;

		$requestData["currency"] = $HTTP_POST_VARS['transactionCurrency'];
		$requestData["amount"] = $params->order_total;
		$requestData["reference"] = $params->trans_number;
		$requestData["card[name]"] = $params->nameoncard;
		$requestData["card[number]"] = $params->cc_number;
		$requestData["card[expiry_month]"] = $params->expirymonth;
		$requestData["card[expiry_year]"] = $params->expiryyear;
		$requestData["card[cvv]"] = $params->cvv;

		try
		{
			$credentials = MODULE_PAYMENT_PAYCHOICE_USER . ":" . MODULE_PAYMENT_PAYCHOICE_PASSWORD;
			$response = $paychoiceClient->sendChargeRequest($credentials, $useSandbox, $requestData);

			// Make sure the API returned something
			if (!isset($response))
			{
				$errorMessage = "Transaction Error: Payment processor did not return a valid response.";
			}

			// Set an error message if the transaction failed
			if ($response->charge->status_code != '0')
			{
				$errorMessage = "Transaction Error. Payment processor declined transaction: {$response->charge->error_code} {$response->charge->error}";
			}
		}
		catch (PaychoiceException $e)
		{
			$errorMessage = $e->getMessage();
		}

		// Set an error and redirect if something went wrong
		if (isset($errorMessage) && strlen($errorMessage))
		{

		}

		// Check whether the curl_exec worked.
		if( curl_errno( $ch ) == CURLE_OK ) {

			// Parse the XML response
			xml_parse($this->parser, $xmlResponse, TRUE);

			$rtn = null;
			if( xml_get_error_code( $this->parser ) == XML_ERROR_NONE ) {
				// Get the result into local variables.
				$rtn->paychoiceTrxnStatus = $this->xmlData['paychoiceTrxnStatus'];
				$rtn->response = $rtn->paychoiceTrxnStatus=='True' ? 'accepted' : 'declined';
				$rtn->paychoiceTrxnNumber = $this->xmlData['paychoiceTrxnNumber'];
				$rtn->paychoiceTrxnReference = $this->xmlData['paychoiceTrxnReference'];
				//$rtn->paychoiceAuthCode = $this->xmlData['paychoiceAuthCode'];
				$rtn->paychoiceReturnAmount = $this->xmlData['paychoiceReturnAmount']/100;
				$rtn->order_number = $this->xmlData['paychoiceTrxnOption1'];
				$rtn->paychoiceTrxnError = $this->xmlData['paychoiceTrxnError'];
				$rtn->raw = json_encode($this->xmlData);
				$rtn->error = 0;
				$rtn->errormsg = '';
			} else {
				// An XML error occured. Return the error message and number.
				$rtn->response = 'error';
				$rtn->error = xml_get_error_code( $this->parser ) + 2000;
				$rtn->errormsg = xml_error_string( $rtn->error );
			}
			// Clean up our XML parser
			xml_parser_free( $this->parser );
		} else {
            // A CURL Error occured. Return the error message and number. (offset so we can pick the error apart)
			$rtn->response = 'error';
			$rtn->error = curl_errno( $ch ) + 1000;
			$rtn->errormsg = curl_error( $ch );
		}

		//echo '<pre>'; print_r($this->xmlData); print_r($rtn); exit;
		// Clean up CURL, and return any error.
		curl_close( $ch );
		return $rtn;
	}

    /***********************************************************************
     *** XML Parser - Callback functions                                 ***
     ***********************************************************************/
	function epXmlElementStart ($parser, $tag, $attributes) {  $this->currentTag = $tag; }
	function epXmlElementEnd ($parser, $tag) { $this->currentTag = ""; }
	function epXmlData ($parser, $cdata) { $this->xmlData[$this->currentTag] = $cdata; }
}

class PaychoiceProxy
{
  	public function sendChargeRequest($credentials, $useSandbox, $requestData)
	{
        $headers = array();

		if (strlen($useSandbox) < 1)
        {
            throw new PayChoiceException("Paychoice sandbox/live environment not set");
        }

		$environment = $useSandbox == true ? "sandbox" : "secure";
		$endPoint = "https://{$environment}.paychoice.com.au/api/v3/charge";

		// Initialise CURL and set base options
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_TIMEOUT, 60);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($curl, CURLOPT_FORBID_REUSE, true);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded; charset=utf-8'));

        // Setup CURL request method
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $this->encodeData($requestData));

		// Setup CURL params for this request
		curl_setopt($curl, CURLOPT_URL, $endPoint);
		curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($curl, CURLOPT_USERPWD, $credentials);

		// Run CURL
		$response = curl_exec($curl);
   		$error = curl_error($curl);
		$responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $responseObject = json_decode($response);

        if (is_object($responseObject) && $responseObject->object_type == "error")
        {
            $errorParam = strlen($responseObject->error->param) > 0 ? ". Parameter: " . $responseObject->error->param : "";
            throw new PaychoiceException("Paychoice returned an error. Error: " . $responseObject->error->message . $errorParam);
        }

		// Check for CURL errors
		if (isset($error) && strlen($error))
		{
			throw new PaychoiceException("Could not successfully communicate with payment processor. Error: {$error}.");
		}
		else if (isset($responseCode) && strlen($responseCode) && $responseCode == '500')
		{
			throw new PaychoiceException("Could not successfully communicate with payment processor. HTTP response code {$responseCode}.");
		}

        return $responseObject;
	}

    private function encodeData($requestData)
    {
        if (!is_array($requestData))
        {
            throw new PaychoiceException("Request data is not in an array");
        }

        $formValues = "";
        foreach($requestData as $key=>$value)
        {
            $formValues .= $key.'='.urlencode($value).'&';
        }
        rtrim($formValues, '&');

        return $formValues;
    }
}

class PaychoiceException extends Exception {}

// No closing tag