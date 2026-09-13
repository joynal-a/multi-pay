<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pay with {{ $gatewayLabel }}</title>
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

        .code {
            margin: 0 0 16px;
            font-size: 34px;
            font-weight: 700;
            letter-spacing: 4px;
        }

        .qr {
            width: 200px;
            height: 200px;
            margin: 0 auto 16px;
            display: block;
        }

        .status {
            margin: 0 0 24px;
            color: #4b5563;
        }

        a {
            display: inline-block;
            border-radius: 12px;
            padding: 12px 18px;
            font-size: 15px;
            text-decoration: none;
            background: #e5e7eb;
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <h1 class="title">Pay with {{ $gatewayLabel }}</h1>
            <p class="meta">Order: {{ $session->order_id }}</p>
            <p class="total">{{ $session->currency }} {{ number_format((float) $session->amount, 2) }}</p>

            @if ($gateway === 'ecocash')
                <p class="meta">
                    A payment prompt was sent to <strong>{{ $msisdn }}</strong>.<br>
                    Enter your EcoCash PIN on your phone to approve it.
                </p>
            @else
                <p class="meta">In the InnBucks app, choose <strong>Pay by Code</strong> and enter:</p>
                <p class="code">{{ $code }}</p>
                @if ($qrCode)
                    <img class="qr" src="data:image/png;base64,{{ $qrCode }}" alt="InnBucks QR code">
                @endif
            @endif

            <p class="status" id="multipay-status">Waiting for your approval…</p>
            <a href="{{ $cancelUrl }}">Cancel</a>
        </div>
    </div>

    <script>
        (function () {
            var statusEl = document.getElementById('multipay-status');
            var startedAt = Date.now();
            var timeoutMs = 10 * 60 * 1000;

            function poll() {
                if (Date.now() - startedAt > timeoutMs) {
                    statusEl.textContent = 'Still no confirmation. If you approved the payment, refresh this page.';
                    return;
                }

                fetch(@json($statusUrl), { headers: { 'Accept': 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.status === 'paid') {
                            window.location.href = @json($successUrl);
                        } else if (data.status === 'cancelled') {
                            window.location.href = @json($cancelUrl);
                        } else if (data.status === 'failed') {
                            window.location.href = @json($failureUrl);
                        } else {
                            setTimeout(poll, 5000);
                        }
                    })
                    .catch(function () { setTimeout(poll, 5000); });
            }

            setTimeout(poll, 5000);
        })();
    </script>
</body>
</html>
