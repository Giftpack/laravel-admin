<span class="grid-selector" data-toggle="modal" data-target="#{{ $modal }}" key="{{ $key }}" data-val="{{ $original }}">
   <a href="javascript:void(0)" class="text-muted">
       <i class="fa fa-check-square-o"></i>&nbsp;&nbsp;
       <span class="text">{{ $value }}</span>
   </a>
</span>

<style>
    .belongsto.modal tr {
        cursor: pointer;
    }

    .belongsto.modal .box {
        border-top: none;
        margin-bottom: 0;
        box-shadow: none;
    }
    .belongsto.modal .loading {
        margin: 50px;
    }
</style>

<template>
    <div class="modal fade belongsto" id="{{ $modal }}" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="border-radius: 5px;">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">{{ admin_trans('admin.choose') }}</h4>
                </div>
                <div class="modal-body">
                    <div class="loading text-center">
                        <i class="fa fa-spinner fa-pulse fa-3x fa-fw"></i>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{{ admin_trans('admin.cancel') }}</button>
                    <button type="button" class="btn btn-primary submit">{{ admin_trans('admin.submit') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>

    var $modal = $('#{{ $modal }}');
    var $related = null;

    var update = function (callback) {
        $.ajax({
            url: "{{ $resource }}/" + $related.attr('key'),
            type: "POST",
            data: {
                '{{ $name }}': selected.length ? selected : [''],
                _token: LA.token,
                _method: 'PUT',
                _edit_inline: true
            },
            success: function (data) {
                if (data.status === false) {
                    toastr.error(data.message);
                    return;
                }
                callback(data);
            },
            error: function (xhr) {
                var data = xhr.responseJSON || {};
                $modal.find('.selector-error').remove();
                $('<div class="selector-error text-danger" role="alert">').text(data.message || Object.values(data.errors || {}).join(' ') || @json(__('admin.failed'))).prependTo($modal.find('.modal-body'));
            }
        });
    };

@if($relation == \Encore\Admin\Grid\Displayers\BelongsTo::class)

    var selected = null;

    var load = function (url) {
        $.get(url, function (data) {
            $modal.find('.modal-body').html(data);
            $modal.find('.select').iCheck({
                radioClass:'iradio_minimal-blue',
                checkboxClass:'icheckbox_minimal-blue'
            });
            $modal.find('.box-header:first').hide();

            $modal.find('input.select').each(function (index,    el) {
                if ($(el).val() == selected) {
                    $(el).iCheck('toggle');
                }
            });
        }).fail(function () { $modal.find('.modal-body').text(@json(__('admin.failed'))); });
    };

    $modal.off('.adminSelector').on('show.bs.modal.adminSelector', function (e) {
        $related = $(e.relatedTarget);
        selected = $related.data('val');
        load("{!! $url !!}");
    }).on('ifChecked.adminSelector', 'input.select', function (e) {
        selected = $(this).val();
    }).find('.modal-footer .submit').off('click.adminSelector').on('click.adminSelector',function () {
        update(function (data) {
            $related.data('val', selected);
            $related.find('.text').text(data.display["{{ $name }}"]);
            $related.find('a').toggleClass('text-green text-muted');

            setTimeout(function () {
                $related.find('a').toggleClass('text-green text-muted');
            }, 2000);

            $modal.modal('toggle');

            toastr.success(data.message);
        });
    });

@else

    var selected = [];

    var load = function (url) {
        $.get(url, function (data) {
            $modal.find('.modal-body').html(data);
            $modal.find('.select').iCheck({
                radioClass:'iradio_minimal-blue',
                checkboxClass:'icheckbox_minimal-blue'
            });
            $modal.find('.box-header:first').hide();

            $modal.find('input.select').each(function (index, el) {
                if ($.inArray($(el).val().toString(), selected) >=0 ) {
                    $(el).iCheck('toggle');
                }
            });
        }).fail(function () { $modal.find('.modal-body').text(@json(__('admin.failed'))); });
    };

    $modal.off('.adminSelector').on('show.bs.modal.adminSelector', function (e) {
        $related = $(e.relatedTarget);
        selected = ($related.data('val') || []).map(function (value) {
            return value.toString();
        });

        load("{!! $url !!}");
    }).on('ifChecked.adminSelector', 'input.select', function (e) {
        var val = $(this).val().toString();
        if (selected.indexOf(val) < 0) {
            selected.push(val);
        }
    }).on('ifUnchecked.adminSelector', 'input.select', function (e) {
        var val = $(this).val().toString();
        var index = selected.indexOf(val);
        if (index !== -1) {
            selected.splice(index, 1);
        }
    }).find('.modal-footer .submit').off('click.adminSelector').on('click.adminSelector',function () {
        update(function (data) {
            $related.data('val', selected);
            $related.find('.text').text(data.display["{{ $name }}"]);
            $related.find('a').toggleClass('text-green text-muted');

            setTimeout(function () {
                $related.find('a').toggleClass('text-green text-muted');
            }, 2000);

            $modal.modal('toggle');

            toastr.success(data.message);
        });
    });
@endif

    $modal.on('click.adminSelector', '.page-item a, .filter-box a', function (e) {
        load($(this).attr('href'));
        e.preventDefault();
    }).on('click.adminSelector', 'tr', function (e) {
        $(this).find('input.select').iCheck('toggle');
        e.preventDefault();
    }).on('submit.adminSelector', '.box-header form', function (e) {
        load($(this).attr('action')+'&'+$(this).serialize());
        e.preventDefault();
        return false;
    })

</script>
