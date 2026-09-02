define(['jquery', 'core/ajax'], function($, ajax) {
    var initialized = false;

    var getSourceSelect = function() {
        return document.getElementById('id_config_sourcecourse') ||
            document.querySelector("select[name='config_sourcecourse']");
    };

    var getChannelsSelect = function() {
        return document.getElementById('id_config_channels') ||
            document.querySelector("select[name='config_channels[]']") ||
            document.querySelector("select[name='config_channels']");
    };

    var setChannelsOptions = function(options, disabled) {
        var channelsSelect = getChannelsSelect();
        if (!channelsSelect) {
            console.debug('[block_vitrinadb] channels select not found');
            return;
        }

        var selectNode = $(channelsSelect);
        var previous = selectNode.val() || [];
        var previousMap = {};

        for (var p = 0; p < previous.length; p++) {
            previousMap[String(previous[p])] = true;
        }

        selectNode.empty();

        if (Array.isArray(options)) {
            for (var i = 0; i < options.length; i++) {
                var option = new Option(options[i].label, options[i].value);
                if (previousMap[String(options[i].value)]) {
                    option.selected = true;
                }
                channelsSelect.add(option);
            }
        }

        selectNode.prop('disabled', !!disabled);
    };

    var onSourceCourseChange = function(sourceSelect, loadingText) {
        var channelsSelect = getChannelsSelect();
        if (!channelsSelect) {
            console.debug('[block_vitrinadb] source changed but channels select missing');
            return;
        }

        var courseId = parseInt(sourceSelect.value, 10);
        console.debug('[block_vitrinadb] source course changed', {value: sourceSelect.value, parsed: courseId});

        if (!courseId) {
            setChannelsOptions([], true);
            return;
        }

        setChannelsOptions([
            {value: '', label: loadingText + '...'}
        ], true);

        console.debug('[block_vitrinadb] sending ajax', {method: 'block_vitrinadb_get_field_options', courseid: courseId});

        var requests = ajax.call([{
            methodname: 'block_vitrinadb_get_field_options',
            args: {
                courseid: courseId,
                fielddescription: 'channels'
            }
        }]);

        requests[0].done(function(response) {
            var options = response || [];
            console.debug('[block_vitrinadb] ajax done', {count: options.length});
            setChannelsOptions(options, false);
        }).fail(function(error) {
            console.debug('[block_vitrinadb] ajax failed', error);
            setChannelsOptions([], false);
        });
    };

    return {
        init: function(loadingText) {
            if (initialized) {
                console.debug('[block_vitrinadb] edit_form already initialized');
                return;
            }
            initialized = true;

            console.debug('[block_vitrinadb] edit_form init');

            document.addEventListener('change', function(e) {
                if (!e.target) {
                    return;
                }

                if (e.target.id === 'id_config_sourcecourse' || e.target.name === 'config_sourcecourse') {
                    onSourceCourseChange(e.target, loadingText || 'Loading');
                }
            });

            var sourceSelect = getSourceSelect();
            if (!sourceSelect) {
                console.debug('[block_vitrinadb] source select not found on init');
            }
        }
    };
});
