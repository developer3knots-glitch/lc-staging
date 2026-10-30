/**
 * Stable loader. Customer sites keep this one script tag.
 * The loader asks rev.php for the current widget file time, then loads that build.
 * A pinned ?v= on the website does not freeze future widget updates.
 */
(function () {
    var current = document.currentScript;
    if (!current || !current.src) return;
    var origin = String(current.src).replace(/\/widget\/embed\.js(?:\?.*)?$/i, '');

    function mount(version) {
        var s = document.createElement('script');
        s.src = origin + '/widget/embed-app.js?v=' + encodeURIComponent(version);
        s.charset = 'utf-8';
        s.async = false;
        var attrs = current.attributes;
        for (var i = 0; i < attrs.length; i++) {
            var name = attrs[i].name;
            if (name.indexOf('data-') === 0) s.setAttribute(name, attrs[i].value);
        }
        (document.head || document.documentElement).appendChild(s);
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', origin + '/widget/rev.php?t=' + Date.now(), true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        var version = Date.now();
        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data && data.v) version = data.v;
            } catch (e) {}
        }
        mount(version);
    };
    xhr.onerror = function () { mount(Date.now()); };
    xhr.send();
})();
