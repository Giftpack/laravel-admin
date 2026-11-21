$('.{{ $trigger }}').popover({
    html: true,
    container: 'body',
    trigger: 'manual',
    placement: function (context, source) {
        var position = $(source).position();
        if (position.left < 100) return "right";
        if (position.top < 110) return "bottom";
        if ($(window).height() - $(source).offset().top < 370) {
            return 'top';
        }
        return "bottom";
    },
    content: function () {
        var $trigger = $(this);
        var $template = $($('template#'+$(this).data('target')).html());
        {{ $content }}
        return $template.prop("outerHTML");
    }
}).off('shown.bs.popover.adminInline').on('shown.bs.popover.adminInline', function (e) {

    var $popover = $($(this).data('bs.popover').$tip[0]).find('.ie-content');
    var $display = $(this).parents('.ie-wrap').find('.ie-display');
    var $trigger = $(this);

    $popover.data('display', $display);
    $popover.data('trigger', $trigger);

    {{ $shown ?? '' }}

}).off('click.adminInline').on('click.adminInline', function () {
    $('[data-toggle="popover"]').popover('hide');
    $(this).popover('toggle');
    // Bootstrap 3.3.4 exposes the inserted tip synchronously, before its shown event.
    // A fast submit must already know its row and display target during the animation.
    var $popover = $($(this).data('bs.popover').$tip[0]).find('.ie-content');
    $popover.data('trigger', $(this));
    $popover.data('display', $(this).parents('.ie-wrap').find('.ie-display'));
});
