<?php
/**
 * @version		0.1.0
 * @package		Joomla
 * @subpackage	Membership Pro
 * @author  	Joshua E Vines
 * @copyright	Copyright © 2017 Phoenix Technological Research
 * @license		GNU/GPL, see LICENSE.php
 */
// no direct access
defined('_JEXEC') or die();
jimport('paypal_php_sdk.autoload');
use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\Address;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Links;
use PayPal\Api\Payee;
use PayPal\Api\Payer;
use PayPal\Api\PayerInfo;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\ShippingAddress;
use PayPal\Api\Transaction;
use PayPal\Api\VerifyWebhookSignature;
use PayPal\Api\VerifyWebhookSignatureResponse;
use PayPal\Api\Webhook;
use PayPal\Api\WebhookEvent;

class os_paypal_express extends MPFPayment
{
	private $apiContext;
	private $payee;
	private $approvalUrl;
	
	function PayPalError($e) {
		$err = "";
		do {
			if (is_a($e, "PayPal\Exception\PayPalConnectionException")) {
				$data = json_decode($e->getData(),true);
				$err .= $data['name'] . " - " . $data['message'] . "<br>";
				if (isset($data['details'])) {
					$err .= "<ul>";
					foreach ($data['details'] as $details) {
						$err .= "<li>". $details['field'] . ": " . $details['issue'] . "</li>";
					}
					$err .= "</ul>";
				}
			} else {
				//some other type of error
				$err .= sprintf("%s:%d %s (%d) [%s]\n", $e->getFile(), $e->getLine(), $e->getMessage(), $e->getCode(), get_class($e));
			}
		} while($e = $e->getPrevious());
		return $err;
	} // END FUNCTION PayPalError

	public function logIpn($extraData = null)
	{
		if (!$this->params->get('ipn_log')) return;
		$text = '[' . date('Y/m/d g:i A') . '] - ';
		$text .= "Log Data From : ".$this->title." \n";
		foreach ($this->postData as $key => $value)
		{
			$text .= "$key=$value, ";
		}
		if (strlen($extraData))
		{
			$text .= $extraData;
		}
		$ipnLogFile = JPATH_COMPONENT . '/ipn_' . $this->getName() . '.txt';
		error_log($text."\n\n", 3, $ipnLogFile);
	}

	/**********************************************************************************************
	 * Constructor
	 *
	 * @param JRegistry $params
	 * @param array     $config
	 */
	public function __construct($params, $config = array())
	{
		parent::__construct($params, $config);

		// Create and define merchant
		// Create Payee object
		$this->payee = new Payee();
		// Set PayPal API config options
		$this->mode = $params->get('paypal_mode');
		if ($this->mode == 'live')
		{
			$this->apiContext = new PayPal\Rest\ApiContext(new PayPal\Auth\OAuthTokenCredential(
				$params->get('client_id_l'), $params->get('secret_l') ));
			$this->apiContext->setConfig( array( 'mode' => 'live',
				'http.ConnectionTimeOut' => $params->get('timeout_l'),
				'log.LogEnabled' => $params->get('log_enabled_l'),
				'log.FileName' => $params->get('log_path_l').'PayPal.log',
				'log.LogLevel' => $params->get('log_level_l') ) );
			$this->payee->setMerchantId($params->get('merchant_id_l'));
			//	->setEmail($params->get('email_l'));
			$this->url = 'https://www.paypal.com/cgi-bin/webscr';
		}
		else if ($this->mode == 'sandbox')
		{
			$this->apiContext = new PayPal\Rest\ApiContext(new PayPal\Auth\OAuthTokenCredential(
				$params->get('client_id_s'), $params->get('secret_s') ));
			$this->apiContext->setConfig( array( 'mode' => 'sandbox',
				'http.ConnectionTimeOut' => $params->get('timeout_s'),
				'log.LogEnabled' => $params->get('log_enabled_s'),
				'log.FileName' => $params->get('log_path_s').'PayPal.log',
				'log.LogLevel' => $params->get('log_level_s') ) );
			$this->payee->setMerchantId($params->get('merchant_id_s'));
			//	->setEmail($params->get('email_s'));
			$this->url = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
		}
	}

