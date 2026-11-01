{{-- Köşeli alanlar hukuki incelemede doldurulacak yer tutuculardır; olduğu gibi, işaretlenerek gösterilir. --}}
{!! collect($segments)->map(fn (array $segment): string => $segment['pending'] ? '<mark class="legal-placeholder">'.e($segment['text']).'</mark>' : e($segment['text']))->implode('') !!}
