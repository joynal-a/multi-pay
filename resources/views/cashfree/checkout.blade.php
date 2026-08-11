<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redirecting to Cashfree</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #111827;
        }

        .wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            width: 100%;
            max-width: 560px;
            background: #ffffff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 18px 60px rgba(15, 23, 42, 0.10);
            text-align: center;
        }

        .title {
            margin: 0 0 10px;
            font-size: 28px;
            font-weight: 700;
        }

        .meta {
            margin: 0 0 10px;
            color: #4b5563;
        }

        .total {
            margin: 0 0 24px;
            font-weight: 700;
            color: #1d4ed8;
        }

        button,
        a {
            display: inline-block;
            border: 0;
            border-radius: 12px;
            padding: 12px 18px;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
        }

        button {
            background: #111827;
            color: #ffffff;
        }

        a {
            margin-left: 12px;
            background: #e5e7eb;
            color: #111827;
        }
    </style>
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <h1 class="title">Redirecting to Cashfree</h1>
            <p class="meta">Order: {{ $session->order_id }}</p>
            <p class="total">{{ $session->currency }} {{ number_format((float) $session->amount, 2) }}</p>
            <button id="cashfree-pay-button" type="button">Continue to Cashfree</button>
            <a href="{{ $cancelUrl }}">Cancel</a>
        </div>
    </div>

    <script>
        const cashfree = Cashfree({
            mode: @json($mode),
        });

        function launchCashfreeCheckout() {
            cashfree.checkout({
                paymentSessionId: @json($paymentSessionId),
                redirectTarget: "_self",
            });
        }

        document.getElementById('cashfree-pay-button').addEventListener('click', launchCashfreeCheckout);
        window.addEventListener('load', launchCashfreeCheckout);
    </script>
</body>
</html>
