<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class AuthorizeNetGateway extends BaseGateway
{
    protected const PAID_STATUSES = [
        'authorizedPendingCapture',
        'capturedPendingSettlement',
        'settledSuccessfully',
    ];

    /**
     * Required config keys for the gateway
     *
     * @return array
     */
    public function needyConfig(): array
    {
        return [
            'api_login_id',
            'transaction_key',
            'environment' // 'sandbox' or 'production'
        ];
    }

    /**
     * Initiate a payment with Stripe
     *
     * Expected $payload keys:
     * - amount (int) Required. Amount in smallest currency unit.
     * - email (string) Required. Customer's email address.
     *
     * @param array $payload
     * @return array ['payment_url' => string, 'payment_id' => string]
     * @throws \Exception
     */
    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $config = $this->config;
        // Authorize.net credentials
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($config['api_login_id']);
        $merchantAuthentication->setTransactionKey($config['transaction_key']);

        $refId = $payment->orderId;

        // Create transaction request
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType("authCaptureTransaction");
        $transactionRequestType->setAmount($payment->amount);

        // The invoice number is what verification matches on later.
        $order = new AnetAPI\OrderType();
        $order->setInvoiceNumber(substr($payment->orderId, 0, 20));
        $order->setDescription($payment->meta['description'] ?? ('Payment for order ' . $payment->orderId));
        $transactionRequestType->setOrder($order);

        // Create request for a payment page token
        $request = new AnetAPI\GetHostedPaymentPageRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setTransactionRequest($transactionRequestType);

        // Hosted payment settings
        $setting1 = new AnetAPI\SettingType();
        $setting1->setSettingName("hostedPaymentReturnOptions");
        $setting1->setSettingValue(
            json_encode([
                "showReceipt" => false,
                "url" => $this->successUrl(),
                "urlText" => "Return",
                "cancelUrl" => $this->cancelUrl(),
                "cancelUrlText" => "Cancel"
            ])
        );

        $setting2 = new AnetAPI\SettingType();
        $setting2->setSettingName("hostedPaymentButtonOptions");
        $setting2->setSettingValue(json_encode(["text" => "Pay"]));

        $request->addToHostedPaymentSettings($setting1);
        $request->addToHostedPaymentSettings($setting2);

        $controller = new AnetController\GetHostedPaymentPageController($request);

        // Sandbox or Live
        $env = $config['environment'] === 'production'
            ? \net\authorize\api\constants\ANetEnvironment::PRODUCTION
            : \net\authorize\api\constants\ANetEnvironment::SANDBOX;

        $response = $controller->executeWithApiResponse($env);

        try{
            if ($response !== null && $response->getToken()) {
                $token = $response->getToken();

                return new PaymentResponseData(
                    true,
                    $this->gatewayName,
                    $token,
                    "https://accept.authorize.net/payment/payment/" . $token,
                    'pending',
                    false,
                    'Authorize.Net hosted payment page generated successfully.',
                    ['token' => $token]
                );
            } else {
                throw new \Exception('Failed to initiate Authorize.net payment.');
            }
        } catch (\Exception $e) {
            throw new \Exception('Authorize.net Error: ' . $e->getMessage());
        }

    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($this->config['api_login_id']);
        $merchantAuthentication->setTransactionKey($this->config['transaction_key']);

        $env = $this->config['environment'] === 'production'
            ? \net\authorize\api\constants\ANetEnvironment::PRODUCTION
            : \net\authorize\api\constants\ANetEnvironment::SANDBOX;

        // Preferred path: the Accept Hosted return carries a transId we can
        // look up directly and match against our invoice number.
        $transId = (string) $request->input('transId', '');

        if ($transId !== '') {
            $detailsRequest = new AnetAPI\GetTransactionDetailsRequest();
            $detailsRequest->setMerchantAuthentication($merchantAuthentication);
            $detailsRequest->setTransId($transId);

            $controller = new AnetController\GetTransactionDetailsController($detailsRequest);
            $response = $controller->executeWithApiResponse($env);
            $transaction = $response?->getTransaction();

            if ($transaction) {
                $invoice = (string) ($transaction->getOrder()?->getInvoiceNumber() ?? '');
                $raw = [
                    'trans_id' => $transaction->getTransId(),
                    'status' => $transaction->getTransactionStatus(),
                    'invoice' => $invoice,
                    'auth_amount' => $transaction->getAuthAmount(),
                ];

                $invoiceOk = $invoice === substr((string) $session->order_id, 0, 20);
                $amountOk = (float) $transaction->getAuthAmount() >= (float) $session->amount;
                $statusOk = in_array($transaction->getTransactionStatus(), self::PAID_STATUSES, true);

                if ($invoiceOk && $amountOk && $statusOk) {
                    return $this->verified($session, (string) $transaction->getTransId(), $raw, 'Authorize.Net payment verified.');
                }

                return $this->unverified(
                    $session,
                    "Authorize.Net reports transaction status '" . $transaction->getTransactionStatus() . "'.",
                    $raw
                );
            }
        }

        // Fallback: search unsettled transactions for our invoice number.
        $listRequest = new AnetAPI\GetUnsettledTransactionListRequest();
        $listRequest->setMerchantAuthentication($merchantAuthentication);

        $listController = new AnetController\GetUnsettledTransactionListController($listRequest);
        $listResponse = $listController->executeWithApiResponse($env);

        foreach ($listResponse?->getTransactions() ?? [] as $summary) {
            $invoiceOk = (string) $summary->getInvoiceNumber() === substr((string) $session->order_id, 0, 20);
            $amountOk = (float) $summary->getSettleAmount() >= (float) $session->amount;
            $statusOk = in_array($summary->getTransactionStatus(), self::PAID_STATUSES, true);

            if ($invoiceOk && $amountOk && $statusOk) {
                $raw = [
                    'trans_id' => $summary->getTransId(),
                    'status' => $summary->getTransactionStatus(),
                    'invoice' => $summary->getInvoiceNumber(),
                    'settle_amount' => $summary->getSettleAmount(),
                ];

                return $this->verified($session, (string) $summary->getTransId(), $raw, 'Authorize.Net payment verified.');
            }
        }

        return $this->unverified(
            $session,
            'Authorize.Net has no paid transaction matching this order.',
            [],
            'pending'
        );
    }
}
