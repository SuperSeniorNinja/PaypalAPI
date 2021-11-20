<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Library docs: https://github.com/angelleye/paypal-php-library

class Paypal {

  protected $CI;

  public function __construct() {
    require 'application/config/paypal.php';
    require_once 'application/third_party/PayPal-PHP-SDK/autoload.php';
    require_once 'application/third_party/paypal-php-library/autoload.php';

    $this->CI = &get_instance();

    $this->configArray = array(
      'Sandbox' => $sandbox,
      'ClientID' => $rest_client_id,
      'ClientSecret' => $rest_client_secret,
      'LogResults' => $log_results,
      'LogPath' => $log_path,
      'LogLevel' => $log_level
    );

    $this->sandbox = $sandbox;
    $this->debug = FALSE;
  }

  public function sendPayout($payoutMethod, $eventID, $payoutAmount, $recipientProfileType) {
    $paypal = new \angelleye\PayPal\rest\payouts\PayoutsAPI( $this->configArray );

    if ( strtoupper('paypal') != strtoupper($payoutMethod->payout_method) ) return FALSE;
    if ( !$eventID ) return FALSE;

    // SenderBatchId: A sender-specified ID number. Tracks the batch payout in an accounting system.Note: PayPal prevents duplicate batches from being processed. If you specify a `sender_batch_id` that was used in the last 30 days, the API rejects the request and returns an error message that indicates the duplicate `sender_batch_id` and includes a HATEOAS link to the original batch payout with the same `sender_batch_id`. If you receive a HTTP `5nn` status code, you can safely retry the request with the same `sender_batch_id`. In any case, the API completes a payment only once for a specific `sender_batch_id` that is used within 30 days.
    // EmailSubject: The subject line text for the email that PayPal sends when a payout item completes. The subject line is the same for all recipients. Value is an alphanumeric string with a maximum length of 255 single-byte characters.
    $gathrBatchId = uniqid();
    $batchHeader = array(
      'SenderBatchId' => $gathrBatchId,
      'EmailSubject'  => '[Action Required] You have a Gathr payout'
    );

    // currency: Required. 3-letter [currency code] (https://developer.paypal.com/docs/integration/direct/rest_api_payment_country_currency_support/). PayPal does not support all currencies.
    // value: Required. Total amount paid out to the payee. 10 characters max with support for 2 decimal places.
    $amount = array(
      'currency' => 'USD',
      'value'    => $payoutAmount
    );

    // RecipientType: Valid values: EMAIL | PHONE | PAYPAL_ID.
    // Note: Optional. A sender-specified note for notifications. Value is any string value. Maximum length: 4000.
    // Receiver: The receiver of the payment. Corresponds to the recipient_type value in the request. Maximum length: 127.
    // SenderItemId: A sender-specified ID number. Tracks the batch payout in an accounting system. Maximum length: 30.
    $payoutData = array(
      'RecipientType' => 'EMAIL',
      'Note'          => 'Congratulations on a successful event with Gathr!',
      'Receiver'      => $payoutMethod->payout_email,
      'SenderItemId'  => $gathrBatchId
    );

    $requestData = array(
      "batchHeader" => $batchHeader,
      "amount"      => $amount,
      "PayoutItem"  => $payoutData
    );

    // Pass data into class for processing with PayPal and load the response array into $result
    $result = $paypal->CreateSinglePayout($requestData);

    if ($this->debug) {
      echo "<pre>";
      print_r($result);
    }

    if ($result['RESULT'] == 'Success') {
      $batchId = $result['PAYOUT']['batch_header']['payout_batch_id'];
      $batchStatus = $result['PAYOUT']['batch_header']['batch_status'];

      // Record the Payout in the database
      $this->CI->load->model('Payouts');
      $payout = new Payouts();
      $payout->user_id = $payoutMethod->user_id;
      $payout->event_id = $eventID;
      $payout->user_payout_method_id = $payoutMethod->id;
      $payout->gathr_batch_id = $gathrBatchId;
      $payout->paypal_batch_id = $batchId;
      $payout->stripe_payout_id = NULL;
      $payout->status = $batchStatus;
      $payout->amount = $payoutAmount;
      $payout->recipient_profile_type = $recipientProfileType;
      $payoutRowId = $payout->add();

      $this->load->model('Events');
      $event = $this->Events->getAsObject($eventID);

      // Send an email to the talent, if applicable:
      if ('talent' == $recipientProfileType) {
        $talent = $event->getTalent();
        $talent->sendPayoutNotification($payoutRowId);
      }

      // Send an email to the host, if applicable:
      if ('host' == $recipientProfileType) {
        $host = $event->getHost();
        $host->sendPayoutNotification($payoutRowId);
      }

      // Send an email to the venue, if applicable:
      if ('venue' == $recipientProfileType) {
        $venue = $event->getVenue();
        $venue->sendPayoutNotification($payoutRowId);
      }

      return $batchId;
    }
    else {
      return FALSE;
    }
  }

