{{-- Taslaktaki yetki kuralı; yeni kayıt ekranında seçilen yolcuya göre travelers.js ilgili paragrafı gösterir. --}}
<section class="panel panel-trust traveler-rule" aria-label="Yetki kuralı">
    <h2 class="panel-title"><x-ui.icon name="shield" /> Kim yönetebilir?</h2>
    <p data-access-rule="adult" @if ($minor) hidden @endif>{{ $notice['traveler_access']['adult'] }}</p>
    <p data-access-rule="minor" @unless ($minor) hidden @endunless>{{ $notice['traveler_access']['minor'] }}</p>
</section>
