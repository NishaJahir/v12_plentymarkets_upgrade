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

use Plenty\Plugin\Templates\Twig;

use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Comment\Contracts\CommentRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use \Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Novalnet\Services\PaymentService;
use Novalnet\Services\TransactionService;

/**
 * Class NovalnetOrderConfirmationDataProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetOrderConfirmationDataProvider
{
    /**
     * Setup the Novalnet transaction comments for the requested order
     *
     * @param Twig $twig
     * @param PaymentRepositoryContract $paymentRepositoryContract
     * @param Arguments $arg
     * @return string
     */
    public function call(Twig $twig, PaymentRepositoryContract $paymentRepositoryContract, $arg)
    {
        $paymentHelper = pluginApp(PaymentHelper::class);
        $paymentService = pluginApp(PaymentService::class);
        $transactionLog  = pluginApp(TransactionService::class); 
        $sessionStorage = pluginApp(FrontendSessionStorageFactoryContract::class);
        $order = $arg[0];
        if (!empty ($order['id'])) {
			$payments = $paymentRepositoryContract->getPaymentsByOrderId($order['id']);
		$paymentHelper->logger('payment conf', $payments);
            foreach($payments as $payment)
            {
                $properties = $payment->properties;
                foreach($properties as $property)
                {
                    if ($property->typeId == 30)
                    {
                    $tidStatus = $property->value;
                    }
                }
                if($paymentHelper->getPaymentKeyByMop($payment->mopId))
                {
                    $orderId = (int) $payment->order['orderId'];
                    $comment = '';
                    $paymentDetails = $paymentService->getDatabaseValues($orderId);
                    $paymentHelper->logger('db1', $paymentDetails);
                    $comments = '';
                    $comments .= PHP_EOL . $paymentHelper->getTranslatedText('nn_tid') . $paymentDetails['tid'];
                    if(!empty($paymentDetails['test_mode'])) {
                        $comments .= PHP_EOL . $paymentHelper->getTranslatedText('test_order');
                    }
                    $getTransactionDetails = $transactionLog->getTransactionData('orderNo', $orderId);
                    $totalCallbackAmount = 0;
                    foreach ($getTransactionDetails as $transactionDetail) {
                       $totalCallbackAmount += $transactionDetail->callbackAmount;
                    }
                    
                    if(in_array($tidStatus, ['PENDING', 'ON_HOLD', 'SUCCESS']) && ($paymentDetails['invoice_type'] == 'INVOICE' && ($transactionDetail->amount > $totalCallbackAmount) || $paymentDetails['payment_id'] == 96) ) {
                        //$bankDetails .= PHP_EOL . $paymentService->getInvoicePrepaymentComments($db_details);
                        $paymentHelper->logger('invoice comments called', $transactionDetail);
			    $bankDetails = $paymentDetails;
                    }
                }
            }
                    $comment .= (string) $comments;
                    $comment .= PHP_EOL;
        }   
                  return $twig->render('Novalnet::NovalnetOrderHistory', ['bankDetails' => $bankDetails, 'paymentDetails' => $paymentDetails]);
    }
}

    

