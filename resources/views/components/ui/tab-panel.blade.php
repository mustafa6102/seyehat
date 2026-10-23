@props(['id'])
<div class="tab-panel" role="tabpanel" id="panel-{{ $id }}" aria-labelledby="tab-{{ $id }}" tabindex="0" {{ $attributes }}>{{ $slot }}</div>
