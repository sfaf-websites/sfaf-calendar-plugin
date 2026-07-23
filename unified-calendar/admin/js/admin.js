/**
 * Unified Calendar Admin JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Integration panels are toggled via onclick in the HTML
        // This handles additional JS interactions

        // Add satellite site button
        $('.uc-add-satellite').on('click', function() {
            var url = prompt('Enter the satellite site URL (e.g., blog.sfaf.org):');
            if (url) {
                var html = '<div class="uc-satellite-item">' +
                    '<span class="uc-status-dot uc-dot-yellow"></span>' +
                    '<code>' + url + '</code>' +
                    '<span class="uc-muted">Pending setup</span>' +
                    '</div>';
                $(this).before(html);
            }
        });
    });

})(jQuery);
