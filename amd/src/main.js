// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Javascript to initialise the block.
 *
 * @module block_vitrinadb/main
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import $ from 'jquery';
import {get_strings as getStrings} from 'core/str';
import Notification from 'core/notification';
import Log from 'core/log';
import Ajax from 'core/ajax';

/**
 * Private functions.
 *
 */

// Current instance (optional).
var instanceid = [];

// Courses by page.
var bypage = [];

// Paging variable controls.
var paging = [];

// Filters box.
var $filtersbox = null;

// Loading courses.
var loading = false;

// Load strings.
var strings = [
    {key: 'courselinkcopiedtoclipboard', component: 'block_vitrinadb'},
    {key: 'nocoursesview', component: 'block_vitrinadb'},
    {key: 'nomorecourses', component: 'block_vitrinadb'},
    {key: 'pendingpermissionnotset', component: 'block_vitrinadb'},
];
var s = [];

/**
 * Load strings from server.
 */
function loadStrings() {

    strings.forEach(one => {
        s[one.key] = one.key;
    });

    getStrings(strings).then(function(results) {
        var pos = 0;
        strings.forEach(one => {
            s[one.key] = results[pos];
            pos++;
        });
        return true;
    }).fail(function(e) {
        Log.debug('Error loading strings');
        Log.debug(e);
    });
}
// End of Load strings.

/**
 * Load courses for a tab.
 *
 * @param {integer} uniqueid
 * @param {object} $tabcontent
 */