	/**********************************************************************************************
	 * Build payment
	 */
	private function buildPayment ($row, $data)
	{
		$app = JFactory::getApplication();
		$Itemid = $app->input->getInt('Itemid', 0);
		$rate = 1;
		// Create Payer object
		$payer = new Payer();
		// Payment method is via PayPal. Take the customer to PayPal for processing.
		$payer->setPaymentMethod("paypal");
		// Create billingAddress as Address object and fill with customer's billing address.
		$billingAddress = new Address();
		$billingAddress->setLine1($data['address'])
			->setLine2($data['address2'])
			->setCity($data['city'])
			->setState($data['state'])
			->setPostalCode($data['zip'])
			->setCountryCode($data['country'])
			;
		// Create PayerInfo object, populate with customer's billing
		// info (name, billingAddress, phone, email)
		$payerInfo = new PayerInfo();
		$payerInfo->setFirstName($data['first_name'])
			->setLastName($data['last_name'])
			->setBillingAddress($billingAddress)
			//->setPhone($data['phone'])
			->setEmail($data['email'])
			;
		$payer->setPayerInfo($payerInfo);
		$itemList = new ItemList();
		$item = new Item();
		$item->setName($data['item_name'])
			//->setSku($product['product_sku'])
			->setQuantity(1)
			->setPrice(number_format(round($row->amount * $rate, 2) , 2 , "." , "," ))
			->setTax(number_format($row->tax_amount , 2 , "." , "," ))
			->setCurrency($data['currency'])
			;
		$itemList->addItem($item);

		/*$shippingAddress = new ShippingAddress();
		$shippingAddress->setRecipientName($data['shipping_firstname'].' '.$data['shipping_lastname'])
			->setLine1($data['shipping_address_1'])
			->setLine2($data['shipping_address_2'])
			->setCity($data['shipping_city'])
			->setState($shippingZoneInfo->zone_code)
			->setPostalCode($data['shipping_postcode'])
			->setCountryCode($shippingCountryInfo->iso_code_2)
			;
		$itemList->setShippingAddress($shippingAddress);*/

		// Find totals
		//$data['totals'][0]['name'];
		$totals = array(
			'subTotal' => $row->amount,
			//'shipping' => 0,
			'tax' => $row->tax_amount,
			'total' => $row->gross_amount,
			);

		$details = new Details();
		$details //->setShipping(number_format($totals['shipping'] , 2 , "." , "," ))
			->setTax(number_format($totals['tax'] , 2 , "." , "," ))
			->setSubtotal(number_format($totals['subTotal'] , 2 , "." , "," ))
			;

		$amount = new Amount();
		$amount->setCurrency($data['currency'])
			->setTotal(number_format($data['amount'] , 2 , "." , "," ))
			->setDetails($details)
			;

		$transaction = new Transaction();
		$transaction->setAmount($amount)
			->setCustom($row->id)
			->setPayee($this->payee)
			->setItemList($itemList)
			->setInvoiceNumber((string)$data['transaction_id'])
			->setNotifyUrl(JUri::base().'index.php?option=com_osmembership&task=payment_confirm&payment_method=os_paypal_express')
			;
		$redirectUrls = new RedirectUrls();
		$redirectUrls
			->setReturnUrl(JUri::base().'index.php?option=com_osmembership&task=payment_confirm&payment_method=os_paypal_express&ep=execute')
			->setCancelUrl(JUri::base().'index.php?option=com_osmembership&view=cancel&id=' . $row->id . '&Itemid=' . $Itemid)
			;
		$payment = new Payment();
		$payment->setIntent("sale")
			->setPayer($payer)
			->setRedirectUrls($redirectUrls)
			->addTransaction($transaction)
			;

		return $payment;
	}

