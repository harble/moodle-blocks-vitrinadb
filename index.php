<?php
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
 * List of available courses.

 * @package   block_vitrinadb
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('classes/output/catalog.php');

$instanceid = optional_param('id', 0, PARAM_INT);
$view = optional_param('view', 'default', PARAM_TEXT);
$filters = optional_param('filters', '', PARAM_TEXT);
$q = optional_param('q', '', PARAM_TEXT);

require_login(null, true);

$syscontext = context_system::instance();

$PAGE->set_context($syscontext);
$PAGE->set_url('/blocks/vitrinadb/index.php');
$PAGE->set_pagelayout('incourse');
$PAGE->set_heading(get_string('catalog', 'block_vitrinadb'));
$PAGE->set_title(get_string('catalog', 'block_vitrinadb'));

$uniqueid = \block_vitrinadb\local\controller::get_uniqueid();
\block_vitrinadb\local\controller::include_templatecss($instanceid);

$bypage = get_config('block_vitrinadb', 'amount');
if (empty($bypage)) {
    $bypage = 20;
}

$filtersselected = [];

if (!empty($filters)) {
    $filters = explode(';', $filters);

    $configuredcustomfields = \block_vitrinadb\local\controller::get_configuredcustomfields();
    $staticfilters = \block_vitrinadb\local\controller::get_staticfilters();

    foreach ($filters as $filter) {
        $filter = explode(':', $filter);

        if (count($filter) == 2) {
            $key = trim($filter[0]);

            // If the filter is categories and the block is configured to show specific categories, we ignore the filter.
            if ($key == 'categories' && !empty($instanceid)) {
                continue;
            }

            if (!in_array($key, $staticfilters) && !is_numeric($key)) {
                foreach ($configuredcustomfields as $customfield) {
                    if ($customfield->shortname == $key) {
                        $key = $customfield->id;
                        break;
                    }
                }
            }

            if ($key) {
                $filtersselected[] = (object) ['key' => $key, 'values' => explode(',', $filter[1])];
            }
        }
    }
}

if (!empty($q)) {
    $filtersselected[] = (object) ['key' => 'fulltext', 'values' => [$q]];
}

$categoriesids = [];
if (!empty($instanceid)) {
    $block = block_instance_by_id($instanceid);

    if ($block->config && count($block->config->categories) > 0) {
        $categoriesids = $block->config->categories;
    }
}

if (count($categoriesids) > 0) {
    $filtersselected[] = (object) ['key' => 'categories', 'values' => $categoriesids];
}

// Preselect channels filter from block configuration (Channels filter setting)
// when opening the catalog via "view all", so that the checkbox list on the
// left matches the resources already being filtered by channels.
if (!empty($instanceid)) {
    if (!isset($block)) {
        $block = block_instance_by_id($instanceid);
    }

    if ($block && !empty($block->config) && !empty($block->config->channels)) {
        $configuredchannels = \block_vitrinadb\local\controller::normalize_channels_list((string)$block->config->channels);

        if (!empty($configuredchannels)) {
            $filtersselected[] = (object) ['key' => 'channels', 'values' => $configuredchannels];
        }
    }
}

// If no channels filter has been defined (neither via URL filters nor block
// instance configuration), preselect all available channels so that the UI
// state matches the "search in all channels" behaviour.
$haschannelfilter = false;
foreach ($filtersselected as $selected) {
    if ($selected->key === 'channels') {
        $haschannelfilter = true;
        break;
    }
}

if (!$haschannelfilter && !empty($instanceid)) {
    $allchannels = \block_vitrinadb\local\controller::get_channels_filter_options((int)$instanceid);
    if (!empty($allchannels)) {
        $values = [];
        foreach ($allchannels as $opt) {
            if (!empty($opt['value'])) {
                $values[] = (string)$opt['value'];
            }
        }
        if (!empty($values)) {
            $values = array_values(array_unique($values));
            $filtersselected[] = (object) ['key' => 'channels', 'values' => $values];
        }
    }
}

// Build catalog header metadata: [category] / [database activity name].
$catalogmeta = '';
if (!empty($instanceid)) {
    global $DB;

    // Determine categories to look in (same logic as the external service).
    $metaCategories = [];
    if (!empty($categoriesids)) {
        $metaCategories = $categoriesids;
    } else if (!empty($block) && $block->config && !empty($block->config->categories)) {
        $metaCategories = $block->config->categories;
    }

    $metaCategories = array_map('intval', $metaCategories);
    $metaCategories = array_filter($metaCategories);

    if (!empty($metaCategories)) {
        $datamoduleid = $DB->get_field('modules', 'id', ['name' => 'data']);

        if ($datamoduleid) {
            list($catinsql, $catparams) = $DB->get_in_or_equal($metaCategories, SQL_PARAMS_NAMED, 'cat');

            $paramsdb = $catparams;
            $paramsdb['siteid'] = SITEID;
            $paramsdb['now'] = time();
            $paramsdb['datamoduleid'] = $datamoduleid;

            $sql = "SELECT cm.id, cm.course, cm.instance,
                           c.category AS categoryid,
                           c.fullname AS coursename,
                           cc.name AS categoryname,
                           d.name AS dataname
                      FROM {course_modules} cm
                      JOIN {course} c ON c.id = cm.course
                      JOIN {course_categories} cc ON cc.id = c.category
                      JOIN {data} d ON d.id = cm.instance
                     WHERE c.category $catinsql
                       AND c.visible = 1
                       AND c.id <> :siteid
                       AND (c.enddate > :now OR c.enddate = 0)
                       AND cm.module = :datamoduleid
                       AND cm.deletioninprogress = 0
                  ORDER BY cm.id ASC";

            if ($firstcm = $DB->get_record_sql($sql, $paramsdb, IGNORE_MULTIPLE)) {
                $catname = format_string($firstcm->categoryname, true);
                $coursename = format_string($firstcm->coursename, true);
                $dataname = format_string($firstcm->dataname, true);
                $catalogmeta = $catname . ' / ' . $coursename . ' / ' . $dataname;
            }
        }
    }

    if ($catalogmeta !== '') {
        $PAGE->requires->js_init_code("require(['jquery'], function(\$) {
            var meta = $('.vitrinadb-catalog-meta').first();
            var title = $('#page-header h1').first();
            if (meta.length && title.length) {
                meta.insertAfter(title);
            }
        });");
    }
}

$PAGE->requires->js_call_amd('block_vitrinadb/main', 'filters', [$uniqueid, $filtersselected]);
$PAGE->requires->js_call_amd('block_vitrinadb/main', 'catalog', [$uniqueid, $view, $instanceid, $bypage]);

echo $OUTPUT->header();

$summary = get_config('block_vitrinadb', 'summary');

if ($catalogmeta !== '') {
    echo html_writer::tag('div', s($catalogmeta), ['class' => 'vitrinadb-catalog-meta']);
}

echo format_text($summary, FORMAT_HTML, ['trusted' => true, 'noclean' => true]);

$renderable = new \block_vitrinadb\output\catalog($uniqueid, $view, $instanceid);
$renderer = $PAGE->get_renderer('block_vitrinadb');

echo $renderer->render($renderable);

echo $OUTPUT->footer();
