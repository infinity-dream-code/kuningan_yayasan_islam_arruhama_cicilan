/**
 * Format No. VA: prefix + NIS (total 16 digit).
 * Open 7797793 (VA 93 / cicil), Close 7797794 (VA 94 / non-cicil).
 */
(function (window) {
    const DEFAULT_OPEN = '7797793';
    const DEFAULT_CLOSE = '7797794';
    const TOTAL_LENGTH = 16;

    function digitsOnly(value) {
        return String(value ?? '').replace(/\D/g, '');
    }

    window.formatNoVA = function (nis, prefix) {
        const vaPrefix = digitsOnly(prefix ?? window.APP_VA_PREFIX ?? window.APP_VA_OPEN ?? DEFAULT_OPEN) || DEFAULT_OPEN;
        const digits = digitsOnly(nis);
        if (!digits) {
            return '';
        }
        const padLen = Math.max(1, TOTAL_LENGTH - vaPrefix.length);
        return vaPrefix + digits.padStart(padLen, '0');
    };

    window.showVAOpen = function (nis) {
        return window.formatNoVA(nis, window.APP_VA_OPEN || DEFAULT_OPEN);
    };

    window.showVAClose = function (nis) {
        return window.formatNoVA(nis, window.APP_VA_CLOSE || DEFAULT_CLOSE);
    };

    window.showVABoth = function (nis) {
        const open = window.showVAOpen(nis);
        const close = window.showVAClose(nis);
        if (open && close && open !== close) {
            return open + ' / ' + close;
        }
        return open || close || '';
    };

    window.novaFromBills = function (nis, tagihans) {
        const unique = [];
        (tagihans || []).forEach(function (item) {
            const v = item && item.NOVA ? String(item.NOVA).trim() : '';
            if (v && unique.indexOf(v) === -1) {
                unique.push(v);
            }
        });
        if (unique.length) {
            return unique.join(' / ');
        }
        return nis ? window.showVABoth(nis) : '';
    };
})(window);
