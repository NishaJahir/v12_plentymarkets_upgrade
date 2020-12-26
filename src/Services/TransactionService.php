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

use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Services\PaymentService;

/**
 * Class TransactionService
 *
 * @package Novalnet\Services
 */
class TransactionService
{
    use Loggable;

    /**
     * Save data in transaction table
     *
     * @param $transactionData
     */
    public function saveTransaction($transactionData)
    {
        try {
            $database = pluginApp(DataBase::class);
            $transaction = pluginApp(TransactionLog::class);
            $transaction->orderNo             = $transactionData['order_no'];
            $transaction->amount              = $transactionData['amount'];
            $transaction->callbackAmount      = $transactionData['callback_amount'];
            $transaction->referenceTid        = $transactionData['ref_tid'];
            $transaction->transactionDatetime = date('Y-m-d H:i:s');
            $transaction->tid                 = $transactionData['tid'];
            $transaction->paymentName         = $transactionData['payment_name'];
            $transaction->additionalInfo      = !empty($transactionData['additional_info']) ? $transactionData['additional_info'] : '0';
	    $transaction->instalmentInfo      = !empty($transactionData['instalment_info']) ? $transactionData['instalment_info'] : '0';
            
            $database->save($transaction);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Callback table insert failed!.', $e);
        }
    }

    /**
     * Retrieve transaction log table data
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return array
     */
    public function getTransactionData($key, $value)
    {
        $database = pluginApp(DataBase::class);
        $order    = $database->query(TransactionLog::class)->where($key, '=', $value)->get();
        return $order;
    }
    
    public function updateTransactionData($key, $value, $response)
    {
        /**
         * @var DataBase $database
         */
        $database = pluginApp(DataBase::class);
        $orderDetails    = $database->query(TransactionLog::class)->where($key, '=', $value)->get();
       
	 $orderDetail = $orderDetails[0];  
        $additionalInfo = json_decode($orderDetail->additionalInfo,true);
        $additionalInfo['invoice_bankname']  = !empty($response['invoice_bankname']) ? $response['invoice_bankname'] : $additionalInfo['invoice_bankname'];
	$additionalInfo['invoice_bankplace'] = !empty($response['invoice_bankplace']) ? utf8_encode($response['invoice_bankplace']) : utf8_encode($additionalInfo['invoice_bankplace']);
	$additionalInfo['invoice_iban']      = !empty($response['invoice_iban']) ? $response['invoice_iban'] : $additionalInfo['invoice_iban'];
	$additionalInfo['invoice_bic']       = !empty($response['invoice_bic']) ? $response['invoice_bic'] : $additionalInfo['invoice_bic'];
	$additionalInfo['due_date']          = !empty($response['due_date']) ? $response['due_date'] : $additionalInfo['due_date'];
	$additionalInfo['invoice_type']      = !empty($response['invoice_type']) ? $response['invoice_type'] : $additionalInfo['invoice_type'];
	$additionalInfo['invoice_account_holder'] = !empty($response['invoice_account_holder']) ? $response['invoice_account_holder'] : $additionalInfo['invoice_account_holder']; 
        $orderDetail->additionalInfo = json_encode($additionalInfo); 
	     $this->getLogger(__METHOD__)->error('update', $orderDetail);
       $database->save($orderDetail);
    }
    
}
