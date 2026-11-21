// Keep only delegated bindings whose owning trigger is still in the document.
// Grid identities change on PJAX renders, so rebinding only the new selector
// cannot release closures retained by earlier, removed grids.
var inlineLifecycle = $.admin.inlineEditLifecycle;
if (!inlineLifecycle) {
    inlineLifecycle = $.admin.inlineEditLifecycle = {
        owners: Object.create(null),
        prune: function () {
            var owners = this.owners;
            Object.keys(owners).forEach(function (owner) {
                if (document.querySelector('.' + owner)) return;
                $(document).off('click.adminInline', owners[owner]);
                $('.ie-content-' + owner).closest('.popover').remove();
                delete owners[owner];
            });
        }
    };
    $(document).on('pjax:end.adminInlineLifecycle', function () {
        inlineLifecycle.prune();
    });
}
inlineLifecycle.prune();
var owner = @json($trigger);
var submitSelector = '.ie-content-' + owner + ' .ie-submit';
inlineLifecycle.owners[owner] = submitSelector;
$(document).off('click.adminInline', submitSelector).on('click.adminInline', submitSelector, function () {

    var $popover = $(this).closest('.ie-content');
    var $trigger = $popover.data('trigger');

    @isset($val)
        {{ $val }}
    @else
        var val = $popover.find('.ie-input').val();
    @endisset

    var original = $trigger.data('original');

    if (val == original) {
        $('[data-toggle="popover"]').popover('hide');
        return;
    }

    var data = {
        _token: LA.token,
        _method: 'PUT',
        _edit_inline: true,
    };
    data[$trigger.data('name')] = val;

    $.ajax({
        url: "{{ $resource }}/" + $trigger.data('key'),
        type: "POST",
        data: data,
        success: function (data) {
            if (data.status === false) {
                $popover.find('.error').text(data.message || @json(__('admin.failed')));
                return;
            }
            toastr.success(data.message);

            {{ $slot }}

            $trigger.data('value', val)
                .data('original', val);

            $('[data-toggle="popover"]').popover('hide');
        },
        error: function (xhr) {
            if (xhr.status !== 422) $popover.find('.error').text((xhr.responseJSON || {}).message || @json(__('admin.failed')));
        },
        statusCode: {
            422: function(xhr) {
                $popover.find('.error').empty();
                var errors = xhr.responseJSON.errors;
                for (var key in errors) {
                    $popover.find('.error').append($('<div>').text([].concat(errors[key]).join(' ')));
                }
            }
        }
    });
});
