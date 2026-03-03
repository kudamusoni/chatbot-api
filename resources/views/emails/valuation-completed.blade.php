<p>Hello{{ $lead->name ? ' ' . e($lead->name) : '' }},</p>

<p>Your valuation request for <strong>{{ $clientName }}</strong> is now complete.</p>

@php
    $median = is_array($result) ? ($result['median'] ?? null) : null;
    $range = is_array($result) ? ($result['range'] ?? null) : null;
@endphp

@if(is_numeric($median))
    <p><strong>Median estimate:</strong> {{ (int) $median }}</p>
@endif

@if(is_array($range) && isset($range['low'], $range['high']))
    <p><strong>Range:</strong> {{ (int) $range['low'] }} - {{ (int) $range['high'] }}</p>
@endif

<p>Thank you.</p>

