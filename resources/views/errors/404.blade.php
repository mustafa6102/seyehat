@extends('layouts.app')

@section('title', 'Sayfa bulunamadı')

@section('content')
    <section class="card empty-state" aria-labelledby="error-title">
        <h1 id="error-title" class="page-title">Aradığınız kayıt bulunamadı</h1>
        <p>Bağlantı hatalı olabilir veya kayıt artık mevcut değil.</p>
        <a class="btn btn-primary" href="{{ route('trip-planner.index') }}">Yeni seyahat planı oluştur</a>
    </section>
@endsection
