<?php

namespace Drupal\commerce_zibal\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class PaymentOffsiteForm extends BasePaymentOffsiteForm
{

    /**
    * {@inheritdoc}
    */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = $this->entity;
        $order = $payment->getOrder();
        $order_id = $order->id();

        $redirect = Url::fromUri('base:/checkout/' . $order_id . '/payment/return/', ['absolute' => true])
        ->toString();
        $amount = (int) $payment->getAmount()->getNumber();
        if ($payment->getAmount()->getCurrencyCode() != 'IRR') {
            // Converts Iranian Rials to Toman
            $amount = $amount * 10;
        }
        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
        $gateway_configuration = $payment_gateway_plugin->getConfiguration();
        $mode = $gateway_configuration['mode'];
        // $merchant_code = $gateway_configuration['merchant_code'];
        // Checks if we are in debug mode.
        if ($mode == 'test') {
            $merchant_code = 'zibal';
        } elseif ($mode == 'live') {
            $merchant_code = $gateway_configuration['merchant_code'];
        }

        $data = array(
            'merchant' => $merchant_code,
            'amount' => (int) $amount,
            'description' => $order->getStore()->label(),
            'callbackUrl' => $redirect,
        );

        $result = $this->postToZibal('request', $data);

        if ($result->result == 100) {
            $redirect_method = 'post';
            $redirect_url = 'https://gateway.zibal.ir/start/' . $result->trackId;
            $data = [];
            return $this->buildRedirectForm($form, $form_state, $redirect_url, $data, $redirect_method);
        } else {
            drupal_set_message('Error: ' . $result->result . ' ' . $this->resultCodes($result->result), 'error');
            $chekout_page = Url::fromUri('base:/checkout/' . $order_id . '/review', ['absolute' => true])
            ->toString();
            return $this->buildRedirectForm($form, $form_state, $chekout_page, [], null);
        }
    }


    // Functions

    /**
     * connects to zibal's rest api
     * @param $path
     * @param $parameters
     * @return stdClass
     */
    public function postToZibal($path, $parameters)
    {
        $url = 'https://gateway.zibal.ir/v1/'.$path;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response  = curl_exec($ch);
        curl_close($ch);
        return json_decode($response);
    }

    /**
     * returns a string message based on result parameter from curl response
     * @param $code
     * @return String
     */
    public function resultCodes($code)
    {
        switch ($code) {
          case 100:
              return "با موفقیت تایید شد";
          
          case 102:
              return "merchant یافت نشد";

          case 103:
              return "merchant غیرفعال";

          case 104:
              return "merchant نامعتبر";

          case 201:
              return "قبلا تایید شده";
          
          case 105:
              return "amount بایستی بزرگتر از 1,000 ریال باشد";

          case 106:
              return "callbackUrl نامعتبر می‌باشد. (شروع با http و یا https)";

          case 113:
              return "amount مبلغ تراکنش از سقف میزان تراکنش بیشتر است.";

          case 201:
              return "قبلا تایید شده";
          
          case 202:
              return "سفارش پرداخت نشده یا ناموفق بوده است";

          case 203:
              return "trackId نامعتبر می‌باشد";

          default:
              return "وضعیت مشخص شده معتبر نیست";
      }
    }
}
