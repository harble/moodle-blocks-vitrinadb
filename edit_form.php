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
 * Form for editing block instances.
 *
 * @package   block_vitrinadb
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Form for editing block instances.
 *
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_vitrinadb_edit_form extends block_edit_form {
    /**
     * Defines forms elements.
     *
     * @param \moodleform $mform The form to add elements to.
     *
     * @return void
     */
    protected function specific_definition($mform) {
        global $CFG, $DB;

        // Fields for editing HTML block title and contents.
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        $mform->addElement('text', 'config_title', get_string('customtitle', 'block_vitrinadb'));
        $mform->setType('config_title', PARAM_TEXT);

        // Amount of courses shown at instance.
        $mform->addElement('text', 'config_singleamount', get_string('singleamountcourses', 'block_vitrinadb'), ['size' => 2]);
        $mform->setType('config_singleamount', PARAM_INT);
        $mform->setDefault('config_singleamount', 0);
        $mform->addHelpButton('config_singleamount', 'singleamountcourses', 'block_vitrinadb');

        // Tabs.
        $options = [
            '0' => get_string('no'),
            '1' => get_string('yes'),
        ];
        $mform->addElement('select', 'config_default', get_string('defaultsort', 'block_vitrinadb'), $options);
        $mform->setDefault('config_default', 1);

        $mform->addElement('select', 'config_recents', get_string('recents', 'block_vitrinadb'), $options);

        // Show greats tab config only if rating feature exists.
        $ratemanager = \block_vitrinadb\local\controller::get_ratemanager();
        $ratingavailable = $ratemanager::rating_available();

        if ($ratingavailable) {
            $mform->addElement('select', 'config_greats', get_string('greats', 'block_vitrinadb'), $options);
        }

        // Show premium tab config only if premium is available.
        if (\block_vitrinadb\local\controller::premium_available()) {
            $mform->addElement('select', 'config_premium', get_string('premium', 'block_vitrinadb'), $options);
        }

        // Select source course, based on global Categories setting and presence of database activities.
        $sourcecourseoptions = [0 => ''];

        // Read global categories configuration for this block.
        $globalcategories = get_config('block_vitrinadb', 'categories');
        $categoriesids = [];
        if (!empty($globalcategories)) {
            if (is_array($globalcategories)) {
                $categoriesids = array_map('intval', $globalcategories);
            } else {
                $categoriesids = array_filter(array_map('intval', explode(',', (string)$globalcategories)));
            }
        }

        $datamoduleid = $DB->get_field('modules', 'id', ['name' => 'data']);

        if ($datamoduleid) {
            $params = ['siteid' => SITEID, 'now' => time(), 'datamoduleid' => $datamoduleid];
            $categorywhere = '';

            if (!empty($categoriesids)) {
                list($catinsql, $catparams) = $DB->get_in_or_equal($categoriesids, SQL_PARAMS_NAMED, 'cat');
                $categorywhere = " AND c.category $catinsql";
                $params = array_merge($params, $catparams);
            }

            $sql = "SELECT DISTINCT c.id, c.fullname
                      FROM {course_modules} cm
                      JOIN {course} c ON c.id = cm.course
                     WHERE c.id <> :siteid
                       AND c.visible = 1
                       AND (c.enddate > :now OR c.enddate = 0)
                       AND cm.module = :datamoduleid
                       AND cm.deletioninprogress = 0" .
                   $categorywhere .
                   " ORDER BY c.fullname ASC";

            $sourcecourseoptions = [0 => ''] + $DB->get_records_sql_menu($sql, $params);
        }

        $mform->addElement('select', 'config_sourcecourse', get_string('sourcecourse', 'block_vitrinadb'), $sourcecourseoptions);
        $mform->addHelpButton('config_sourcecourse', 'sourcecourse', 'block_vitrinadb');

        // Channels filter (optional, free-text, comma/semicolon separated).
        $mform->addElement('text', 'config_channels', get_string('channels', 'block_vitrinadb'));
        $mform->setType('config_channels', PARAM_TEXT);
        $mform->addHelpButton('config_channels', 'channels', 'block_vitrinadb');

        // Tags filter configuration: choose which item tags are available
        // in the catalog filter. Options include standard tags and tags
        // already used on Database (mod_data) records.
        $tagoptions = [];
        $tagrecords = $DB->get_records_sql(
            "SELECT DISTINCT t.id, t.name
               FROM {tag} t
          LEFT JOIN {tag_instance} ti ON ti.tagid = t.id
                 AND ti.component = 'mod_data'
                 AND ti.itemtype = 'data_records'
              WHERE t.isstandard = 1 OR ti.id IS NOT NULL
           ORDER BY t.name ASC"
        );

        foreach ($tagrecords as $tagrecord) {
            $tagoptions[$tagrecord->id] = $tagrecord->name;
        }

        if (!empty($tagoptions)) {
            $tagselectoptions = [
                'multiple' => true,
                'noselectionstring' => get_string('selecttags', 'block_vitrinadb'),
            ];

            $mform->addElement(
                'autocomplete',
                'config_tags',
                get_string('resourcetagsfilter', 'block_vitrinadb'),
                $tagoptions,
                $tagselectoptions
            );
            $mform->addHelpButton('config_tags', 'resourcetagsfilter', 'block_vitrinadb');
        }

        // Sort by default (per instance, same three modes as global setting).
        $sortOptions = [
            'default' => get_string('sortdefault', 'block_vitrinadb'),
            'alphabetically' => get_string('sortalphabetically', 'block_vitrinadb'),
            'code' => get_string('sortbycode', 'block_vitrinadb'),
        ];
        $mform->addElement('select', 'config_sort', get_string('sortbydefault', 'block_vitrinadb'), $sortOptions);
        // $mform->setDefault('config_sort', 1);
        $mform->addHelpButton('config_sort', 'sortbydefault', 'block_vitrinadb');

        // Sort direction.
        $directionOptions = [
            'asc' => get_string('sortdirection_asc', 'block_vitrinadb'),
            'desc' => get_string('sortdirection_desc', 'block_vitrinadb'),
        ];
        $mform->addElement('select', 'config_sortdirection', get_string('sortdirection', 'block_vitrinadb'), $directionOptions);
        $mform->addHelpButton('config_sortdirection', 'sortdirection', 'block_vitrinadb');

        // Open target type
        $openOptions = [
            '_blank' => get_string('opendetailstarget_blank', 'block_vitrinadb'),
            '_self' => get_string('opendetailstarget_self', 'block_vitrinadb'),
        ];
        $mform->addElement('select', 'config_opendetailstarget', get_string('opendetailstarget', 'block_vitrinadb'), $openOptions);
        // $mform->setDefault('config_opendetailstarget', 1);
        $mform->addHelpButton('config_opendetailstarget', 'opendetailstarget', 'block_vitrinadb');

        // Template type.
        $templOptions = ['default' => get_string('default')];

        $path = $CFG->dirroot . '/blocks/vitrinadb/templates/';
        $files = array_diff(scandir($path), ['..', '.']);

        foreach ($files as $file) {
            if (is_dir($path . $file)) {
                $templOptions[$file] = $file;
            }
        }

        $mform->addElement('select', 'config_templatetype', get_string('templatetype', 'block_vitrinadb'), $templOptions);
        // $mform->setDefault('config_templatetype', 1);
        $mform->addHelpButton('config_templatetype', 'templatetype', 'block_vitrinadb');

        // ------

        $editoroptions = ['maxfiles' => EDITOR_UNLIMITED_FILES, 'noclean' => true, 'context' => $this->block->context];

        // Header HTML editor.
        $mform->addElement('editor', 'config_htmlheader', get_string('htmlheader', 'block_vitrinadb'), null, $editoroptions);
        $mform->setType('config_htmlheader', PARAM_RAW); // XSS is prevented when printing the block contents and serving files.

        // Footer HTML editor.
        $mform->addElement('editor', 'config_htmlfooter', get_string('htmlfooter', 'block_vitrinadb'), null, $editoroptions);
        $mform->setType('config_htmlfooter', PARAM_RAW); // XSS is prevented when printing the block contents and serving files.
    }

    /**
     * Set the data for header and footer html draft.
     *
     * @param array $defaults
     * @return void
     */
    public function set_data($defaults) {

        // Set data for header.
        if (!empty($this->block->config) && !empty($this->block->config->htmlheader)) {
            $htmlheader = $this->block->config->htmlheader;
            $draftidheader = file_get_submitted_draft_itemid('config_htmlheader');
            if (empty($htmlheader)) {
                $currenthtmlheader = '';
            } else {
                $currenthtmlheader = $htmlheader;
            }
            $defaults->config_htmlheader['text'] = file_prepare_draft_area(
                $draftidheader,
                $this->block->context->id,
                'block_vitrinadb',
                'content_header',
                0,
                ['subdirs' => true],
                $currenthtmlheader
            );
            $defaults->config_htmlheader['itemid'] = $draftidheader;
            $defaults->config_htmlheader['format'] = $this->block->config->htmlheaderformat ?? FORMAT_MOODLE;
        } else {
            $htmlheader = '';
        }

        // Set data for footer.
        if (!empty($this->block->config) && !empty($this->block->config->htmlfooter)) {
            $htmlfooter = $this->block->config->htmlfooter;
            $draftidfooter = file_get_submitted_draft_itemid('config_htmlfooter');
            if (empty($htmlfooter)) {
                $currenthtmlfooter = '';
            } else {
                $currenthtmlfooter = $htmlfooter;
            }
            $defaults->config_htmlfooter['text'] = file_prepare_draft_area(
                $draftidfooter,
                $this->block->context->id,
                'block_vitrinadb',
                'content_footer',
                0,
                ['subdirs' => true],
                $currenthtmlfooter
            );
            $defaults->config_htmlfooter['itemid'] = $draftidfooter;
            $defaults->config_htmlfooter['format'] = $this->block->config->htmlfooterformat ?? FORMAT_MOODLE;
        } else {
            $htmlfooter = '';
        }

        unset($this->block->config->htmlheader);
        unset($this->block->config->htmlfooter);
        parent::set_data($defaults);

        // Restore html header and html footer.
        if (!isset($this->block->config)) {
            $this->block->config = new stdClass();
        }
        $this->block->config->htmlheader = $htmlheader;
        $this->block->config->htmlfooter = $htmlfooter;
    }
}
