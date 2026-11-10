@if (session('status'))
    <div class="notice success" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="notice error" role="alert">
        <strong>That did not go through.</strong>
        <ul>
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
