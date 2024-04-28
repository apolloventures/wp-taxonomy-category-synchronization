jQuery(document).ready(function($) {
    $('#doaction, #doaction2').click(function(e) {
        var action = $(this).prev("select").val();
        if(action == 'delete') {
            $(this).after('<div class="spinner is-active" style="float: none; margin-left: 5px;"></div>');
        }
    });
});