	/**********************************************************************************************
	 * Process onetime subscription payment
	 *
	 * @param JTable $row
	 * @param array  $data
	 */
	public function processPayment($row, $data)
	{
		$payment = new Payment();
		$payment = $this->buildPayment($row, $data);
		if (!$this->redirectHeading)
		{
			$db = JFactory::getDbo();
			$query = $db->getQuery(true);
			$query->select('title')
				->from('#__osmembership_plugins')
				->where('name = "' . $this->name . '"')
				;
			$db->setQuery($query);
			$this->redirectHeading = JText::sprintf('OSMEMBERSHIP_REDIRECT_HEADING', JText::_($db->loadResult()))
			;
		}
	?><div class="osmembership-heading"><?php echo JUri::getHost() . ' ' . $this->redirectHeading; ?></div><?php
		try {
			$payment->create($this->apiContext);
		} catch (Exception $ex) {
			echo $this->PayPalError($ex);
		}
		$approvalUrl = $payment->getApprovalLink();
		if(isset($approvalUrl)) {
			JFactory::getApplication()->redirect($approvalUrl);
		}
		/**
		 * Processing stops here. PayPal returns the user to the site with PayerID. Another
		 * function will be needed to process this data and call Payment->execute()
		 */
		return;
	} // END FUNCTION processPayment

	/***********************************************************************************************
	 * Execute the PayPal payment
	 */
	public function executePayment($jinput)
	{
		// get URL data and retrieve Payment
		$paymentId = $jinput->get('paymentId');
		$payerId = $jinput->get('PayerID');
		$payment = Payment::get($paymentId, $this->apiContext);

		// create paymentExecution
		$paymentExecution = new PaymentExecution();
		$paymentExecution->setPayerId($payerId);

		// Execute the payment
		$payment->execute($paymentExecution, $this->apiContext);

		// Redirect to payment completed page.
		JFactory::getApplication()->redirect(JUri::base().'index.php?option=com_osmembership&view=complete&id=' . $row->id . '&Itemid=' . $Itemid);
	} // END FUNCTION executePayment

	/**********************************************************************************************
	 * Verify onetime subscription payment
	 *
	 * @return bool
	 */
	public function verifyPayment()
	{
		$jinput = JFactory::getApplication()->input;
		if ($jinput->get('ep') == 'execute')
		{
			$this->executePayment($jinput);
		}
		else
		{

			$ret = $this->validate();
			if ($ret)
			{
				$row           = JTable::getInstance('OsMembership', 'Subscriber');
				$id            = $this->notificationData['custom'];
				$transactionId = $this->notificationData['txn_id'];
				if ($transactionId && OSMembershipHelper::isTransactionProcessed($transactionId))
				{
					return false;
				}
				$amount = $this->notificationData['mc_gross'];
				if ($amount < 0)
				{
					return false;
				}
				$row->load($id);
				if ($row->published)
				{
					return false;
				}
				if ($row->gross_amount > $amount)
				{
					return false;
				}

				$this->onPaymentSuccess($row, $transactionId);
			}
		}
	}

	/**********************************************************************************************
	 * Process recurring subscription payment
	 *
	 * @param JTable $row
	 * @param array  $data
	 */
	public function processRecurringPayment($row, $data)
	{
		$app     = JFactory::getApplication();
		$db      = JFactory::getDbo();
		$query   = $db->getQuery(true);
		$siteUrl = JUri::base();
		$Itemid  = $app->input->getInt('Itemid', 0);

		$query->select('*')
			->from('#__osmembership_plans')
			->where('id = ' . $row->plan_id);
		$db->setQuery($query);
		$rowPlan = $db->loadObject();

		$this->setParameter('currency_code', $data['currency']);
		$this->setParameter('item_name', $data['item_name']);
		$this->setParameter('custom', $row->id);

		// Override Paypal email if needed
		if ($rowPlan->paypal_email)
		{
			$this->setParameter('business', $rowPlan->paypal_email);
		}

		$this->setParameter('return', $siteUrl . 'index.php?option=com_osmembership&view=complete&id=' . $row->id . '&Itemid=' . $Itemid);
		$this->setParameter('cancel_return', $siteUrl . 'index.php?option=com_osmembership&view=cancel&id=' . $row->id . '&Itemid=' . $Itemid);
		$this->setParameter('notify_url', $siteUrl . 'index.php?option=com_osmembership&task=recurring_payment_confirm&payment_method=os_paypal');

		$this->setParameter('cmd', '_xclick-subscriptions');
		$this->setParameter('src', 1);
		$this->setParameter('sra', 1);
		$this->setParameter('a3', $data['regular_price']);
		$this->setParameter('address1', $row->address);
		$this->setParameter('address2', $row->address2);
		$this->setParameter('city', $row->city);
		$this->setParameter('country', $data['country']);
		$this->setParameter('first_name', $row->first_name);
		$this->setParameter('last_name', $row->last_name);
		$this->setParameter('state', $row->state);
		$this->setParameter('zip', $row->zip);
		$this->setParameter('p3', $rowPlan->subscription_length);
		$this->setParameter('t3', $rowPlan->subscription_length_unit);
		$this->setParameter('lc', 'US');

		if ($rowPlan->number_payments > 1)
		{
			$this->setParameter('srt', $rowPlan->number_payments);
		}

		if ($data['trial_duration'])
		{
			$this->setParameter('a1', $data['trial_amount']);
			$this->setParameter('p1', $data['trial_duration']);
			$this->setParameter('t1', $data['trial_duration_unit']);
		}

		//Redirect users to PayPal for processing payment
		$this->renderRedirectForm();
	}

