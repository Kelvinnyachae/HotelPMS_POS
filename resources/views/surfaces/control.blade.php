@extends('layouts.foundation')

@section('title', 'Central Control Panel')
@section('eyebrow', 'Platform operations')
@section('heading', 'Central Dawineks Control Panel')
@section('intro', 'The platform administration surface is being established. It is available only in the local development environment while identity, privileged access, and tenant isolation are implemented and verified.')

@section('cards')
    <article class="card">
        <h2>Governance baseline</h2>
        <p>G1 is closed. The first foundation scope is authorized and remains limited to platform setup and security evidence.</p>
        <div class="status"><span class="dot" aria-hidden="true"></span> Foundation work in progress</div>
    </article>
    <article class="card">
        <h2>Tenant isolation</h2>
        <p>PostgreSQL RLS, tenant context, and pooled-connection controls must pass G2 before tenant tables or client data are introduced.</p>
        <div class="status"><span class="dot" aria-hidden="true"></span> G2 evidence pending</div>
    </article>
    <article class="card">
        <h2>Privileged access</h2>
        <p>Administrator sign-in, MFA, and audit controls are not enabled in this preview.</p>
        <div class="status"><span class="dot" aria-hidden="true"></span> Not enabled</div>
    </article>
@endsection