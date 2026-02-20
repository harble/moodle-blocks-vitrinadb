<?php
// This file is part of Moodle - http://moodle.org/.
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
 * Form for editing vitrinadb block instances.
 *
 * @package   block_vitrinadb
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Class containing block base implementation for VitrinaDb.
 *
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_vitrinadb extends block_base {
    /**
     * Initialise the block.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_vitrinadb');
    }

    /**
     * Subclasses should override this and return true if the
     * subclass block has a settings.php file.
     *
     * @return boolean
     */
    public function has_config() {
        return true;
    }

    /**
     * Which page types this block may appear on.
     *
     * @return array page-type prefix => true/false.
     */
    public function applicable_formats() {
        return ['all' => true];
    }

    /**
     * This function is called on your subclass right after an instance is loaded.
     */
    public function specialization() {
        if (isset($this->config->title)) {
            $this->title = format_string($this->config->title, true, ['context' => $this->context]);
        } else {
            $this->title = get_string('newblocktitle', 'block_vitrinadb');
        }
    }

    /**
     * Are you going to allow multiple instances of each block?
     *
     * @return boolean
     */
    public function instance_allow_multiple() {
        return true;
    }

    /**
     * Implemented to return the content object.
     *
     * @return stdClass
     */
    public function get_content() {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        // Security validation. If not logged in and guest login button is disabled, do not show courses.
        if (!isloggedin() && empty($CFG->guestloginbutton) && empty($CFG->autologinguests)) {
            return $this->content;
        }

        $amount = get_config('block_vitrinadb', 'singleamount');

        if (!$amount || !is_numeric($amount)) {
            $amount = 4;
        }

        // Take config from instance if it isn't empty.
        if (!empty($this->config->singleamount)) {
            $amount = $this->config->singleamount;
        }

        // Load tabs and views.
        $tabnames = \block_vitrinadb\local\controller::get_courses_views();
        $tabs = [];

        if (isset($this->config) && is_object($this->config)) {
            foreach ($tabnames as $tabname) {
                if (property_exists($this->config, $tabname) && $this->config->$tabname) {
                    $tabs[] = $tabname;
                    $views[$tabname] = [];
                }
            }
        }

        if (empty($tabs)) {
            $tabs[] = 'default';
        }

        $html = '';
        $filteropt = new stdClass();
        $filteropt->overflowdiv = true;

        // If the content is trusted, do not clean it.
        if ($this->content_is_trusted()) {
            $filteropt->noclean = true;
        }

        if (isset($this->config->htmlheader)) {
            // Rewrite url.
            $this->config->htmlheader = file_rewrite_pluginfile_urls(
                $this->config->htmlheader,
                'pluginfile.php',
                $this->context->id,
                'block_vitrinadb',
                'content_header',
                null
            );
            // Default to FORMAT_HTML.
            $htmlheaderformat = FORMAT_HTML;
            if (isset($this->config->htmlheaderformat)) {
                $htmlheaderformat = $this->config->htmlheaderformat;
            }
            $html .= format_text($this->config->htmlheader, $htmlheaderformat, $filteropt);
        }

        if (isset($this->config->htmlfooter)) {
            // Rewrite url.
            $this->config->htmlfooter = file_rewrite_pluginfile_urls(
                $this->config->htmlfooter,
                'pluginfile.php',
                $this->context->id,
                'block_vitrinadb',
                'content_footer',
                null
            );
            // Default to FORMAT_HTML.
            $htmlfooterformat = FORMAT_HTML;
            if (isset($this->config->htmlfooterformat)) {
                $htmlfooterformat = $this->config->htmlfooterformat;
            }
            $this->content->footer = format_text($this->config->htmlfooter, $htmlfooterformat, $filteropt);
        }
        unset($filteropt);

        $uniqueid = \block_vitrinadb\local\controller::get_uniqueid();

        // Load templates to display courses.
        $renderable = new \block_vitrinadb\output\main($uniqueid, $tabs[0], $this->instance->id, $tabs);
        $renderer = $this->page->get_renderer('block_vitrinadb');
        $html .= $renderer->render($renderable);

        $this->content->text = $html;

        \block_vitrinadb\local\controller::include_templatecss($this->instance->id);
        $this->page->requires->js_call_amd('block_vitrinadb/main', 'catalog', [$uniqueid, $tabs[0], $this->instance->id, $amount]);

        return $this->content;
    }

    /**
     * Serialize and store config data.
     *
     * @param object $data
     * @param boolean $nolongerused
     * @return void
     */
    public function instance_config_save($data, $nolongerused = false) {
        $config = clone($data);
        // Move embedded files into a proper filearea and adjust HTML links to match.
        $config->htmlheader = file_save_draft_area_files(
            $data->htmlheader['itemid'],
            $this->context->id,
            'block_vitrinadb',
            'content_header',
            0,
            ['subdirs' => true],
            $data->htmlheader['text']
        );
        $config->htmlfooter = file_save_draft_area_files(
            $data->htmlfooter['itemid'],
            $this->context->id,
            'block_vitrinadb',
            'content_footer',
            0,
            ['subdirs' => true],
            $data->htmlfooter['text']
        );
        $config->htmlheaderformat = $data->htmlheader['format'];
        $config->htmlfooterformat = $data->htmlfooter['format'];
        parent::instance_config_save($config, $nolongerused);
    }

    /**
     * Delete area files when the block instance is deleted.
     *
     * @return bool
     */
    public function instance_delete() {
        $fs = get_file_storage();
        $fs->delete_area_files($this->context->id, 'block_vitrinadb');
        return true;
    }

    /**
     * Copy any block-specific data when copying to a new block instance.
     *
     * @param int $fromid the id number of the block instance to copy from
     * @return boolean
     */
    public function instance_copy($fromid) {
        $fromcontext = context_block::instance($fromid);
        $fs = get_file_storage();

        if (!$fs->is_area_empty($fromcontext->id, 'block_vitrinadb', 'content_header', 0, false)) {
            $draftitemid = 0;
            file_prepare_draft_area($draftitemid, $fromcontext->id, 'block_vitrinadb', 'content_header', 0, ['subdirs' => true]);
        }

        if (!$fs->is_area_empty($fromcontext->id, 'block_vitrinadb', 'content_footer', 0, false)) {
            $draftitemid = 0;
            file_prepare_draft_area($draftitemid, $fromcontext->id, 'block_vitrinadb', 'content_footer', 0, ['subdirs' => true]);
        }

        return true;
    }
}
