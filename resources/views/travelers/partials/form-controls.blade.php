{{-- Kaydetme işlemi olmadığı için form sunucuya gönderilemez: girdilerin name niteliği yoktur, düğmeler type="button"dır. --}}
<label class="check">
    <input type="checkbox" autocomplete="off" data-traveler-preview @disabled($minor)>
    <span>Alanları kayıt yapmadan dene<span class="check-hint">Girdiğiniz değerler yalnız bu ekranda kalır, gönderilmez ve sayfadan çıkınca silinir. Gerçek belge bilgisi girmeyin.</span></span>
</label>
<p class="field-hint" data-preview-locked @unless ($minor) hidden @endunless>Çocuk ve bebek yolcular için alanlar veli/vasi doğrulanana kadar kapalıdır.</p>
