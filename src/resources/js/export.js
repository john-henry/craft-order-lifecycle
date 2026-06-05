(function($) {
    'use strict';

    // Use jQuery's document ready instead of Craft.ready()
    $(document).ready(function() {
        // Initialize drag-and-drop for export columns table
        if ($('#exportColumnsList').length) {
            new Garnish.DragSort($('#exportColumnsList tr'), {
                handle: '.move',
                axis: 'y',
                onSortChange: function() {
                    // Update order values after drag
                    updateColumnOrder();
                }
            });

            // Initialize order values on page load
            updateColumnOrder();
        }

        /**
         * Update the order input values based on current row positions
         */
        function updateColumnOrder() {
            $('#exportColumnsList tr').each(function(index) {
                $(this).find('.order-input').val(index);
            });
            console.log('Export columns order updated');
        }

        // Date preset buttons
        $('.date-preset-btn').on('click', function(e) {
            e.preventDefault();

            $('.date-preset-btn').removeClass('active');
            $(this).addClass('active');

            const preset = $(this).data('preset');
            const now = new Date();
            let dateFrom = new Date();
            let dateTo = new Date();

            switch(preset) {
                case 'today':
                    dateFrom.setHours(0, 0, 0, 0);
                    dateTo.setHours(23, 59, 59, 999);
                    break;

                case 'yesterday':
                    dateFrom.setDate(dateFrom.getDate() - 1);
                    dateFrom.setHours(0, 0, 0, 0);
                    dateTo.setDate(dateTo.getDate() - 1);
                    dateTo.setHours(23, 59, 59, 999);
                    break;

                case 'last7':
                    dateFrom.setDate(dateFrom.getDate() - 7);
                    break;

                case 'last30':
                    dateFrom.setDate(dateFrom.getDate() - 30);
                    break;

                case 'last90':
                    dateFrom.setDate(dateFrom.getDate() - 90);
                    break;

                case 'thisMonth':
                    dateFrom = new Date(now.getFullYear(), now.getMonth(), 1);
                    dateTo = new Date(now.getFullYear(), now.getMonth() + 1, 0, 23, 59, 59);
                    break;

                case 'lastMonth':
                    dateFrom = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                    dateTo = new Date(now.getFullYear(), now.getMonth(), 0, 23, 59, 59);
                    break;

                case 'thisYear':
                    dateFrom = new Date(now.getFullYear(), 0, 1);
                    dateTo = new Date(now.getFullYear(), 11, 31, 23, 59, 59);
                    break;

                case 'last365':
                    dateFrom.setDate(dateFrom.getDate() - 365);
                    break;

                case 'all':
                    dateFrom = new Date('2000-01-01');
                    dateTo = new Date();
                    break;
            }

            // Update the date fields
            updateDateTimeField('dateFrom', dateFrom);
            updateDateTimeField('dateTo', dateTo);
        });

        // Select all events checkbox functionality
        $('#select-all-events').on('click', function(e) {
            e.preventDefault();

            const $checkboxes = $('.event-checkbox');
            const allChecked = $checkboxes.filter(':checked').length === $checkboxes.length;

            $checkboxes.prop('checked', !allChecked);

            $(this).text(allChecked ? 'Select All' : 'Deselect All');
        });

        /**
         * Update a Craft dateTimeField. Craft renders the date input as
         * #fieldId-date and the time input as #fieldId-time.
         */
        function updateDateTimeField(fieldId, date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');

            $('#' + fieldId + '-date').val(`${year}-${month}-${day}`).trigger('change');
            $('#' + fieldId + '-time').val(`${hours}:${minutes}`).trigger('change');
        }
    });

})(jQuery);
