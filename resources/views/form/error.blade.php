@if(is_array($errorKey))
    @foreach($errorKey as $key => $col)
        @foreach(array_unique([$col, $col.$key]) as $projectedErrorKey)
            @foreach($errors->get($projectedErrorKey) as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
            @endforeach
        @endforeach
    @endforeach
@else
    @if($errors->has($errorKey))
        @foreach($errors->get($errorKey) as $message)
            <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
        @endforeach
    @endif
@endif