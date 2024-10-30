jQuery(document).ready(function($) {
    var focusedElement;

    // Tooltip
    $('[data-toggle="tooltip"]').tooltip();

    // Stats
    if($('#sc-widget-stats').length > 0){
        var box = $('#sc-widget-stats');
        var data = {
            'action': 'smart_cache_admin_ajax',
            'task': 'get-widget-stats',
            'data': '',
            'key': '9ffee905c0d35a0d60d6cc4c176c12bc'
        }
        jQuery.post(ajaxurl, data, function(response) {
            responseobj = $.parseJSON(response);
            if(responseobj.success){
                box.html(responseobj.msg);
            }else{
                box.html('');
            }
        });
    }

    // Settings changed (checkboxes)
    $('#smart-cache-form input[type="checkbox"]').on('click', function(){
        var control = $(this).attr('data-control');
        if($(this).is(':checked')){
            $('#smart-cache-form').find('[data-depends-on="'+control+'"]').removeAttr('disabled');
            $('#smart-cache-form').find('[readonly="readonly"]').attr('disabled', 'disabled');
        }else{
            $('#smart-cache-form').find('[data-depends-on="'+control+'"]').attr('disabled', 'disabled');
        }
        $('#settings-changed').val('1');
    });

    // Settings changed (select menus and textboxes)
    $('#smart-cache-form select, #smart-cache-form input[type="text"]').on('change', function(){
        $('#settings-changed').val('1');
    });

    // Mod-Rewrite settings changed
    $('.mod-rewrite-select').change(function(){
        $('#mod-rewrite-settings-changed').val('1');
    })

    // Mod-Rewrite settings changed
    $('.mod-rewrite-check').click(function(){
        $('#mod-rewrite-settings-changed').val('1');
    })

    // Minify active checked
    $('#minify_active_checkbox').on('click', function(){
        var text = null;
        if($(this).is(':checked')){
            text = 'Great! You can now calibrate how much minification you need.  Remember to save your changes.';
        }else{
            text = 'You have chosen to disable minification and not take advantage of optimal performance.';
        }
        $('.minify_active-post-text').text(text);
    });

    // .htaccess-mod
    $('#htaccess-mod, #support_info_textarea').focus(function(e){
        if (focusedElement == this) return; //already focused, return so user can now place cursor at specific point in input.
        focusedElement = $(this);
        setTimeout(function () { focusedElement.select(); }, 50); //select all text in any field on focus for easy re-entry. Delay sightly to allow focus to "stick" before selecting.
    });

    if($('#do-scan').length > 0 && $('#do-scan').val() != '') {
        alert('SmartCache is ready to begin a pre-scan of your site.  Please do not close this page until the scan has finished.');
        location.replace($('#do-scan').val());
    }

    if($('#sc-scan-site').length > 0) {
        var scanner = setInterval(function(){
            var data = {
                'action': 'smart_cache_admin_ajax',
                'task': 'continue-scan-site',
                'data': '',
                'key': 'ffa2f3d94b3eee517145be9c4ef69b93'
            }
            jQuery.post(ajaxurl, data, function(response) {
                responseobj = $.parseJSON(response);
                if(responseobj.success){
                    $('#sc-scan-site').html(responseobj.msg);
                }else{
                    $('#sc-scan-site').html(responseobj.msg);
                    alert(responseobj.msg);
                    clearInterval(scanner);
                    var page = $('#sc-scan-site').attr('data-page');
                    location.replace(page);
                }
            });
        }, 10000);
    }

    $('#smart-cache-send-ticket').click(function(e){
        e.preventDefault();

        var email = $('#support_email_text').val().trim();
        var reason = $('#support_reason_select').val();
        var subject = $('#support_subject_text').val().trim();
        var descr = $('#support_descr_textarea').val().trim();
        var support_info = null;

        if(email == '' || reason == '' || descr == ''){
            alert('Please make sure to enter your email, the help you need and a brief description.');
            return false;
        }else{
            var data = {
                'action': 'smart_cache_admin_ajax',
                'task': 'send-ticket',
                'data': {email:email, reason:reason, subject:subject, descr:descr, info:support_info},
                'key': '8425698e689bddaf92f66ba83814cb3d'
            }
            jQuery.post(ajaxurl, data, function(response) {
                responseobj = $.parseJSON(response);
                if(responseobj.success){
                    alert('Your support request has been sent.  You should receive a reply soon.');
                }else{
                    alert('Sorry, your ticket was not delivered.  Please try again shortly, or visit https://www.codedragon.ca/support/submit-ticket/.  \n\nError: ' + responseobj.msg);
                }
            });
        }
    })

    // Form submitted
    $('#smart-cache-form').submit(function(){
        if($('#mod-rewrite-settings-changed').val() == '1'){
            alert('You have made a change to a setting that needs to be updated in the .htaccess (Apache/Litespeed) or smartcacheopt.conf (NGINX) file.  Take a second to make sure this file is writable before clicking ok.');
        }
    });

    // Accordion/collapse toggle
    $(document).delegate('.togglepanel', 'click', function(e){
        e.preventDefault();
        var target = $(this).attr('data-target');
        if(target == undefined || target == '')
            target = $(this).attr('href');
        target = $(target);
        if(target.is(':hidden'))
            target.slideDown(500);
        else
            target.slideUp(500);
    });
});

String.prototype.lpad = function(length) {
    var str = this;
    while (str.length < length)
        str = '0' + str;
    return str;
}

