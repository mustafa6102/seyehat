(() => {
    const form = document.querySelector('[data-flight-opportunity]');
    if (!form) return;

    const amount = form.elements.namedItem('threshold_amount');
    const currency = form.elements.namedItem('currency');
    const result = form.querySelector('[data-opportunity-result]');
    const button = form.querySelector('[type="submit"]');
    button.disabled = false;
    let generation = 0;
    let pending = null;

    const invalidate = () => {
        generation += 1;
        pending?.abort();
        pending = null;
        result.textContent = '';
        button.disabled = false;
    };
    amount.addEventListener('input', invalidate);
    currency.addEventListener('input', invalidate);

    const formatMoney = (money) => {
        const formatter = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: money.currency });
        return formatter.format(money.minor_units / 10 ** formatter.resolvedOptions().maximumFractionDigits);
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        invalidate();
        const current = generation;
        const code = currency.value.trim().toUpperCase();
        const match = /^(0|[1-9]\d*)(?:[.,](\d+))?$/.exec(amount.value.trim());
        if (!match || !/^[A-Z]{3}$/.test(code)) {
            result.textContent = 'Tutarı ve üç harfli para birimini kontrol edin.';
            return;
        }
        let digits;
        try {
            digits = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: code }).resolvedOptions().maximumFractionDigits;
        } catch {
            result.textContent = 'Para birimini kontrol edin.';
            return;
        }
        const fraction = match[2] ?? '';
        if (fraction.length > digits) {
            result.textContent = `Bu para birimi için en fazla ${digits} ondalık basamak girin.`;
            return;
        }
        // Decimal string conversion preserves every minor unit; no floating-point rounding enters the request.
        const minorUnits = (match[1] + fraction.padEnd(digits, '0')).replace(/^0+(?=\d)/, '');
        if (BigInt(minorUnits) > BigInt(Number.MAX_SAFE_INTEGER)) {
            result.textContent = 'Bu tutar ekranda hassas biçimde gösterilemeyecek kadar büyük.';
            return;
        }
        const url = new URL(form.action, window.location.href);
        url.search = new URLSearchParams({ max_minor_units: minorUnits, currency: code }).toString();
        pending = new AbortController();
        button.disabled = true;
        result.textContent = 'Seçili uçuşun doğrulanmış fiyatı karşılaştırılıyor…';
        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: pending.signal });
            const body = await response.json();
            if (generation !== current) return;
            if (!response.ok) {
                result.textContent = ({ 400: 'Tutarı ve para birimini kontrol edin.', 401: 'Oturumunuz sona ermiş. Yeniden giriş yapın.',
                    403: 'Bu uçuş seçimini yalnız seyahat sahibi karşılaştırabilir.', 404: 'Uçuş seçimi bulunamadı. Önce bir uçuş seçin.' })[response.status]
                    ?? 'Karşılaştırma şu anda yapılamadı. Daha sonra yeniden deneyin.';
                return;
            }
            if (body.status === 'NOT_VERIFIED') {
                result.textContent = 'Seçimin güncel toplam fiyatı doğrulanamadı. Uçuş seçimini gözden geçirip yeniden doğrulayın.';
            } else if (body.status === 'CURRENCY_MISMATCH') {
                result.textContent = 'Eşik ile uçuş fiyatının para birimleri farklı. Kur dönüşümü yapılmadı.';
            } else if (['MATCH', 'NO_MATCH'].includes(body.status) && body.observed_price && body.threshold
                && Number.isSafeInteger(body.observed_price.minor_units) && Number.isSafeInteger(body.threshold.minor_units)) {
                const verdict = body.status === 'MATCH' ? 'Belirlediğiniz üst sınır içinde.' : 'Belirlediğiniz üst sınırı aşıyor.';
                result.textContent = `${verdict} Doğrulanmış fiyat: ${formatMoney(body.observed_price)}. Üst sınır: ${formatMoney(body.threshold)}.`;
            } else {
                result.textContent = 'Karşılaştırma sonucu güvenli biçimde gösterilemedi.';
            }
        } catch (error) {
            if (generation === current && error.name !== 'AbortError') {
                result.textContent = 'Karşılaştırma yapılamadı. Bağlantınızı kontrol edip yeniden deneyin.';
            }
        } finally {
            if (generation === current) {
                pending = null;
                button.disabled = false;
            }
        }
    });
})();
