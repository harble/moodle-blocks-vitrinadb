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
 * External function to get field options from a Database activity.
 *
 * @package    block_vitrinadb
 * @copyright  2024 David Herney @ BambuCo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_vitrinadb\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Service implementation for fetching field options.
 */
class get_field_options extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'fielddescription' => new external_value(PARAM_TEXT, 'Field description to look for (e.g. channels)'),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'value' => new external_value(PARAM_TEXT, 'Option value'),
                'label' => new external_value(PARAM_TEXT, 'Option label'),
            ])
        );
    }

    /**
     * Return field options from the Database activity in the given course.
     *
     * @param int $courseid Course ID
     * @param string $fielddescription Field description to look for
     * @return array List of options
     */
    public static function execute(int $courseid, string $fielddescription): array {
        global $DB;

        self::validate_context(\context_system::instance());

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'fielddescription' => $fielddescription,
        ]);

        $courseid = $params['courseid'];
        $fielddescription = trim((string)$params['fielddescription']);

        if ($courseid <= 0 || $fielddescription === '') {
            return [];
        }

        $datamoduleid = $DB->get_field('modules', 'id', ['name' => 'data']);
        if (!$datamoduleid) {
            return [];
        }

        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.instance
               FROM {course_modules} cm
               JOIN {course} c ON c.id = cm.course
              WHERE c.id = :courseid
                AND c.visible = 1
                AND c.id <> :siteid
                AND (c.enddate > :now OR c.enddate = 0)
                AND cm.module = :datamoduleid
                AND cm.deletioninprogress = 0
           ORDER BY cm.id ASC",
            [
                'courseid' => $courseid,
                'siteid' => SITEID,
                'now' => time(),
                'datamoduleid' => $datamoduleid,
            ],
            IGNORE_MULTIPLE
        );

        if (!$cm || empty($cm->instance)) {
            return [];
        }

        $fields = $DB->get_records('data_fields', ['dataid' => (int)$cm->instance]);
        $targetfield = null;
        foreach ($fields as $field) {
            if (trim((string)$field->description) === $fielddescription) {
                $targetfield = $field;
                break;
            }
        }

        if (!$targetfield) {
            return [];
        }

        $options = [];

        if (!empty($targetfield->param1)) {
            $lines = preg_split('/[\r\n]+/', (string)$targetfield->param1);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $options[] = [
                    'value' => $line,
                    'label' => $line,
                ];
            }
        }

        if (empty($options)) {
            $contents = $DB->get_records('data_content', ['fieldid' => $targetfield->id], '', 'id, content');
            $seen = [];
            foreach ($contents as $content) {
                $parts = \block_vitrinadb\local\controller::normalize_channels_list((string)$content->content);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '' || isset($seen[$part])) {
                        continue;
                    }
                    $seen[$part] = true;
                    $options[] = [
                        'value' => $part,
                        'label' => $part,
                    ];
                }
            }
        }

        return $options;
    }
}
