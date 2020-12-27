<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

namespace Novalnet\Services;

use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Frontend\Services\AccountService;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Modules\Payment\History\Contracts\PaymentHistoryRepositoryContract;
use Plenty\Modules\Payment\History\Models\PaymentHistory as PaymentHistoryModel;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
/**
 * Class PaymentService
 *
 * @package Novalnet\Services
 */
class PaymentService
{
    use Loggable;
    
    /**
     * @var PaymentHistoryRepositoryContract
     */
    private $paymentHistoryRepo;
    
   /**
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     * @var ConfigRepository
     */
    private $config;
   
    /**
     * @var FrontendSessionStorageFactoryContract
     */
    private $sessionStorage;

    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;

    /**
     * @var CountryRepositoryContract
     */
    private $countryRepository;

    /**
     * @var WebstoreHelper
     */
    private $webstoreHelper;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;
    
    /**
     * @var TransactionLogData
     */
    private $transactionLogData;
    
    private $redirectPayment = ['NOVALNET_SOFORT', 'NOVALNET_PAYPAL', 'NOVALNET_IDEAL', 'NOVALNET_EPS', 'NOVALNET_GIROPAY', 'NOVALNET_PRZELEWY'];

    /**
     * Constructor.
     *
     * @param ConfigRepository $config
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param PaymentHelper $paymentHelper
     * @param TransactionService $transactionLogData
     */
    public function __construct(ConfigRepository $config,
                                FrontendSessionStorageFactoryContract $sessionStorage,
                                AddressRepositoryContract $addressRepository,
                                CountryRepositoryContract $countryRepository,
                                WebstoreHelper $webstoreHelper,
                                PaymentHelper $paymentHelper,
                                PaymentHistoryRepositoryContract $paymentHistoryRepo,
                                PaymentRepositoryContract $paymentRepository,
                                TransactionService $transactionLogData)
    {
        $this->config                   = $config;
        $this->sessionStorage           = $sessionStorage;
        $this->addressRepository        = $addressRepository;
        $this->countryRepository        = $countryRepository;
        $this->webstoreHelper           = $webstoreHelper;
        $this->paymentHistoryRepo       = $paymentHistoryRepo;
        $this->paymentRepository        = $paymentRepository;
        $this->paymentHelper            = $paymentHelper;
        $this->transactionLogData       = $transactionLogData;
    }
    
    /**
     * Push notification
     *
     */
    public function pushNotification($message, $type, $code = 0) {
        
    $notifications = json_decode($this->sessionStorage->getPlugin()->getValue('notifications'), true);  
        
    $notification = [
            'message'       => $message,
            'code'          => $code,
            'stackTrace'    => []
           ];
        
    $lastNotification = $notifications[$type];

        if( !is_null($lastNotification) )
    {
            $notification['stackTrace'] = $lastNotification['stackTrace'];
            $lastNotification['stackTrace'] = [];
            array_push( $notification['stackTrace'], $lastNotification );
        }
        
        $notifications[$type] = $notification;
        $this->sessionStorage->getPlugin()->setValue('notifications', json_encode($notifications));
    }
    
    /**
     * Validate  the response data.
     *
     */
    public function validateResponse()
    {
        $nnPaymentData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', null);
      
        $nnPaymentData['mop']            = $this->sessionStorage->getPlugin()->getValue('mop');
        $nnPaymentData['payment_method'] = strtolower($this->paymentHelper->getPaymentKeyByMop($nnPaymentData['mop']));
        
		if(in_array($nnPaymentData['result']['status'], ['PENDING', 'SUCCESS'])) {
		   $this->paymentHelper->createPlentyPayment($nnPaymentData);
		}
	  $this->getLogger(__METHOD__)->error('validate response updated', $nnPaymentData);    
	    
        //$this->executePayment($nnPaymentData);
        
        $additionalInfo = $this->additionalInfo($nnPaymentData);
	
	if($nnPaymentData['payment_method'] == 'INSTALMENT_INVOICE') {
		$instalmentInfo = [
			'total_paid_amount' => $nnPaymentData['instalment']['cycle_amount'],
			'instalment_cycle_amount' => $nnPaymentData['instalment']['cycle_amount'],
			'paid_instalment' => $nnPaymentData['instalment']['cycles_executed'],
			'due_instalment_cycles' => $nnPaymentData['instalment']['pending_cycles'],
			'next_instalment_date' => $nnPaymentData['instalment']['next_cycle_date'],
			'future_instalment_date' => $nnPaymentData['instalment']['cycle_dates']
		];
	}

        $transactionData = [
            'amount'           => $nnPaymentData['transaction']['amount'],
            'callback_amount'  => $nnPaymentData['transaction']['amount'],
            'tid'              => $nnPaymentData['transaction']['tid'],
            'ref_tid'          => $nnPaymentData['transaction']['tid'],
            'payment_name'     => $nnPaymentData['payment_method'],
	    'customer_email'  => $nnPaymentData['customer']['email'],
            'order_no'         => $nnPaymentData['transaction']['order_no'],
            'additional_info'  => !empty($additionalInfo) ? json_encode($additionalInfo) : 0,
	    'save_card_token'	=> !empty($nnPaymentData['transaction']['payment_data']['token']) ? $nnPaymentData['transaction']['payment_data']['token'] : 0,
	    'mask_details'  => !empty($nnPaymentData['transaction']['payment_data']['token']) ? $this->saveAdditionalPaymentData ($nnPaymentData) : 0,
	    'instalment_info'  => !empty($instalmentInfo) ? json_encode($instalmentInfo) : 0,
        ];
       
        if($nnPaymentData['payment_method'] == 'novalnet_invoice' || (in_array($nnPaymentData['transaction']['status'], ['PENIDNG', 'ON_HOLD']))) {
            $transactionData['callback_amount'] = 0;    
	}
        $this->transactionLogData->saveTransaction($transactionData);

     }
	
