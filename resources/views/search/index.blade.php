@switch($scope)
    @case('teach')
        <x-layouts::workspace title="Search" step="dense">@include('search._results')</x-layouts::workspace>
        @break
    @case('ops')
        <x-layouts::operations title="Search" step="dense">@include('search._results')</x-layouts::operations>
        @break
    @default
        <x-student.shell title="Search">@include('search._results')</x-student.shell>
@endswitch
