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
 * Block renderer

 * @package   block_vitrinadb
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_vitrinadb\output;

use plugin_renderer_base;
use renderable;

/**
 * Vitrina block renderer
 *
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Return the template content for the block.
     *
     * @param main $main The main renderable
     * @return string HTML string
     */
    public function render_main(main $main): string {
        global $CFG;

        $templateParams = $main->export_for_template($this);

        // $template = get_config('block_vitrinadb', 'templatetype');
        $template = main::get_config_ex($templateParams->instanceid ?: 0, 'block_vitrinadb', 'templatetype');

        $path = $CFG->dirroot . '/blocks/vitrinadb/templates/' . $template . '/main.mustache';

        if ($template != 'default' && file_exists($path)) {
            $templatefile = 'block_vitrinadb/' . $template . '/main';
        } else {
            $templatefile = 'block_vitrinadb/main';
        }

        return $this->render_from_template($templatefile, $templateParams);
    }

    /**
     * Return the template content for the block.
     *
     * @param catalog $catalog The catalog renderable
     * @return string HTML string
     */
    public function render_catalog(catalog $catalog): string {
        global $CFG;

        $templateParams = $catalog->export_for_template($this);

        // $template = get_config('block_vitrinadb', 'templatetype');
        $template = main::get_config_ex($templateParams->instanceid ?: 0, 'block_vitrinadb', 'templatetype');

        $path = $CFG->dirroot . '/blocks/vitrinadb/templates/' . $template . '/catalog.mustache';

        if ($template != 'default' && file_exists($path)) {
            $templatefile = 'block_vitrinadb/' . $template . '/catalog';
        } else {
            $templatefile = 'block_vitrinadb/catalog';
        }

        return $this->render_from_template($templatefile, $templateParams);
    }

    /**
     * Return the template content for the block.
     *
     * @param detail $detail The detail renderable
     * @return string HTML string
     */
    public function render_detail(detail $detail): string {
        global $CFG;

        $templateParams = $detail->export_for_template($this);
        // $template = get_config('block_vitrinadb', 'templatetype');
        $template = main::get_config_ex($templateParams->instanceid ?: 0, 'block_vitrinadb', 'templatetype');

        $path = $CFG->dirroot . '/blocks/vitrinadb/templates/' . $template . '/detail.mustache';

        if ($template != 'default' && file_exists($path)) {
            $templatefile = 'block_vitrinadb/' . $template . '/detail';
        } else {
            $templatefile = 'block_vitrinadb/detail';
        }

        return $this->render_from_template($templatefile, $templateParams);
    }

    /**
     * Return the template for courses in the block.
     *
     * @param object $course The course information
     * @return string HTML string
     */
    public function render_course(object $course): string {
        global $CFG;

        static $shopmanager = null;

        // $template = get_config('block_vitrinadb', 'templatetype');
        $template = main::get_config_ex($course->instanceid ?: 0, 'block_vitrinadb', 'templatetype');

        // Prepare a plain-text summary for safe use in attributes like title.
        if (!empty($course->summary)) {
            $summarytext = trim(strip_tags($course->summary));
            if ($summarytext !== '') {
                $course->summarytitle = \core_text::substr($summarytext, 0, 200);
            }
        }

        $path = $CFG->dirroot . '/blocks/vitrinadb/templates/' . $template . '/course.mustache';

        if ($template != 'default' && file_exists($path)) {
            $templatefile = 'block_vitrinadb/' . $template . '/course';
        } else {
            $templatefile = 'block_vitrinadb/course';
        }

        // When using the "cards" template, avoid nested <a> tags by
        // stripping any anchor tags present in the summary HTML. The
        // inner text and other markup are preserved.
        if ($template === 'cards' && !empty($course->summary)) {
            $course->summary = preg_replace('~</?a\b[^>]*>~i', '', $course->summary);
        }

        if ($shopmanager === null) {
            $shoppluginname = get_config('block_vitrinadb', 'shopmanager');
            if (!empty($shoppluginname)) {
                $shopmanager = 'block_vitrinadb\local\shop\\' . $shoppluginname;
            }
        }

        if ($shopmanager && !$course->enrolled && !$course->canview) {
            $course->hascart = true;
            $course->shopmanager = $shopmanager::render_from_template();

            foreach ($course->fee as $fee) {
                $fee->reference = $shopmanager::get_product_reference('enrol_fee', $fee->itemid);
            }
        }
        // $course->opendetailstarget = get_config('block_vitrinadb', 'opendetailstarget');
        $course->opendetailstarget = main::get_config_ex($course->instanceid ?: 0, 'block_vitrinadb', 'opendetailstarget');

        return $this->render_from_template($templatefile, $course);
    }
}
