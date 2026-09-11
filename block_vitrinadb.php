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

        // When the block instance is configured to split by channels and
        // more than one channel has been selected, render one independent
        // sub-block per channel. Each sub-block behaves like its own
        // block_vitrinadb instance: it has its own tabs, paging state and
        // load more button, and it is stacked vertically in the block
        // content area. The catalog page continues to use the unified
        // (non-split) behaviour.
        $splitbychannels = !empty($this->config->splitbychannels);
        $configuredchannels = [];

        if (!empty($this->config->channels)) {
            if (is_array($this->config->channels)) {
                $configuredchannels = $this->config->channels;
            } else {
                $configuredchannels = \block_vitrinadb\local\controller::normalize_channels_list(
                    (string)$this->config->channels
                );
            }

            $configuredchannels = array_values(
                array_filter(array_map('trim', $configuredchannels), function($value) {
                    return $value !== '';
                })
            );
            $configuredchannels = array_unique($configuredchannels);
        }

        if ($splitbychannels && count($configuredchannels) > 1) {
            // Split mode: render one section per channel.
            $splithtml = '';

            foreach ($configuredchannels as $channel) {
                $channeluniqueid = \block_vitrinadb\local\controller::get_uniqueid();

                // Wrapper so the JS can hide the whole section when empty.
                $splithtml .= \html_writer::start_div('block_vitrinadb-channelsection', [
                    'data-vitrinadb-uniqueid' => $channeluniqueid,
                ]);

                // Channel title heading with link to the catalog page.
                // Pass the channel name as a URL parameter so the catalog
                // page opens with only this channel's items pre-selected,
                // matching the behaviour of block_vitrina's category link.
                $channelurl = new \moodle_url('/blocks/vitrinadb/index.php', [
                    'id' => $this->instance->id,
                    'channel' => $channel,
                ]);

                $channellink = \html_writer::link($channelurl, s($channel), [
                    'class' => 'block_vitrinadb-channellink',
                ]);

                $splithtml .= \html_writer::tag('h4', $channellink, [
                    'class' => 'block_vitrinadb-channeltitle',
                    'style' => 'margin-top:18px;margin-bottom:2px;font-size:large;',
                ]);

                $channelrenderable = new \block_vitrinadb\output\main(
                    $channeluniqueid,
                    $tabs[0],
                    $this->instance->id,
                    $tabs
                );
                $splithtml .= $renderer->render($channelrenderable);

                // Fixed filter so this sub-block only shows records for this channel.
                $fixedfilters = [
                    [
                        'type' => 'channels',
                        'values' => [$channel],
                    ],
                ];

                $this->page->requires->js_call_amd(
                    'block_vitrinadb/main',
                    'catalog',
                    [$channeluniqueid, $tabs[0], $this->instance->id, $amount, $fixedfilters]
                );

                $splithtml .= \html_writer::end_div();
            }

            // Prepend the split-by-channel sections before the original
            // "view all" block. The original block always uses all channels.
            $this->content->text = str_replace(
                'id="' . $uniqueid . '"',
                'id="' . $uniqueid . '" style="display:none;"',
                $this->content->text
            );
            $this->content->text = $splithtml . $this->content->text;
        } else {
            // Default behaviour: single unified block using all configured
            // channels for this instance.
            $this->page->requires->js_call_amd(
                'block_vitrinadb/main',
                'catalog',
                [$uniqueid, $tabs[0], $this->instance->id, $amount]
            );
        }
        $this->page->requires->js_call_amd('block_vitrinadb/edit_form', 'init', [
            get_string('loading', 'moodle'),
            get_string('selectchannels', 'block_vitrinadb'),
        ]);

        // Decorate the block title with a link to the catalog and a search icon.
        $catalogurl = new \moodle_url('/blocks/vitrinadb/index.php', ['id' => $this->instance->id]);
        $this->page->requires->js_amd_inline(<<<JS
            require(['jquery'], function($) {
                var header = document.getElementById('instance-{$this->instance->id}-header');
                if (header) {
                    var title = header.textContent.trim();
                    var a = document.createElement('a');
                    a.href = '{$catalogurl->out(false)}';
                    a.className = 'block_vitrina-title-link';
                    a.target = '_blank';
                    a.rel = 'noopener noreferrer';
                    a.textContent = title;
                    var icon = document.createElement('i');
                    icon.className = 'icon fa fa-search fa-fw block_vitrina-title-search';
                    icon.setAttribute('aria-hidden', 'true');
                    a.appendChild(icon);
                    header.textContent = '';
                    header.appendChild(a);
                }
            });
        JS);

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

    /**
     * Check if the block content is trusted and avoid JS injection.
     *
     * @return bool
     */
    public function content_is_trusted() {
        global $SCRIPT;

        $context = context::instance_by_id($this->instance->parentcontextid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        // Find out if this block is on the profile page.
        if ($context->contextlevel == CONTEXT_USER) {
            if ($SCRIPT === '/my/index.php') {
                return true;
            } else {
                // No JS on public personal pages, it would be a big security issue.
                return false;
            }
        }
        return true;
    }

    /**
     * Overridden by the block to prevent the block from being dockable.
     *
     * @return bool
     *
     * Return false as per MDL-64506.
     */
    public function instance_can_be_docked() {
        return false;
    }
}