/**
 * Admin Reports Scripts
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Initialize reports functionality.
     */
    function init() {
        bindExportButton();
        bindPeriodFilter();
    }

    /**
     * Bind export button click handler.
     */
    function bindExportButton() {
        $('.sd-export-btn').on('click', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var tab = $btn.data('tab') || 'donations';
            var period = $('#sd-period-filter').val() || 'month';
            
            var exportUrl = sdReports.ajaxUrl + '?' + $.param({
                action: 'sd_export_report',
                report: tab,
                period: period,
                _wpnonce: sdReports.nonce
            });
            
            window.location.href = exportUrl;
        });
    }

    /**
     * Bind period filter auto-submit.
     */
    function bindPeriodFilter() {
        $('#sd-period-filter').on('change', function() {
            $(this).closest('form').submit();
        });
    }

    // Initialize when document is ready.
    $(document).ready(init);

})(jQuery);
