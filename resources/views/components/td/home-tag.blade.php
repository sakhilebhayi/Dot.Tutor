{{-- A state in words with a glyph (never colour alone). tone: neutral | info | ok | warn | danger. --}}
@props(['text', 'icon' => 'circle-dashed', 'tone' => 'neutral'])
<span {{ $attributes->class(['hm-tag', 'hm-tag--'.$tone => $tone !== 'neutral']) }}><x-td.icon :name="$icon" />{{ $text }}</span>