	public function saveAdditionalPaymentData($requestPaymentData) {
		switch (strtolower($requestPaymentData['payment_method'])) {
                
            case 'novalnet_cc':
                //if ($this->helper->getConfigurationParams('cc3d_active_mode')) {
                    //return '';
                //}

                return json_encode(
                    array(
                        'card_type' => $requestPaymentData['transaction']['payment_data']['card_brand'],
                        'card_number' => $requestPaymentData['transaction']['payment_data']['card_number'],
                        'card_validity' => $requestPaymentData['transaction']['payment_data']['card_expiry_month'] .'/'. $requestPaymentData['transaction']['payment_data']['card_expiry_year']
                        )
                );
            case 'novalnet_sepa':

                return json_encode(
                    array(
                        'iban' => $requestPaymentData['transaction']['payment_data']['iban']
                    )
                );
            case 'novalnet_paypal':

                return json_encode(
                    array(
                        'paypal_account' => utf8_decode($requestPaymentData['transaction']['payment_data']['paypal_account'])
                        )
                );
            default:
               
                return '';
            }
		
	}
     
    /**
     * Creates the payment for the order generated in plentymarkets.
     *
     * @param array $requestData 
     * @param bool $callbackfailure
     * 
     * @return array
     */
    public function executePayment($requestData, $callbackfailure = false)
    {
        try {
            if(!$callbackfailure &&  in_array($requestData['result']['status'], [100, 90])) {
				if($requestData['tid_status'] == 90) {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_payment_pending_status'));
                    $requestData['paid_amount'] = 0;
                } elseif(in_array($requestData['payment_id'], [27, 59]) && $requestData['tid_status'] == 100) {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_order_completion_status'));
                    $requestData['paid_amount'] = 0;
                } elseif(in_array($requestData['tid_status'], [85, 91, 98, 99])) {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_onhold_confirmation_status'));
                    $requestData['paid_amount'] = 0;
                } else {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_order_completion_status'));
                    $requestData['paid_amount'] = ($requestData['tid_status'] == 100) ? $requestData['amount'] : 0;
                }
            } else {
                $requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_order_cancel_status'));
                $requestData['paid_amount'] = 0;
            }
        
            $this->paymentHelper->createPlentyPayment($requestData);
            $this->paymentHelper->updateOrderStatus((int)$requestData['order_no'], $requestData['order_status']);
           
            return [
                'type' => 'success',
                'value' => $this->paymentHelper->getNovalnetStatusText($requestData)
            ];
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('ExecutePayment failed.', $e);
            return [
                'type'  => 'error',
                'value' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Build Invoice and Prepayment transaction comments
     *
     * @param array $requestData
     * @return string
     */
    public function getInvoicePrepaymentComments($requestData)
    {     
        $comments = '';
        $comments .= PHP_EOL . PHP_EOL . $this->paymentHelper->getTranslatedText('transfer_amount_text');
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('account_holder_novalnet') . $requestData['invoice_account_holder'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('iban') . $requestData['invoice_iban'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bic') . $requestData['invoice_bic'];
        if($requestData['due_date'])
        {
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('due_date') . date('Y/m/d', (int)strtotime($requestData['due_date']));
        }
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bank') . $requestData['invoice_bankname']. ' ' . $requestData['invoice_bankplace'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('amount') . $requestData['amount'] . ' ' . $requestData['currency'];

        $comments .= PHP_EOL . PHP_EOL .$this->paymentHelper->getTranslatedText('any_one_reference_text');
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('payment_reference1').' ' .('BNR-' . $requestData['product_id'] . '-' . $requestData['order_no']). PHP_EOL. $this->paymentHelper->getTranslatedText('payment_reference2') .' ' . 'TID '. $requestData['tid']. PHP_EOL;
        $comments .= PHP_EOL;
        return $comments;
    }

    /**
     * Build Novalnet server request parameters
     *
     * @param object $basket
     * @param string $paymentKey
     *
     * @return array
     */
    public function getRequestParameters(Basket $basket, $paymentKey = '')
    {
        
     /** @var \Plenty\Modules\Frontend\Services\VatService $vatService */
        $vatService = pluginApp(\Plenty\Modules\Frontend\Services\VatService::class);

        //we have to manipulate the basket because its stupid and doesnt know if its netto or gross
        if(!count($vatService->getCurrentTotalVats())) {
            $basket->itemSum = $basket->itemSumNet;
            $basket->shippingAmount = $basket->shippingAmountNet;
            $basket->basketAmount = $basket->basketAmountNet;
        }
        
        $billingAddressId = $basket->customerInvoiceAddressId;
        $billingAddress = $this->addressRepository->findAddressById($billingAddressId);
        if(!empty($basket->customerShippingAddressId)){
            $shippingAddress = $this->addressRepository->findAddressById($basket->customerShippingAddressId);
        }
		$customerName = $this->getCustomerName($billingAddress);

	$this->getLogger(__METHOD__)->error('basket', $basket);
	    $this->getLogger(__METHOD__)->error('shipping', $shippingAddress);
	    $this->getLogger(__METHOD__)->error('billing', $billingAddress);
        $account = pluginApp(AccountService::class);
        $customerId = $account->getAccountContactId();
        $paymentKeyLower = strtolower((string) $paymentKey);
        $testModeKey = 'Novalnet.' . $paymentKeyLower . '_test_mode'; 

        $paymentRequestParameters = [];
        // Build Merchant Data
        $paymentRequestParameters['merchant'] = [
            'signature' => $this->paymentHelper->getNovalnetConfig('novalnet_public_key'),
            'tariff'    => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
        ];

        // Build Customer Data
        $paymentRequestParameters['customer'] = [
            'first_name' => !empty($billingAddress->firstName) ? $billingAddress->firstName : $customerName['firstName'],
            'last_name'  => !empty($billingAddress->lastName) ? $billingAddress->lastName : $customerName['lastName'],
            'email'      => $billingAddress->email,
            'gender'     => 'u',
            'customer_no'  => ($customerId) ? $customerId : 'guest',
            'customer_ip'  => $this->paymentHelper->getRemoteAddress(),
        ];
	    
	  $billingShippingDetails = $this->getBillingShippingDetails($billingAddress, $shippingAddress);
	    $paymentRequestParameters['customer'] = array_merge($paymentRequestParameters['customer'], $billingShippingDetails);
	    
	   if ($paymentRequestParameters['customer']['billing'] == $paymentRequestParameters['customer']['shipping']) {
            $paymentRequestParameters['customer']['shipping']['same_as_billing'] = '1';
            }

        // Build Transaction Data
        $paymentRequestParameters['transaction'] = [
            'test_mode'        => (int)($this->config->get($testModeKey) == 'true'),
            'payment_type'       => $this->getTypeByPaymentKey($paymentKey),
            'amount'           => $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount),
            'currency'         => $basket->currency,
            'hook_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/callback/',
        ];
	    
	    

        $paymentRequestParameters['custom'] = [
            'lang' => strtoupper($this->sessionStorage->getLocaleSettings()->language),
        ];

        if(!empty($billingAddress->companyName)) {
            $paymentRequestParameters['customer']['billing']['company'] = $billingAddress->companyName;
        } elseif(!empty($shippingAddress->companyName)) {
            $paymentRequestParameters['customer']['shipping']['company'] = $shippingAddress->companyName;
        }

        if(!empty($billingAddress->phone)) {
            $paymentRequestParameters['tel'] = $billingAddress->phone;
        }
	
	 $this->getPaymentData($paymentKey, $paymentRequestParameters);
	    $this->getLogger(__METHOD__)->error('servoce request error', $paymentRequestParameters);
$this->getLogger(__METHOD__)->info('servoce request info', $paymentRequestParameters);
	    
        $url = NovalnetConstants::PAYMENT_URL;
        return [
            'data' => $paymentRequestParameters,
            'url'  => $url
        ];
    }
    
    /**
     * Get customer name if the salutation as Person
     *
     * @param object $address
     *
     * @return array
     */
    public function getCustomerName($address) {
		foreach ($address->options as $option) {
			if ($option->typeId == 12) {
					$name = $option->value;
			}
        }
        $customerName = explode(' ', $name);
        $firstname = $customerName[0];
			if( count( $customerName ) > 1 ) {
				unset($customerName[0]);
				$lastname = implode(' ', $customerName);
			} else {
				$lastname = $firstname;
			}
        $firstName = empty ($firstname) ? $lastname : $firstname;
        $lastName = empty ($lastname) ? $firstname : $lastname;
        return ['firstName' => $firstName, 'lastName' => $lastName];
	}
	
	public function getBillingShippingDetails($billingAddress, $shippingAddress) {
		
		$billingShippingDetails = [];
		$billingShippingDetails['billing']     = [
                'street'       => $billingAddress->street,
                'house_no'     => $billingAddress->houseNumber,
                'city'         => $billingAddress->town,
                'zip'          => $billingAddress->postalCode,
                'country_code' => $this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2')
            ];
         $billingShippingDetails['shipping']    = [
                'street'   => !empty($shippingAddress->street) ? $shippingAddress->street : $billingAddress->street,
                'house_no'     => !empty($shippingAddress->houseNumber) ? $shippingAddress->street : $billingAddress->houseNumber,
                'city'     => !empty($shippingAddress->town) ? $shippingAddress->street : $billingAddress->town,
                'zip' => !empty($shippingAddress->postalCode) ? $shippingAddress->street : $billingAddress->postalCode,
		'country_code' => !empty($shippingAddress->countryId) ? $this->countryRepository->findIsoCode($shippingAddress->countryId, 'iso_code_2') : $this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2')
            ];
		
		return $billingShippingDetails;
	}
	
    /**
     * Get required data for credit card form load
     *
     * @param array $paymentRequestData
     * @param string $paymentKey
     */
    public function getCcFormData(Basket $basket, $paymentKey)
    {
	     $billingAddressId = $basket->customerInvoiceAddressId;
        $billingAddress = $this->addressRepository->findAddressById($billingAddressId);
        if(!empty($basket->customerShippingAddressId)){
            $shippingAddress = $this->addressRepository->findAddressById($basket->customerShippingAddressId);
        }
		$customerName = $this->getCustomerName($billingAddress);
		$ccFormRequestParameters = [
			'client_key'	=> $this->paymentHelper->getNovalnetConfig('novalnet_client_key'),
			'inline_form'   => (int) ($this->paymentHelper->getNovalnetConfig('novalnet_cc_display_inline_form') == 'true'),
			'test_mode'        => (int)($this->config->get('Novalnet.' . strtolower((string) $paymentKey) . '_test_mode') == 'true'),
			'first_name' => !empty($billingAddress->firstName) ? $billingAddress->firstName : $customerName['firstName'],
            		'last_name'  => !empty($billingAddress->lastName) ? $billingAddress->lastName : $customerName['lastName'],
            		'email'      => $billingAddress->email,
			'street'       => $billingAddress->street,
			'house_no'     => $billingAddress->houseNumber,
			'city'         => $billingAddress->town,
			'zip'          => $billingAddress->postalCode,
			'country_code' => $this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2'),
		   	'amount'       => $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount),
            		'currency'     => $basket->currency,
		    	'lang' => strtoupper($this->sessionStorage->getLocaleSettings()->language)
		];	
        $billingShippingDetails = $this->getBillingShippingDetails($billingAddress, $shippingAddress);
        if ($billingShippingDetails['billing'] == $billingShippingDetails['shipping']) {
			$ccFormRequestParameters['same_as_billing'] = 1;
		}
		
		return json_encode($ccFormRequestParameters);
		
	}

    /**
     * Get payment related param
     *
     * @param array $paymentRequestData
     * @param string $paymentKey
     */
    public function getPaymentData($paymentKey, &$paymentRequestParameters )
    {
        //$url = $this->getpaymentUrl($paymentKey);
        if(in_array($paymentKey, ['NOVALNET_CC', 'NOVALNET_SEPA', 'NOVALNET_PAYPAL', 'NOVALNET_INVOICE', 'NOVALNET_INSTALMENT_INVOICE'])) {
            $onHoldLimit = $this->paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_on_hold');
            $onHoldAuthorize = $this->paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_payment_action');
			if((is_numeric($onHoldLimit) && $paymentRequestParameters['amount'] >= $onHoldLimit && $onHoldAuthorize == 'true') || ($onHoldAuthorize == 'true' && empty($onHoldLimit))) {
				$paymentRequestParameters['on_hold'] = 1;
			}
			if($paymentKey == 'NOVALNET_CC') {
				if($this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' ) {
				if($this->config->get('Novalnet.novalnet_cc_3d') == 'true') {
					$paymentRequestParameters['cc_3d'] = 1;
				}
				// $url = NovalnetConstants::CC3D_PAYMENT_URL;
				}
			} else if($paymentKey == 'NOVALNET_SEPA') {
				$dueDate = $this->paymentHelper->getNovalnetConfig('novalnet_sepa_due_date');
				if(is_numeric($dueDate) && $dueDate >= 2 && $dueDate <= 14) {
					$paymentRequestParameters['transaction']['sepa_due_date'] = $this->paymentHelper->dateFormatter($dueDate);
				}
			} else if($paymentKey == 'NOVALNET_INVOICE') {
				$invoiceDueDate = $this->paymentHelper->getNovalnetConfig('novalnet_invoice_due_date');
				if(is_numeric($invoiceDueDate)) {
					$paymentRequestParameters['transaction']['due_date'] = $this->paymentHelper->dateFormatter($invoiceDueDate);
				}
			}
        }

        //if($this->isRedirectPayment($paymentKey))
       // {
			//$paymentRequestParameters['uniqid'] = $this->paymentHelper->getUniqueId();
			//$this->encodePaymentData($paymentRequestParameters);
			//$paymentRequestParameters['implementation'] = 'ENC';
			//$paymentRequestParameters['return_url'] = $paymentRequestParameters['error_return_url'] = $this->getReturnPageUrl();
			//$paymentRequestParameters['return_method'] = $paymentRequestParameters['error_return_method'] = 'POST';
			//if ($paymentKey != 'NOVALNET_CC') {
				//$paymentRequestParameters['user_variable_0'] = $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl;
			//}
        // }
        
        //return $url;
    }

    /**
     * Check if the payment is redirection or not
     *
     * @param string $paymentKey
     */
    public function isRedirectPayment($paymentKey) {
        return (bool) (in_array($paymentKey, $this->redirectPayment) || ($paymentKey == 'NOVALNET_CC' && ($this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' )));
    }

    /**
     * Encode the server request parameters
     *
     * @param array
     */
    public function encodePaymentData(&$paymentRequestData)
    {
        foreach (['auth_code', 'product', 'tariff', 'amount', 'test_mode'] as $key) {
            // Encoding payment data
            $paymentRequestData[$key] = $this->paymentHelper->encodeData($paymentRequestData[$key], $paymentRequestData['uniqid']);
        }

        // Generate hash value
        $paymentRequestData['hash'] = $this->paymentHelper->generateHash($paymentRequestData);
    }

    /**
     * Get the payment response controller URL to be handled
     *
     * @return string
     */
    private function getReturnPageUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/paymentResponse/';
    }

    /**
    * Get the direct payment process controller URL to be handled
    *
    * @return string
    */
    public function getProcessPaymentUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/processPayment/';
    }

    /**
    * Get the redirect payment process controller URL to be handled
    *
    * @return string
    */
    public function getRedirectPaymentUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/redirectPayment/';
    }
    
    public function getTokenRemovalUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/removeCard/';
    }
	
    /**
    * Get the payment process URL by using plenty payment key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getpaymentUrl($paymentKey)
    {
	    if (in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_CC','NOVALNET_SEPA', 'NOVALNET_INSTALMENT_INVOICE' ])) {
		// $paymentUrl = NovalnetConstants::PAYPORT_URL;   
	    } else {
		// $paymentUrl = NovalnetConstants::PAYPAL_PAYMENT_URL;       
	    }

        //return $paymentUrl;
    }

   /**
    * Get payment key by plenty payment key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getkeyByPaymentKey($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE' => 27,
            'NOVALNET_CC'      => 6,
            'NOVALNET_SEPA'    => 37,
            'NOVALNET_PAYPAL'  => 34
        ];

        return $payment[$paymentKey];
    }

    /**
    * Get payment type by plenty payment Key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getTypeByPaymentKey($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>'INVOICE',
            'NOVALNET_CC'=>'CREDITCARD',
            'NOVALNET_SEPA'=>'DIRECT_DEBIT_SEPA',
            'NOVALNET_PAYPAL'=>'PAYPAL'
        ];

        return $payment[$paymentKey];
    }

    /**
    * Get the Credit card payment form design configuration
    *
    * @return array
    */
    public function getCcDesignConfig()
    {
	    
        return [
            'standardStyleLabel' => $this->paymentHelper->getNovalnetConfig('novalnet_cc_standard_style_label'),
            'standardStyleInput' => $this->paymentHelper->getNovalnetConfig('novalnet_cc_standard_style_field'),
            'standardStyleCss' => $this->paymentHelper->getNovalnetConfig('novalnet_cc_standard_style_css'),
        ];
    }
	
	public function getCcFormFields()
    {
        $ccformFields = [];

        $styleConfiguration = array('novalnet_cc_standard_style_label', 'novalnet_cc_standard_style_field', 'novalnet_cc_standard_style_css');

        foreach ($styleConfiguration as $value) {
            $ccformFields[$value] = $this->paymentHelper->getNovalnetConfig($value);
        }

        $textFields = array( 'novalnetCcHolderLabel', 'novalnetCcHolderInput', 'novalnetCcNumberLabel', 'novalnetCcNumberInput', 'novalnetCcExpiryDateLabel', 'novalnetCcExpiryDateInput', 'novalnetCcCvcLabel', 'novalnetCcCvcInput', 'novalnetCcError' );

        foreach ($textFields as $value) {
            $ccformFields[$value] = utf8_encode($this->paymentHelper->getTranslatedText($value));
        }

        return json_encode($ccformFields);
    }
	
    
	public function callCaptureVoid($order, $captureVoid=false) {
		
		$payments = pluginApp(\Plenty\Modules\Payment\Contracts\PaymentRepositoryContract::class);  
       		$paymentDetails = $payments->getPaymentsByOrderId($order->id);
		$this->getLogger(__METHOD__)->error('payment', $paymentDetails);
	   	 foreach ($paymentDetails as $paymentDetail)
		{
			$property = $paymentDetail->properties;
			foreach($property as $proper)
			{
				  if ($proper->typeId == 1)
				  {
					$tid = $proper->value;
				  }
				  if ($proper->typeId == 30)
				  {
					$status = $proper->value;
				  }
			}
		}

	    $orderInfo = $this->transactionLogData->getTransactionData('tid', $tid);
	    $order_info = json_decode($orderInfo[0]->additionalInfo);
	    
	    if(in_array($status, ['85', '91', '98', '99'])) {
        	$this->doCaptureVoid($order, $paymentDetails, $tid, $order_info, $captureVoid);
	    } 
		
	}
	
    /**
     * Execute capture and void process
     *
     * @param object $order
     * @param object $paymentDetails
     * @param int $tid
     * @param bool $capture
     * @return none
     */
   public function doCaptureVoid($order, $paymentDetails, $tid, $order_info, $capture=false)
    {
        
        try {
        $paymentRequestData = [
            'vendor'         => $this->paymentHelper->getNovalnetConfig('novalnet_vendor_id'),
            'auth_code'      => $this->paymentHelper->getNovalnetConfig('novalnet_auth_code'),
            'product'        => $this->paymentHelper->getNovalnetConfig('novalnet_product_id'),
            'tariff'         => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
            'key'            => $order_info->payment_id, 
            'edit_status'    => 1, 
            'tid'            => $tid, 
            'remote_ip'      => $this->paymentHelper->getRemoteAddress(),
            'lang'           => 'de'  
             ];
        
        if($capture) {
        $paymentRequestData['status'] = 100;
        } else {
        $paymentRequestData['status'] = 103;
        }
        
        // $response = $this->paymentHelper->executeCurl($paymentRequestData, NovalnetConstants::PAYPORT_URL);
		$response = $paymentRequestData;
         $responseData =$this->paymentHelper->convertStringToArray($response['response'], '&');
         if ($responseData['status'] == 100) {
            $paymentData['currency']    = $paymentDetails[0]->currency;
            $paymentData['paid_amount'] = (float) $order->amounts[0]->invoiceTotal;
            $paymentData['tid']         = $tid;
            $paymentData['order_no']    = $order->id;
            $paymentData['type']        = $responseData['tid_status'] != 100 ? 'cancel' : 'credit';
            $paymentData['mop']         = $paymentDetails[0]->mopId;
            $paymentData['tid_status']  = $responseData['tid_status'];
            
            $transactionComments = '';
            if($responseData['tid_status'] == 100) {
                   if ($paymentRequestData['key'] == 27) {
                     $this->transactionLogData->updateTransactionData('orderNo', $order->id, $responseData);
                 } 
		
               $transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('transaction_confirmation', $paymentRequestData['lang']), date('d.m.Y'), date('H:i:s'));
           } else {
            $transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('transaction_cancel', $paymentRequestData['lang']), date('d.m.Y'), date('H:i:s'));
        }
             if (($responseData['tid_status'] == '100' && $paymentRequestData['key'] == '27') || $responseData['tid_status'] != '100') {
             $paymentData['paid_amount'] = 0;
             }
             $paymentData['booking_text'] = $transactionComments;  
            // $this->paymentHelper->updatePayments($tid, $responseData['tid_status'], $order->id);
             $this->paymentHelper->createPlentyPayment($paymentData);
		 
         } else {
               $error = $this->paymentHelper->getNovalnetStatusText($responseData);
               $this->getLogger(__METHOD__)->error('Novalnet::doCaptureVoid', $error);
               throw new \Exception('Novalnet doCaptureVoid not executed');
         }  
    } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::doCaptureVoid', $e);
      }
    }
    
    /**
     * Show payment for allowed countries
     *
     * @param object $basket
     * @param string $allowed_country
     *
     * @return bool
     */
    public function allowedCountries(Basket $basket, $allowed_country) {
        $allowed_country = str_replace(' ', '', strtoupper($allowed_country));
        $allowed_country_array = explode(',', $allowed_country);    
        try {
            if (! is_null($basket) && $basket instanceof Basket && !empty($basket->customerInvoiceAddressId)) {         
                $billingAddressId = $basket->customerInvoiceAddressId;              
                $address = $this->addressRepository->findAddressById($billingAddressId);
                $country = $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2');
                if(!empty($address) && !empty($country) && in_array($country,$allowed_country_array)) {             
					return true;
                }
        
            }
        } catch(\Exception $e) {
            return false;
        }
        return false;
    }
    
    /**
     * Show payment for Minimum Order Amount
     *
     * @param object $basket
     * @param int $minimum_amount
     *
     * @return bool
     */
    public function getMinBasketAmount(Basket $basket, $minimum_amount) {   
        if (!is_null($basket) && $basket instanceof Basket) {
            $amount = $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount);
            if (!empty($minimum_amount) && $minimum_amount<=$amount)    {
                return true;
            }
        } 
        return false;
    }
    
    /**
     * Show payment for Maximum Order Amount
     *
     * @param object $basket
     * @param int $maximum_amount
     *
     * @return bool
     */
    public function getMaxBasketAmount(Basket $basket, $maximum_amount) {   
        if (!is_null($basket) && $basket instanceof Basket) {
            $amount = $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount);
            if (!empty($maximum_amount) && $maximum_amount>=$amount)    {
            
                return true;
            }
        } 
        return false;
    }

    /**
     * Get database values
     *
     * @param int $orderId
     *
     * @return array
     */
    public function getDatabaseValues($orderId) { 
        $database = pluginApp(DataBase::class);
        $transaction_details = $database->query(TransactionLog::class)->where('orderNo', '=', $orderId)->get();
	    $this->getLogger(__METHOD__)->error('transaction2345', $transaction_details);
        if (!empty($transaction_details)) {
		$this->getLogger(__METHOD__)->error('transaction234545465', $transaction_details);
        //Typecasting object to array
        $transaction_details = (array)($transaction_details[0]);
        $transaction_details['order_no'] = $transaction_details['orderNo'];
        $transaction_details['amount'] = $transaction_details['amount'] / 100;
        //Decoding the json as array
        $transaction_details['additionalInfo'] = json_decode(  $transaction_details['additionalInfo'], true );
	//Merging the array
	$transaction_details = array_merge($transaction_details, $transaction_details['additionalInfo']);
	//Unsetting the redundant key
	//unset($transaction_details['additionalInfo']);
	if (!empty($transaction_details['instalmentInfo'])) {
	$this->getLogger(__METHOD__)->error('instalment', $transaction_details['instalmentInfo']);
	$transaction_details['instalmentInfo'] = json_decode( $transaction_details['instalmentInfo'], true );
	//Merging the array
	$transaction_details = array_merge($transaction_details, $transaction_details['additionalInfo'], $transaction_details['instalmentInfo']);
	//Unsetting the redundant key
        unset($transaction_details['additionalInfo'], $transaction_details['instalmentInfo']);
	}
        
	
	
        
	$this->getLogger(__METHOD__)->error('transaction123', $transaction_details);
        
        return $transaction_details;
        }
    }
    
    /**
     * Send the payment call to novalnet server
     *
     */
	public function performServerCall() {
		try {
			$serverRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
			//$paymentRequestUrl = $this->sessionStorage->getPlugin()->getValue('nnPaymentUrl');
			$serverRequestData['data']['transaction']['order_no'] = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
			if($serverRequestData['data']['transaction']['payment_type'] == 'PAYPAL') {
			    $serverRequestData['data']['transaction']['return_url'] = $serverRequestData['data']['transaction']['error_return_url'] = $this->getReturnPageUrl();	
			}
			$this->getLogger(__METHOD__)->error('request formation', $serverRequestData);
			$response = $this->paymentHelper->executeCurl(json_encode($serverRequestData['data']), $serverRequestData['url']);
		        $this->getLogger(__METHOD__)->error('checksum response', $response);
			 if($serverRequestData['data']['transaction']['payment_type'] == 'PAYPAL') {
				 $this->getLogger(__METHOD__)->error('checksum URL', $response['result']['redirect_url']);
				 if (!empty($response['result']['redirect_url']) && !empty($response['transaction']['txn_secret'])) {
					 $this->getLogger(__METHOD__)->error('checksum URL called', $response['result']['redirect_url']);
            				header('Location: ' . $response['result']['redirect_url']);
					 exit;
        }
			 } else {
				$this->getLogger(__METHOD__)->error('response formation', $response);
			$notificationMessage = $this->paymentHelper->getTranslatedText('payment_success');
			$isPaymentSuccess = isset($response['result']['status']) && $response['result']['status'] == 'SUCCESS';
			if($isPaymentSuccess)
			{           
				if(isset($serverRequestData['data']['pan_hash']))
				{
				   unset($serverRequestData['data']['pan_hash']);
				}
				$this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($serverRequestData, $response));
				$this->pushNotification($notificationMessage, 'success', 100);
				
			} else {
				$this->pushNotification($notificationMessage, 'error', 100);
			}
				
			}

		} catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('performServerCall failed.', $e);
            return [
                'type'  => 'error',
                'value' => $e->getMessage()
            ];
        }
	}
	
	

    /**
     * Build the additional params
     *
     * @param array $nnPaymentData
     *
     * @return array
     */
	public function additionalInfo ($nnPaymentData) {
	 $lang = strtolower((string)$nnPaymentData['custom']['lang']);
	 $additionalInfo = [
		'currency' => $nnPaymentData['transaction']['currency'],
		'plugin_version' => NovalnetConstants::PLUGIN_VERSION,
		'test_mode' => !empty($nnPaymentData['transaction']['test_mode']) ? $this->paymentHelper->getTranslatedText('test_order',$lang) : 0,
	 ];
	
	if($nnPaymentData['payment_method'] == 'novalnet_invoice') {
		if(!empty($nnPaymentData['transaction']['bank_details']) ) {
	   	$additionalInfo['invoice_bankname']  = $nnPaymentData['transaction']['bank_details']['bank_name'];
		$additionalInfo['invoice_bankplace'] = utf8_encode($nnPaymentData['transaction']['bank_details']['bank_place']);
		$additionalInfo['invoice_iban']     = $nnPaymentData['transaction']['bank_details']['iban'];
		$additionalInfo['invoice_bic']     = $nnPaymentData['transaction']['bank_details']['bic'];
		$additionalInfo['invoice_account_holder'] = $nnPaymentData['transaction']['bank_details']['account_holder'];
		}
		$additionalInfo['due_date']     = !empty($nnPaymentData['transaction']['due_date']) ? $nnPaymentData['transaction']['due_date'] : 0;
		$additionalInfo['invoice_type'] = !empty($nnPaymentData['transaction']['payment_type']) ? $nnPaymentData['transaction']['payment_type'] : 0;
		$additionalInfo['invoice_ref']  = !empty($nnPaymentData['transaction']['invoice_ref']) ? $nnPaymentData['transaction']['invoice_ref'] : 0;
		
	}
	 return $additionalInfo;
	 
    }
	
   /**
    * Show the Payment based on payment conditions
    *
    * @param object $basket
    * @param string $paymentKey
    * @return string
    */
    public function checkPaymentDisplayConditions(Basket $basket, $paymentKey)
    {
		if (!is_null($basket) && $basket instanceof Basket) {
			$paymentActive = $this->config->get('Novalnet.'.$paymentKey.'_payment_active');
			if ($paymentActive == 'true') {
				// Minimum amount validation
				$minimumAmount = $this->paymentHelper->getNovalnetConfig($paymentKey . '_min_amount');
				$minimumAmount = ((preg_match('/^[0-9]*$/', $minimumAmount) && $minimumAmount >= '1998')  ? $minimumAmount : '1998');
				$amount        = (sprintf('%0.2f', $basket->basketAmount) * 100);
				// Check instalment cycles
				$instalementCyclesCheck = false;
				$instalementCycles = explode(',', $this->paymentHelper->getNovalnetConfig($paymentKey . '_cycles'));
				$this->getLogger(__METHOD__)->error('corrected cycles', $instalementCycles);
				if($minimumAmount >= 1998) {
					foreach($instalementCycles as $key => $value) {
						$cycleAmount = ($amount / $value);
						if($cycleAmount >= 999) {
							$this->getLogger(__METHOD__)->error('corrected cycles val', $value);
							$instalementCyclesCheck = true;
						}
					}
				}
				$this->getLogger(__METHOD__)->error('min', $minimumAmount);
				$this->getLogger(__METHOD__)->error('amount', $amount);
				$this->getLogger(__METHOD__)->error('insta', $instalementCyclesCheck);
				// Address validation
				$billingAddressId = $basket->customerInvoiceAddressId;
				$billingAddress = $this->addressRepository->findAddressById($billingAddressId);
				if(!empty($basket->customerShippingAddressId)){
					$shippingAddress = $this->addressRepository->findAddressById($basket->customerShippingAddressId);
				}
				// Get country validation value
				$billingShippingDetails = $this->getBillingShippingDetails($billingAddress, $shippingAddress);
				$countryValidation = $this->EuropeanUnionCountryValidation($paymentKey, $billingShippingDetails['billing']['country_code']);
				$this->getLogger(__METHOD__)->error('country', $countryValidation);
				// Check the payment condition
				if((((int) $amount >= (int) $minimumAmount && $instalementCyclesCheck && $countryValidation && $basket->currency == 'EUR' && ($billingShippingDetails['billing'] === $billingShippingDetails['shipping']) )
				)) {
					return true;
				} else {
					return false;
				}
			}
		}
		return false;
    }
    
    public function EuropeanUnionCountryValidation($paymentKey, $countryCode)
    {
		$allowB2B = $this->paymentHelper->getNovalnetConfig($paymentKey . '_allow_b2b_customer');
	    $this->getLogger(__METHOD__)->error('allow b2b', $allowB2B);
		$europeanUnionCountryCodes =  [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
            'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
            'PT', 'RO', 'SE', 'SI', 'SK', 'UK', 'CH'
        ];
		if(in_array($countryCode, ['DE', 'AT', 'CH'])) {
			$countryValidation = true;
		} elseif($allowB2B == true && in_array($countryCode, $europeanUnionCountryCodes)) {
			$countryValidation = true;
		} else {
			$countryValidation = false;
		}
	    $this->getLogger(__METHOD__)->error('allow b2b condn', $countryValidation);
        return $countryValidation;
    }
	
	
}
