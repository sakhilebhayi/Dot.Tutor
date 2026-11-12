<x-td.tabs label="Materials views" class="mb-6" :items="[
    ['label' => 'Study materials', 'href' => route('teach.materials'), 'current' => request()->routeIs('teach.materials')],
    ['label' => 'Question banks and assessments', 'href' => route('teach.materials.assessments'), 'current' => request()->routeIs('teach.materials.assessments*', 'teach.materials.banks*')],
]" />