  // public function getPayoutStatus($payoutId) {
  //   $paypal = new \angelleye\PayPal\rest\payouts\PayoutsAPI( $this->configArray );

  //   // Pass data into class for processing with PayPal and load the response array into $result
  //   $result = $paypal->GetPayoutItemStatus($payoutId);

  //   if ($this->debug) {
  //     echo "<pre>";
  //     print_r($result);
  //   }

  //   if ($result['RESULT'] == 'Success') {
  //     // Store and return the status of the item in the batch:
  //     $payoutStatus = $result['PAYOUT_ITEM']['transaction_status'];

  //     // Update the payout's status in the database
  //     // ...

  //     return $payoutStatus;
  //   }
  //   else {
  //     return FALSE;
  //   }
  // }

  public function getPayoutBatchStatus($payoutBatchId) {
    $paypal = new \angelleye\PayPal\rest\payouts\PayoutsAPI( $this->configArray );

    // Pass data into class for processing with PayPal and load the response array into $result
    $result = $paypal->GetPayoutBatchStatus($payoutBatchId);

    if ($this->debug) {
      echo "<pre>";
      print_r($result);
    }

    if ($result['RESULT'] == 'Success') {
      // Store and return the status of the first (and only) item in the batch:
      $payoutStatus = $result['BATCH_STATUS']['items'][0]['transaction_status'];

      // Update the payout's status in the database
      $this->CI->load->model('Payouts');
      $this->CI->Payouts->updatePaypalPayoutStatus($payoutBatchId, $payoutStatus);

      return $payoutStatus;
    }
    else {
      return FALSE;
    }
  }

