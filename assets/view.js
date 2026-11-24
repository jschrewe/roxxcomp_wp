(function () {
    'use strict';

    if (!window.fetch || !window.AbortController || !window.URL || !window.DOMParser || !window.WeakMap) {
        return;
    }

    var states = new WeakMap();

    function initialize(block) {
        var select = block.querySelector('.roxxcomp-results__filter select');
        var button = block.querySelector('.roxxcomp-results__filter button');
        var status = block.querySelector('.roxxcomp-results__status');
        var content = block.querySelector('.roxxcomp-results__content');
        if (!select || !button || !status || !content) {
            return null;
        }
        var state = { select: select, button: button, status: status, content: content, value: select.value, controller: null };
        states.set(block, state);
        button.hidden = true;
        return state;
    }

    function syncFallbackForms() {
        var params = new URL(window.location.href).searchParams;
        document.querySelectorAll('.roxxcomp-results__filter').forEach(function (form) {
            var select = form.querySelector('select');
            form.querySelectorAll('input[type="hidden"]').forEach(function (input) {
                if (/^roxxcomp_category_[1-9][0-9]*$/.test(input.name)) {
                    input.remove();
                }
            });
            params.forEach(function (value, name) {
                if (/^roxxcomp_category_[1-9][0-9]*$/.test(name) && name !== select.name && /^\d+$/.test(value)) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }
            });
        });
    }

    document.querySelectorAll('.roxxcomp-results').forEach(initialize);

    document.addEventListener('change', function (event) {
        var select = event.target;
        if (!(select instanceof HTMLSelectElement) || !select.matches('.roxxcomp-results__filter select')) {
            return;
        }
        var block = select.closest('.roxxcomp-results');
        var state = states.get(block);
        if (!state || !/^roxxcomp_category_[1-9][0-9]*$/.test(select.name)) {
            return;
        }
        if (state.controller) {
            state.controller.abort();
        }
        var controller = new AbortController();
        state.controller = controller;
        var value = select.value;
        var url = new URL(window.location.href);
        url.searchParams.set(select.name, value);
        state.status.textContent = state.status.dataset.loading;
        state.content.setAttribute('aria-busy', 'true');

        fetch(url.href, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal })
            .then(function (response) {
                if (!response.ok || response.redirected) {
                    throw new Error('Results page request failed');
                }
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var updatedSelect = doc.querySelector('select[name="' + select.name + '"]');
                var updatedBlock = updatedSelect && updatedSelect.closest('.roxxcomp-results');
                var updatedContent = updatedBlock && updatedBlock.querySelector('.roxxcomp-results__content');
                if (!updatedContent || updatedSelect.value !== value) {
                    throw new Error('Results were not available in the response');
                }
                state.content.innerHTML = updatedContent.innerHTML;
                state.value = value;
                var currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set(select.name, value);
                window.history.replaceState(window.history.state, '', currentUrl.href);
                syncFallbackForms();
                state.button.hidden = true;
                state.status.textContent = state.status.dataset.loaded.replace('%s', select.selectedOptions[0].textContent);
            })
            .catch(function (error) {
                if (error.name === 'AbortError') {
                    return;
                }
                select.value = state.value;
                state.button.hidden = false;
                state.status.textContent = state.status.dataset.error;
            })
            .finally(function () {
                if (state.controller === controller) {
                    state.controller = null;
                    state.content.removeAttribute('aria-busy');
                }
            });
    });
}());
