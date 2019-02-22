jQuery(document).ready(function ($) {
    var laybuyPopup = $('#laybuy-learn-more').dialog({
        autoOpen: false,
        modal: true,
        draggable: false,
        resizable: false,
        width: 600,
        classes: {
            'ui-dialog': 'highlight custom-theme',
            'ui-dialog-titlebar': 'highlight',
        },
        open: function (event, ui) {
            $('body').css('overflow', 'hidden');
        },
        close: function (event, ui) {
            $('body').css('overflow', 'visible');
        }
    });
    $(document).on('click', '#laybuy-learn-more-open', function (e) {
        e.preventDefault();
        laybuyPopup.dialog('open');
    });
});