  // Record a Paypal webhook event
  public function recordWebhookEvent($webhookDataRaw, $headers) {
    $webhookData = json_decode($webhookDataRaw);
    // error_log('PayPal webhook: Verifying: ' . $webhookData->id . '...');

    // Verify the signature to make sure the webhook came from PayPal.
    // (PayPal webhook verification is not supported on their sandbox.)
    $result = array();
    $payoutsWebhookId = $this->CI->config->item('paypal_payouts_webhook_id');
    $paypal = new \angelleye\PayPal\rest\notifications\NotificationsAPI( $this->configArray );
    $result = $paypal->VerifyWebhookSignature($headers, $payoutsWebhookId , $webhookDataRaw);

    if ( 'SUCCESS' == $result['STATUS'] ) {
      // Signature verified, update the payout's status:
      // error_log('PayPal webhook: signature verified.');
      $signatureVerified = TRUE;
    }
    else {
      // error_log('PayPal webhook: unable to verify webhook event ID.');
      $signatureVerified = FALSE;
    }

    // error_log('PayPal webhook: Verification complete.');

    // Update the payout's status if the signature was verified OR if we're on the sandbox
    if ( $signatureVerified ) {
      $this->CI->load->model('Payouts');

      // PayPal payout event names: https://developer.paypal.com/docs/api-basics/notifications/webhooks/event-names/#batch-payouts

      switch ($webhookData->event_type) {
        case 'PAYMENT.PAYOUTS-ITEM.BLOCKED':
        case 'PAYMENT.PAYOUTS-ITEM.CANCELED':
        case 'PAYMENT.PAYOUTS-ITEM.DENIED':
        case 'PAYMENT.PAYOUTS-ITEM.FAILED':
        case 'PAYMENT.PAYOUTS-ITEM.HELD':
        case 'PAYMENT.PAYOUTS-ITEM.REFUNDED':
        case 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED':
        case 'PAYMENT.PAYOUTS-ITEM.UNCLAIMED':
          // Update the payout's status in the database
          // error_log('PayPal webhook: updating payout batch ID ' . $webhookData->resource->payout_batch_id . ' with status ' . $webhookData->resource->transaction_status);
          $this->CI->Payouts->updatePaypalPayoutStatus($webhookData->resource->payout_batch_id, $webhookData->resource->transaction_status);
          $updateStatus = TRUE;
        case 'PAYMENT.PAYOUTSBATCH.DENIED':
        case 'PAYMENT.PAYOUTSBATCH.PROCESSING':
        case 'PAYMENT.PAYOUTSBATCH.SUCCESS':
          // Update the payout's status in the database
          // error_log('PayPal webhook: updating payout batch ID ' . $webhookData->resource->batch_header->payout_batch_id . ' with status ' . $webhookData->resource->batch_header->batch_status);
          $this->CI->Payouts->updatePaypalPayoutStatus($webhookData->resource->batch_header->payout_batch_id, $webhookData->resource->batch_header->batch_status);
          $updateStatus = TRUE;
      } // end switch
      return $updateStatus;
    } // end if sandbox or signature verified
    else {
      return FALSE;
    }
  }

  //Refund order paid via paypal checkout
  public function refundOrder($transaction_id, $refund_amount){
    //get client id and secret from the config
    $paypal_client_id = $this->CI->config->item('paypal_rest_client_id');
    $paypal_secret_id = $this->CI->config->item('paypal_rest_client_secret');

    //get access token
    $ch = curl_init();
    if ('production' == $this->CI->config->item('environment'))
        $url = "https://api-m.paypal.com/v1/oauth2/token";
    else
        $url = "https://api-m.sandbox.paypal.com/v1/oauth2/token";

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
    curl_setopt($ch, CURLOPT_USERPWD, $paypal_client_id.':' . $paypal_secret_id);

    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'Accept-Language: en_US';
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = array("status" => "failure");
        return json_encode($error);
    }
    curl_close($ch);
    $result_json = json_decode($result, true);

    //get paypal access token to call PayPal's user profile service
    $app_id = $result_json["app_id"];
    $token_type = $result_json["token_type"];
    $access_token = $result_json["access_token"];

    //get paypal user profile information based on access token.
    $ch_1 = curl_init();
    
    if ('production' == $this->CI->config->item('environment'))
        $url1 = 'https://api-m.paypal.com/v2/payments/captures/'.$transaction_id.'/refund';
    else
      $url1 = 'https://api-m.sandbox.paypal.com/v2/payments/captures/'.$transaction_id.'/refund';

    curl_setopt($ch_1, CURLOPT_URL, $url1);
    curl_setopt($ch_1, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch_1, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch_1, CURLOPT_POST, true);
    $array = array(
      "amount" => array(
        "currency_code" => "USD",
        "value" => $refund_amount
      )
    );
    curl_setopt($ch_1, CURLOPT_POSTFIELDS, json_encode($array));

    $headers = array();
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Authorization: Bearer '.$access_token;
    curl_setopt($ch_1, CURLOPT_HTTPHEADER, $headers);
    $result = curl_exec($ch_1);
    if (curl_errno($ch_1)) {
        $error = array("status" => "failure");
        return json_encode($error);
    }
    curl_close($ch_1);
    $res = json_decode($result, true);

    return json_encode($res);
  }
}
