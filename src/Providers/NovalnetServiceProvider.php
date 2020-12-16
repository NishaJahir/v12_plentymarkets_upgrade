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

namespace Novalnet\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Novalnet\Services\TransactionService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Order\Pdf\Events\OrderPdfGenerationEvent;
use Plenty\Modules\Order\Pdf\Models\OrderPdfGeneration;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Modules\Document\Models\Document;
use Novalnet\Constants\NovalnetConstants;

use Novalnet\Methods\NovalnetCcPaymentMethod;
use Novalnet\Methods\NovalnetSepaPaymentMethod;
use Novalnet\Methods\NovalnetInvoicePaymentMethod;
use Novalnet\Methods\NovalnetInstalmentbyInvoicePaymentMethod;
use Novalnet\Methods\NovalnetPaypalPaymentMethod;

use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;

/**
 * Class NovalnetServiceProvider
 * @package Novalnet\Providers
 */
class NovalnetServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * Register the route service provider
     */
    public function register()
    {
        $this->getApplication()->register(NovalnetRouteServiceProvider::class);
    }

    /**
     * Boot additional services for the payment method
     *
     * @param Dispatcher $eventDispatcher
     * @param paymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentMethodContainer $payContainer
     * @param PaymentMethodRepositoryContract $paymentMethodService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param TransactionService $transactionLogData
     * @param Twig $twig
     * @param ConfigRepository $config
     */
    public function boot( Dispatcher $eventDispatcher,
                          PaymentHelper $paymentHelper,
                          AddressRepositoryContract $addressRepository,
                          PaymentService $paymentService,
                          BasketRepositoryContract $basketRepository,
                          PaymentMethodContainer $payContainer,
                          PaymentMethodRepositoryContract $paymentMethodService,
                          FrontendSessionStorageFactoryContract $sessionStorage,
                          TransactionService $transactionLogData,
                          Twig $twig,
                          ConfigRepository $config,
                          PaymentRepositoryContract $paymentRepository,
                          DataBase $dataBase,
                          EventProceduresService $eventProceduresService)
    {
        // Register the Novalnet payment methods in the payment method container
        $payContainer->register('Novalnet::NOVALNET_CC', NovalnetCcPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('Novalnet::NOVALNET_SEPA', NovalnetSepaPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
	    $payContainer->register('Novalnet::NOVALNET_INVOICE', NovalnetInvoicePaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('Novalnet::NOVALNET_INSTALMENT_INVOICE', NovalnetInstalmentbyInvoicePaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('Novalnet::NOVALNET_PAYPAL', NovalnetPaypalPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        
        // Listen for the event that gets the payment method content
        $eventDispatcher->listen(GetPaymentMethodContent::class,
                function(GetPaymentMethodContent $event) use($config, $paymentHelper, $addressRepository, $paymentService, $basketRepository, $sessionStorage, $twig, $dataBase)
                {
                    if($paymentHelper->getPaymentKeyByMop($event->getMop()))
                    {   
                        $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop());
                        $basket = $basketRepository->load();
			$name = trim($config->get('Novalnet.' . strtolower($paymentKey) . '_payment_name'));
                        $paymentName = ($name ? $name : $paymentHelper->getTranslatedText(strtolower($paymentKey)));
                        // Get the payment request data
                        $serverRequestData = $paymentService->getRequestParameters($basket, $paymentKey);			
						if (empty($serverRequestData['data']['customer']['first_name']) && empty($serverRequestData['data']['customer']['last_name'])) {
                            $content = $paymentHelper->getTranslatedText('nn_first_last_name_error');
                            $contentType = 'errorCode';   
                        } else {
				if(in_array($paymentKey, ['NOVALNET_CC', 'NOVALNET_SEPA', 'NOVALNET_INSTALMENT_INVOICE'])) {
					 $billingAddressId = $basket->customerInvoiceAddressId;
        $billingAddress = $addressRepository->findAddressById($billingAddressId);
					$paymentDetails = $dataBase->query(TransactionLog::class)->where('paymentName', '=', strtolower($paymentKey))->where('customerEmail', '=', $billingAddress->email)->where('saveOneTimeToken', '!=', "")->whereNull('maskingDetails', 'and', true)->orderBy('id','DESC')->limit(2)->get();
					//$paymentDetails = $dataBase->query(TransactionLog::class)->where([['paymentName', '=', strtolower($paymentKey)],['customerEmail', '=', $billingAddress->email]])->whereNull([['maskingDetails', 'and', false], ['saveOneTimeToken', 'and', false]])->orderBy('id','DESC')->limit(2)->get();
					$this->getLogger(__METHOD__)->error('db get new chnaged', $paymentDetails);
					$paymentDetails = json_decode(json_encode($paymentDetails), true);
					foreach($paymentDetails as $key => $paymentDetail) {
						$paymentDetails[$key]['iban'] = json_decode($paymentDetail['maskingDetails'])->iban;
					}
					//$jsonValue = ($paymentData['maskingDetails'],true);
					$this->getLogger(__METHOD__)->error('db temp', $paymentDetails);
					$paymentDetails = json_decode(json_encode($paymentDetails));
					$this->getLogger(__METHOD__)->error('db object', $paymentDetails);

					if($paymentKey == 'NOVALNET_CC') {
								$ccFormDetails = $paymentService->getCcFormData($basket, $paymentKey);
						$ccCustomFields = $paymentService->getCcFormFields();
							        }
				   $content = $twig->render('Novalnet::PaymentForm.NovalnetPaymentForm', [
					   			'nnPaymentProcessUrl' => $paymentService->getProcessPaymentUrl(),
								'paymentMopKey'       =>  $paymentKey,
								'paymentName' 		  => $paymentName,
					                        'ccFormDetails'       => !empty($ccFormDetails)? $ccFormDetails : '',
					   			'ccCustomFields'       => !empty($ccCustomFields)? $ccCustomFields : '',
					   			'oneClickShopping'   => (int) ($config->get('Novalnet.' . strtolower($paymentKey) . '_shopping_type') == true),
					                        'instalmentNetAmount'  => $basket->basketAmount,
								'orderCurrency' => $basket->currency,
					                         'paymentDetails' => $paymentDetails,
					                         'removedCardDetail' => $paymentHelper->getTranslatedText('removedCardDetail'),
					                         'removalProcessUrl' => $paymentService->getTokenRemovalUrl(),
								//'recurringPeriod'      => $paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_recurring_period'),
								'instalmentCycles' => explode(',', $paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_cycles') )
					   			
					   ]);	
					$this->getLogger(__METHOD__)->error('instalment', $paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_cycles'));
				   $contentType = 'htmlContent';     
				} else {
				$sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData);
				//$sessionStorage->getPlugin()->setValue('nnPaymentUrl', $serverRequestData['url']);
				$content = '';
				$contentType = 'continue';
				}
		        }
							$event->setValue($content);
							$event->setType($contentType);
                    } 
                });

        // Listen for the event that executes the payment
        $eventDispatcher->listen(ExecutePayment::class,
            function (ExecutePayment $event) use ($paymentHelper, $paymentService, $sessionStorage)
            {
                if($paymentHelper->getPaymentKeyByMop($event->getMop())) {
                    $sessionStorage->getPlugin()->setValue('nnOrderNo',$event->getOrderId());
                    $sessionStorage->getPlugin()->setValue('mop',$event->getMop());
                    $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop());
					$paymentService->performServerCall();
					$paymentService->validateResponse();
                }
            });
        
     // Invoice PDF Generation
    
    // Listen for the document generation event
        $eventDispatcher->listen(OrderPdfGenerationEvent::class,
        function (OrderPdfGenerationEvent $event) use ($dataBase, $paymentHelper, $paymentService, $paymentRepository, $transactionLogData) {
            
        /** @var Order $order */ 
        $order = $event->getOrder();
        $payments = $paymentRepository->getPaymentsByOrderId($order->id);
        foreach ($payments as $payment)
        {
            $properties = $payment->properties;
            foreach($properties as $property)
            {
		    if($property->typeId == 30)
		    {
		    $tid_status = $property->value;
		    }
            }
        }
        $paymentKey = $paymentHelper->getPaymentKeyByMop($payments[0]->mopId);
        $db_details = $paymentService->getDatabaseValues($order->id);
        $get_transaction_details = $transactionLogData->getTransactionData('orderNo', $order->id);
	    $totalCallbackAmount = 0;
	    foreach ($get_transaction_details as $transaction_details) {
	       $totalCallbackAmount += $transaction_details->callbackAmount;
	    }
        if (in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_CC', 'NOVALNET_SEPA', 'NOVALNET_PAYPAL']) && !empty($db_details['plugin_version'])
        ) {
             
        try {
                $comments = '';
                $comments .= PHP_EOL . $paymentHelper->getTranslatedText('nn_tid') . $db_details['tid'];
                if(!empty($db_details['test_mode'])) {
                    $comments .= PHP_EOL . $paymentHelper->getTranslatedText('test_order');
                }
                 if(in_array($tid_status, [91, 100]) && ($db_details['payment_id'] == 27 && ($transaction_details->amount > $totalCallbackAmount) ) ) {
                $comments .= PHP_EOL . $paymentService->getInvoicePrepaymentComments($db_details);
                
                }
                
                $orderPdfGenerationModel = pluginApp(OrderPdfGeneration::class);
                $orderPdfGenerationModel->advice = $paymentHelper->getTranslatedText('novalnet_details'). PHP_EOL . $comments;
                if ($event->getDocType() == Document::INVOICE) {
                    $event->addOrderPdfGeneration($orderPdfGenerationModel); 
                }
        } catch (\Exception $e) {
                    $this->getLogger(__METHOD__)->error('Adding PDF comment failed for order' . $order->id , $e);
        } 
        }
        } 
      );  
    }
}
