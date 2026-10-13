@props([
    'title' => 'Please review the highlighted fields',
])

@if($errors->any())
    <div class="ui-form-errors" role="alert" tabindex="-1" data-ui-error-summary>
        <div class="ui-form-errors__title">
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
            {{ $title }}
        </div>
        <ul class="ui-form-errors__list">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
