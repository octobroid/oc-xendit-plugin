<?php namespace Octobro\Xendit\PaymentTypes;

use Auth;
use Input;
use Flash;
use Redirect;
use Exception;
use ApplicationException;
use Octobro\Xendit\Models\Tokenization;
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
        try {
            $response = $this->getXenditResponse($data, $host, $invoice);

            if (array_get($response, 'errors') or array_get($response, 'error_code')) {
                throw new ApplicationException(array_get($response, 'message'));
            }

            $paymentMethod = $invoice->getPaymentMethod();

            switch ($paymentMethod->payment_channel) {
                case 'credit_card':
                    if ($invoice->markAsPaymentProcessed()) {
                        $invoice->logPaymentAttempt($response['status'], 1, null, $response, null);
                        $invoice->updateInvoiceStatus($paymentMethod->invoice_paid_status);
                    }
                    break;
                case 'virtual_account':
                    $invoice->logPaymentAttempt($response['status'], 1, [], $response, null);
                    $invoice->updateInvoiceStatus($paymentMethod->invoice_pending_status);
                    break;
            }
        } catch (Exception $e) {
            if (isset($response)) {
                trace_log($response);
            }
            throw new ApplicationException($e->getMessage());
        }

    }

    /**
     * Get Xendit response when creating invoice or capture credit card
     *
     * @param array $data
     * @param model $host
     * @param model $invoice
     * @return JSON $response
     */
    protected function getXenditResponse($data, $host, $invoice)
    {
        $xendit = new XenditClient(['secret_api_key' => $host->secret_key]);

        if ($invoice->getPaymentMethod()->payment_channel != 'credit_card') {
            return $xendit->createInvoice(
                (string) $invoice->id,
                $invoice->total,
                $invoice->email,
                $invoice->items->first()->description
            );
        }

        $tokenization = Auth::getUser()->cc_tokenizations()->firstOrCreate([
            'token'              => $data['token_id'],
            'masked_card_number' => $data['masked_card_number'],
        ]);

        return $xendit->captureCreditCardPayment(
            (string) $invoice->id,
            $tokenization->token,
            $invoice->total,
            $data
        );
    }

    public function getUserTokenisations()
    {
        if ($user = Auth::getUser()) {
            return $user->cc_tokenizations;
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
