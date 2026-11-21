<a href="javascript:void(0);" class="text-muted inline-upload-trigger" data-target="{{ $target }}">
    <i class="fa fa-upload"></i>&nbsp;{{ is_array($value) ? implode('; ', $value) : $value }}
</a>
<div class="hide">
    <input type="file" class="inline-upload" id="{{ $target }}" data-key="{{ $key }}" {{ $multiple ? 'multiple' : '' }}/>
</div>
<span class="inline-upload-error text-danger" data-for="{{ $target }}" role="alert"></span>
<script>
(function () {
    var target = @json($target);
    var $input = $(document.getElementById(target));
    var $trigger = $('.inline-upload-trigger').filter(function () { return $(this).data('target') === target; });
    var $error = $('.inline-upload-error').filter(function () { return $(this).data('for') === target; });
    $trigger.off('click.adminUpload').on('click.adminUpload', function () { $input.trigger('click'); });
    $input.off('change.adminUpload').on('change.adminUpload', function (event) {
        var files = event.target.files;
        if (!files.length) return;
        var formData = new FormData();
        Array.prototype.forEach.call(files, function (file) {
            formData.append(@json($name.($multiple ? '[]' : '')), file);
        });
        formData.append('_token', LA.token);
        formData.append('_method', 'PUT');
        formData.append('_edit_inline', 'true');
        $error.empty();
        $.ajax({
            url: @json($resource) + '/' + encodeURIComponent($input.data('key')),
            type: 'POST', processData: false, contentType: false, data: formData,
            success: function (data) {
                if (data.status === false) {
                    $error.text(data.message || @json(__('admin.failed')));
                    return;
                }
                toastr.success(data.message);
                $.admin.reload();
            },
            error: function (xhr) {
                var data = xhr.responseJSON || {};
                var messages = [];
                Object.keys(data.errors || {}).forEach(function (key) { messages = messages.concat(data.errors[key]); });
                $error.text(messages.join(' ') || data.message || @json(__('admin.failed')));
            },
            complete: function () { $input.val(''); }
        });
    });
})();
</script>
