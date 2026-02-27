<p>Hello,</p>

<p>A new lead request was submitted for <strong>{{ $clientName }}</strong>.</p>

<ul>
    <li><strong>Name:</strong> {{ $lead->name ?: 'N/A' }}</li>
    <li><strong>Email:</strong> {{ $lead->email ?: 'N/A' }}</li>
    <li><strong>Phone:</strong> {{ $lead->phone_normalized ?: 'N/A' }}</li>
    <li><strong>Status:</strong> {{ $lead->status }}</li>
</ul>

<p>View in dashboard: <a href="{{ $dashboardUrl }}">{{ $dashboardUrl }}</a></p>

