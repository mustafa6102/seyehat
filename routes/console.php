<?php

use App\Http\Support\PrivacyNotices;
use App\Modules\Authorization\Contracts\AuditIdentity;
use App\Modules\Traveler\Application\ActiveContractPointer;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;
use App\Modules\Traveler\Contracts\TravelerSubjectRequestRefused;
use App\Modules\Traveler\Contracts\TravelerSubjectRequests;
use App\Modules\Traveler\Domain\Encryption\SensitiveTravelerVault;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('traveler:basic-readiness', function (AuditIdentity $audit, ActiveContractPointer $contract, PrivacyNotices $notices) {
    $required = (string) config('traveler_basic.required_contract_version');
    try {
        $schema = Schema::hasColumns('travelers', ['created_at', 'idempotency_key', 'request_fingerprint'])
            && Schema::hasTable('traveler_subject_requests');
    } catch (Throwable) {
        $schema = false;
    }
    $legal = config('traveler_basic.legal_approval_ref');
    $notice = $notices->travelerProfile();

    // Reports only whether each condition holds; no configured value is printed.
    $checks = [
        'TRAVELER_BASIC_ENABLED açık' => config('traveler_basic.enabled') === true,
        'AUDIT_PSEUDONYM_KEY geçerli (en az 32 bayt, APP_KEY değil)' => $audit->isAvailable(),
        'Legal onay referansı kayıtlı' => is_string($legal) && trim($legal) !== '',
        "ACTIVE sözleşme en az {$required}" => $contract->isAtLeast($required),
        'OIDC web girişi yapılandırılmış' => filled(config('services.oidc.issuer')) && filled(config('services.oidc.client_id')) && filled(config('services.oidc.redirect_uri')),
        'Aydınlatma metni onaylı (APPROVED, boş alan yok)' => ! $notice['is_draft'] && $notice['pending_fields'] === 0,
        'Yolcu tabloları güncel (veritabanına erişildi)' => $schema,
    ];
    foreach ($checks as $label => $passed) {
        $this->line(($passed ? '[TAMAM] ' : '[EKSİK] ').$label);
    }

    return in_array(false, $checks, true) ? 1 : 0;
})->purpose('Basic Traveler aktivasyon koşullarını değer göstermeden denetler');

Artisan::command('traveler:subject-requests {action=list : list, file, complete veya reject} {id?} {--type=} {--operator=} {--ref=}', function (TravelerSubjectRequests $requests) {
    $action = $this->argument('action');
    if ($action === 'list') {
        foreach ($requests->pending(100) as $request) {
            $this->line("{$request['id']}  {$request['type']}  {$request['created_at']}");
        }

        return 0;
    }

    $id = (string) $this->argument('id');
    $operator = trim((string) $this->option('operator'));
    $reference = trim((string) $this->option('ref'));
    if (! in_array($action, ['file', 'complete', 'reject'], true) || $id === '' || $operator === '' || $reference === ''
        || strlen($operator) > 255 || strlen($reference) > 255) {
        $this->error('Kullanım: traveler:subject-requests file|complete|reject <yolcu veya başvuru no> --operator=<kişi kaydı> --ref=<yazışma veya karar kaydı> [--type=DELETE|ACCESS]');

        return 1;
    }

    try {
        match ($action) {
            'file' => $requests->fileOnBehalf($id, (string) $this->option('type'), $reference),
            'complete' => $requests->complete($id, $operator, $reference),
            'reject' => $requests->reject($id, $operator, $reference),
        };
    } catch (TravelerSubjectRequestRefused $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
    $this->info('Başvuru işlendi.');

    return 0;
})->purpose('Basic Traveler silme ve bilgi başvurularını listeler ve yetkili kişi kararıyla işler');

Artisan::command('traveler:rewrap-data-keys {--limit=100}', function (SensitiveTravelerVault $vault) {
    try {
        $result = $vault->rewrapStale(max(1, (int) $this->option('limit')));
    } catch (TravelerStorageClosed) {
        $this->error('Anahtar sağlayıcısı yapılandırılmadığı için işlem yapılmadı.');

        return 1;
    }
    $this->info("Yenilenen anahtar: {$result['rewrapped']}, başarısız: {$result['failed']}");

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Yolcu veri anahtarlarını güncel KEK sürümüyle yeniden sarar');
