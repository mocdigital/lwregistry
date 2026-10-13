{{--
  International phone input with country flag + dial code (intl-tel-input).

  Params:
    $model   — the Livewire property name (e.g. 'bizPhone')
    $value   — current value (e.g. $bizPhone) for re-init after Livewire updates
    $inputId — unique DOM id for the input
    $mode    — "international" for strict E.164 entry, "local-digits" for dial code + simple digits

  The widget is wrapped in wire:ignore so Livewire won't clobber the JS-managed
  DOM. An Alpine bridge pushes the selected dial code plus typed digits back to
  the Livewire model on every change.
--}}
@php
    $mode = $mode ?? 'international';
    $placeholder = $placeholder ?? ($mode === 'local-digits' ? '08012345678' : '');
@endphp
<div wire:ignore
     x-data="intlPhone(@js($value ?? ''), @js($model), @js($mode))"
     x-init="init()"
     class="intl-phone-wrapper">
    <input type="tel"
           id="{{ $inputId }}"
           x-ref="input"
           class="form-control"
           placeholder="{{ $placeholder }}"
           autocomplete="tel">
</div>
