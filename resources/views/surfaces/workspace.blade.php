@extends('layouts.foundation')

@section('title', 'Client Workspace')
@section('eyebrow', 'Client operations')
@section('heading', 'Client Workspace')
@section('intro', 'The client workspace surface is reserved for authenticated hotel and hospitality operations. Identity, account context, and tenant isolation must be completed and verified before this surface can access data.')

@section('cards')
    <article class="card">
        <h2>Account context</h2>
        <p>Account membership and property scope will be resolved from the authenticated server-side identity.</p>
        <div class="status"><span class="dot" aria-hidden="true"></span> Not configured</div>
    </article>
    <article class="card">
        <h2>Tenant data</h2>
        <p>No tenant tables, guest records, finance entries, inventory, or reservations are present in this preview.</p>
        <div class="status"><span class="dot" aria-hidden="true"></span> Not available</div>
    </article>
@endsection