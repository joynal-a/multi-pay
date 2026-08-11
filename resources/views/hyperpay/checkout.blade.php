<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HyperPay Checkout</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #111827;
        }

        .wrapper {
            max-width: 640px;
            margin: 40px auto;
            padding: 24px;
        }

        .card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 18px 60px rgba(15, 23, 42, 0.10);
        }

        .heading {
            margin: 0 0 8px;
            font-size: 28px;
            font-weight: 700;
        }

        .meta {
            margin: 0 0 24px;
            color: #4b5563;
        }

        .total {
            display: inline-block;
            margin-bottom: 24px;
            padding: 10px 14px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 700;
        }

        .cancel {
            display: inline-block;
            margin-top: 16px;
            color: #4b5563;
        }
    </style>
    <script src="{{ $scriptUrl }}"></script>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <h1 class="heading">Complete your payment</h1>
            <p class="meta">Order: {{ $session->order_id }}</p>
            <div class="total">{{ $session->currency }} {{ number_format((float) $session->amount, 2) }}</div>

            <form action="{{ $shopperResultUrl }}" class="paymentWidgets" data-brands="{{ $brands }}"></form>

            <a class="cancel" href="{{ $cancelUrl }}">Cancel and go back</a>
        </div>
    </div>
</body>
</html>
