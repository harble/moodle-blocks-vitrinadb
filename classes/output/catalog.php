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
 * Class containing renderers for the block.
 *
 * @package   block_vitrinadb
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_vitrinadb\output;

use renderable;
use renderer_base;
use templatable;

/**
 * Class containing data for the courses catalog.
 *
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalog implements renderable, templatable {
    /**
     * @var string The uniqueid of the block instance.
     */
    private $uniqueid;

    /**
     * @var string The view type.
     */
    private $view;

    /**
     * @var int The block instance id.
     */
    private $instanceid;

    /**
     * Constructor.
     *
     * @param string $uniqueid The uniqueid of the block instance.
     * @param string $view The view type.
     */
    public function __construct($uniqueid, $view = 'default', int $instanceid = 0) {

        $this->uniqueid = $uniqueid;
        $this->view = $view;
        $this->instanceid = $instanceid;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param \renderer_base $output
     * @return array Context variables for the template
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $DB;

        $isadmin = \is_siteadmin();

        $availableviews = \block_vitrinadb\local\controller::get_courses_views();

        $icons = \block_vitrinadb\local\controller::get_views_icons();

        $showtabs = [];
        foreach ($availableviews as $k => $view) {
            $one = new \stdClass();
            $one->title = get_string('tabtitle_' . $view, 'block_vitrinadb');
            $one->key = $view;
            $one->icon = $output->image_icon($icons[$view], $one->title);
            $one->state = $view == $this->view ? 'active' : '';
            $showtabs[] = $one;
        }

        // Filter controls.
        $filtercontrols = [];

        $staticfilters = get_config('block_vitrinadb', 'staticfilters');
        $staticfilters = explode(',', $staticfilters);

        $filterproperties = new \stdClass();

        // Tags filter: when the block instance has configured tags, show them
        // as a checkbox list (like categories/channels) above the other
        // filters. When there are no configured tags, expose a single-select
        // dropdown with all relevant tags (standard tags + tags already used
        // on Database records).
        $tagscontrol = null;

        $tagrecords = $DB->get_records_sql(
            "SELECT DISTINCT t.id, t.name
               FROM {tag} t
          LEFT JOIN {tag_instance} ti ON ti.tagid = t.id
                 AND ti.component = 'mod_data'
                 AND ti.itemtype = 'data_records'
              WHERE t.isstandard = 1 OR ti.id IS NOT NULL
           ORDER BY t.name ASC"
        );

        $allowedtagids = [];
        if ($this->instanceid) {
            $block = block_instance_by_id($this->instanceid);
            if ($block && !empty($block->config) && !empty($block->config->tags)) {
                if (is_array($block->config->tags)) {
                    $allowedtagids = $block->config->tags;
                } else {
                    $allowedtagids = [$block->config->tags];
                }
                $allowedtagids = array_map('intval', $allowedtagids);
                $allowedtagids = array_filter($allowedtagids);
            }
        }

        // If the block has configured tags, render them as a checkbox
        // filter control; otherwise, expose a dropdown with all tags.
        if (!empty($allowedtagids)) {
            $tagsoptions = [];

            foreach ($tagrecords as $tagrecord) {
                if (!in_array((int)$tagrecord->id, $allowedtagids)) {
                    continue;
                }

                $tagsoptions[] = [
                    'value' => (string)$tagrecord->id,
                    'label' => format_string($tagrecord->name, true),
                    'selected' => false,
                    'haschilds' => false,
                    'childs' => [],
                    'indent' => 0,
                ];
            }

            if (!empty($tagsoptions)) {
                $tagscontrol = new \stdClass();
                $tagscontrol->title = get_string('resourcetagsfilter', 'block_vitrinadb');
                $tagscontrol->key = 'tags';
                $tagscontrol->options = $tagsoptions;
            }
        } else {
            $tagsoptions = [];

            foreach ($tagrecords as $tagrecord) {
                $tagsoptions[] = [
                    'value' => (string)$tagrecord->id,
                    'label' => format_string($tagrecord->name, true),
                ];
            }

            if (!empty($tagsoptions)) {
                $filterproperties->hastags = true;
                $filterproperties->tagsoptions = $tagsoptions;
            }
        }

        // If we have a tags checkbox control (block-level configured tags),
        // show it before the rest of the checkbox-based filters.
        if ($tagscontrol !== null) {
            $filtercontrols[] = $tagscontrol;
        }

        // Filter by channels (displayed as categories) using the configured
        // Database activity "channels" field instead of Moodle course
        // categories. Honour the "Category filter view" setting so that the
        // list can be shown either flat or as a tree.
        $catfilterview = null;
        if (in_array('categories', $staticfilters)) {
            $catfilterview = get_config('block_vitrinadb', 'catfilterview');

            $nested = ($catfilterview == 'tree');

            $channelsoptions = \block_vitrinadb\local\controller::get_channels_filter_options((int)$this->instanceid, $nested);

            if (count($channelsoptions) > 0) {
                $control = new \stdClass();
                // Reuse the generic "category" label so the UI still
                // shows a categories-like list with checkboxes.
                $control->title = get_string('category');
                $control->key = 'channels';
                $control->options = $channelsoptions;
                $filtercontrols[] = $control;
            }
        }

        // Filter by show_status (display status) using the configured
        // Database activity "show_status" field. This is rendered as a
        // dropdown above the categories list. Only site administrators can
        // see and use this filter.
        $showstatusfilter = null;
        if ($isadmin) {
            $showstatusoptions = \block_vitrinadb\local\controller::get_showstatus_filter_options((int)$this->instanceid);
            if (!empty($showstatusoptions)) {
                $showstatusfilter = (object) [
                    'options' => $showstatusoptions,
                ];
            }
        }

        // Filter by author (record creator) using the distinct users who
        // created entries in the configured Database activity. This is
        // rendered as a dropdown above the categories list (alongside
        // show_status).
        $authoroptions = \block_vitrinadb\local\controller::get_authors_filter_options((int)$this->instanceid);
        $authorfilter = null;
        if (!empty($authoroptions)) {
            $authorfilter = (object) [
                'options' => $authoroptions,
            ];
        }

        // Filter by language.
        if (in_array('langs', $staticfilters)) {
            $options = \block_vitrinadb\local\controller::get_languages();

            if (count($options) > 1) {
                $control = new \stdClass();
                $control->title = get_string('language');
                $control->key = 'langs';
                $control->options = $options;
                $filtercontrols[] = $control;
            }
        }

        // Filter by custom fields.

        // Add to filtercontrols the array returned by the method get_customfieldsfilters.
        $filtercontrols = array_merge($filtercontrols, \block_vitrinadb\local\controller::get_customfieldsfilters());

        if (in_array('fulltext', $staticfilters)) {
            $filterproperties->fulltext = true;
        }

        // Only administrators can see and use the "Only pending approval
        // records" checkbox.
        $pendingfilter = $isadmin;
        // End of filter controls.

        $sortvalue = main::get_config_ex($this->instanceid ?: 0, 'block_vitrinadb', 'sortbydefault');
        if (empty($sortvalue)) {
            $sortvalue = 'default';
        }

        $sortdirectionvalue = main::get_config_ex($this->instanceid ?: 0, 'block_vitrinadb', 'sortdirection');
        if (empty($sortdirectionvalue)) {
            $sortdirectionvalue = 'asc';
        }

        // Only expose the supported sort modes for resources.
        $sortlabels = [
            'default' => get_string('sortdefault', 'block_vitrinadb'),
            'alphabetically' => get_string('sortalphabetically', 'block_vitrinadb'),
            'code' => get_string('sortbycode', 'block_vitrinadb'),
        ];

        $sortoptions = [];
        foreach ($sortlabels as $value => $label) {
            $option = new \stdClass();
            $option->value = $value;
            $option->label = $label;
            $option->selected = $value === $sortvalue;
            $sortoptions[] = $option;
        }

        $sortdirectionlabels = [
            'asc' => get_string('sortdirection_asc', 'block_vitrinadb'),
            'desc' => get_string('sortdirection_desc', 'block_vitrinadb'),
        ];

        $sortdirectionoptions = [];
        foreach ($sortdirectionlabels as $value => $label) {
            $option = new \stdClass();
            $option->value = $value;
            $option->label = $label;
            $option->selected = $value === $sortdirectionvalue;
            $sortdirectionoptions[] = $option;
        }

        $defaultvariables = [
            'uniqueid' => $this->uniqueid,
            'baseurl' => $CFG->wwwroot,
            'hastabs' => count($showtabs) > 1,
            'tabs' => $showtabs,
            'showicon' => \block_vitrinadb\local\controller::show_tabicon(),
            'showtext' => \block_vitrinadb\local\controller::show_tabtext(),
            'filtercontrols' => $filtercontrols,
            'filterproperties' => $filterproperties,
            'showstatusfilter' => $showstatusfilter,
            'pendingfilter' => $pendingfilter,
            'authorfilter' => $authorfilter,
            'sortoptions' => $sortoptions,
            'sortdirectionoptions' => $sortdirectionoptions,
            'catfilterview' => $catfilterview,
            // 'opendetailstarget' => get_config('block_vitrinadb', 'opendetailstarget'),
            'opendetailstarget' => main::get_config_ex($this->instanceid ?: 0, 'block_vitrinadb', 'opendetailstarget'),
        ];

        return $defaultvariables;
    }
}