function loadCourses(uniqueid, $tabcontent) {

    var view = $tabcontent.data('view');

    $tabcontent.addClass('loading');
    var $coursesbox = $tabcontent.find('.courses-list');

    if (paging[uniqueid] === undefined) {
        paging[uniqueid] = [];
    }

    if (paging[uniqueid][view] === undefined) {
        paging[uniqueid][view] = {
            loaded: 0,
            ended: false,
        };
    }

    // Not more courses.
    if (paging[uniqueid][view].ended) {
        $tabcontent.removeClass('loading');
        return;
    }

    // Check active filters.
    var filters = [];

    if ($filtersbox) {
        var $fulltextcontrol = $filtersbox.find('.filterfulltext input[name=q]');

        if ($fulltextcontrol.length > 0) {
            var $fulltext = $fulltextcontrol.val().trim();

            if ($fulltext) {
                filters.push({
                    'values': [$fulltext],
                    'type': 'fulltext',
                });
            }
        }

        // Author dropdown (single-select).
        var $authorcontrol = $filtersbox.find('.filterauthor select[name="author"]');
        if ($authorcontrol.length > 0) {
            var author = $authorcontrol.val();
            if (author) {
                filters.push({
                    'values': [author],
                    'type': 'author',
                });
            }
        }

        // Tags dropdown filter (single-select). If a tag is selected, add a
        // "tags" filter for the backend.
        var $tagselect = $filtersbox.find('select[name="tagsfilter"]');
        if ($tagselect.length > 0) {
            var tagvalue = $tagselect.val();
            if (tagvalue) {
                filters.push({
                    'values': [tagvalue],
                    'type': 'tags',
                });
            }
        }

        // Pending approval checkbox.
        var $pendingcontrol = $filtersbox.find('.filterpending input[name="pending"]');
        if ($pendingcontrol.length > 0 && $pendingcontrol.is(':checked')) {
            filters.push({
                'values': ['1'],
                'type': 'pending',
            });
        }

        $filtersbox.find('.filtercontrol').each(function() {
            var $control = $(this);
            var key = $control.data('key');
            var values = [];

            $control.find('.filteroptions input:checked').each(function() {
                var $option = $(this);
                values.push($option.val());
            });

            if (values.length > 0) {
                filters.push({
                    'values': values,
                    'type': key
                });
            } else if (key === 'channels') {
                // User has explicitly unselected all categories (channels).
                // Send an explicit empty channels filter so the backend can
                // distinguish this case from "no channels filter" and
                // return no resources instead of falling back to defaults.
                filters.push({
                    'values': [],
                    'type': key
                });
            } else if (key === 'tags') {
                // When the block has configured item tags, they are shown
                // as a checkbox list (key = "tags"). If the user
                // unchecks all of them, send an explicit empty "tags"
                // filter so that the backend knows tag filtering has been
                // intentionally cleared and does NOT fall back to the
                // block's default configured tags.
                filters.push({
                    'values': [],
                    'type': key
                });
            }
        });
    }
    // End of check active filters.

    var sort = '';
    var sortdirection = '';

    if ($filtersbox) {
        var $sortcontrol = $filtersbox.find('.filtersort select[name="sort"]');
        if ($sortcontrol.length > 0) {
            sort = $sortcontrol.val();
        }

        var $sortdirectioncontrol = $filtersbox.find('.filtersortdirection select[name="sortdirection"]');
        if ($sortdirectioncontrol.length > 0) {
            sortdirection = $sortdirectioncontrol.val();
        }
    }

    loading = true;
        Ajax.call([{
        methodname: 'block_vitrinadb_get_courses',
        args: {'view': view, 'filters': filters, 'instanceid': instanceid[uniqueid],
            'amount': bypage[uniqueid], 'initial': paging[uniqueid][view].loaded,
            'sort': sort, 'sortdirection': sortdirection},
        done: function(data) {

            if (data && data.length > 0) {
                paging[uniqueid][view].loaded += data.length;

                if (data.length < bypage[uniqueid]) {
                    paging[uniqueid][view].ended = true;
                }

                data.forEach(one => {
                    $coursesbox.append(one.html);
                });

            } else {
                paging[uniqueid][view].ended = true;
            }

            loading = false;
            $tabcontent.removeClass('loading');

            if (paging[uniqueid][view].ended) {
                $tabcontent.addClass('ended');
                $tabcontent.find('.loadmore').hide();

                var nocoursesbox = $tabcontent.find('.nocourses');
                var nocoursesmsg = '';
                if (paging[uniqueid][view].loaded == 0) {
                    nocoursesmsg = s.nocoursesview;
                    nocoursesbox.removeClass('hidden');
                } else {
                    // Only show the message if not is the first call when reached the end.
                    if (paging[uniqueid][view].loaded > bypage[uniqueid]) {
                        nocoursesmsg = s.nomorecourses;
                        nocoursesbox.removeClass('hidden');
                    }
                }
                nocoursesbox.html(nocoursesmsg);
            }
        },
        fail: function(e) {
            loading = false;
            $tabcontent.removeClass('loading');
            Notification.exception(e);
            Log.debug(e);
        }
    }]);

}

/**
 * Restart all controls to new course load.
 *
 * @param {integer} uniqueid
 */
function restartSearch(uniqueid) {
    paging[uniqueid] = [];
    $('#' + uniqueid + ' .block_vitrina-tabs [data-ref]').each(function() {
        var $tab = $(this);
        var $tabcontent = $($tab.attr('data-ref'));
        $tabcontent.removeClass('ended');
        $tabcontent.find('.loadmore').show();
        $tabcontent.find('.nocourses').addClass('hidden');
        $tabcontent.find('.courses-list').empty();
    });
}

/**
 * Component initialization.
 *
 * @method init
 *
 * @param {integer} uniqueid
 */
