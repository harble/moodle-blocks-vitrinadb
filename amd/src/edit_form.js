define(['jquery', 'core/ajax', 'core/form-autocomplete'], function($, ajax, AutoComplete) {
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

    var ensureChannelsAutocomplete = function(noSelectionText) {
        var channelsSelect = getChannelsSelect();
        if (!channelsSelect) {
            return;
        }

        var selectNode = $(channelsSelect);
        if (selectNode.data('enhanced') !== 'enhanced') {
            AutoComplete.enhance('#id_config_channels', false, '', '', false, true, noSelectionText || '', false);
        }
    };

    var setChannelsOptions = function(options, disabled) {
        var channelsSelect = getChannelsSelect();
        if (!channelsSelect) {
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

    var onSourceCourseChange = function(sourceSelect, loadingText, noSelectionText) {
        var channelsSelect = getChannelsSelect();
        if (!channelsSelect) {
            return;
        }

        var courseId = parseInt(sourceSelect.value, 10);

        if (!courseId) {
            setChannelsOptions([], true);
            return;
        }

        setChannelsOptions([
            {value: '', label: loadingText + '...'}
        ], true);

        var requests = ajax.call([{
            methodname: 'block_vitrinadb_get_field_options',
            args: {
                courseid: courseId,
                fielddescription: 'channels'
            }
        }]);

        requests[0].done(function(response) {
            var options = response || [];
            setChannelsOptions(options, false);
        }).fail(function() {
            setChannelsOptions([], false);
        });
    };

    return {
        init: function(loadingText, noSelectionText) {
            if (initialized) {
                return;
            }
            initialized = true;

            ensureChannelsAutocomplete(noSelectionText || '');

            document.addEventListener('change', function(e) {
                if (!e.target) {
                    return;
                }

                if (e.target.id === 'id_config_sourcecourse' || e.target.name === 'config_sourcecourse') {
                    onSourceCourseChange(e.target, loadingText || 'Loading', noSelectionText || '');
                }
            });

            var sourceSelect = getSourceSelect();
            if (!sourceSelect) {
                return;
            }
        }
    };
});
