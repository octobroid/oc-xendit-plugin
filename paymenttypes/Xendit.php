<?php namespace Octobro\Xendit\PaymentTypes;

use Auth;
use Input;
use Flash;
use Request;
use Redirect;
use Exception;
use ApplicationException;
use Octobro\Xendit\Models\Tokenization;
use Octobro\Xendit\Classes\Xendit as XenditClient;
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
            'xendit_notify'          => 'processNotify',
            'xendit_va_is_paid'      => 'processVaIsPaid',
            'xendit_va_status'       => 'processVaStatus',
            'xendit_cc_subscription' => 'processCcSubscription'
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

            if (array_get($response, 'status') == 'FAILED') {
                $message = array_get($response, 'failure_reason');
                $invoice->logPaymentAttempt($message, 0, null, $response, null);

                throw new ApplicationException($message);
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
            trace_log($e);
            trace_log($response);
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
        $configData = $invoice->getPaymentMethod()->config_data;
        $prefixName = empty($configData['prefix']) ? '' : $configData['prefix'] . ' ';

        if ($invoice->getPaymentMethod()->payment_channel != 'credit_card') {
            return $xendit->createCallbackVirtualAccount(
                (string) $invoice->id,
                array_get($data, 'bank', 'BNI'),
                $prefixName . $invoice->first_name . ' ' . $invoice->last_name,
                [
                    'expiration_date' => $this->getExpiredTime($invoice, $configData)
                ]
            );
        }

        $token = $data['token_id'];

        // Save cc if user tick to save
        if (array_get($data, 'save_cc')) {
            Auth::getUser()->cc_tokenizations()->firstOrCreate([
                'token'              => $token,
                'masked_card_number' => $data['masked_card_number'],
            ]);
        }

        return $xendit->captureCreditCardPayment(
            (string) $invoice->id,
            $token,
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
            $amount = array_get($response, 'paid_amount', array_get($response, 'amount'));
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

    public function processVaIsPaid($params)
    {
        try {
			$response = Input::all();
            $invoice = $this->getInvoice($response);

            $this->checkInvoiceGates($invoice);

            if ($invoice->markAsPaymentProcessed()) {
                $invoice->logPaymentAttempt('Payment completed', 1, null, $response, null);
                $invoice->updateInvoiceStatus($invoice->getPaymentMethod()->invoice_paid_status);
            }
        } catch (Exception $ex) {
            if (isset($invoice) && $invoice) {
                $invoice->logPaymentAttempt($ex->getMessage(), 0, null, $response, null);
            }

            trace_log($ex);
            trace_log($response);
            throw new ApplicationException($ex->getMessage());
        }
    }

    public function processVaStatus($params)
    {
        //Nothing todo for now
    }

    public function processCcSubscription($params)
    {
        //Nothing todo for now
    }

    protected function getInvoice($response)
    {
        $amount = array_get($response, 'amount');
        $orderId = array_get($response, 'external_id');

        return $this->createInvoiceModel()
            ->whereTotal($amount)
            ->whereId($orderId)
            ->first();
    }

    protected function checkInvoiceGates($invoice)
    {
        if (! $invoice) {
            throw new ApplicationException('Invoice not found');
        }

        if (! $paymentMethod = $invoice->getPaymentMethod()) {
            throw new ApplicationException('Payment method not found');
        }

        if ($paymentMethod->getGatewayClass() != 'Octobro\Xendit\PaymentTypes\Xendit') {
            throw new ApplicationException('Invalid payment method');
        }

        if (! $this->isGenuineNotify($paymentMethod)) {
            throw new ApplicationException('Hacker coming..');
        }
    }

    protected function isGenuineNotify($paymentMethod)
    {
        $callbackToken = Request::header('X-CALLBACK-TOKEN');

        return $paymentMethod->validation_token == $callbackToken;
    }

    /**
     * Get expired time based on config data
     *
     * @param string $time
     */
    protected function getExpiredTime($invoice, $configData)
    {
        $unit = '';

        switch ($configData['expiry_unit']) {
            case 'minute':
                $unit = 'addMinutes';
                break;
            case 'day':
                $unit = 'addDays';
                break;
            case 'hour':
                $unit = 'addHours';
                break;
        }

        $duration = is_null($configData['expiry_duration']) ? 1 : $configData['expiry_duration'];

        return $invoice->created_at
            ->{$unit}($duration)
            ->format('Y-m-d\TH:i:s\Z');
    }
}
