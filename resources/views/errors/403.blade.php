@extends('layouts.app')

@section('title', 'Erişim izni yok')

@section('content')
    <section class="card empty-state" aria-labelledby="error-title">
        <h1 id="error-title" class="page-title">Bu sayfaya erişim izniniz yok</h1>
        <p>Bu bilgiler yalnız seyahat sahibine veya yetkili kişiye gösterilir.</p>
        <a class="btn btn-primary" href="{{ route('trip-requests.mine') }}">Seyahatlerime dön</a>
    </section>
@endsection
