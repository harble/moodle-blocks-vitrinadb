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
 * Settings for the block.
 *
 * @package   block_vitrinadb
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_vitrinadb\local as localvitrinadb;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/blocks/vitrinadb/classes/local/admin_setting_configmultiselect_autocomplete.php');

if ($ADMIN->fulltree) {
    // Get custom fields.
    $fields = [];
    $fieldstofilter = [];
    $fieldstopremium = [0 => ''];

    $sql = "SELECT cf.id, cf.name, cf.type FROM {customfield_field} cf " .
            " INNER JOIN {customfield_category} cc ON cc.id = cf.categoryid AND cc.component = 'core_course'" .
            " ORDER BY cf.name";
    $customfields = $DB->get_records_sql($sql);

    foreach ($customfields as $k => $v) {
        $fields[$k] = format_string($v->name, true);

        if (in_array($v->type, localvitrinadb\controller::CUSTOMFIELDS_SUPPORTED)) {
            $fieldstofilter[$k] = format_string($v->name, true);
        }

        if ($v->type == 'checkbox') {
            $fieldstopremium[$k] = format_string($v->name, true);
        }
    }

    $fieldswithempty = [0 => ''] + $fields;

    // Get user fields.
    $userfields = [0 => ''];
    $customuserfields = $DB->get_records_menu('user_info_field', null, 'shortname', 'id, shortname');

    foreach ($customuserfields as $k => $v) {
        $userfields[$k] = format_string($v, true);
    }

    // Course fields.
    $name = 'block_vitrinadb/settingsheaderfields';
    $heading = get_string('settingsheaderfields', 'block_vitrinadb');
    $setting = new admin_setting_heading($name, $heading, '');
    $settings->add($setting);

    // Only available if exist course custom fields.
    if (count($fields) > 0) {
        // Short fields.
        $name = 'block_vitrinadb/showcustomfields';
        $title = get_string('showcustomfields', 'block_vitrinadb');
        $help = get_string('showcustomfields_help', 'block_vitrinadb');
        $setting = new admin_setting_configmultiselect($name, $title, $help, [], $fields);
        $settings->add($setting);

        // Long fields.
        $name = 'block_vitrinadb/showlongcustomfields';
        $title = get_string('showlongcustomfields', 'block_vitrinadb');
        $help = get_string('showlongcustomfields_help', 'block_vitrinadb');
        $setting = new admin_setting_configmultiselect($name, $title, $help, [], $fields);
        $settings->add($setting);
    }

    // License field.
    $name = 'block_vitrinadb/license';
    $title = get_string('licensefield', 'block_vitrinadb');
    $help = get_string('licensefield_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 0, $fieldswithempty);
    $settings->add($setting);

    // Media field.
    $name = 'block_vitrinadb/media';
    $title = get_string('mediafield', 'block_vitrinadb');
    $help = get_string('mediafield_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 0, $fieldswithempty);
    $settings->add($setting);

    // Payment fields.
    $name = 'block_vitrinadb/settingsheaderpayment';
    $heading = get_string('settingsheaderpayment', 'block_vitrinadb');
    $setting = new admin_setting_heading($name, $heading, '');
    $settings->add($setting);

    // Payment url field.
    $name = 'block_vitrinadb/paymenturl';
    $title = get_string('paymenturlfield', 'block_vitrinadb');
    $help = get_string('paymenturlfield_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 0, $fieldswithempty);
    $settings->add($setting);

    // Premium course field. Only checkbox fields are allowed.
    $name = 'block_vitrinadb/premiumcoursefield';
    $title = get_string('premiumcoursefield', 'block_vitrinadb');
    $help = get_string('premiumcoursefield_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 0, $fieldstopremium);
    $settings->add($setting);

    // Premium type user field.
    $name = 'block_vitrinadb/premiumfield';
    $title = get_string('premiumfield', 'block_vitrinadb');
    $help = get_string('premiumfield_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 0, $userfields);
    $settings->add($setting);

    // Premium type value.
    $name = 'block_vitrinadb/premiumvalue';
    $title = get_string('premiumvalue', 'block_vitrinadb');
    $help = get_string('premiumvalue_help', 'block_vitrinadb');
    $setting = new admin_setting_configtext($name, $title, $help, '');
    $settings->add($setting);

    // Select course for premium.
    $name = 'block_vitrinadb/premiumenrolledcourse';
    $title = get_string('premiumenrolledcourse', 'block_vitrinadb');
    $help = get_string('premiumenrolledcourse_help', 'block_vitrinadb');
    $displaylist = $DB->get_records_menu('course', null, 'fullname', 'id, fullname');
    $default = [];
    $setting = new localvitrinadb\admin_setting_configmultiselect_autocomplete($name, $title, $help, $default, $displaylist);
    $settings->add($setting);

    // Cohort to recognize premium self enrolment.
    $cohorts = [0 => ''] + $DB->get_records_menu('cohort', ['visible' => 1], 'name', 'id, name');
    $name = 'block_vitrinadb/premiumcohort';
    $title = get_string('premiumcohort', 'block_vitrinadb');
    $help = get_string('premiumcohort_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 0, $cohorts);
    $settings->add($setting);

    // Decimal points.
    $options = [
        '0' => '0',
        '1' => '1',
        '2' => '2',
        '3' => '3',
        '4' => '4',
        '5' => '5',
    ];
    $name = 'block_vitrinadb/decimalpoints';
    $title = get_string('decimalpoints', 'block_vitrinadb');
    $help = get_string('decimalpoints_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 2, $options);
    $settings->add($setting);

    // Filtering.
    $name = 'block_vitrinadb/settingsheaderfiltering';
    $heading = get_string('settingsheaderfiltering', 'block_vitrinadb');
    $setting = new admin_setting_heading($name, $heading, '');
    $settings->add($setting);

    // Select courses categories.
    $name = 'block_vitrinadb/categories';
    $title = get_string('categories', 'block_vitrinadb');
    $help = get_string('categories_help', 'block_vitrinadb');
    $displaylist = \core_course_category::make_categories_list('moodle/category:manage');
    $default = [];
    $setting = new localvitrinadb\admin_setting_configmultiselect_autocomplete($name, $title, $help, $default, $displaylist);
    $settings->add($setting);

    // General filters.
    $staticfilters = [
                        'fulltext' => get_string('fulltextsearch', 'block_vitrinadb'),
                        'categories' => get_string('category'),
                        'langs' => get_string('language'),
                    ];
    $name = 'block_vitrinadb/staticfilters';
    $title = get_string('staticfilters', 'block_vitrinadb');
    $help = get_string('staticfilters_help', 'block_vitrinadb');
    $setting = new admin_setting_configmultiselect($name, $title, $help, [], $staticfilters);
    $settings->add($setting);

    // Category filter view.
    $catfilterviews = [
        'default' => new lang_string('catfilterview_default', 'block_vitrinadb'),
        'tree' => new lang_string('catfilterview_tree', 'block_vitrinadb'),
    ];
    $name = 'block_vitrinadb/catfilterview';
    $title = get_string('catfilterview', 'block_vitrinadb');
    $help = get_string('catfilterview_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 'default', $catfilterviews);
    $settings->add($setting);

    // Only availabe if exist fields to filter.
    if (count($fieldstofilter) > 0) {
        // Custom fields to filter.
        $name = 'block_vitrinadb/filtercustomfields';
        $title = get_string('filtercustomfields', 'block_vitrinadb');
        $help = get_string('filtercustomfields_help', 'block_vitrinadb');
        $setting = new admin_setting_configmultiselect($name, $title, $help, [], $fieldstofilter);
        $settings->add($setting);
    }

    // Appearance.
    $name = 'block_vitrinadb/settingsheaderappearance';
    $heading = get_string('settingsheaderappearance', 'block_vitrinadb');
    $setting = new admin_setting_heading($name, $heading, '');
    $settings->add($setting);

    // Courses in block view.
    $name = 'block_vitrinadb/singleamount';
    $title = get_string('singleamountcourses', 'block_vitrinadb');
    $help = get_string('singleamountcourses_help', 'block_vitrinadb');
    $setting = new admin_setting_configtext($name, $title, $help, 4, PARAM_INT, 2);
    $settings->add($setting);

    // Courses by page.
    $name = 'block_vitrinadb/amount';
    $title = get_string('amountcourses', 'block_vitrinadb');
    $help = get_string('amountcourses_help', 'block_vitrinadb');
    $setting = new admin_setting_configtext($name, $title, $help, 20, PARAM_INT, 5);
    $settings->add($setting);

    // Related courses.
    $name = 'block_vitrinadb/relatedlimit';
    $title = get_string('relatedlimit', 'block_vitrinadb');
    $help = get_string('relatedlimit_help', 'block_vitrinadb');
    $setting = new admin_setting_configtext($name, $title, $help, 3, PARAM_INT, 5);
    $settings->add($setting);

    // Sort by default (only the supported resource sort modes).
    $options = [
        'default' => get_string('sortdefault', 'block_vitrinadb'),
        'alphabetically' => get_string('sortalphabetically', 'block_vitrinadb'),
        'code' => get_string('sortbycode', 'block_vitrinadb'),
    ];

    $name = 'block_vitrinadb/sortbydefault';
    $title = get_string('sortbydefault', 'block_vitrinadb');
    $help = get_string('sortbydefault_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 'default', $options);
    $settings->add($setting);

    // Sort direction.
    $name = 'block_vitrinadb/sortdirection';
    $title = get_string('sortdirection', 'block_vitrinadb');
    $help = get_string('sortdirection_help', 'block_vitrinadb');
    $options = [
        'asc' => get_string('sortdirection_asc', 'block_vitrinadb'),
        'desc' => get_string('sortdirection_desc', 'block_vitrinadb'),
    ];
    $setting = new admin_setting_configselect($name, $title, $help, 'asc', $options);
    $settings->add($setting);

    // Code field for sorting (only if code fields are available).
    if (count($fields) > 0) {
        $name = 'block_vitrinadb/codefield';
        $title = get_string('codefield', 'block_vitrinadb');
        $help = get_string('codefield_help', 'block_vitrinadb');
        $setting = new admin_setting_configselect($name, $title, $help, 0, $fieldswithempty);
        $settings->add($setting);
    }

    $name = 'block_vitrinadb/opendetailstarget';
    $title = get_string('opendetailstarget', 'block_vitrinadb');
    $help = get_string('opendetailstarget_help', 'block_vitrinadb');
    $options = [
        '_blank' => get_string('opendetailstarget_blank', 'block_vitrinadb'),
        '_self' => get_string('opendetailstarget_self', 'block_vitrinadb'),
    ];
    $setting = new admin_setting_configselect($name, $title, $help, '_blank', $options);
    $settings->add($setting);

    // Days to upcoming courses.
    $name = 'block_vitrinadb/daystoupcoming';
    $title = get_string('daystoupcoming', 'block_vitrinadb');
    $help = get_string('daystoupcoming_help', 'block_vitrinadb');
    $setting = new admin_setting_configtext($name, $title, $help, 0, PARAM_INT, 3);
    $settings->add($setting);

    // Social networks.
    $name = 'block_vitrinadb/networks';
    $title = get_string('socialnetworks', 'block_vitrinadb');
    $help = get_string('socialnetworks_help', 'block_vitrinadb');
    $setting = new admin_setting_configtextarea($name, $title, $help, '');
    $settings->add($setting);

    // Block summary.
    $name = 'block_vitrinadb/summary';
    $title = get_string('summary', 'block_vitrinadb');
    $help = get_string('summary_help', 'block_vitrinadb');
    $setting = new admin_setting_confightmleditor($name, $title, $help, '');
    $settings->add($setting);

    // Block detail info.
    $name = 'block_vitrinadb/detailinfo';
    $title = get_string('detailinfo', 'block_vitrinadb');
    $help = get_string('detailinfo_help', 'block_vitrinadb');
    $setting = new admin_setting_confightmleditor($name, $title, $help, '');
    $settings->add($setting);

    // Tabs view.
    $options = [
        'default' => get_string('textandicon', 'block_vitrinadb'),
        'showtext' => get_string('showtext', 'block_vitrinadb'),
        'showicon' => get_string('showicon', 'block_vitrinadb'),
    ];

    $name = 'block_vitrinadb/tabview';
    $title = get_string('tabview', 'block_vitrinadb');
    $help = get_string('tabview_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 'default', $options);
    $settings->add($setting);

    // Views icons.
    $name = 'block_vitrinadb/viewsicons';
    $title = get_string('viewsicons', 'block_vitrinadb');
    $help = get_string('viewsicons_help', 'block_vitrinadb');
    $setting = new admin_setting_configtextarea($name, $title, $help, '');
    $settings->add($setting);

    // Cover image type.
    $options = [
        'default' => get_string('coverimagetype_default', 'block_vitrinadb'),
        'generated' => get_string('coverimagetype_generated', 'block_vitrinadb'),
        'none' => get_string('coverimagetype_none', 'block_vitrinadb'),
    ];

    $name = 'block_vitrinadb/coverimagetype';
    $title = get_string('coverimagetype', 'block_vitrinadb');
    $help = get_string('coverimagetype_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 'default', $options);
    $settings->add($setting);

    // Template type.
    $options = ['default' => get_string('default')];

    $path = $CFG->dirroot . '/blocks/vitrinadb/templates/';
    $files = array_diff(scandir($path), ['..', '.']);

    foreach ($files as $file) {
        if (is_dir($path . $file)) {
            $options[$file] = $file;
        }
    }

    $name = 'block_vitrinadb/templatetype';
    $title = get_string('templatetype', 'block_vitrinadb');
    $help = get_string('templatetype_help', 'block_vitrinadb');
    $setting = new admin_setting_configselect($name, $title, $help, 'default', $options);
    $settings->add($setting);

    // Rating components.
    $options = [];

    if (localvitrinadb\rating\base::rating_available()) {
        $options['block_rate_course'] = get_string('pluginname', 'block_rate_course') . ' (block_rate_course)';
    }

    if (localvitrinadb\rating\tool_courserating::rating_available()) {
        $options['tool_courserating'] = get_string('pluginname', 'tool_courserating') . ' (tool_courserating)';
    }

    if (count($options) > 0) {
        $name = 'block_vitrinadb/ratingmanager';
        $title = get_string('ratingmanager', 'block_vitrinadb');
        $help = get_string('ratingmanager_help', 'block_vitrinadb');
        $setting = new admin_setting_configselect($name, $title, $help, '', $options);
        $settings->add($setting);
    }

    // Comments components.
    $options = [];

    if (localvitrinadb\comments\base::comments_available()) {
        $options['block_comments'] = get_string('pluginname', 'block_comments') . ' (block_comments)';
    }

    if (localvitrinadb\comments\tool_courserating::comments_available()) {
        $options['tool_courserating'] = get_string('pluginname', 'tool_courserating') . ' (tool_courserating)';
    }

    if (count($options) > 0) {
        $defaultvalue = key($options);
        $name = 'block_vitrinadb/commentsmanager';
        $title = get_string('commentsmanager', 'block_vitrinadb');
        $help = get_string('commentsmanager_help', 'block_vitrinadb');
        $setting = new admin_setting_configselect($name, $title, $help, $defaultvalue, $options);
        $settings->add($setting);
    }

    // Shop components.
    $options = [];

    if (localvitrinadb\shop\local_buybee::available()) {
        $options['local_buybee'] = get_string('pluginname', 'local_buybee') . ' (local_buybee)';
    }

    if (localvitrinadb\shop\local_bazaar::available()) {
        $options['local_bazaar'] = get_string('pluginname', 'local_bazaar') . ' (local_bazaar)';
    }

    if (count($options) > 0) {
        $name = 'block_vitrinadb/shopmanager';
        $title = get_string('shopmanager', 'block_vitrinadb');
        $help = get_string('shopmanager_help', 'block_vitrinadb');
        $setting = new admin_setting_configselect($name, $title, $help, '', $options);
        $settings->add($setting);
    }
}