	/**********************************************************************************************
	 * Verify recurring payment
	 */
	public function verifyRecurringPayment()
	{
		$ret = $this->validate();
		if ($ret)
		{
			$id            = $this->notificationData['custom'];
			$transactionId = $this->notificationData['txn_id'];
			$amount        = $this->notificationData['mc_gross'];
			$txnType       = $this->notificationData['txn_type'];

			if ($amount < 0)
			{
				return false;
			}

			if ($transactionId && OSMembershipHelper::isTransactionProcessed($transactionId))
			{
				return false;
			}

			$row = JTable::getInstance('OsMembership', 'Subscriber');

			switch ($txnType)
			{
				case 'subscr_signup':
					$row->load($id);

					if (!$row->id)
					{
						return false;
					}

					if (!$row->published)
					{
						$this->onPaymentSuccess($row, $transactionId);
					}
					break;
				case 'subscr_payment':
					OSMembershipHelper::extendRecurringSubscription($id, $transactionId);
					break;
				case 'subscr_cancel':
					OSMembershipHelperSubscription::cancelRecurringSubscription($id);
					break;
			}
		}
	}

	/**
	 * Get list of supported currencies
	 *
	 * @return array
	 */
	public function getSupportedCurrencies()
	{
		return array(
			'AUD','BRL','CAD','CZK','DKK','EUR','HKD','HUF','ILS','JPY',
			'MYR','MXN','NOK','NZD','PHP','PLN','GBP','RUB','SGD','SEK',
			'CHF','TWD','THB','TRY','USD'
		);
	}

	/**********************************************************************************************
	 * Validate the post data from paypal to our server
	 *
	 * @return string
	 */
	protected function validate()
	{
		$errNum                 = "";
		$errStr                 = "";
		$urlParsed              = parse_url($this->url);
		$host                   = $urlParsed['host'];
		$path                   = $urlParsed['path'];
		$postString             = '';
		$response               = '';
		$this->notificationData = $_POST;
		foreach ($_POST as $key => $value)
		{
			$postString .= $key . '=' . urlencode(stripslashes($value)) . '&';
		}
		$postString .= 'cmd=_notify-validate';
		$fp = fsockopen($host, '80', $errNum, $errStr, 30);
		if (!$fp)
		{
			$response = 'Could not open SSL connection to ' . $this->url;
			$this->logGatewayData($response);

			return false;
		}
		fputs($fp, "POST $path HTTP/1.1\r\n");
		fputs($fp, "Host: $host\r\n");
		fputs($fp, "User-Agent: Membership Pro\r\n");
		fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
		fputs($fp, "Content-length: " . strlen($postString) . "\r\n");
		fputs($fp, "Connection: close\r\n\r\n");
		fputs($fp, $postString . "\r\n\r\n");
		while (!feof($fp))
		{
			$response .= fgets($fp, 1024);
		}
		fclose($fp);
		$this->logGatewayData($response);

		if ($this->mode == 'sandbox' || stristr($response, "VERIFIED"))
		{
			return true;
		}

		return false;
	}
}
