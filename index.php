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
$authorid = optional_param('author', 0, PARAM_INT);
$pendingflag = optional_param('pending', 0, PARAM_INT);

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

// Preselect author filter from URL parameter when provided. This allows
// links like .../index.php?author=123 to open the catalog already filtered
// by that user in the Author dropdown.
if (!empty($authorid)) {
    $hasauthorfilter = false;
    foreach ($filtersselected as $selected) {
        if ($selected->key === 'author') {
            $hasauthorfilter = true;
            break;
        }
    }

    if (!$hasauthorfilter) {
        $filtersselected[] = (object) ['key' => 'author', 'values' => [(string)$authorid]];
    }
}

// Preselect pending approval filter from URL parameter when provided for
// site administrators. This allows links like .../index.php?pending=1 to
// open the catalog already restricted to only pending approval records.
if (!empty($pendingflag) && (int)$pendingflag === 1 && is_siteadmin()) {
    $haspendingfilter = false;
    foreach ($filtersselected as $selected) {
        if ($selected->key === 'pending') {
            $haspendingfilter = true;
            break;
        }
    }

    if (!$haspendingfilter) {
        $filtersselected[] = (object) ['key' => 'pending', 'values' => ['1']];
    }
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
    $catfilterview = get_config('block_vitrinadb', 'catfilterview');
    $nested = ($catfilterview == 'tree');

    $allchannels = \block_vitrinadb\local\controller::get_channels_filter_options((int)$instanceid, $nested);
    if (!empty($allchannels)) {
        $values = [];

        // Collect all channel values, including children in tree mode.
        $collectvalues = function(array $options, array &$acc) use (&$collectvalues) {
            foreach ($options as $opt) {
                if (!empty($opt['value'])) {
                    $acc[] = (string)$opt['value'];
                }
                if (!empty($opt['childs']) && is_array($opt['childs'])) {
                    $collectvalues($opt['childs'], $acc);
                }
            }
        };

        $collectvalues($allchannels, $values);

        if (!empty($values)) {
            $values = array_values(array_unique($values));
            $filtersselected[] = (object) ['key' => 'channels', 'values' => $values];
        }
    }
}

// Build catalog header metadata: [category] / [database activity name], and
// capture the related course so we can show its cover image on the catalog
// page header.
$catalogmeta = '';
$catalogcourseimage = '';
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

                // Load the full course record to obtain its *explicit* cover image
                // (course overview image). Do not use generated or default images
                // here so that the catalog header image only appears when the
                // course has a real cover selected by the user.
                $course = get_course($firstcm->course);
                if ($course) {
                    global $CFG;

                    $coursefull = new \core_course_list_element($course);
                    foreach ($coursefull->get_course_overviewfiles() as $file) {
                        if ($file->is_valid_image()) {
                            $urlpath = '/' . $file->get_contextid() . '/' . $file->get_component() . '/';
                            $urlpath .= $file->get_filearea() . $file->get_filepath() . $file->get_filename();

                            $url = \moodle_url::make_file_url(
                                "$CFG->wwwroot/pluginfile.php",
                                $urlpath,
                                false
                            );

                            $catalogcourseimage = (string)$url;
                            break;
                        }
                    }
                }
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

// Show the course cover image (if any) for the course that owns the
// configured Database activity. It is displayed below the page header
// (and catalog meta) and above the summary HTML.
if (!empty($catalogcourseimage)) {
    $img = html_writer::empty_tag('img', [
        'src' => $catalogcourseimage,
        'alt' => '',
        'class' => 'vitrinadb-catalog-courseimage-img',
    ]);
    echo html_writer::div($img, 'vitrinadb-catalog-courseimage');
}

echo format_text($summary, FORMAT_HTML, ['trusted' => true, 'noclean' => true]);

$renderable = new \block_vitrinadb\output\catalog($uniqueid, $view, $instanceid);
$renderer = $PAGE->get_renderer('block_vitrinadb');

echo $renderer->render($renderable);

echo $OUTPUT->footer();
