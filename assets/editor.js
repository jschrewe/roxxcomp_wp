(function (wp, labels) {
    'use strict';

    var el = wp.element.createElement;
    var useEffect = wp.element.useEffect;
    var useState = wp.element.useState;

    wp.blocks.registerBlockType('roxxcomp/results', {
        apiVersion: 2,
        title: labels.title,
        icon: 'awards',
        category: 'widgets',
        attributes: {
            competitionId: { type: 'string', default: '' },
            scope: { type: 'string', default: 'combined' }
        },
        edit: function (props) {
            var state = useState({ loading: true, competitions: [], error: false });
            var data = state[0];
            var setData = state[1];
            var blockProps = wp.blockEditor.useBlockProps();

            useEffect(function () {
                var active = true;
                wp.apiFetch({ path: '/roxxcomp/v1/competitions' }).then(function (competitions) {
                    if (active) {
                        setData({ loading: false, competitions: competitions, error: false });
                    }
                }).catch(function () {
                    if (active) {
                        setData({ loading: false, competitions: [], error: true });
                    }
                });
                return function () { active = false; };
            }, []);

            var options = [{ label: labels.select, value: '' }];
            data.competitions.forEach(function (competition) {
                options.push({
                    label: competition.name + (!competition.public ? ' (' + labels.private + ')' : '') +
                        (competition.start_date ? ' (' + competition.start_date + ')' : ''),
                    value: competition.uuid
                });
            });
            var missing = props.attributes.competitionId && !data.loading && !data.error &&
                !data.competitions.some(function (competition) {
                    return competition.uuid === props.attributes.competitionId;
                });
            if (missing) {
                options.push({ label: labels.missing, value: props.attributes.competitionId });
            }

            return el('div', blockProps,
                el('h3', null, labels.title),
                el('p', null, labels.notice),
                data.loading ? el('p', null, labels.loading) : null,
                data.error ? el('p', { role: 'alert' }, labels.loadError) : null,
                !data.loading && !data.error ? el(wp.components.SelectControl, {
                    label: labels.competition,
                    value: props.attributes.competitionId,
                    options: options,
                    onChange: function (value) { props.setAttributes({ competitionId: value }); }
                }) : null,
                el(wp.components.SelectControl, {
                    label: labels.scope,
                    value: props.attributes.scope,
                    options: [
                        { label: labels.combined, value: 'combined' },
                        { label: labels.stage, value: 'stage' }
                    ],
                    onChange: function (value) { props.setAttributes({ scope: value }); }
                }),
                el('p', null, labels.preview)
            );
        },
        save: function () { return null; }
    });
}(window.wp, window.roxxcompResultsLabels));
