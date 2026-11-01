@extends('layouts.app')

@section('title', 'Giriş gerekli')

@section('content')
    <section class="card empty-state" aria-labelledby="error-title">
        <h1 id="error-title" class="page-title">Bu sayfa için giriş yapmanız gerekiyor</h1>
        <p>Bu seyahate ait bilgiler yalnız hesabıyla giriş yapan seyahat sahibine gösterilir.</p>
        <a class="btn btn-primary" href="{{ route('auth.login', ['donus' => '/'.ltrim(request()->path(), '/')]) }}">Giriş yap</a>
    </section>
@endsection
