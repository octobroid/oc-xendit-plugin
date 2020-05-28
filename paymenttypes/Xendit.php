<?php namespace Octobro\Xendit\PaymentTypes;

use Twig;
use Input;
use Request;
use Redirect;
use Exception;
use Carbon\Carbon;
use ApplicationException;
use Responsiv\Pay\Classes\GatewayBase;

class Xendit extends GatewayBase
{
    /**
     * {@inheritDoc}
     */
    public function __construct($model = null)
    {
        parent::__construct($model);

        if ($this->model) {
            $this->model->addJsonable(['payment_channels']);
        }
    }

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
    public function processPaymentForm($data, $invoice)
    {
        $paymentMethod = $invoice->getPaymentMethod();

        // Init Xendit Client
        \Xendit\Xendit::setApiKey($paymentMethod->is_production ? $paymentMethod->production_secret_key : $paymentMethod->sandbox_secret_key);

        // Init options
        $options = [
            'external_id'          => (string) $invoice->id,
            'amount'               => (int) $invoice->total,
            'payer_email'          => $invoice->email,
            'description'          => $invoice->first_name . ' ' . $invoice->last_name,
        ];

        // Proceed DANA and LinkAja
        if (in_array('DANA', $paymentMethod->payment_channels)) {
            $options = array_merge($options, [
                'ewallet_type' => 'DANA',
                'callback_url' => url('api_responsiv_pay/xendit_notify/params'),
                'redirect_url' => $invoice->getReceiptUrl(),
            ]);

            $response = \Xendit\EWallets::create($options);

            if (array_get($response, 'error_code')) {
                throw new ApplicationException(array_get($response, 'message', 'Something went wrong.'));
            }

            if ($checkoutUrl = array_get($response, 'checkout_url')) {
                $invoice->updateInvoiceStatus($paymentMethod->invoice_pending_status);
                return Redirect::to($checkoutUrl);
            }
        }

        // Proceed LinkAja
        if (in_array('LINKAJA', $paymentMethod->payment_channels)) {
            $options = array_merge($options, [
                'ewallet_type' => 'LINKAJA',
                'phone' => array_get($data, 'phone'),
                'items' => [
                    [
                        'id'       => 'pay',
                        'name'     => 'Payment',
                        'price'    => $invoice->total,
                        'quantity' => 1,
                    ],
                ],
                'callback_url' => url('api_responsiv_pay/xendit_notify/params'),
                'redirect_url' => $invoice->getReceiptUrl(),
            ]);

            $response = \Xendit\EWallets::create($options);

            if (array_get($response, 'error_code')) {
                throw new ApplicationException(array_get($response, 'message', 'Something went wrong.'));
            }

            if ($checkoutUrl = array_get($response, 'checkout_url')) {
                $invoice->updateInvoiceStatus($paymentMethod->invoice_pending_status);
                return Redirect::to($checkoutUrl);
            }
        }

        // Additional options for invoice
        $options = array_merge($options, [
            'success_redirect_url' => $invoice->getReceiptUrl(),
            'failure_redirect_url' => $invoice->getReceiptUrl(),
        ]);

        // Set payment channels
        if (is_array($paymentMethod->payment_channels) && !empty($paymentMethod->payment_channels)) {
            $options['payment_methods'] = $paymentMethod->payment_channels;
        }

        // Send email configuration
        if (isset($paymentMethod->should_send_email)) {
            $options['should_send_email'] = $paymentMethod->should_send_email ? true : false;
        }

        // Calculate invoice duration
        if ($paymentMethod->expiry_unit && $paymentMethod->expiry_duration) {
            switch ($paymentMethod->expiry_unit) {
                case 'minute':
                    $options['invoice_duration'] = $paymentMethod->expiry_duration * 60;
                    break;
                case 'hour':
                    $options['invoice_duration'] = $paymentMethod->expiry_duration * 60 * 60;
                    break;
                case 'day':
                    $options['invoice_duration'] = $paymentMethod->expiry_duration * 60 * 60 * 24;
                    break;
            }
        }

        // Create invoice on Xendit
        $response = \Xendit\Invoice::create($options);

        // Set invoice due_at
        if (array_get($options, 'invoice_duration')) {
            $invoice->update([
                'due_at' => Carbon::now()->addSeconds(array_get($options, 'invoice_duration')),
            ]);
        }

        // If getting error
        if (array_get($response, 'error_code')) {
            $invoice->logPaymentAttempt('error', 0, $options, $response, null);
            throw new ApplicationException(array_get($response, 'message') ?: 'Something went wrong.');
        } 

        // Update the invoice
        $status = array_get($response, 'status') ?: array_get($response, 'payment_status');

        switch ($status) {
            case 'PENDING':
                $invoice->logPaymentAttempt($status, 0, $options, $response, null);
                $invoice->updateInvoiceStatus($paymentMethod->invoice_pending_status);

                if (!$paymentMethod->skip_xendit_payment_page) {
                    return Redirect::to(array_get($response, 'invoice_url'));
                }
            default:
                $invoice->logPaymentAttempt($status, 0, $options, $response, null);
        }

        return Redirect::to($invoice->getReceiptUrl());
    }

    public function processNotify($params)
    {
        try {
            $response = Input::all();

            $invoice = $this->getInvoice($response);

            $this->checkInvoiceGates($invoice);

            $status = array_get($response, 'status') ?: array_get($response, 'payment_status');
            
            $paymentMethod = $invoice->getPaymentMethod();

            switch ($status) {
                case 'PAID':
                case 'SUCCESS_COMPLETED':
                    if ($invoice->markAsPaymentProcessed()) {
                        $invoice->logPaymentAttempt('Payment success', 1, null, $response, null);
                        $invoice->updateInvoiceStatus($paymentMethod->invoice_paid_status);
                    }
                    break;
                case 'EXPIRED':
                    break;
                default:
                    throw new ApplicationException('Status "' . $status . '" not found.');
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
        
        if (!$callbackToken && is_array(Input::get('callback_authentication_token'))) {
            $callbackToken = array_get(Input::get('callback_authentication_token'), 'token');
        }

        if (!$callbackToken && is_string(Input::get('callback_authentication_token'))) {
            $callbackToken = Input::get('callback_authentication_token');
        }

        return $callbackToken == ($paymentMethod->is_production ? $paymentMethod->production_validation_token : $paymentMethod->sandbox_validation_token);
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

    public function getPaymentInstructions($invoice)
    {
        $paymentData = $this->getInvoicePaymentData($invoice);

        $partialPath = plugins_path('octobro/xendit/paymenttypes/xendit/_payment_instructions.htm');

        return Twig::parse(file_get_contents($partialPath), ['data' => $paymentData]);
    }

    protected function getInvoicePaymentData($invoice)
    {
        $logs = $invoice->payment_log()->get();

        foreach ($logs as $log) {
            $data = $log->response_data;

            if (array_get($data, 'status') == 'PENDING') {
                return $data;
            }
        }
    }
}