export const init = (uniqueid = null) => {

    loadStrings();

    $('[data-vitrina-toggle]').on('click', function() {
        var $this = $(this);
        var cssclass = $this.attr('data-vitrina-toggle');
        var target = $this.attr('data-target');

        $(target).toggleClass(cssclass);
    });

    if (uniqueid) {
        $('#' + uniqueid + '.block_vitrina-content').each(function() {
            var $blockcontent = $(this);

            // Manage tabs.
            $blockcontent.find('.block_vitrina-tabs').each(function() {
                var $tabs = $(this);
                var tabslist = [];

                $tabs.find('[data-ref]').each(function() {
                    var $tab = $(this);
                    var $tabcontent = $($tab.data('ref'));
                    tabslist.push($tab);

                    $tab.on('click', function() {
                        tabslist.forEach(one => {
                            $(one.data('ref')).removeClass('active');
                        });

                        $tabs.find('.active[data-ref]').removeClass('active');
                        $tab.addClass('active');

                        if ($tabcontent) {
                            $tabcontent.addClass('active');

                            // Load courses only the first time.
                            var view = $tabcontent.data('view');

                            if (paging[uniqueid][view] === undefined) {
                                loadCourses(uniqueid, $tabcontent);
                            }
                        }
                    });

                    $tabcontent.find('.loadmore').on('click', function() {
                        var $this = $(this);

                        // If is a link, do nothing. Only for buttons.
                        if ($this.attr('href')) {
                            return;
                        }

                        loadCourses(uniqueid, $tabcontent);
                    });
                });

                // Load dynamic buttons.
                $blockcontent.find('[data-vitrina-tab]').each(function() {
                    var $button = $(this);

                    $button.on('click', function() {
                        var key = '.tab-' + $button.data('vitrina-tab');

                        tabslist.forEach($tab => {
                            if ($tab.data('ref').indexOf(key) >= 0) {
                                $tab.trigger('click');
                            }
                        });
                    });
                });
            });
        });
    }
};

/**
 * Initialize functions for the detail page.
 *
 */
export const detail = () => {
    strings.push({key: 'courselinkcopiedtoclipboard', component: 'block_vitrinadb'});

    $('input[name="courselink"]').on('click', function() {
        var $input = $(this);
        $input.select();
        document.execCommand("copy");

        var $msg = $('<div class="msg-courselink-copy">' + s.courselinkcopiedtoclipboard + '</div>');

        $input.parent().append($msg);
        window.setTimeout(function() {
            $msg.remove();
        }, 1600);
    });

    init();
};

/**
 * Initialize functions for the catalog page.
 *
 * @param {string} uniqueid
 * @param {string} view
 * @param {integer} currentinstanceid
 * @param {integer} currentbypage
 */
export const catalog = (uniqueid, view, currentinstanceid = 0, currentbypage = 20) => {

    instanceid[uniqueid] = currentinstanceid;
    bypage[uniqueid] = parseInt(currentbypage);
    var $tabcontent = $('#' + uniqueid + ' .tabs-content .tab-' + view);

    loadCourses(uniqueid, $tabcontent);

    init(uniqueid);
};

/**
 * Initialize the filter controls.
 *
 * @param {integer} uniqueid
 * @param {array} selectedfilters
 */
