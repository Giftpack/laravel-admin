<div class="input-group input-group-sm">
    @if($group)
        <div class="input-group-btn">
            <input type="hidden" name="{{ $group_field }}" class="{{ $group_name }}-operation" value="{{ $group_index }}"/>
            <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" style="min-width: 32px;">
                <span class="{{ $group_name }}-label">{{ $default['label'] }}</span>
                &nbsp;&nbsp;
                <span class="fa fa-caret-down"></span>
            </button>
            <ul class="dropdown-menu {{ $group_name }}">
                @foreach($group as $index => $item)
                    <li><a href="#" data-index="{{ $index }}"> {{ $item['label'] }} </a></li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="input-group-addon">
        <i class="fa fa-calendar"></i>
    </div>
    <input class="form-control" id="{{$id}}" placeholder="{{$label}}" name="{{$name}}" value="{{ is_scalar($value) ? $value : (is_array($value) ? implode(',', array_filter($value, 'is_scalar')) : '') }}">
</div>