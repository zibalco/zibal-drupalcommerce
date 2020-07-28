<?php

namespace Drupal\commerce_zibal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Url;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_zibal_redirect",
 *   label = " Zibal.ir (Off-site redirect)",
 *   display_label = "Zibal.ir",
 *    forms = {
 *     "offsite-payment" =
 *   "Drupal\commerce_zibal\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase
{
    /**
    * {@inheritdoc}
    */
    public function defaultConfiguration()
    {
        return [
        'merchant_code' => 'Enter your Merchant Code',
      ] + parent::defaultConfiguration();
    }

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);
        $form['merchant_code'] = [
            '#type' => 'textfield',
            '#title' => t('Merchant Code'),
            '#default_value' => $this->configuration['merchant_code'],
            '#description' => t('The merchat code which is provided by Zibal. If you use the gateway in the Test mode, You can use "zibal"'),
            '#required' => true,
        ];
        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values = $form_state->getValue($form['#parents']);
            // Save configuration
            $this->configuration['merchant_code'] = $values['merchant_code'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onReturn(OrderInterface $order, Request $request)
    {
        $trackId = $request->query->get('trackId');
        if ($this->configuration['mode'] == 'test') {
            $merchant_code = 'zibal';
        }
        elseif ($this->configuration['mode'] == 'live') {
            $merchant_code = $this->configuration['merchant_code'];
        }

        // Prevents double spending:
        // If a bad manner user have a successfull transaction and want
        // to have another payment with previous trans_id, we must prevent him/her.
        $query = \Drupal::entityQuery('commerce_payment')
        ->condition('remote_state', $trackId);
        $payments = $query->execute();
        if (count($payments) > 0) {
            \Drupal::logger('commerce_zibal')
        ->error('Zibal: Double spending occured on order <a href="@url">%order</a> from ip @ip', [
          '@url' => Url::fromUri('base:/admin/commerce/orders/' . $order->id())
            ->toString(),
          '%order' => $order->id(),
          '@ip' => $order->getIpAddress(),
        ]);
            drupal_set_message('Double spending occured.', 'error');
            /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
            $checkout_flow = $order->checkout_flow->entity;
            $checkout_flow_plugin = $checkout_flow->getPlugin();
            $redirect_step = $checkout_flow_plugin->getPreviousStepId('payment');
            $checkout_flow_plugin->redirectToStep($redirect_step);
        }

        if ($request->query->get('status') == '2') {
            $amount = (int) $order->getTotalPrice()->getNumber();
            if ($order->getTotalPrice()->getCurrencyCode() != 'IRR') {
                // Converts Iranian Rials to Toman
                $amount = $amount * 10;
            }

            $data = array(
                'merchant' => $merchant_code,
                'trackId' => $trackId,
            );

            $result = $this->postToZibal('verify', $data);

            if ($result->result == 100 && $amount == $result->amount) {
                $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
                $payment = $payment_storage->create([
                    'state' => 'completed',
                    'amount' => $order->getTotalPrice(),
                    'payment_gateway' => $this->entityId,
                    'order_id' => $order->id(),
                    'test' => $this->getMode() == 'test',
                    'remote_id' => $result->refNumber,
                    'remote_state' => $trackId,
                    'authorized' => $this->time->getRequestTime(),
                ]);
                $payment->save();
                drupal_set_message($this->t('Payment was processed'));
            } else {
                drupal_set_message($this->t('Transaction failed. Result:') . $result->result . ' ' . $this->resultCodes($result->result));
            }
        } else {
            drupal_set_message($this->t('Transaction canceled by user'));
        }
    }


    
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
    
    /**
     * returns a string message based on status parameter from $_GET
     * @param $code
     * @return String
     */
    public function statusCodes($code)
    {
        switch ($code) {
            case -1:
                return "در انتظار پردخت";
            
            case -2:
                return "خطای داخلی";

            case 1:
                return "پرداخت شده - تاییدشده";

            case 2:
                return "پرداخت شده - تاییدنشده";

            case 3:
                return "لغوشده توسط کاربر";
            
            case 4:
                return "‌شماره کارت نامعتبر می‌باشد";

            case 5:
                return "‌موجودی حساب کافی نمی‌باشد";

            case 6:
                return "رمز واردشده اشتباه می‌باشد";

            case 7:
                return "‌تعداد درخواست‌ها بیش از حد مجاز می‌باشد";
            
            case 8:
                return "‌تعداد پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد";

            case 9:
                return "مبلغ پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد";

            case 10:
                return "‌صادرکننده‌ی کارت نامعتبر می‌باشد";
            
            case 11:
                return "خطای سوییچ";

            case 12:
                return "کارت قابل دسترسی نمی‌باشد";

            default:
                return "وضعیت مشخص شده معتبر نیست";
        }
    }
}
