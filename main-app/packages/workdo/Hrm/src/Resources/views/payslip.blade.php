<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Payslip') }} {{ $payslip->payroll->month }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #ddd; text-align: left; }
        td.amount, th.amount { text-align: right; }
        .total td { font-weight: bold; border-top: 2px solid #111; border-bottom: none; }
        .net { margin-top: 18px; padding: 10px; background: #f1f5f9; font-size: 15px; font-weight: bold; }
        .cols { width: 100%; }
        .cols > tbody > tr > td { vertical-align: top; width: 50%; border: none; padding: 0 8px 0 0; }
    </style>
</head>
<body>
    <table class="cols">
        <tr>
            <td>
                <h1>{{ $company }}</h1>
                <div class="muted">{{ __('Payslip for') }} {{ $payslip->payroll->month }}</div>
            </td>
            <td style="text-align:right">
                <strong>{{ $payslip->employee->user->name }}</strong> ({{ $payslip->employee->employee_code }})<br>
                <span class="muted">{{ collect([$payslip->employee->designation?->name, $payslip->employee->department?->name])->filter()->implode(' · ') }}</span><br>
                <span class="muted">{{ __('Working days') }}: {{ $payslip->working_days }} · {{ __('Unpaid days') }}: {{ rtrim(rtrim(number_format($payslip->unpaid_days, 1), '0'), '.') ?: 0 }}</span>
            </td>
        </tr>
    </table>

    <br>
    <table class="cols">
        <tr>
            <td>
                <table>
                    <tr><th>{{ __('Earnings') }}</th><th class="amount"></th></tr>
                    @foreach ($earnings as $line)
                        <tr><td>{{ $line->label }}</td><td class="amount">{{ $money($line->amount) }}</td></tr>
                    @endforeach
                    <tr class="total"><td>{{ __('Total') }}</td><td class="amount">{{ $money($payslip->earnings) }}</td></tr>
                </table>
            </td>
            <td>
                <table>
                    <tr><th>{{ __('Deductions') }}</th><th class="amount"></th></tr>
                    @forelse ($deductions as $line)
                        <tr><td>{{ $line->label }}</td><td class="amount">{{ $money($line->amount) }}</td></tr>
                    @empty
                        <tr><td class="muted">—</td><td></td></tr>
                    @endforelse
                    <tr class="total"><td>{{ __('Total') }}</td><td class="amount">{{ $money($payslip->deductions) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="net">{{ __('Net Pay') }}: {{ $money($payslip->net_pay) }}</div>
    @if ($payslip->payroll->paid_on)
        <p class="muted">{{ __('Paid on') }} {{ $payslip->payroll->paid_on->toDateString() }}</p>
    @endif
</body>
</html>
