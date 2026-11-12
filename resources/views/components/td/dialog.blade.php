{{-- Native modal dialog; a bottom sheet on phones when sheet=true. Open it with any element carrying
     data-td-dialog-open="{{ id }}" (resources/js/ds/dialog.js). The browser traps focus, closes on Escape and
     returns focus to the opener. Use only for a task that needs protected focus; prefer inline disclosure. --}}
@props(['id', 'title', 'sheet' => false, 'dismissible' => true])
<dialog id="{{ $id }}" {{ $attributes->class(['td-dialog', 'td-dialog--sheet' => $sheet]) }} aria-labelledby="{{ $id }}-title" data-td-dismissible="{{ $dismissible ? 'true' : 'false' }}">
    <div class="td-dialog__head">
        <h2 class="td-dialog__title" id="{{ $id }}-title">{{ $title }}</h2>
        @if ($dismissible)
            <x-td.button variant="quiet" icon="x" icon-only data-td-dialog-close value="cancel">Close</x-td.button>
        @endif
    </div>
    <div class="td-dialog__body">{{ $slot }}</div>
    @isset($footer)
        <div class="td-dialog__foot">{{ $footer }}</div>
    @endisset
</dialog>
