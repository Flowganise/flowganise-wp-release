jQuery(document).ready(function($) {
    // Log version info to help with debugging
    if (typeof flowganiseAdmin !== 'undefined' && flowganiseAdmin.version) {
        console.log('Flowganise Admin JS - Version: ' + flowganiseAdmin.version);
    }

    $('#flowganise-connect').on('click', function() {
        const $button = $(this);
        const $status = $('#flowganise-connect-status');
        
        $button.prop('disabled', true);
        $status.html('<div class="notice notice-info"><p>Connecting...</p></div>');

        $.post(
            flowganiseAdmin.ajaxUrl,
            {
                action: 'flowganise_connect',
                _ajax_nonce: flowganiseAdmin.nonce
            },
            function(response) {
                if (response.success) {
                    $status.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    $status.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    $button.prop('disabled', false);
                }
            }
        ).fail(function(xhr, status, error) {
            $status.html('<div class="notice notice-error"><p>Error: ' + error + '</p></div>');
            $button.prop('disabled', false);
        });
    });

    $('#flowganise-disconnect').on('click', function() {
        if (confirm('Are you sure you want to disconnect from Flowganise?')) {
            const $button = $(this);
            $button.prop('disabled', true);
            
            // Simple disconnect by removing settings and reloading
            $.post(
                flowganiseAdmin.ajaxUrl,
                {
                    action: 'flowganise_disconnect',
                    _ajax_nonce: flowganiseAdmin.nonce
                },
                function() {
                    window.location.reload();
                }
            );
        }
    });
});