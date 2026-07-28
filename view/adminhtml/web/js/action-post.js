/**
 * Copyright (c) Bonlineco.
 *
 * SPDX-License-Identifier: MIT
 */
define([
    'jquery',
    'Magento_Ui/js/modal/confirm'
], function ($, confirmation) {
    'use strict';

    /**
     * Submit a short-lived POST form containing trusted server-rendered data.
     *
     * @param {Object} config
     */
    function submit(config) {
        var form = $('<form></form>', {
            action: config.url,
            method: 'post'
        }).hide();

        $.each(config.params || {}, function (name, value) {
            $('<input/>', {
                type: 'hidden',
                name: name,
                value: value
            }).appendTo(form);
        });

        form.appendTo(document.body);
        form.trigger('submit');
    }

    /**
     * Initialize one toolbar action.
     *
     * @param {Object} config
     * @param {HTMLElement} element
     * @returns {void}
     */
    return function (config, element) {
        $(element).on('click.salesExchange', function () {
            var execute;

            if ($(element).prop('disabled')) {
                return;
            }

            execute = function () {
                if (config.post) {
                    $(element).prop('disabled', true).addClass('disabled');
                    submit(config);
                    return;
                }

                window.location.assign(config.url);
            };

            if (config.confirm) {
                confirmation({
                    title: config.confirm.title,
                    content: config.confirm.content,
                    actions: {
                        confirm: execute
                    }
                });
                return;
            }

            execute();
        });
    };
});
