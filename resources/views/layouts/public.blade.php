{{-- Public shell: <x-layouts::public title="..." description="..."> page </x-layouts::public>.
     For the landing, policies and any page a signed-out visitor reads. credits: picture credits for the footer. --}}
@props(['title' => null, 'description' => null, 'credits' => []])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => $title, 'description' => $description, 'livewire' => false])
</head>
<body class="td-public">
    <a class="td-skip" href="#main-content">Skip to content</a>
    @include('layouts.partials.public-top')
    <main id="main-content" tabindex="-1">
        {{ $slot }}
    </main>
    @include('layouts.partials.public-foot', ['credits' => $credits])
    <x-td.toasts />
</body>
</html>
