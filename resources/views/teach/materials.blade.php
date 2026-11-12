@php
    $m = fn (int $minor, ?string $c = null) => \App\Domain\Teach\Support\TutorContext::money($minor, $c);
    $statusWords = ['published' => 'Published', 'pending_review' => 'Waiting for a source check', 'taken_down' => 'Taken down', 'withdrawn' => 'Withdrawn'];
    $languageOptions = collect($languages)->map(fn ($l) => ['value' => $l['code'], 'label' => $l['endonym'] === $l['name'] ? $l['name'] : $l['endonym'].' ('.$l['name'].')'])->all();
@endphp
<x-layouts::workspace title="Materials" step="dense">
    <x-td.page-head title="Materials" lede="Notes, worksheets, revision guides and past-paper practice in the study library, free or for sale, with the licence that says what others may do with them." />
    @include('teach.partials.materials-tabs')

    <div class="td-panels td-panels--2">
        <x-td.panel title="Your uploads" flush>
            @if ($resources->isEmpty())
                <div class="p-5"><x-td.state kind="empty" title="Nothing uploaded yet">Your own work can be free or sold; someone else's work only under an open licence or from an official source, never for sale. Past exam papers need the publisher's link and a reviewer's check.</x-td.state></div>
            @else
                <x-td.table caption="Your library uploads" caption-hidden dense stack>
                    <thead><tr><th scope="col">Title</th><th scope="col">Licence</th><th scope="col">Quality</th><th scope="col">State</th><th scope="col" class="is-num" data-stack="key">Price</th><th scope="col" class="is-num">Sales</th></tr></thead>
                    <tbody>
                        @foreach ($resources as $r)
                            @php $sale = $sales->get($r->id); @endphp
                            <tr>
                                <th scope="row"><a class="td-link" href="{{ route('learning.library.show', $r) }}">{{ $r->title }}</a><span class="block text-sm font-normal text-td-ink-2">{{ \App\Domain\Learning\Enums\ResourceType::tryFrom((string) ($r->type?->value ?? $r->type))?->label() }}</span></th>
                                <td>{{ \App\Domain\Learning\Enums\ResourceLicence::tryFrom((string) ($r->licence?->value ?? $r->licence))?->label() }}</td>
                                <td>{{ \App\Domain\Learning\Enums\QualityTier::tryFrom((string) ($r->quality_tier?->value ?? $r->quality_tier))?->label() ?? '—' }}</td>
                                <td>{{ $statusWords[$r->status?->value ?? $r->status] ?? ($r->status?->value ?? $r->status) }}</td>
                                <td class="is-num">{{ $r->is_free ? 'Free' : $m((int) $r->price_minor, $r->currency) }}</td>
                                <td class="is-num">{{ (int) ($sale->n ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>

        <x-td.panel title="Upload to the library">
            <form method="POST" action="{{ route('teach.materials.store') }}" enctype="multipart/form-data" class="grid gap-4" data-teach-upload>
                @csrf
                <x-td.field label="Title" for="title"><x-td.input name="title" maxlength="160" required /></x-td.field>
                <x-td.field label="Kind" for="type"><x-td.select name="type" :options="collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" /></x-td.field>
                <x-td.field label="Subject" for="subject_id" optional><x-td.select name="subject_id" :options="$subjects" placeholder="Choose a subject" /></x-td.field>
                <x-td.field label="Level" for="level_id" optional><x-td.select name="level_id" :options="$levels" placeholder="Choose a level" /></x-td.field>
                <x-td.field label="Language" for="language"><x-td.select name="language" :options="$languageOptions" value="en" /></x-td.field>
                <fieldset class="grid gap-2">
                    <legend class="td-label">Whose work is it?</legend>
                    <x-td.check type="radio" name="ownership" value="own_work" :checked="true">My own work</x-td.check>
                    <x-td.check type="radio" name="ownership" value="third_party">Someone else's (open licence or official source only)</x-td.check>
                </fieldset>
                {{-- The most protective honest licence is the default (D-2208): a tutor opts in to letting others reuse their work. --}}
                @php
                    $licenceWords = [
                        'all_rights_reserved' => 'All rights reserved: learners may use it, nobody may copy or share it',
                        'cc_by' => 'CC BY 4.0: anyone may reuse it with credit to you',
                        'cc_by_sa' => 'CC BY-SA 4.0: reuse with credit, under the same licence',
                        'public_domain' => 'Public domain: no rights kept',
                        'official_source' => 'Official source: a department or exam body published it',
                    ];
                    $licenceOptions = collect($licenceWords)->only(collect($licences)->map->value->all())->all();
                @endphp
                <x-td.field label="Licence" for="licence" hint="Keeps all your rights unless you choose an open licence."><x-td.select name="licence" :options="$licenceOptions" value="all_rights_reserved" :hint="true" /></x-td.field>
                <x-td.field label="Source link" for="source_url" optional hint="Required for someone else's work and for official papers (https)."><x-td.input name="source_url" type="url" maxlength="2048" :hint="true" /></x-td.field>
                <x-td.field label="Price (ZAR)" for="price" optional hint="Leave empty or 0 for free. Only your own work can be sold."><x-td.input name="price" inputmode="decimal" :hint="true" /></x-td.field>
                <x-td.file-picker name="file" label="Choose the file" :max-kb="(int) config('learning.max_upload_kb')" hint="PDF, Word, PowerPoint or Excel without macros, OpenDocument, images or MP4/WebM. Images are re-saved to remove hidden details." />
                <x-td.check name="rights_confirmed" value="1">I have the right to share this file under the licence I chose.</x-td.check>
                <div><x-td.button type="submit" variant="primary">Upload</x-td.button></div>
            </form>
        </x-td.panel>
    </div>
</x-layouts::workspace>
