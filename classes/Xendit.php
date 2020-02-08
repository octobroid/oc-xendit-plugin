<?php namespace Octobro\Xendit\Classes;

use XenditClient\XenditPHPClient;

class Xendit extends XenditPHPClient
{
    public function createCallbackVirtualAccount ($external_id, $bank_code, $name, $amount = null, $options = []) 
    {
        $curl = curl_init();

        $headers = array();
        $headers[] = 'Content-Type: application/json';

        $end_point = $this->server_domain.'/callback_virtual_accounts';

        $data['external_id'] = $external_id;
        $data['bank_code']   = $bank_code;
        $data['name']        = $name;

        if ($amount) {
            $data['is_closed']       = true;
            $data['expected_amount'] = $amount;
            $data['is_single_use']   = true;
        }

        $data = array_merge($data, $options);

        if (!empty($virtual_account_number)) {
            $data['virtual_account_number'] = $virtual_account_number;
        }

        $payload = json_encode($data);

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_USERPWD, $this->secret_api_key.":");
        curl_setopt($curl, CURLOPT_URL, $end_point);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);
        curl_close($curl);

        $responseObject = json_decode($response, true);
        return $responseObject;
    }

    public function createEwalletPayment($external_id, $ewallet_type, $amount = null, $options = []) 
    {
        $curl = curl_init();

        $headers = array();
        $headers[] = 'Content-Type: application/json';

        $end_point = $this->server_domain.'/ewallets';

        $data['external_id']  = $external_id;
        $data['ewallet_type'] = $ewallet_type;
        $data['amount']       = $amount;

        $data = array_merge($data, $options);

        $payload = json_encode($data);

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_USERPWD, $this->secret_api_key.":");
        curl_setopt($curl, CURLOPT_URL, $end_point);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);
        curl_close($curl);

        $responseObject = json_decode($response, true);
        return $responseObject;
    }
}
