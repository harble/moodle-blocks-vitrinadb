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
 * This class contains the VitrinaDb courses webservice functions.
 *
 * @package    block_vitrinadb
 * @copyright  2024 David Herney @ BambuCo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_vitrinadb\external;

use external_api;
use external_function_parameters;
use external_value;
use external_multiple_structure;
use external_single_structure;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/login/lib.php');

/**
 * Service implementation.
 *
 * @copyright   2024 David Herney - cirano
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_courses extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'view' => new external_value(PARAM_TEXT, 'Courses view', VALUE_DEFAULT, 'default'),
                'filters' => new external_multiple_structure(
                    new \external_single_structure(
                        [
                            'type' => new external_value(PARAM_TEXT, 'Filter type key'),
                            'values' => new external_multiple_structure(
                                new external_value(PARAM_TEXT, 'Filter value'),
                            ),
                        ],
                        'A filter to apply'
                    ),
                    'List of filters to search the courses',
                    VALUE_DEFAULT,
                    []
                ),
                'instanceid' => new external_value(PARAM_INT, 'Block instance id', VALUE_DEFAULT, 0),
                'amount' => new external_value(PARAM_INT, 'Amount of courses', VALUE_DEFAULT, 0),
                'initial' => new external_value(PARAM_INT, 'From where to start', VALUE_DEFAULT, 0),
                'sort' => new external_value(PARAM_TEXT, 'Sort key', VALUE_DEFAULT, ''),
                'sortdirection' => new external_value(PARAM_TEXT, 'Sort direction (asc or desc)', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Return a courses list.
     *
     * @param string $view Courses view type
     * @param array $filters List of filters to search the courses
     * @param int $instanceid Block instance id
     * @param int $amount Amount of courses
     * @param int $initial From where to start
     * @param string $sort Sort key
     * @param string $sortdirection Sort direction (asc or desc)
     * @return array Courses list
     */
    public static function execute(
        string $view = 'default',
        array $filters = [],
        int $instanceid = 0,
        int $amount = 0,
        int $initial = 0,
        string $sort = '',
        string $sortdirection = ''
    ): array {

        global $PAGE, $CFG, $DB;

        if (!isloggedin() && empty($CFG->guestloginbutton) && empty($CFG->autologinguests)) {
            require_login(null, true);
        }

        $syscontext = \context_system::instance();
        // The self::validate_context($syscontext) is not used because we require show the courses
        // to unauthenticated user in some pages. The security is managed locally.
        $PAGE->set_context($syscontext);

        // Parameter validation.
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'view' => $view,
                'filters' => $filters,
                'instanceid' => $instanceid,
                'amount' => $amount,
                'initial' => $initial,
                'sort' => $sort,
                'sortdirection' => $sortdirection,
            ]
        );

        // Read the categories if is a block instance call or the filter by categories is defined.
        $categoriesids = [];

        // Detect explicit "no channels selected" filter (channels present with
        // an empty values array). In that case, we must return no resources
        // instead of falling back to the block default channels.
        $explicitemptychannels = false;
        foreach ($params['filters'] as $filter) {
            if (!empty($filter['type']) && $filter['type'] === 'channels') {
                if (empty($filter['values'])) {
                    $explicitemptychannels = true;
                    break;
                }
            }
        }

        if ($explicitemptychannels) {
            return [];
        }

        foreach ($params['filters'] as $filter) {
            if ($filter['type'] == 'categories') {
                $categoriesids = $filter['values'];

                // Remove filter.
                $params['filters'] = array_filter($params['filters'], function ($filter) {
                    return $filter['type'] != 'categories';
                });

                // Cast to int.
                $categoriesids = array_map('intval', $categoriesids);

                // Remove duplicates.
                $categoriesids = array_unique($categoriesids);

                // Remove empty values.
                $categoriesids = array_filter($categoriesids);

                break;
            }
        }

        $sort = $params['sort'];
        $sortdirection = $params['sortdirection'];

        if (count($categoriesids) == 0) {
            if (!empty($params['instanceid'])) {
                $block = block_instance_by_id($params['instanceid']);

                if ($block->config && count($block->config->categories) > 0) {
                    $categoriesids = $block->config->categories;
                }

                if ($block->config && $block->config->sort != '' && empty($sort)) {
                    $sort = $block->config->sort;
                }

                if ($block->config && !empty($block->config->sortdirection) && empty($sortdirection)) {
                    $sortdirection = $block->config->sortdirection;
                }
            }
        }
        // End of read categories.

        // Read channels filter from block instance config (if provided).
        if (!empty($params['instanceid'])) {
            if (!isset($block)) {
                $block = block_instance_by_id($params['instanceid']);
            }

            if ($block && !empty($block->config) && !empty($block->config->channels)) {
                $configuredchannels = \block_vitrinadb\local\controller::normalize_channels_list((string)$block->config->channels);

                if (!empty($configuredchannels)) {
                    // Only add a channels filter if one is not already present.
                    $haschannelfilter = false;
                    foreach ($params['filters'] as $filter) {
                        if (!empty($filter['type']) && $filter['type'] === 'channels') {
                            $haschannelfilter = true;
                            break;
                        }
                    }

                    if (!$haschannelfilter) {
                        $params['filters'][] = [
                            'type' => 'channels',
                            'values' => $configuredchannels,
                        ];
                    }
                }
            }
        }

        $pagedresources = [];

        // No categories resolved means nothing to search.
        if (!empty($categoriesids)) {
            // Get "data" module id.
            $datamoduleid = $DB->get_field('modules', 'id', ['name' => 'data']);

            if ($datamoduleid) {
                [$catinsql, $catparams] = $DB->get_in_or_equal($categoriesids, SQL_PARAMS_NAMED, 'cat');

                $paramsdb = $catparams;
                $paramsdb['siteid'] = SITEID;
                $paramsdb['now'] = time();
                $paramsdb['datamoduleid'] = $datamoduleid;

                $sql = "SELECT cm.id, cm.course, cm.instance
                          FROM {course_modules} cm
                          JOIN {course} c ON c.id = cm.course
                         WHERE c.category $catinsql
                           AND c.visible = 1
                           AND c.id <> :siteid
                           AND (c.enddate > :now OR c.enddate = 0)
                           AND cm.module = :datamoduleid
                           AND cm.deletioninprogress = 0
                      ORDER BY cm.id ASC";

                // First database activity across all matching courses.
                if ($firstcm = $DB->get_record_sql($sql, $paramsdb, IGNORE_MULTIPLE)) {
                    if ($course = $DB->get_record('course', ['id' => $firstcm->course])) {
                        $pagedresources = \block_vitrinadb\local\controller::get_course_resources(
                            $course,
                            (int)$firstcm->instance,
                            $params['view'],
                            $params['filters'],
                            $sort,
                            $sortdirection,
                            $params['amount'],
                            $params['initial']
                        );
                    }
                }
            }
        }

        $response = [];
        $renderer = $PAGE->get_renderer('block_vitrinadb');

        foreach ($pagedresources as $resource) {
            // Build a minimal object compatible with course templates.
            $item = new \stdClass();
            // Keep ID as the parent course id so existing links to detail.php
            // continue to work (they will open the course that owns this resource).
            $item->id = $resource->courseid;
            $item->category = $resource->category;
            $item->baseurl = $CFG->wwwroot;
            $item->fullname = format_string($resource->subject, true);
            $item->shortname = $item->fullname;
            $item->summary = self::sanitize_summary($resource->summary);
            $item->hassummary = !empty($item->summary);
            $item->imagepath = $resource->imagepath;

            // Resource specific metadata used by templates.
            $item->sharefiletype = $resource->sharefiletype;
            $item->sharefiletypelabel = $resource->sharefiletypelabel;
            $item->sharefileicon = $resource->sharefileicon;
            $item->sharefiletitle = $resource->sharefiletitle ?? '';
            $item->sharedbyname = $resource->sharedbyname;
            $item->sharedbyavatar = $resource->sharedbyavatar;
            $item->shareddayslabel = $resource->shareddayslabel;
            // Only show the pinned badge in the "All courses" (default) view.
            $item->pinned = ($view === 'default') && !empty($resource->pinned);

            // Rating information coming from the database activity.
            $item->hasrating = !empty($resource->hasrating) && !empty($resource->rating);
            $item->rating = $item->hasrating ? $resource->rating : null;

            // Fields used by templates but not relevant for resources.
            $item->active = true;
            $item->premium = false;
            $item->completed = null;
            $item->progress = null;
            $item->hasprogress = false;
            $item->fee = null;
            $item->hascart = false;
            $item->instanceid = $instanceid;

            $renderedcourse = new \stdClass();
            $renderedcourse->id = $item->id;
            $renderedcourse->html = $renderer->render_course($item);

            $response[] = $renderedcourse;
        }

        return $response;
    }

    /**
     * Remove auto-loading internal media references from a summary HTML fragment.
     *
     * Any media elements which point at pluginfile.php are stripped so that the
     * browser does not perform background requests to protected files, which can
     * otherwise interfere with login redirect behaviour when sessions expire.
     *
     * @param string $summary
     * @return string
     */
    private static function sanitize_summary(string $summary): string {
        global $CFG;

        if ($summary === '') {
            return $summary;
        }

        $pluginfilebase = preg_quote($CFG->wwwroot . '/pluginfile.php', '/');

        // Remove tags whose media-related attributes point directly to pluginfile.php.
        // Tags: img, video, audio, iframe, embed, object, source.
        // Attributes: src, srcset, data, poster.
        $summary = preg_replace(
            '/<(img|video|audio|iframe|embed|object|source)[^>]+'
            . '(src|srcset|data|poster)\s*=\s*"' . $pluginfilebase . '[^" ]*"[^>]*>/i',
            '',
            $summary
        );
        $summary = preg_replace(
            "/<(img|video|audio|iframe|embed|object|source)[^>]+"
            . "(src|srcset|data|poster)\s*=\s*'" . $pluginfilebase . "[^' ]*'[^>]*>/i",
            '',
            $summary
        );

        // Remove entire <audio>...</audio> or <video>...</video> blocks which contain
        // any pluginfile.php URL inside their content (as in the provided audio example
        // where the URL appears as inner text rather than an attribute).
        $summary = preg_replace(
            '/<audio\b[^>]*>.*?' . $pluginfilebase . '.*?<\/audio>/is',
            '',
            $summary
        );
        $summary = preg_replace(
            '/<video\b[^>]*>.*?' . $pluginfilebase . '.*?<\/video>/is',
            '',
            $summary
        );

        // Finally, remove any bare pluginfile.php URLs that might remain in text nodes.
        $summary = preg_replace(
            '/' . $pluginfilebase . '[^\s"<\']*/i',
            '',
            $summary
        );

        return $summary;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, 'Course id'),
                    'html' => new external_value(PARAM_RAW, 'HTML with course information'),
                ]
            ),
            'List of courses'
        );
    }
}
