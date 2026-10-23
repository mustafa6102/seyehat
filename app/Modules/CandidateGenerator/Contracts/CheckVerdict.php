<?php

namespace App\Modules\CandidateGenerator\Contracts;

/** UNKNOWN means the evidence is unavailable; it is never read as PASS or FAIL. */
enum CheckVerdict: string
{
    case PASS = 'PASS';
    case FAIL = 'FAIL';
    case UNKNOWN = 'UNKNOWN';
}
