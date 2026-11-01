<?php

namespace App\Http\Controllers;

use App\Http\Support\PrivacyNotices;
use Illuminate\Contracts\View\View;

/** Public, separate presentation of the notice and of the optional consent; neither writes a record (no contract for one). */
final class PrivacyNoticeController extends Controller
{
    public function __construct(private readonly PrivacyNotices $notices) {}

    public function notice(): View
    {
        return view('privacy.notice', ['notice' => $this->notices->travelerProfile()]);
    }

    public function consent(): View
    {
        return view('privacy.consent', ['notice' => $this->notices->travelerProfile()]);
    }
}
