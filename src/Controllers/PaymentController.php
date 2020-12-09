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

namespace Novalnet\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Novalnet\Services\PaymentService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;

/**
 * Class PaymentController
 *
 * @package Novalnet\Controllers
 */
class PaymentController extends Controller
{
    use Loggable;
    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var SessionStorageService
     */
    private $sessionStorage;

    /**
     * @var basket
     */
    private $basketRepository;
    
    /**
     * @var PaymentService
     */
    private $paymentService;

    /**
     * @var Twig
     */
    private $twig;
    
    /**
     * @var ConfigRepository
     */
    private $config;

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param SessionStorageService $sessionStorage
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentService $paymentService
     * @param Twig $twig
     */
    public function __construct(  Request $request,
                                  Response $response,
                                  ConfigRepository $config,
                                  PaymentHelper $paymentHelper,
                                  FrontendSessionStorageFactoryContract $sessionStorage,
                                  BasketRepositoryContract $basketRepository,             
                                  PaymentService $paymentService,
                                  Twig $twig
                                )
    {

        $this->request         = $request;
        $this->response        = $response;
        $this->paymentHelper   = $paymentHelper;
        $this->sessionStorage  = $sessionStorage;
        $this->basketRepository  = $basketRepository;
        $this->paymentService  = $paymentService;
        $this->twig            = $twig;
        $this->config          = $config;
    }

    /**
     * Novalnet redirects to this page if the payment was executed successfully
     *
     */
    public function paymentResponse() {
        $requestData = $this->request->all();
	    $this->getLogger(__METHOD__)->error('payment response', $requestData);
	   
	  $responseData = $this->paymentService->checksumForRedirects($requestData);
	    $this->getLogger(__METHOD__)->error('payment response', $requestData);

				
	
        $isPaymentSuccess = isset($responseData['result']['status']) && in_array($responseData['status'], ['PENDING', 'SUCCESS']);
        $notificationMessage = $this->paymentHelper->getTranslatedText('payment_success');
        if ($isPaymentSuccess) {
            $this->paymentService->pushNotification($notificationMessage, 'success', 100);
        } else {
            $this->paymentService->pushNotification($notificationMessage, 'error', 100);    
        }
        
        //$responseData['test_mode'] = $this->paymentHelper->decodeData($responseData['test_mode'], $responseData['uniqid']);
       // $responseData['amount']    = $this->paymentHelper->decodeData($responseData['amount'], $responseData['uniqid']) / 100;
        $paymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($paymentRequestData, $responseData));
        $this->paymentService->validateResponse();
        return $this->response->redirectTo('confirmation');
    }

    /**
     * Process the Form payment
     *
     */
    public function processPayment()
    {
        $requestData = $this->request->all();
	    $this->getLogger(__METHOD__)->error('request controller', $requestData);
        $notificationMessage = $this->paymentHelper->getNovalnetStatusText($requestData);
        
        $serverRequestData = $this->paymentService->getRequestParameters($this->basketRepository->load(), $requestData['paymentKey']);
	
        if (empty($serverRequestData['data']['customer']['first_name']) && empty($serverRequestData['data']['customer']['last_name'])) {
        $notificationMessage = $this->paymentHelper->getTranslatedText('nn_first_last_name_error');
                $this->paymentService->pushNotification($notificationMessage, 'error', 100);
                return $this->response->redirectTo('checkout');
        }
        
        if($requestData['paymentKey'] == 'NOVALNET_CC') {
            $serverRequestData['data']['transaction']['payment_data']['pan_hash'] = $requestData['nnCcPanHash'];
            $serverRequestData['data']['transaction']['payment_data']['unique_id'] = $requestData['nnCcUniqueId'];
            if($this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' )
            {
                $this->sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
                $this->sessionStorage->getPlugin()->setValue('nnPaymentUrl',$serverRequestData['url']);
                $this->paymentService->pushNotification($notificationMessage, 'success', 100);
                return $this->response->redirectTo('place-order');
            }
        } elseif ( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) {
		    if($this->config->get('Novalnet.novalnet_sepa_shopping_type') == true) {
			  $serverRequestData['data']['transaction']['create_token'] = 1;  
		     }
			    $serverRequestData['data']['transaction']['payment_data']['bank_account_holder'] = $serverRequestData['data']['customer']['first_name'] . ' ' . $serverRequestData['data']['customer']['last_name'];
			    $serverRequestData['data']['transaction']['payment_data']['iban'] = $requestData['nnSepaIban'];   
		    
            } elseif ($requestData['paymentKey'] == 'NOVALNET_INSTALMENT_INVOICE' ) {
		$serverRequestData['data']['payment_type'] = 'INSTALMENT_INVOICE';
                    $serverRequestData['data']['key']          = '96';
		 $serverRequestData['data']['instalment_cycles'] = $requestData['nnInstalmentCycle'];
		 $serverRequestData['data']['instalment_period'] = trim($this->config->get('Novalnet.novalnet_instalment_invoice_recurring_period')).'m';
		//$serverRequestData['data']['birth_date']   =  $birthday;
	}
	    $this->getLogger(__METHOD__)->error('request controller', $serverRequestData);
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData);  
        return $this->response->redirectTo('place-order');
    }

    /**
     * Process the redirect payment
     *
     */
    public function redirectPayment()
    {
        $paymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $orderNo = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
        $paymentRequestData['order_no'] = $orderNo;
        $paymentUrl = $this->sessionStorage->getPlugin()->getValue('nnPaymentUrl');

        return $this->twig->render('Novalnet::NovalnetPaymentRedirectForm', [
									   'formData'     => $paymentRequestData,
									   'nnPaymentUrl' => $paymentUrl
                                   ]);
    }
    
}
