<?php namespace Octobro\Xendit\PaymentTypes;

use Input;
use Flash;
use Redirect;
use Exception;
use ApplicationException;
use XenditClient\XenditPHPClient as XenditClient;
use Responsiv\Pay\Classes\GatewayBase;

class Xendit extends GatewayBase
{

    /**
     * {@inheritDoc}
     */
    public function gatewayDetails()
    {
        return [
            'name'        => 'Xendit',
            'description' => 'Xendit payment gateway.'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function defineFormFields()
    {
        return 'fields.yaml';
    }

    /**
     * {@inheritDoc}
     */
    public function defineValidationRules()
    {
        return [
            'public_key'       => 'required',
            'secret_key'       => 'required',
            'validation_token' => 'required'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function initConfigData($host)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function registerAccessPoints()
    {
        return array(
            'xendit_notify' => 'processNotify'
        );
    }

    /**
     * Status field options.
     */
    public function getDropdownOptions()
    {
        return $this->createInvoiceStatusModel()->listStatuses();
    }

    /**
     **/
    public function getFormAction($host)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function processPaymentForm($data, $host, $invoice)
    {
        $xendit = new XenditClient(['secret_api_key' => $host->secret_key]);

        try {
            $response = $xendit->createInvoice(
                (string) $invoice->id,
                $invoice->total,
                $invoice->email,
                $invoice->items->first()->description
            );

            if (array_get($response, 'errors')) {
                throw new ApplicationException(array_get($response, 'message'));
            }

            $invoice->logPaymentAttempt($response['status'], 1, [], $response, null);
            $invoice->updateInvoiceStatus($invoice->getPaymentMethod()->invoice_pending_status);
        } catch (Exception $e) {
            trace_log($response);
            throw new ApplicationException($e->getMessage());
        }

    }

    public function processNotify($params)
    {
        try {
			$response = Input::all();
            $amount = $response['paid_amount'];
            $orderId = $response['external_id'];

            $invoice = $this->createInvoiceModel()
                ->whereTotal($amount)
                ->whereId($orderId)
                ->first();

            if (! $invoice) {
                throw new ApplicationException('Invoice not found');
            }

            if (! $paymentMethod = $invoice->getPaymentMethod()) {
                throw new ApplicationException('Payment method not found');
            }

            if ($paymentMethod->getGatewayClass() != 'Octobro\Xendit\PaymentTypes\Xendit') {
                throw new ApplicationException('Invalid payment method');
            }

            if (! $this->isGenuineNotify($response, $invoice)) {
                throw new ApplicationException('Hacker coming..');
            }

			$status = $response['status'];
            $statusMessage = 'Payment success';

            switch ($status) {
                case 'PAID':
                    if ($invoice->markAsPaymentProcessed()) {
                        $invoice->logPaymentAttempt($statusMessage, 1, null, $response, null);
                        $invoice->updateInvoiceStatus($paymentMethod->invoice_paid_status);
                    }
                    break;
            }
        } catch (Exception $ex) {
            if (isset($invoice) && $invoice) {
                $invoice->logPaymentAttempt($ex->getMessage(), 0, null, $_POST, null);
            }

            throw new ApplicationException($ex->getMessage());
        }
    }

    public function isGenuineNotify($response, $invoice)
    {
        return true;
    }
}
