/* global jQuery, ETTMC_Admin */
(function ($) {
    'use strict';

    function testDb() {
        var $dot  = $('#ettmc-db-dot');
        var $text = $('#ettmc-db-status-text');
        if (!$dot.length) return;

        $dot.attr('class', 'ett-dot');
        $text.text('Testing…').attr('class', 'ett-muted');

        $.post(ETTMC_Admin.ajaxUrl, {
            action: 'ettmc_test_db',
            nonce:  ETTMC_Admin.nonce
        }, function (resp) {
            if (resp && resp.ok) {
                $dot.addClass('ok');
                $text.text(resp.message || 'Connected').attr('class', 'ett-ok');
            } else {
                $dot.addClass('bad');
                $text.text((resp && resp.message) ? resp.message : 'Connection failed').attr('class', 'ett-bad');
            }
        }).fail(function () {
            $dot.addClass('bad');
            $text.text('Request failed').attr('class', 'ett-bad');
        });
    }

    $(function () {
        if (ETTMC_Admin.dbConfigured) {
            testDb();
        }
    });

}(jQuery));
