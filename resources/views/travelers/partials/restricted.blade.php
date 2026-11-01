@if (! empty($basicActive))
    <x-ui.alert variant="info" title="Yalnız yetişkin yolcu kaydı açık">
        <p>Çocuk ve bebek yolcular ile vatandaşlık, pasaport, vize ve oturum izni bilgileri kapalıdır.</p>
    </x-ui.alert>
@else
<x-ui.alert variant="info" title="Yolcu bilgileri şu anda kaydedilmiyor">
    <p>Hukuki ve güvenlik onayları tamamlanana kadar yolcu ve belge bilgisi kaydetme, görüntüleme ve yurt dışına aktarma işlemleri kapalıdır. Bu ekranlar hiçbir yolcu bilgisini sunucuya göndermez.</p>
    <p><a href="{{ route('privacy.traveler-notice') }}">Aydınlatma metni (taslak)</a> · <a href="{{ route('privacy.traveler-consent') }}">Açık rıza (taslak)</a></p>
</x-ui.alert>
@endif
