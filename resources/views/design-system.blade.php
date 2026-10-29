{{-- Tasarım sistemi sayfası: belirteçleri ve ortak bileşenleri tüm durumlarıyla gösterir. Seyahat içeriği sabit örnek veridir;
     hiçbir sağlayıcıya, API'ye veya veritabanına bağlanmaz. Yalnız üretim dışı ortamlarda yayınlanır (routes/web.php). --}}
@extends('layouts.app')

@section('title', 'Tasarım sistemi')
@section('main-class', 'container container-wide page')

@push('scripts')
    <script src="{{ asset('assets/trip-planner.js') }}?v={{ @filemtime(public_path('assets/trip-planner.js')) }}" defer></script>
@endpush

@php
    $colors = [
        'Marka' => ['primary' => 'Okyanus (ana renk)', 'primary-hover' => 'Okyanus, üzerinde', 'primary-subtle' => 'Okyanus, yumuşak', 'secondary' => 'Turkuaz (ikincil)', 'secondary-text' => 'Turkuaz metin', 'secondary-subtle' => 'Turkuaz, yumuşak', 'accent' => 'Mercan (vurgu)', 'accent-subtle' => 'Mercan, yumuşak', 'ink' => 'Mürekkep'],
        'Nötr' => ['background' => 'Zemin', 'surface' => 'Yüzey', 'surface-sunken' => 'Girintili yüzey', 'border' => 'Çerçeve', 'border-strong' => 'Güçlü çerçeve', 'text-primary' => 'Ana metin', 'text-secondary' => 'İkincil metin', 'text-muted' => 'Soluk metin'],
        'Anlamsal' => ['success' => 'Başarı', 'warning' => 'Uyarı', 'error' => 'Hata', 'info' => 'Bilgi', 'focus' => 'Odak'],
    ];
    $type = ['4xl' => 'Yolculuğunuzu planlayın', '3xl' => 'İstanbul → Roma', '2xl' => '4.250 TL', 'xl' => 'Uçuş teklifleri', 'lg' => 'Rota ve tarihler', 'base' => 'Gövde metni uzun planlama oturumlarında rahat okunur.', 'sm' => 'Yardımcı metin ve olgular', 'xs' => 'Dipnot ve veri kaynağı'];
    $day = [
        ['time' => '08:35', 'title' => 'İstanbul’dan kalkış', 'text' => 'IST · Aktarmasız, 2 sa 40 dk', 'travel' => true],
        ['time' => '10:15', 'title' => 'Roma’ya varış', 'text' => 'FCO · Şehir merkezine tren 32 dk', 'travel' => true],
        ['time' => '13:00', 'title' => 'Trastevere’de öğle yemeği', 'text' => 'Serbest zaman'],
        ['time' => '16:30', 'title' => 'Kolezyum çevresinde yürüyüş'],
    ];
@endphp

@section('content')
    <header class="page-header">
        <div>
            <h1 class="page-title">Tasarım sistemi</h1>
            <p class="page-lead">Tüm ekranların paylaştığı renkler, yazı, boşluk ve bileşenler. Seyahat örneklerindeki uçuş, otel ve fiyatlar sabit örnek veridir; gerçek teklif değildir.</p>
        </div>
    </header>

    <section class="section" aria-labelledby="ds-colors">
        <x-ui.section-header id="ds-colors" title="Renk" description="Ana renk marka ve birincil eylemler içindir; mercan vurgu yalnız seyahatle ilgili önemli işaretlerde, tutumlu kullanılır." />
        <div class="stack">
            @foreach ($colors as $group => $tokens)
                <div>
                    <h3 class="swatch-group">{{ $group }}</h3>
                    <ul class="swatch-grid">
                        @foreach ($tokens as $token => $label)
                            <li class="swatch">
                                <span class="swatch-color" style="background: var(--color-{{ $token }})"></span>
                                <span class="summary-strong">{{ $label }}</span>
                                <code>--color-{{ $token }}</code>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    <section class="section" aria-labelledby="ds-type">
        <x-ui.section-header id="ds-type" title="Yazı" description="Tek aile, iki optik boyut. Saat ve fiyatlarda rakamlar eş genişliklidir." />
        <dl class="type-scale">
            @foreach ($type as $step => $sample)
                <div>
                    <dt><code>--text-{{ $step }}</code></dt>
                    <dd style="font-size: var(--text-{{ $step }}); @if (in_array($step, ['4xl', '3xl', '2xl', 'xl', 'lg'], true)) font-family: var(--font-display); font-weight: var(--weight-bold); letter-spacing: var(--tracking-tight); line-height: var(--leading-tight); @endif">{{ $sample }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="section" aria-labelledby="ds-buttons">
        <x-ui.section-header id="ds-buttons" title="Düğmeler" description="Her ekranda tek bir birincil eylem bulunur." />
        <div class="stack">
            <div class="cluster">
                <x-ui.button>Uçuşları ara</x-ui.button>
                <x-ui.button variant="secondary">Talep özetine dön</x-ui.button>
                <x-ui.button variant="ghost">Vazgeç</x-ui.button>
                <x-ui.button variant="danger">Talebi sil</x-ui.button>
            </div>
            <div class="cluster">
                <x-ui.button icon="search">Simgeli</x-ui.button>
                <x-ui.button :loading="true">Aranıyor…</x-ui.button>
                <x-ui.button disabled>Devre dışı</x-ui.button>
                <x-ui.button variant="secondary" disabled>Devre dışı</x-ui.button>
                <x-ui.button size="sm" variant="secondary">Küçük</x-ui.button>
                <x-ui.button size="lg">Büyük</x-ui.button>
            </div>
            <div class="cluster">
                <x-ui.icon-button icon="filter" label="Filtreler" :outline="true" />
                <x-ui.icon-button icon="star" label="Favorilere ekle" aria-pressed="true" />
                <x-ui.icon-button icon="close" label="Kapat" />
                <x-ui.icon-button icon="close" label="Kapat (devre dışı)" disabled />
                <x-ui.tooltip id="tip-baggage" text="Kabin bagajı tüm tekliflere dahildir.">
                    <x-ui.icon-button icon="info" label="Bagaj bilgisi" aria-describedby="tip-baggage" />
                </x-ui.tooltip>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="ds-forms">
        <x-ui.section-header id="ds-forms" title="Form" description="Etiket her zaman görünür; hata, alanın hemen altında ve ne yapılacağını söyleyerek yazılır." />
        <form class="grid-auto" action="#" onsubmit="return false" novalidate>
            <x-ui.input label="Ad" name="ds_name" placeholder="Kimlikteki gibi" hint="Biletle aynı olmalı." />
            <x-ui.input label="E-posta" name="ds_email" type="email" value="ornek@" error="E-posta adresini ad@alan.com biçiminde yazın." />
            <x-ui.input label="Sadakat numarası" name="ds_loyalty" :optional="true" disabled value="Devre dışı" />
            <x-ui.search-input label="Varış noktası ara" name="ds_search" placeholder="Şehir veya havalimanı" />
            <x-ui.select label="Kabin" name="ds_cabin" :options="['economy' => 'Ekonomi', 'business' => 'Business']" selected="economy" />
            <x-ui.date-field label="Gidiş tarihi" name="ds_date" value="2026-11-14" />
        </form>
        <div class="grid-auto ds-gap">
            <fieldset>
                <legend class="field-label">Aktarma</legend>
                <x-ui.checkbox label="Aktarmasız" name="ds_direct" :checked="true" />
                <x-ui.checkbox label="1 aktarma" name="ds_one_stop" hint="En fazla 3 saat bekleme" />
                <x-ui.checkbox label="2 ve üzeri" name="ds_two_stop" disabled />
            </fieldset>
            <fieldset>
                <legend class="field-label">Kalkış saati</legend>
                <x-ui.radio label="Sabah" name="ds_time" value="morning" :checked="true" />
                <x-ui.radio label="Öğleden sonra" name="ds_time" value="afternoon" />
                <x-ui.radio label="Gece" name="ds_time" value="night" disabled />
            </fieldset>
            <div>
                <x-ui.toggle label="Yalnız iade edilebilir" name="ds_refundable" :checked="true" />
                <x-ui.toggle label="Yakın havalimanlarını dahil et" name="ds_nearby" />
                <x-ui.toggle label="Fiyat alarmı (yakında)" name="ds_alert" disabled />
            </div>
        </div>
        <x-ui.passenger-selector :counts="['adults' => 2, 'children' => 1, 'infants' => 0]" :traveler-count="3" />
    </section>

    <section class="section" aria-labelledby="ds-feedback">
        <x-ui.section-header id="ds-feedback" title="Geri bildirim" description="Anlam yalnız renkle değil, simge ve metinle de verilir." />
        <div class="stack">
            <div class="cluster">
                <x-ui.badge>Taslak</x-ui.badge>
                <x-ui.badge variant="success">Aramaya hazır</x-ui.badge>
                <x-ui.badge variant="warning">Fiyat değişti</x-ui.badge>
                <x-ui.badge variant="error">Süresi doldu</x-ui.badge>
                <x-ui.badge variant="info">Yeni</x-ui.badge>
                <x-ui.badge variant="accent">En uygun</x-ui.badge>
            </div>
            <div>
                <h3 class="swatch-group">Bilgi güvenilirliği: renk, işaret ve çerçeve birlikte</h3>
                <div class="cluster">
                    <x-ui.badge variant="success">Doğrulandı</x-ui.badge>
                    <x-ui.badge variant="estimated">Tahmini</x-ui.badge>
                    <x-ui.badge variant="warning">Güncelliğini yitirmiş</x-ui.badge>
                    <x-ui.badge variant="unavailable">Kullanılamıyor</x-ui.badge>
                    <x-ui.badge variant="unverified">Doğrulanmamış</x-ui.badge>
                </div>
            </div>
            <div class="cluster">
                <x-ui.chip :pressed="true">Aktarmasız</x-ui.chip>
                <x-ui.chip :pressed="false">Bagaj dahil</x-ui.chip>
                <x-ui.chip :pressed="false" disabled>Sabah kalkış</x-ui.chip>
                <x-ui.chip icon="users">3 yolcu</x-ui.chip>
            </div>
            <div>
                <x-ui.alert title="Arama sürüyor">Sonuçlar geldikçe liste güncellenir.</x-ui.alert>
                <x-ui.alert variant="success" title="Fiyat doğrulandı">Teklif, sağlayıcıdaki güncel fiyatla aynı.</x-ui.alert>
                <x-ui.alert variant="warning" title="Fiyat değişti">Devam etmeden önce yeni fiyatı gözden geçirin.</x-ui.alert>
                <x-ui.alert variant="danger" title="Teklifin süresi doldu">Güncel teklifler için aramayı yeniden başlatın.</x-ui.alert>
            </div>
            <div class="grid-auto">
                <x-ui.empty-state title="Bu tarihlerde uçuş bulunamadı" :level="3">
                    Tarihleri bir iki gün kaydırmak veya yakın bir havalimanı seçmek sonuç verebilir.
                    <x-slot:actions><x-ui.button variant="secondary">Tarihleri değiştir</x-ui.button></x-slot:actions>
                </x-ui.empty-state>
                <x-ui.error-state title="Teklifler yüklenemedi" :level="3">
                    Bağlantı kesildi. İnternet bağlantınızı kontrol edip yeniden deneyin.
                    <x-slot:actions><x-ui.button>Yeniden dene</x-ui.button></x-slot:actions>
                </x-ui.error-state>
                <x-ui.loading-state title="Uçuşlar aranıyor…">Bu işlem bir dakikaya kadar sürebilir.</x-ui.loading-state>
            </div>
            <div class="grid-auto" aria-busy="true">
                <x-ui.card as="div">
                    <x-ui.skeleton variant="title" />
                    <x-ui.skeleton :lines="3" />
                </x-ui.card>
                <x-ui.card as="div">
                    <x-ui.skeleton variant="media" />
                    <x-ui.skeleton :lines="2" />
                </x-ui.card>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="ds-layers">
        <x-ui.section-header id="ds-layers" title="Sekme ve katmanlar" description="Açılan her katman Escape ile kapanır ve odağı açan öğeye döndürür." />
        <x-ui.tabs label="Sonuç türü" :tabs="['flights' => 'Uçuşlar', 'hotels' => 'Oteller', 'plan' => 'Gün planı', 'cars' => 'Araç']" :disabled="['cars']">
            <x-ui.tab-panel id="flights">Uçuş sonuçları bu panelde listelenir.</x-ui.tab-panel>
            <x-ui.tab-panel id="hotels">Otel sonuçları bu panelde listelenir.</x-ui.tab-panel>
            <x-ui.tab-panel id="plan">Gün gün plan bu panelde gösterilir.</x-ui.tab-panel>
        </x-ui.tabs>
        <div class="cluster">
            <x-ui.dropdown label="Paylaş" id="ds-share-menu">
                <button type="button" class="menu-item" role="menuitem" data-menu-item>Bağlantıyı kopyala</button>
                <button type="button" class="menu-item" role="menuitem" data-menu-item>E-postayla gönder</button>
                <button type="button" class="menu-item" role="menuitem" data-menu-item disabled>PDF indir (yakında)</button>
            </x-ui.dropdown>
            <x-ui.button variant="secondary" data-dialog-open="ds-modal">Pencereyi aç</x-ui.button>
            <x-ui.button variant="secondary" icon="filter" data-dialog-open="ds-drawer">Filtreleri aç</x-ui.button>
        </div>

        <x-ui.modal id="ds-modal" title="Aramayı yeniden başlat">
            <p>Mevcut teklif listesi temizlenir ve aynı talep için yeni bir arama başlatılır.</p>
            <x-slot:footer>
                <x-ui.button variant="ghost" data-dialog-close>Vazgeç</x-ui.button>
                <x-ui.button data-dialog-close>Yeniden başlat</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.drawer id="ds-drawer" title="Filtreler">
            <fieldset class="filter-group">
                <legend>Aktarma</legend>
                <x-ui.checkbox label="Aktarmasız" name="ds_f_direct" :checked="true" />
                <x-ui.checkbox label="1 aktarma" name="ds_f_one" />
            </fieldset>
            <fieldset class="filter-group">
                <legend>Bagaj</legend>
                <x-ui.toggle label="Kayıtlı bagaj dahil" name="ds_f_bag" />
            </fieldset>
            <x-slot:footer>
                <x-ui.button variant="ghost" data-dialog-close>Temizle</x-ui.button>
                <x-ui.button data-dialog-close>Sonuçları göster</x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    </section>

    <section class="section" aria-labelledby="ds-travel">
        <x-ui.section-header id="ds-travel" title="Seyahat desenleri" description="Rota çizgisi her yerde aynı biçimde çizilir: kalkış, yol, varış. Aşağıdaki içerik örnek veridir." />

        <div class="stack">
            <div class="trip-search" role="group" aria-label="Arama özeti (örnek)">
                <p class="route-heading"><span class="route-code">IST</span><span aria-hidden="true">→</span><span class="visually-hidden">ile</span><span class="route-code">FCO</span></p>
                <ul class="facts">
                    <li class="fact"><x-ui.icon name="calendar" size="sm" />14 Kas – 18 Kas 2026</li>
                    <li class="fact"><x-ui.icon name="users" size="sm" />2 yetişkin, 1 çocuk</li>
                    <li class="fact"><x-ui.icon name="plane" size="sm" />Gidiş-dönüş</li>
                </ul>
                <x-ui.button variant="secondary" size="sm">Aramayı düzenle</x-ui.button>
            </div>

            <div class="toolbar">
                <div class="filter-chips" role="group" aria-label="Hızlı filtreler">
                    <x-ui.chip :pressed="true">Aktarmasız</x-ui.chip>
                    <x-ui.chip :pressed="false">Bagaj dahil</x-ui.chip>
                    <x-ui.chip :pressed="false">Sabah kalkış</x-ui.chip>
                    <x-ui.chip :pressed="false">İade edilebilir</x-ui.chip>
                </div>
                <label class="sort-control">
                    <span>Sırala</span>
                    <select name="ds_sort">
                        <option>En düşük fiyat</option>
                        <option>En kısa süre</option>
                        <option>En erken kalkış</option>
                    </select>
                </label>
            </div>

            <ol class="offer-list">
                <li>
                    <x-travel.flight-card carrier="Örnek Havayolu" date="14 Kas 2026 Cumartesi" :from="['time' => '08:35', 'place' => 'IST · İstanbul']" :to="['time' => '10:15', 'place' => 'FCO · Roma']" duration="2 sa 40 dk" price="4.250 TL" price-note="Kişi başı, vergiler dahil" highlight="En uygun" :selected="true" :facts="['luggage' => 'Kabin bagajı + 20 kg', 'clock' => 'Zamanında kalkış oranı yüksek']">
                        <x-ui.button variant="secondary">Seçimi değiştir</x-ui.button>
                    </x-travel.flight-card>
                </li>
                <li>
                    <x-travel.flight-card carrier="Örnek Havayolu" date="14 Kas 2026 Cumartesi" :from="['time' => '13:05', 'place' => 'SAW · İstanbul']" :to="['time' => '18:20', 'place' => 'FCO · Roma']" duration="6 sa 15 dk" :stops="1" price="3.180 TL" price-note="Yalnız temel ücret; vergiler doğrulanamadı" price-state="partial" :facts="['luggage' => 'Yalnız kabin bagajı']">
                        <x-ui.button>Bu uçuşu seç</x-ui.button>
                    </x-travel.flight-card>
                </li>
                <li>
                    <x-travel.flight-card carrier="Örnek Havayolu" date="14 Kas 2026 Cumartesi" :from="['time' => '21:40', 'place' => 'IST · İstanbul']" :to="['time' => '07:55', 'place' => 'CIA · Roma']" duration="11 sa 15 dk" :stops="2" price-state="unknown" price-note="Sağlayıcı fiyatı doğrulamadı; seçim yapılamaz." :facts="['luggage' => 'Bagaj bilgisi yok']">
                        <x-ui.button disabled>Seçilemez</x-ui.button>
                    </x-travel.flight-card>
                </li>
            </ol>

            <div class="stack">
                <x-travel.hotel-card name="Örnek Otel Trastevere" area="Trastevere, merkeze 1,2 km" rating="4,6 / 5" price="2.900 TL" price-note="Gecelik, 2 yetişkin" status="available" status-text="Müsait" :facts="['bed' => 'Çift kişilik oda', 'calendar' => '4 gece']">
                    <x-ui.button>Bu oteli seç</x-ui.button>
                </x-travel.hotel-card>
                <x-travel.hotel-card name="Örnek Pansiyon Monti" area="Monti, merkeze 600 m" rating="4,2 / 5" price="2.150 TL" price-note="Gecelik, 2 yetişkin" status="limited" status-text="Son 2 oda" :facts="['bed' => 'İki tek yataklı oda', 'calendar' => '4 gece']">
                    <x-ui.button variant="secondary">Bu oteli seç</x-ui.button>
                </x-travel.hotel-card>
            </div>

            <div class="grid-auto ds-days">
                <x-travel.day-card title="1. gün · Varış" date="14 Kas Cumartesi" :items="$day" />
                <x-travel.day-card title="2. gün · Antik Roma" date="15 Kas Pazar" :items="[['time' => '09:30', 'title' => 'Roma Forumu'], ['time' => '14:00', 'title' => 'Palatino Tepesi', 'text' => 'Bilet gerekir'], ['time' => '19:30', 'title' => 'Akşam yemeği']]" />
            </div>

            <x-travel.media alt="Roma" caption="Roma, İtalya" :wide="true" />
        </div>
    </section>
@endsection
