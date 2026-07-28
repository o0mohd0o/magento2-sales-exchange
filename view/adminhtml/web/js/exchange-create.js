/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/alert'
], function ($, $t, alert) {
    'use strict';

    /**
     * Initialize the exchange create form behavior.
     *
     * @param {Object} config
     * @param {HTMLElement} element
     * @returns {void}
     */
    return function (config, element) {
        var root = $(element),
            rows = root.find('[data-role="replacement-rows"]'),
            template = root.find('[data-role="replacement-row-template"]').html(),
            rowIndex = rows.find('[data-role="replacement-row"]').length;

        /**
         * Enable only fields belonging to a selected return line.
         *
         * @param {HTMLElement} toggle
         */
        function updateReturnRow(toggle) {
            var checkbox = $(toggle),
                row = checkbox.closest('[data-role="return-item-row"]');

            row.find('[data-role="return-item-field"]').prop('disabled', !checkbox.prop('checked'));
            row.toggleClass('_selected', checkbox.prop('checked'));
        }

        /**
         * Keep at least one replacement input row visible.
         */
        function updateRemoveButtons() {
            var buttons = rows.find('[data-role="remove-replacement-row"]');

            buttons.prop('disabled', buttons.length === 1);
        }

        root.on('change.salesExchange', '[data-role="return-item-toggle"]', function () {
            updateReturnRow(this);
        });

        root.on('click.salesExchange', '[data-role="add-replacement-row"]', function () {
            rows.append(template.replace(/__index__/g, String(rowIndex)));
            rowIndex += 1;
            updateRemoveButtons();
        });

        root.on('click.salesExchange', '[data-role="remove-replacement-row"]', function () {
            if (rows.find('[data-role="replacement-row"]').length <= 1) {
                return;
            }

            $(this).closest('[data-role="replacement-row"]').remove();
            updateRemoveButtons();
        });

        root.find('[data-role="return-item-toggle"]').each(function () {
            updateReturnRow(this);
        });
        updateRemoveButtons();

        root.find('#salesexchange-create-form').on('submit.salesExchange', function (event) {
            if (root.find('[data-role="return-item-toggle"]:checked').length > 0) {
                return;
            }

            event.preventDefault();
            alert({
                title: $t('Return item required'),
                content: $t('Select at least one product to return before creating the exchange.')
            });
        });
    };
});
