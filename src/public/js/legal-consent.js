(function () {
    'use strict';

    var notice = document.getElementById('legalCookieNotice');
    var accept = document.getElementById('legalCookieAccept');
    if (!notice || !accept) {
        return;
    }

    var storageKey = 'edvistro_cookie_notice_v1';
    var understood = false;
    try {
        understood = window.localStorage.getItem(storageKey) === 'understood';
    } catch (error) {
        understood = false;
    }

    if (!understood) {
        notice.hidden = false;
    }

    accept.addEventListener('click', function () {
        try {
            window.localStorage.setItem(storageKey, 'understood');
        } catch (error) {
            // The notice can still be dismissed when storage is unavailable.
        }
        notice.hidden = true;
    });
})();