export const filters = (uniqueid, selectedfilters = []) => {

    // Ensure localised strings are initialised before any preselected
    // pending filter permission checks run (e.g. URL pending=1).
    loadStrings();

    $filtersbox = $('#' + uniqueid);
    var $channelsControl = $filtersbox.find('.filtercontrol[data-key="channels"]');
    var $selectAllChannels = $channelsControl.find('.vitrinadb-channels-selectall');
    var $pendingcontrol = $filtersbox.find('.filterpending input[name="pending"]');
    var arrPrechecked = [];
    var hasArrPrechecked = false;

    var syncSelectAllChannels = function() {
        return;
    };

    var updateTreeExpansion = function() {
        return;
    };

    var normalizeChannelPath = function(value) {
        return String(value || '').replace(/\u00a0/g, ' ').trim();
    };

    var getPendingPermissionMessage = function() {
        var raw = String(s.pendingpermissionnotset || '').trim();
        if (raw === '' || raw === 'pendingpermissionnotset') {
            return '尚未为您的账户设置审批指定类别的权限，如需开通请联系管理人员';
        }
        return raw;
    };

    var getChannelPathValue = function($checkbox) {
        var value = normalizeChannelPath($checkbox.val());
        if (value !== '') {
            return value;
        }

        var $label = $checkbox.closest('.filter-option').find('> label');
        var title = normalizeChannelPath($label.attr('title'));
        if (title !== '') {
            return title;
        }

        return normalizeChannelPath($label.text());
    };

    var setSelectAllDisabled = function(disabled) {
        if ($selectAllChannels.length) {
            $selectAllChannels.prop('disabled', disabled);
        }
    };

    var applyPendingChannelPermissions = function() {
        if (!$pendingcontrol.length || !$channelsControl.length) {
            return true;
        }

        var $allChannelCheckboxes = $channelsControl.find('input.filteroption');

        if (!$pendingcontrol.is(':checked')) {
            if (hasArrPrechecked) {
                $allChannelCheckboxes.each(function(index) {
                    $(this).prop('checked', !!arrPrechecked[index]);
                });
                hasArrPrechecked = false;
                arrPrechecked = [];
            }

            $allChannelCheckboxes.prop('disabled', false);
            setSelectAllDisabled(false);
            updateTreeExpansion();
            syncSelectAllChannels();
            return true;
        }

        var permissionTitle = normalizeChannelPath($pendingcontrol.attr('title'));
        if (permissionTitle === '') {
            window.alert(getPendingPermissionMessage());
            $pendingcontrol.prop('checked', false);
            return false;
        }

        if (!hasArrPrechecked) {
            arrPrechecked = [];
            $allChannelCheckboxes.each(function() {
                arrPrechecked.push($(this).is(':checked'));
            });
            hasArrPrechecked = true;
        }

        if (permissionTitle === '*') {
            $allChannelCheckboxes.prop('disabled', false);
            setSelectAllDisabled(false);
            $allChannelCheckboxes.prop('checked', true);
            updateTreeExpansion();
            syncSelectAllChannels();
            return true;
        }

        var allowedPaths = {};
        permissionTitle.split(/\r?\n/).forEach(path => {
            var normalized = normalizeChannelPath(path);
            if (normalized !== '') {
                allowedPaths[normalized] = true;
            }
        });

        if (Object.keys(allowedPaths).length === 0) {
            window.alert(getPendingPermissionMessage());
            $pendingcontrol.prop('checked', false);
            return false;
        }

        $allChannelCheckboxes.each(function() {
            var $checkbox = $(this);
            var channelpath = getChannelPathValue($checkbox);
            var isallowed = !!allowedPaths[channelpath];

            $checkbox.prop('checked', isallowed);
            $checkbox.prop('disabled', !isallowed);
        });

        setSelectAllDisabled(true);
        updateTreeExpansion();
        syncSelectAllChannels();
        return true;
    };

    var applyFilters = function() {

        if (!loading) {
            restartSearch(uniqueid);
            loadCourses(uniqueid, $filtersbox.find('.block_vitrina-tabcontent.active'));
        }
    };

    selectedfilters.forEach(filter => {

        if (filter.key === 'fulltext') {
            $filtersbox.find('.filterfulltext input[name="q"]').val(filter.values.join(' '));
            return;
        }

        if (filter.key === 'author') {
            if (filter.values && filter.values.length > 0) {
                var authorValue = String(filter.values[0]);
                var $authorselect = $filtersbox.find('.filterauthor select[name="author"]');
                if ($authorselect.length > 0) {
                    if ($authorselect.find('option[value="' + authorValue + '"]').length === 0) {
                        window.alert('没有查到指定作者的记录！本次查询将尝试不限作者查询。');
                    }
                    $authorselect.val(authorValue);
                }
            }
            return;
        }

        if (filter.key === 'pending') {
            if (filter.values && filter.values.length > 0) {
                var pendingValue = String(filter.values[0]);
                if (pendingValue !== '' && pendingValue !== '0') {
                    // Check the "Only pending approval records" checkbox.
                    $filtersbox.find('.filterpending input[name="pending"]').prop('checked', true);
                }
            }
            return;
        }

        $filtersbox.find('.filtercontrol[data-key="' + filter.key + '"] .filteroptions').each(function() {
            var $filteroptions = $(this);

            filter.values.forEach(value => {
                $filteroptions.find('input[value="' + value + '"]').prop('checked', true);
            });
        });
    });

    $filtersbox.find('.filtercontrol .filteroptions input').on('change', applyFilters);
    $filtersbox.find('.filtersort select[name="sort"]').on('change', applyFilters);
    $filtersbox.find('.filtersortdirection select[name="sortdirection"]').on('change', applyFilters);
    $filtersbox.find('.filterauthor select[name="author"]').on('change', applyFilters);
    $filtersbox.find('.filtertags select[name="tagsfilter"]').on('change', applyFilters);

    $filtersbox.find('.filterfulltext button').on('click', applyFilters);
    $filtersbox.find('.filterfulltext input').on('keypress', function(e) {
        if (e.which == 13) {
            applyFilters();
        }
    });

    $filtersbox.find('.vitrina-filter-responsivebutton').on('click', function() {
        $filtersbox.addClass('opened-popup');
    });

    $filtersbox.find('.vitrina-filter-closebutton').on('click', function() {
        $filtersbox.removeClass('opened-popup');
    });

    // Initialize the channels filter as a collapsible tree when there are
    // parent channels with children. A channel value whose label starts
    // with "--" is treated as a child of the previous non-prefixed
    // channel in the list (built server-side).
    if ($channelsControl.length) {

        // Keep the state of the optional "select all" checkbox in sync
        // with the individual channel checkboxes.
        syncSelectAllChannels = function() {
            if (!$selectAllChannels.length) {
                return;
            }

            var $allChannelCheckboxes = $channelsControl.find('input.filteroption');
            if ($allChannelCheckboxes.length === 0) {
                $selectAllChannels.prop('checked', false);
                return;
            }

            var allChecked = $allChannelCheckboxes.filter(':checked').length === $allChannelCheckboxes.length;
            $selectAllChannels.prop('checked', allChecked);
        };

        updateTreeExpansion = function() {
            $channelsControl.find('.filter-optiongroup.haschilds').each(function() {
                var $group = $(this);

                var hasSelectedDescendant = $group.find('ul.filteroptions input.filteroption:checked').length > 0;
                var isSelectedSelf = $group.find('> .filter-option > input.filteroption:checked').length > 0;
                var $icon = $group.find('> .filter-option .tree-toggle');

                if (hasSelectedDescendant || isSelectedSelf) {
                    $group.addClass('expanded');
                    $icon.removeClass('fa-plus-circle').addClass('fa-minus-circle');
                }
            });
        };

        // Initial expansion based on any preselected channels.
        updateTreeExpansion();

        // Initial sync for the select-all checkbox.
        syncSelectAllChannels();

        // Toggle all channels when the "select all" checkbox changes.
        if ($selectAllChannels.length) {
            $selectAllChannels.on('change', function() {
                var checked = $(this).is(':checked');

                // Select/unselect all channel checkboxes (including children).
                $channelsControl.find('input.filteroption').prop('checked', checked);

                // Update tree UI and re-sync the select-all state.
                updateTreeExpansion();
                syncSelectAllChannels();

                // Apply filters so the catalog reloads according to the
                // new channels selection.
                applyFilters();
            });
        }

        // Toggle expand/collapse when clicking on the parent icon.
        $channelsControl.on('click', '.tree-toggle', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $group = $(this).closest('.filter-optiongroup.haschilds');
            $group.toggleClass('expanded');

            var $icon = $group.find('> .filter-option .tree-toggle');
            if ($group.hasClass('expanded')) {
                $icon.removeClass('fa-plus-circle').addClass('fa-minus-circle');
            } else {
                $icon.removeClass('fa-minus-circle').addClass('fa-plus-circle');
            }
        });

        // Keep expansion state in sync with checkbox changes.
        $channelsControl.on('change', 'input.filteroption', function() {
            updateTreeExpansion();
            syncSelectAllChannels();
        });
    }

    if ($pendingcontrol.length) {
        $pendingcontrol.on('change', function() {
            if (!applyPendingChannelPermissions()) {
                return;
            }
            applyFilters();
        });

        if ($pendingcontrol.is(':checked')) {
            applyPendingChannelPermissions();
        }
    }

};
