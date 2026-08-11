<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Braintree Checkout</title>
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

        .error {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #fef2f2;
            color: #b91c1c;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        button,
        .link {
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

        .link {
            background: #e5e7eb;
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <h1 class="heading">Complete your payment</h1>
            <p class="meta">Order: {{ $session->order_id }}</p>
            <div class="total">{{ $session->currency }} {{ number_format((float) $session->amount, 2) }}</div>

            @if (session('multipay_error'))
                <div class="error">{{ session('multipay_error') }}</div>
            @endif

            <form id="payment-form" action="{{ $postUrl }}" method="post">
                @csrf
                <div id="dropin-container"></div>
                <input type="hidden" id="payment-method-nonce" name="payment_method_nonce">
                <div class="actions">
                    <button type="submit">Pay Now</button>
                    <a class="link" href="{{ $cancelUrl }}">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://js.braintreegateway.com/web/dropin/1.44.1/js/dropin.min.js"></script>
    <script>
        let dropinInstance;
        const form = document.getElementById('payment-form');

        braintree.dropin.create({
            authorization: @json($clientToken),
            container: '#dropin-container'
        }, function (createErr, instance) {
            if (createErr) {
                console.error(createErr);
                return;
            }

            dropinInstance = instance;
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!dropinInstance) {
                return;
            }

            dropinInstance.requestPaymentMethod(function (requestErr, payload) {
                if (requestErr) {
                    console.error(requestErr);
                    return;
                }

                document.getElementById('payment-method-nonce').value = payload.nonce;
                form.submit();
            });
        });
    </script>
</body>
</html>
