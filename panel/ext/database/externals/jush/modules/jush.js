/**
 * RAVEN CMS
 * ~/panel/ext/database/externals/jush/modules/jush.js
 * Minimal Jush compatibility shim for Adminer UI behavior.
 * Docs: https://raven.lanterns.io
 */

(function (window) {
    if (window.jush) {
        return;
    }

    // Provide the smallest API surface Adminer's editing.js expects.
    window.jush = {
        urls: {},
        create_links: '',
        custom_links: {},
        highlight_tag: function () {},
        textarea: function (el) {
            return el;
        },
        autocompleteSql: function () {
            return null;
        }
    };
})(window);
