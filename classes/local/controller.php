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
 * Class containing the general controls.
 *
 * @package   block_vitrinadb
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_vitrinadb\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Component controller.
 *
 * @copyright 2023 David Herney @ BambuCo
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class controller {
    /**
     * @var int Cached payment field id.
     */
    protected static $cachedpayfield = null;

    /**
     * @var int Cached premium field id.
     */
    protected static $cachedpremiumfield = null;

    /**
     * @var int Cached code field id.
     */
    protected static $cachedcodefield = null;

    /**
     * @var bool True if load full information about the course.
     */
    protected static $large = false;

    /**
     * @var int Instances includes in page request.
     */
    private static $instancescounter = 0;

    /**
     * @var array List of icons for the views.
     */
    private static $viewsicons = null;

    /**
     * @var bool True if show icons in tabs views.
     */
    private static $showicons = null;

    /**
     * @var bool True if show text in tabs views.
     */
    private static $showtext = null;

    /**
     * @var bool True if the user is premium.
     */
    private static $isuserpremium = null;

    /**
     * @var string Membership type.
     */
    private static $usermembership = null;

    /**
     * @var array List of available courses views.
     */
    public const COURSES_VIEWS = ['default', 'recents', 'greats', 'premium'];

    /**
     * @var array List of available sorts.
     */
    public const COURSES_SORTS = ['default', 'alphabetically', 'startdate', 'finishdate', 'code'];

    /**
     * @var array List of available types in custom fields to filter.
     */
    public const CUSTOMFIELDS_SUPPORTED = ['select', 'checkbox', 'multiselect'];

    /**
     * @var array List of available static filters (not include filters by custom fields).
     */
    public const STATICFILTERS = ['langs', 'categories', 'fulltext'];

    /**
     * @var string The user is premium by user field.
     */
    public const PREMIUMBYFIELD = 'field';

    /**
     * @var string The user is premium by enrolled course.
     */
    public const PREMIUMBYCOURSE = 'course';

    /**
     * @var string The user is premium by cohort.
     */
    public const PREMIUMBYCOHORT = 'cohort';

    /**
     * Process a specific course to be displayed.
     *
     * @param object $course Course to be processed.
     * @param bool   $large  True if load full information about the course.
     */
    public static function course_preprocess($course, $large = false) {
        global $CFG, $DB, $PAGE, $USER;

        $isuserpremium = self::is_user_premium();

        self::$large = $large;
        $course->haspaymentgw = false;
        $course->paymenturl = null;
        $course->baseurl = $CFG->wwwroot;
        $course->hassummary = !empty($course->summary);
        $course->fullname = format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]);
        $course->summary = format_text($course->summary, $course->summaryformat);

        $payfield = self::get_payfield();
        if (!$isuserpremium) {
            if ($payfield) {
                $course->paymenturl = $DB->get_field(
                    'customfield_data',
                    'value',
                    ['fieldid' => $payfield->id, 'instanceid' => $course->id]
                );
            }
        }

        $premiumfield = self::get_premiumfield();

        if ($premiumfield) {
            $course->premium = $DB->get_field(
                'customfield_data',
                'value',
                ['fieldid' => $premiumfield->id, 'instanceid' => $course->id]
            );
        } else {
            $course->premium = null;
        }

        // Load the course enrol info.
        self::load_enrolinfo($course);

        if ((!$premiumfield || $course->premium) && $isuserpremium) {
            $course->fee = null;
        } else {
            // If course has a single cost, load it for fast printing.
            if (count($course->fee) == 1) {
                $course->cost = $course->fee[0]->cost;
            }
        }

        $course->imagepath = self::get_courseimage($course);

        $ratemanager = self::get_ratemanager();
        $ratingavailable = $ratemanager::rating_available();

        if (!property_exists($course, 'rating')) {
            if ($ratingavailable) {
                $course->rating = $ratemanager::get_ratings($course, $large);
            }
        }

        if (property_exists($course, 'rating') && $course->rating) {
            if (!is_object($course->rating)) {
                $rating = $course->rating;
                $course->rating = new \stdClass();
                $course->rating->total = $rating;
                $course->rating->count = property_exists($course, 'ratings') ? $course->ratings : 0;
                $course->rating->detail = null;
                $course->hasrating = $course->rating->count > 0;
            }

            // Not rating course.
            if ($course->rating->total == 0) {
                $course->rating = null;
            } else {
                $course->rating->total = round($course->rating->total, 1);
                $course->rating->percent = round($course->rating->total * 20);
                $course->rating->formated = str_pad($course->rating->total, 3, '.0');
                $course->rating->stars = $course->rating->total > 0 ? range(1, $course->rating->total) : null;
                $course->hasrating = $course->rating->count > 0;
            }
        }

        // If course is active or waiting.
        $course->active = $course->startdate <= time();

        // Course progress.
        if (isloggedin() && !isguestuser() && !empty($course->enablecompletion)) {
            $completioninfo = new \completion_info($course);
            $course->completed = $completioninfo->is_course_complete($USER->id);
            $course->progress = \core_completion\progress::get_course_progress_percentage($course);
            $course->progress = is_numeric($course->progress) ? round($course->progress) : null;
            $course->hasprogress = $course->progress ?? false;
        } else {
            $course->completed = null;
            $course->progress = null;
            $course->hasprogress = false;
        }

        // Load data for course detail.
        if ($large) {
            $fullcourse = new \core_course_list_element($course);

            $commentsmanager = self::get_commentsmanager();
            $comments = $commentsmanager::get_comments($course);
            $course->commentscount = count($comments);
            $course->latestcomments = null;

            if ($course->commentscount > 0) {
                $course->hascomments = true;
                $course->comments = array_values($comments);

                $strftimeformat = get_string('strftimerecentfull', 'langconfig');

                foreach ($course->comments as $comment) {
                    $user = $DB->get_record('user', ['id' => $comment->userid]);
                    $userpicture = new \user_picture($user, ['alttext' => false, 'link' => false]);
                    $userpicture->size = 200;
                    $comment->userpicture = $userpicture->get_url($PAGE);
                    $comment->timeformated = userdate($comment->timecreated, $strftimeformat);
                    $comment->userfirstname = $user->firstname;
                    $comment->userlastname = $user->lastname;
                }

                $course->latestcomments = array_slice($course->comments, 0, 3);
            } else {
                $course->hascomments = false;
                $course->comments = null;
            }

            // Search related courses by tags.
            $course->hasrelated = false;
            $course->related = [];
            $related = [];
            $relatedlimit = get_config('block_vitrinadb', 'relatedlimit');

            $categories = get_config('block_vitrinadb', 'categories');

            $categoriesids = [];
            $catslist = explode(',', $categories);
            foreach ($catslist as $catid) {
                if (is_numeric($catid)) {
                    $categoriesids[] = (int)trim($catid);
                }
            }

            $categoriescondition = '';
            if (count($categoriesids) > 0) {
                $categoriescondition = " AND c.category IN (" . implode(',', $categoriesids) . ")";
            }

            if (!empty($relatedlimit) && \core_tag_tag::is_enabled('core', 'course')) {
                // Get the course tags.
                $tags = \core_tag_tag::get_item_tags_array('core', 'course', $course->id);

                if (count($tags) > 0) {
                    $ids = [];
                    foreach ($tags as $key => $tag) {
                        $ids[] = $key;
                    }

                    $sqlintances = "SELECT c.id, c.category FROM {tag_instance} t " .
                                    " INNER JOIN {course} c ON t.itemtype = 'course' AND c.id = t.itemid AND c.visible = 1" .
                                    " WHERE t.tagid IN (" . (implode(',', $ids)) . ") " . $categoriescondition .
                                    " GROUP BY c.id, c.category" .
                                    " ORDER BY t.timemodified DESC";

                    $instances = $DB->get_records_sql($sqlintances);

                    foreach ($instances as $instance) {
                        if (
                            $instance->id != $course->id &&
                            $instance->id != SITEID &&
                            count($related) < $relatedlimit &&
                            !in_array($instance->id, $related)
                        ) {
                            $related[] = $instance->id;
                        }
                    }
                }
            }

            if (count($related) < $relatedlimit) {
                // Exclude previous related courses, current course and the site.
                $relatedids = implode(',', array_merge($related, [$course->id, SITEID]));
                $sql = "SELECT id FROM {course} c " .
                        " WHERE visible = 1 AND (enddate > :enddate OR enddate IS NULL) AND id NOT IN ($relatedids)" .
                        $categoriescondition .
                        " ORDER BY startdate DESC";
                $params = ['enddate' => time()];
                $othercourses = $DB->get_records_sql($sql, $params, 0, $relatedlimit - count($related));

                foreach ($othercourses as $other) {
                    $related[] = $other->id;
                }
            }

            if (count($related) > 0) {
                $course->hasrelated = true;

                $coursesinfo = $DB->get_records_list('course', 'id', $related);

                // Load other info about the courses.
                foreach ($coursesinfo as $one) {
                    $one->hassummary = !empty($one->summary);
                    $one->imagepath = self::get_courseimage($one);
                    $one->active = $one->startdate <= time();
                    $one->fullname = format_string($one->fullname, true, ['context' => \context_course::instance($one->id)]);
                    $one->summary = format_text($one->summary, $course->summaryformat);

                    if (!$isuserpremium && $payfield) {
                        $one->paymenturl = $DB->get_field(
                            'customfield_data',
                            'value',
                            ['fieldid' => $payfield->id, 'instanceid' => $one->id]
                        );
                    }

                    if ($premiumfield) {
                        $one->premium = $DB->get_field(
                            'customfield_data',
                            'value',
                            ['fieldid' => $premiumfield->id, 'instanceid' => $one->id]
                        );
                    }

                    if ($ratingavailable) {
                        $one->rating = new \stdClass();
                        $one->rating->total = 0;
                        $one->rating->count = 0;
                        $one->rating->detail = null;
                        $one->hasrating = false;

                        $ratemanager = self::get_ratemanager();
                        $onerating = $ratemanager::get_ratings($one->id, $large);

                        if ($onerating && $onerating->count > 0) {
                            $one->rating->total = round($onerating->rating, 1);
                            $one->rating->count = $onerating->count;
                            $one->rating->percent = round($onerating->total * 20);
                            $one->rating->formated = str_pad($onerating->total, 3, '.0');
                            $one->hasrating = true;
                            $one->rating->stars = $onerating->total > 0 ? range(1, $onerating->total) : null;
                        }
                    }

                    // Load the related course enrol info.
                    self::load_enrolinfo($one);
                    $course->related[] = $one;
                }
            }

            // Load the teachers information.
            $course->hasinstructors = false;

            if ($fullcourse->has_course_contacts()) {
                $course->hasinstructors = true;
                $course->instructors = [];
                $instructors = $fullcourse->get_course_contacts();

                foreach ($instructors as $key => $instructor) {
                    $user = $DB->get_record('user', ['id' => $key]);
                    $userpicture = new \user_picture($user, ['alttext' => false, 'link' => false]);
                    $userpicture->size = 200;
                    $user->userpicture = $userpicture->get_url($PAGE);
                    $user->profileurl = $CFG->wwwroot . '/user/profile.php?id=' . $key;
                    $user->description = format_text($user->description, FORMAT_HTML);

                    $course->instructors[] = $user;
                }
            }
        }
    }

    /**
     * Define if premium features are available.
     *
     * @return boolean
     */
    public static function premium_available(): bool {

        $premiumfield = self::get_premiumfield();
        return $premiumfield ? true : false;
    }

    /**
     * Get the payment field.
     *
     * @return object The payment field.
     */
    public static function get_payfield(): ?object {
        global $DB;

        if (!self::$cachedpayfield) {
            $paymenturlfield = get_config('block_vitrinadb', 'paymenturl');
            if (!empty($paymenturlfield)) {
                self::$cachedpayfield = $DB->get_record('customfield_field', ['id' => $paymenturlfield]);
            }
        }

        return self::$cachedpayfield ?? null;
    }

    /**
     * Get the premium field.
     *
     * @return object The premium field.
     */
    public static function get_premiumfield(): ?object {
        global $DB;

        if (!self::$cachedpremiumfield) {
            $premiumfield = get_config('block_vitrinadb', 'premiumcoursefield');
            if (!empty($premiumfield)) {
                self::$cachedpremiumfield = $DB->get_record('customfield_field', ['id' => $premiumfield]);
            }
        }

        return self::$cachedpremiumfield ?? null;
    }

    /**
     * Get the code field for sorting.
     *
     * @return object The code field.
     */
    public static function get_codefield(): ?object {
        global $DB;

        if (!self::$cachedcodefield) {
            $codefield = get_config('block_vitrinadb', 'codefield');
            if (!empty($codefield)) {
                self::$cachedcodefield = $DB->get_record('customfield_field', ['id' => $codefield]);
            }
        }

        return self::$cachedcodefield ?? null;
    }

    /**
     * Define if the current or received user is premium.
     *
     * @param stdClass $user User object.
     * @return boolean True if the user is premium.
     */
    public static function is_user_premium($user = null): bool {

        if (self::$isuserpremium !== null) {
            return self::$isuserpremium;
        }

        $membership = self::type_membership($user);

        return $membership ? true : false;
    }

    /**
     * Return the user membership type.
     *
     * @param stdClass $user User object.
     * @return string|null If the user is premium return the type of membership or null if not.
     */
    public static function type_membership($user = null): ?string {
        global $USER, $DB;

        if (self::$isuserpremium !== null) {
            return self::$usermembership;
        }

        if (!$user) {
            $user = $USER;
        }

        $premiumfieldid = get_config('block_vitrinadb', 'premiumfield');
        $premiumvalue = get_config('block_vitrinadb', 'premiumvalue');

        // If the premium field and value are set, check if the user is premium.
        // It overrides the "Course to read premium users" setting.
        if (!empty($premiumfieldid) && !empty($premiumvalue)) {
            $premiumfield = $DB->get_field('user_info_field', 'shortname', ['id' => $premiumfieldid]);

            if (!empty($premiumfield)) {
                if (isset($user->profile[$premiumfield]) && $user->profile[$premiumfield] == $premiumvalue) {
                    self::$isuserpremium = true;
                    self::$usermembership = self::PREMIUMBYFIELD;
                    return self::$usermembership;
                }
            }
        }

        // If the user is enrolled in the "Course to read premium users" is a premium user.
        $premiumcourseid = get_config('block_vitrinadb', 'premiumenrolledcourse');
        if (!empty($premiumcourseid)) {
            // Check if the user is enrolled in the premium course.
            if (is_enrolled(\context_course::instance($premiumcourseid), $user->id, '', true)) {
                self::$isuserpremium = true;
                self::$usermembership = self::PREMIUMBYCOURSE;
                return self::$usermembership;
            }
        }

        // If the user is in the cohort to premium users.
        $premiumcohort = get_config('block_vitrinadb', 'premiumcohort');
        if (!empty($premiumcohort)) {
            if (cohort_is_member($premiumcohort, $USER->id)) {
                self::$isuserpremium = true;
                self::$usermembership = self::PREMIUMBYCOHORT;
                return self::$usermembership;
            }
        }

        self::$isuserpremium = false;
        return null;
    }

    /**
     * Get the course preview image.
     *
     * @param \stdClass $course Course object.
     * @return string Image url.
     */
    public static function get_courseimage($course): string {
        global $CFG, $OUTPUT;

        $coursefull = new \core_course_list_element($course);

        $courseimage = '';
        foreach ($coursefull->get_course_overviewfiles() as $file) {
            $isimage = $file->is_valid_image();

            if ($isimage) {
                $urlpath = '/' . $file->get_contextid() . '/' . $file->get_component() . '/';
                $urlpath .= $file->get_filearea() . $file->get_filepath() . $file->get_filename();

                $url = \moodle_url::make_file_url(
                    "$CFG->wwwroot/pluginfile.php",
                    $urlpath,
                    !$isimage
                );

                $courseimage = $url;
                break;
            }
        }

        if (empty($courseimage)) {
            $type = get_config('block_vitrinadb', 'coverimagetype');

            switch ($type) {
                case 'generated':
                    $courseimage = $OUTPUT->get_generated_image_for_id($course->id);
                    break;
                case 'none':
                    $courseimage = '';
                    break;
                default:
                    $courseimage = (string)(new \moodle_url($CFG->wwwroot . '/blocks/vitrina/pix/' .
                                                                (self::$large ? 'course' : 'course_small') . '.png'));
            }
        }

        return $courseimage;
    }

    /**
     * Include a CSS file according the current used template.
     *
     * @return void
     */
    public static function include_templatecss(int $instanceid = 0) {

        global $CFG, $PAGE;

        // $template = get_config('block_vitrinadb', 'templatetype');
        $template = \block_vitrinadb\output\main::get_config_ex($instanceid ?: 0, 'block_vitrinadb', 'templatetype');

        $csspath = $CFG->dirroot . '/blocks/vitrinadb/templates/' . $template . '/styles.css';

        // If the template is not the default and a templace CSS file exist, include the CSS file.
        if ($template != 'default' && file_exists($csspath)) {
            $PAGE->requires->css('/blocks/vitrinadb/templates/' . $template . '/styles.css');
        }
    }

    /**
     * Generate a unique id for block instance.
     *
     * @return string Unique identifier.
     */
    public static function get_uniqueid() {
        $uniqueid = 'block_vitrinadb_' . self::$instancescounter;
        self::$instancescounter++;

        return $uniqueid;
    }

    /**
     * Get the available courses views.
     */
    public static function get_courses_views(): array {

        $availablesorting = self::COURSES_VIEWS;

        $ratemanager = self::get_ratemanager();
        $ratingavailable = $ratemanager::rating_available();

        if (!$ratingavailable) {
            // Remove the greats value if the rate feature is not available.
            if (($key = array_search('greats', $availablesorting)) !== false) {
                unset($availablesorting[$key]);
            }
        }

        if (!self::premium_available()) {
            if (($key = array_search('premium', $availablesorting)) !== false) {
                unset($availablesorting[$key]);
            }
        }

        return $availablesorting;
    }

    /**
     * Get courses by view.
     *
     * @param string $view The view key.
     * @param array $categoriesids The categories ids.
     * @param array $filters A filters objects list with type and value.
     * @param string $sort The sort.
     * @param string $sortdirection The sort direction (asc or desc).
     * @param int $amount The amount of courses to get.
     * @param int $initial From where to start counting the next courses to get.
     * @return array The courses list.
     */
    public static function get_courses_by_view(
        string $view = 'default',
        array $categoriesids = [],
        array $filters = [],
        string $sort = '',
        string $sortdirection = 'asc',
        int $amount = 0,
        int $initial = 0
    ): array {
        global $DB, $CFG;

        $availableviews = self::get_courses_views();
        if (!in_array($view, $availableviews)) {
            $view = 'default';
        }

        if (empty($sort) || !in_array($sort, self::COURSES_SORTS)) {
            $sort = get_config('block_vitrinadb', 'sortbydefault');
        }

        // Validate and set sort direction.
        $sortdirection = strtoupper($sortdirection);
        if (!in_array($sortdirection, ['ASC', 'DESC'])) {
            $sortdirectionconfig = get_config('block_vitrinadb', 'sortdirection');
            $sortdirection = ($sortdirectionconfig === 'desc') ? 'DESC' : 'ASC';
        }

        if (empty($amount)) {
            $amount = get_config('block_vitrinadb', 'singleamount');
        }

        if (count($categoriesids) == 0) {
            $categories = get_config('block_vitrinadb', 'categories');
            $catslist = explode(',', $categories);
            foreach ($catslist as $catid) {
                if (is_numeric($catid)) {
                    $categoriesids[] = (int) trim($catid);
                }
            }
        }

        $courses = [];
        $select = 'c.visible = 1 AND c.id <> :siteid AND (c.enddate > :now OR c.enddate = 0)';
        $params = ['siteid' => SITEID, 'now' => time()];

        // Add categories filter.
        if (count($categoriesids) > 0) {
            [$selectincats, $paramsincats] = $DB->get_in_or_equal($categoriesids, SQL_PARAMS_NAMED, 'categories');
            $params += $paramsincats;
            $select .= ' AND category ' . $selectincats;
        }
        // End of categories filter.

        $joincustomfields = '';
        $customfields = self::get_configuredcustomfields();

        // Add filters.
        foreach ($filters as $filter) {
            switch ($filter['type']) {
                case 'fulltext':
                    $text = trim(implode('%', $filter['values']));

                    if (!empty($text)) {
                        $text = $DB->sql_like_escape($text);
                        $text = str_replace(' ', '%', $text);

                        // To search in basic course fields.
                        $fieldstosearch = $DB->sql_concat_join("' '", ['c.fullname', 'c.shortname', 'c.summary']);
                        $like = $DB->sql_like($fieldstosearch, ':text', false);
                        $select .= ' AND ' . $like;
                        $params['text'] = '%' . $text . '%';

                        // To search in custom fields.
                        $like = $DB->sql_like('cfd.value', ':cftext', false);
                        $params['cftext'] = '%' . $text . '%';

                        $joincustomfields .= " LEFT JOIN {customfield_data} cfd ON c.id = cfd.instanceid AND " . $like;
                    }

                    break;
                case 'langs':
                    $langs = $filter['values'];
                    $defaultlang = $CFG->lang;

                    if (in_array($defaultlang, $langs)) {
                        $langs[] = '';
                    } else {
                        // Remove empty values.
                        $langs = array_filter($langs);
                    }

                    if (count($langs) > 0) {
                        [$selectinlangs, $paramsinlangs] = $DB->get_in_or_equal($langs, SQL_PARAMS_NAMED, 'langs');
                        $params = array_merge($params, $paramsinlangs);
                        $select .= ' AND c.lang ' . $selectinlangs;
                    }

                    break;
                default:
                    // Custom fields filters values.

                    // Cast to int.
                    $customfieldid = (int) $filter['type'];

                    if (empty($customfieldid)) {
                        break;
                    }

                    // By security. Only allow to filter by selected custom fields.
                    if (!isset($customfields[$customfieldid])) {
                        break;
                    }

                    $currentfield = $customfields[$customfieldid];

                    $values = array_map('intval', $filter['values']);

                    // If all values are selected, not include in filter.
                    if ($currentfield->type == 'checkbox' && in_array(0, $values) && in_array(1, $values)) {
                        break;
                    }

                    $orifnull = '';

                    $alias = 'cfdf' . $customfieldid;
                    $prefix = 'byfield' . $customfieldid;

                    if ($currentfield->type == 'multiselect') {
                        $select .= " AND (";
                        $elements = [];
                        foreach ($values as $key => $value) {
                            $elementkey = $prefix . '_' . $key;
                            // Multiselect values are stored between 0 and 1, so we need to decrease the value by 1 to search.
                            // The select value are the position in the list, starting by 1. Select is the default value.
                            $value = (int)$value - 1;
                            $elements[] = $DB->sql_like($DB->sql_concat("','", $alias . '.value', "','"), ':' . $elementkey);
                            $params[$elementkey] = '%' . $value . '%';
                        }
                        $select .= implode(' OR ', $elements) . ')';
                    } else {
                        [$selectin, $paramsin] = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, $prefix);

                        // Include "is null" if it is a checkbox and include the 0/not value.
                        if ($currentfield->type == 'checkbox' && in_array(0, $values)) {
                            $prefix = 'bynf' . $customfieldid;
                            [$selectnull, $paramsnull] = $DB->get_in_or_equal([], SQL_PARAMS_NAMED, $prefix, true, null);
                            $orifnull = " OR $alias.id " . $selectnull;
                            $params = array_merge($params, $paramsnull);
                        }

                        $select .= " AND ($alias.intvalue " . $selectin . $orifnull . ')';

                        $params = array_merge($params, $paramsin);
                    }

                    $joincustomfields .= " LEFT JOIN {customfield_data} $alias ON " .
                                        " c.id = $alias.instanceid AND $alias.fieldid = :fieldid$customfieldid";
                    $params['fieldid' . $customfieldid] = $customfieldid;

                    break;
            }
        }
        // End of filters.

        $sql = '';
        $specialfields = '';

        // Create the order by according the sort.
        switch ($sort) {
            case 'startdate':
                $sortby = "c.startdate $sortdirection";
                break;
            case 'finishdate':
                // For finish date, we need special handling
                if ($sortdirection === 'ASC') {
                    $sortby = 'endtype ASC, c.enddate ASC, c.startdate DESC';
                } else {
                    $sortby = 'endtype DESC, c.enddate DESC, c.startdate ASC';
                }
                $specialfields = ", CASE WHEN c.enddate = 0 THEN 2 ELSE 1 END AS endtype";
                break;
            case 'alphabetically':
                $sortby = "c.fullname $sortdirection";
                break;
            case 'code':
                $codefield = self::get_codefield();
                if ($codefield) {
                    // Join to code custom field and sort by it
                    $joincustomfields .= " LEFT JOIN {customfield_data} cfd_code ON " .
                                        " c.id = cfd_code.instanceid AND cfd_code.fieldid = :codefielidid";
                    $params['codefielidid'] = $codefield->id;
                    $sortby = "cfd_code.value $sortdirection";
                } else {
                    // If code field not configured, use default sort
                    $sortby = "c.sortorder $sortdirection";
                }
                break;
            default:
                $sortby = "c.sortorder $sortdirection";
        }

        switch ($view) {
            case 'greats':
                $ratemanager = self::get_ratemanager();
                [$ratingfield, $totalfield, $joinrate] = array_values($ratemanager::sql_map());

                $sql = "SELECT DISTINCT c.*, $ratingfield AS rating, $totalfield AS ratings " .
                            " FROM {course} c " .
                            $joinrate . ' ' .
                            $joincustomfields .
                            " WHERE " . $select .
                            " GROUP BY c.id HAVING rating > 3 " .
                            " ORDER BY rating DESC";
                break;
            case 'premium':
                $premiumfield = self::get_premiumfield();

                if ($premiumfield) {
                    $params['fieldid'] = $premiumfield->id;

                    $sql = "SELECT DISTINCT c.* $specialfields " .
                        " FROM {course} c" .
                        " INNER JOIN {customfield_data} cd ON cd.fieldid = :fieldid AND cd.value = '1' AND cd.instanceid = c.id" .
                        $joincustomfields .
                        " WHERE " . $select .
                        " ORDER BY " . $sortby;
                }
                break;
            case 'recents':
                $select .= ' AND c.startdate > :nowtostart';
                $params['nowtostart'] = time();
                // Not break, continue to default.
            default:
                $sql = "SELECT DISTINCT c.* $specialfields " .
                        " FROM {course} c" .
                        $joincustomfields .
                        " WHERE " . $select .
                        " ORDER BY " . $sortby;
        }

        if (!empty($sql)) {
            $courses = $DB->get_records_sql($sql, $params, $initial, $amount);
        }

        return $courses;
    }

    /**
     * Get resources stored in database activities for a given course.
     *
     * This helper searches all Database (mod_data) activities in the course which
     * contain the expected fields used for the VitrinaDb block:
     *   - subject (text)
     *   - cover_page (image)
     *   - description (HTML)
     *
     * It returns a flat list of resource objects with preprocessed fields so
     * they can be rendered using the existing course templates where
     *   fullname  => subject
     *   imagepath => cover_page
     *   summary  => description
     *
     * @param \stdClass $course Course record.
     * @param int|null $dataid Optional specific Database (mod_data) instance id to restrict to.
     * @param string $view View key (kept for future use: default, recents, greats, premium).
    * @param array $filters Filters list (reserved for future resource-level filtering).
    * @param string $sort Sort key for records (supported: default, alphabetically, code).
    * @param string $sortdirection Sort direction (asc or desc).
     * @param int $amount Max number of records to return (0 = no limit).
     * @param int $initial Offset from which to start (0-based).
     * @return array List of resource objects.
     */
    public static function get_course_resources(
        \stdClass $course,
        ?int $dataid = null,
        string $view = 'default',
        array $filters = [],
        string $sort = 'timecreated',
        string $sortdirection = 'DESC',
        int $amount = 0,
        int $initial = 0
    ): array {
        global $DB, $CFG, $PAGE;

        require_once($CFG->libdir . '/filelib.php');

        $pinnedresources = [];
        $resources = [];
        $now = time();

        // Normalise any fulltext search value (applies to resource subject,
        // description and channels) and channels filter provided from caller.
        $fulltext = '';

        // Normalise any channels filter provided (from block instance config or API caller).
        $channelsfilter = [];
        foreach ($filters as $filter) {
            if (!empty($filter['type']) && $filter['type'] === 'channels' && !empty($filter['values'])) {
                foreach ($filter['values'] as $value) {
                    $parts = self::normalize_channels_list((string)$value);
                    foreach ($parts as $part) {
                        $channelsfilter[] = mb_strtolower($part);
                    }
                }
            } else if (!empty($filter['type']) && $filter['type'] === 'fulltext' && !empty($filter['values'])) {
                $text = trim(implode(' ', (array)$filter['values']));
                if ($text !== '') {
                    $fulltext = mb_strtolower($text);
                }
            }
        }
        if (!empty($channelsfilter)) {
            $channelsfilter = array_values(array_unique($channelsfilter));
        }

        // Normalise sort direction.
        $sortdirection = strtoupper($sortdirection) === 'ASC' ? 'ASC' : 'DESC';
        $sortasc = $sortdirection === 'ASC';

        // Normalise sort key for resources.
        $sort = trim(strtolower($sort));
        if ($sort === '' || $sort === 'timecreated' || $sort === 'default') {
            $sort = 'default';
        } else if ($sort !== 'alphabetically' && $sort !== 'code') {
            // Any unsupported key falls back to default.
            $sort = 'default';
        }

        // DB-level ordering is always by creation time; additional sorting
        // (alphabetically/code) is applied in PHP after building resources.
        $orderby = 'timecreated ' . $sortdirection;

        $initial = max(0, (int)$initial);
        $amount = (int)$amount;

        // Locate the "data" module id.
        $dataModuleId = $DB->get_field('modules', 'id', ['name' => 'data']);
        if (!$dataModuleId) {
            return $resources;
        }

        // All database activities in the course.
        $cms = $DB->get_records('course_modules', [
            'course' => $course->id,
            'module' => $dataModuleId,
            'deletioninprogress' => 0,
        ]);

        if (empty($cms)) {
            return $resources;
        }

        // If a specific data instance is requested, restrict the search to it.
        if ($dataid !== null) {
            $cms = array_filter($cms, function($cm) use ($dataid) {
                return (int)$cm->instance === (int)$dataid;
            });

            if (empty($cms)) {
                return $resources;
            }
        }

        foreach ($cms as $cm) {
            $data = $DB->get_record('data', ['id' => $cm->instance]);
            if (!$data) {
                continue;
            }

            $context = \context_module::instance($cm->id);

            // Read all fields for this database.
            $fields = $DB->get_records('data_fields', ['dataid' => $data->id]);

            if (empty($fields)) {
                continue;
            }

            $subjectfieldid = null;
            $coverfieldid = null;
            $descriptionfieldid = null;
            $channelsfieldid = null;
            $codefieldid = null;
            $sharefilefieldid = null;
            $showstatusfieldid = null;

            foreach ($fields as $field) {
                switch ($field->description) {
                    case 'subject':
                        $subjectfieldid = $field->id;
                        break;
                    case 'cover_page':  // file upload field with cover image.
                        $coverfieldid = $field->id;
                        break;
                    case 'description':  // HTML content field with the resource description.
                        $descriptionfieldid = $field->id;
                        break;
                    case 'channels':  // 类别， 比如 传灯频道、慈善频道...
                        $channelsfieldid = $field->id;
                        break;
                    case 'code':  // Optional code field used for custom ordering.
                        $codefieldid = $field->id;
                        break;
                    case 'share_file':  // optional file upload field with a resource file to share.
                        $sharefilefieldid = $field->id;
                        break;
                    case 'show_status':  // control the visibility of the resource card. options: show, hide， pin(置顶)
                        $showstatusfieldid = $field->id;
                        break;
                }
            }

            // Require at least a subject to create a resource card.
            if (empty($subjectfieldid)) {
                continue;
            }

            // Get all approved records in this database. Pagination and
            // alternative sorting (alphabetically/code) are applied after
            // building the full resources list.
            $records = $DB->get_records('data_records', [
                'dataid' => $data->id,
                'approved' => 1,
            ], $orderby);

            if (empty($records)) {
                continue;
            }

            // Prefetch user ratings aggregated per record using core rating
            // table (mod_data entry ratings, aggregated as AVG).
            $ratingsbyrecord = [];
            $recordids = array_keys($records);
            if (!empty($recordids)) {
                list($insql, $inparams) = $DB->get_in_or_equal($recordids, SQL_PARAMS_NAMED, 'rec');
                $ratingparams = ['contextid' => $context->id] + $inparams;
                $ratingsql = "SELECT itemid, AVG(rating) AS avgrating, COUNT(1) AS numratings
                                FROM {rating}
                               WHERE contextid = :contextid
                                 AND component = 'mod_data'
                                 AND ratingarea = 'entry'
                                 AND itemid $insql
                            GROUP BY itemid";

                $aggregates = $DB->get_records_sql($ratingsql, $ratingparams);
                if (!empty($aggregates)) {
                    foreach ($aggregates as $agg) {
                        $ratingsbyrecord[$agg->itemid] = $agg;
                    }
                }
            }

            foreach ($records as $record) {
                // Prefetch all contents for this record to reduce DB calls.
                $contents = $DB->get_records('data_content', ['recordid' => $record->id]);
                $contentsbyfield = [];
                foreach ($contents as $content) {
                    $contentsbyfield[$content->fieldid] = $content;
                }

                // Helper to get content by field id.
                $getcontent = function (?int $fieldid) use ($contentsbyfield) {
                    if (empty($fieldid)) {
                        return null;
                    }
                    return $contentsbyfield[$fieldid] ?? null;
                };

                // Subject (plain text).
                $subjectcontent = $getcontent($subjectfieldid);

                if (!$subjectcontent || $subjectcontent->content === null || $subjectcontent->content === '') {
                    continue;
                }

                $subject = \content_to_text($subjectcontent->content, FORMAT_PLAIN);

                // Description (HTML).
                $summary = '';
                if (!empty($descriptionfieldid)) {
                    $desccontent = $getcontent($descriptionfieldid);

                    if ($desccontent && $desccontent->content !== null && $desccontent->content !== '') {
                        $options = new \stdClass();
                        $options->para = false;

                        $text = \file_rewrite_pluginfile_urls(
                            $desccontent->content,
                            'pluginfile.php',
                            $context->id,
                            'mod_data',
                            'content',
                            $desccontent->id
                        );

                        $summary = format_text($text, $desccontent->content1, $options);
                    }
                }

                // Cover image.
                $imagepath = '';
                if (!empty($coverfieldid)) {
                    $covercontent = $getcontent($coverfieldid);

                    if ($covercontent && !empty($covercontent->content)) {
                        // Always inline the cover image as a data URI so that the
                        // browser never has to call pluginfile.php for card images.
                        $fs = get_file_storage();
                        $file = $fs->get_file(
                            $context->id,
                            'mod_data',
                            'content',
                            $covercontent->id,
                            '/',
                            $covercontent->content
                        );

                        if ($file) {
                            $imagepath = self::stored_file_to_cached_url($file, 800);
                        } else {
                            // Fallback to the standard URL if file record is not found.
                            $imgurl = \moodle_url::make_pluginfile_url(
                                $context->id,
                                'mod_data',
                                'content',
                                $covercontent->id,
                                '/',
                                $covercontent->content
                            );

                            $imagepath = $imgurl->out(false);
                        }
                    }
                }

                // If there is no cover image, try to get first image from description HTML.
                if (empty($imagepath) && !empty($summary)) {
                    if (preg_match('/<img[^>]+src\s*=\s*"([^"]+)"/i', $summary, $matches) ||
                        preg_match("/<img[^>]+src\\s*=\\s*'([^']+)'/i", $summary, $matches)) {
                        $imagepath = $matches[1];

                        // If the image comes from pluginfile.php, try to inline it as a data URI
                        // so that the browser does not request pluginfile.php directly.
                        if (!empty($imagepath)) {
                            $pluginfilebase = $CFG->wwwroot . '/pluginfile.php';
                            if (strpos($imagepath, $pluginfilebase) === 0) {
                                $path = parse_url($imagepath, PHP_URL_PATH) ?? '';
                                $relative = preg_replace('#^/pluginfile\.php/#', '', $path);
                                $parts = $relative !== '' ? explode('/', $relative) : [];

                                // 重要：pluginfile.php 路径中的文件名和子目录可能包含
                                // URL 转义（例如中文、空格：%E8%83%8C%E6%99%AF%20...），
                                // 需要先对每一段执行 urldecode 才能被 file_storage 找到。
                                if (!empty($parts)) {
                                    foreach ($parts as &$segment) {
                                        $segment = urldecode($segment);
                                    }
                                    unset($segment);
                                }

                                if (count($parts) >= 5) {
                                    $contextid = (int)$parts[0];
                                    $component = $parts[1];
                                    $filearea = $parts[2];
                                    $itemid = (int)$parts[3];

                                    // Remaining parts: optional subdirectories + filename.
                                    $filename = array_pop($parts);
                                    $subdirs = array_slice($parts, 4);
                                    $filepath = '/';
                                    if (!empty($subdirs)) {
                                        $filepath .= implode('/', $subdirs) . '/';
                                    }

                                    $fs = get_file_storage();
                                    $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);

                                    if ($file) {
                                        $imagepath = self::stored_file_to_cached_url($file, 800);
                                    }
                                }
                            }
                        }
                    }
                }

                // If all channels fail, fall back to the same
                // random/generated course image used in cover image config.
                if (empty($imagepath)) {
                    $imagepath = self::get_courseimage($course);
                }

                // Channels (optional, now used for filtering when a channels filter is configured).
                $channels = '';
                if (!empty($channelsfieldid)) {
                    $channelscontent = $getcontent($channelsfieldid);

                    if ($channelscontent && $channelscontent->content !== null && $channelscontent->content !== '') {
                        $channels = $channelscontent->content;
                    }
                }

                // Apply channels filter (if any): skip resources whose channels do not match.
                if (!empty($channelsfilter)) {
                    $resourcechannels = self::normalize_channels_list($channels);

                    if (empty($resourcechannels)) {
                        continue;
                    }

                    $matched = false;
                    foreach ($resourcechannels as $rch) {
                        if (in_array(mb_strtolower($rch), $channelsfilter, true)) {
                            $matched = true;
                            break;
                        }
                    }

                    if (!$matched) {
                        continue;
                    }
                }

                // Optional code field for custom ordering.
                $code = '';
                if (!empty($codefieldid)) {
                    $codecontent = $getcontent($codefieldid);

                    if ($codecontent && $codecontent->content !== null && $codecontent->content !== '') {
                        $code = \content_to_text($codecontent->content, FORMAT_PLAIN);
                    }
                }

                // Rating based on Moodle's rating subsystem for mod_data entries.
                $ratingtotal = 0.0;
                $ratingcount = 0;
                if (isset($ratingsbyrecord[$record->id])) {
                    $agg = $ratingsbyrecord[$record->id];
                    if ($agg && $agg->avgrating !== null) {
                        $ratingvalue = (float)$agg->avgrating;
                        // Clamp to 0-5 range to keep consistent with block_vitrina.
                        if ($ratingvalue < 0) {
                            $ratingvalue = 0.0;
                        } else if ($ratingvalue > 5) {
                            $ratingvalue = 5.0;
                        }

                        if ($ratingvalue > 0) {
                            $ratingtotal = $ratingvalue;
                            $ratingcount = (int)$agg->numratings;
                        }
                    }
                }

                // Apply fulltext search (if any) against subject, summary,
                // channels, code and rating. All comparisons are case-insensitive.
                if ($fulltext !== '') {
                    $haystack = mb_strtolower(
                        $subject . ' ' .
                        strip_tags((string)$summary) . ' ' .
                        (string)$channels . ' ' .
                        (string)$code . ' ' .
                        ($ratingtotal > 0 ? (string)$ratingtotal : '')
                    );

                    if (mb_strpos($haystack, $fulltext) === false) {
                        continue;
                    }
                }

                // Optional uploaded file field (share_file).
                $sharefileurl = '';
                $sharefilename = '';
                $sharefiletype = '';
                if (!empty($sharefilefieldid)) {
                    $sharefilecontent = $getcontent($sharefilefieldid);

                    if ($sharefilecontent && !empty($sharefilecontent->content)) {
                        $fileurl = \moodle_url::make_pluginfile_url(
                            $context->id,
                            'mod_data',
                            'content',
                            $sharefilecontent->id,
                            '/',
                            $sharefilecontent->content
                        );

                        $sharefileurl = $fileurl->out(false);
                        $sharefilename = $sharefilecontent->content;

                        // Detect a simple document/media type based on file extension.
                        $ext = strtolower(pathinfo($sharefilename, PATHINFO_EXTENSION));

                        if (in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'aac'])) {
                            $sharefiletype = 'audio';
                        } else if (in_array($ext, ['mp4', 'avi', 'mov', 'wmv', 'webm', 'mkv'])) {
                            $sharefiletype = 'video';
                        } else if (in_array($ext, ['ppt', 'pptx', 'key'])) {
                            $sharefiletype = 'presentation';
                        } else if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'rtf'])) {
                            $sharefiletype = 'office';
                        } else if (in_array($ext, ['pdf'])) {
                            $sharefiletype = 'pdf';
                        } else if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'])) {
                            $sharefiletype = 'image';
                        } else if (!empty($ext)) {
                            $sharefiletype = 'other';
                        }
                    }
                }

                // If there is no explicit share_file uploaded, try to detect
                // a candidate file from description HTML, with priority:
                // audio/video > pdf > office > presentation > image.
                if (empty($sharefileurl) && !empty($summary)) {
                    $candidates = [];

                    if (preg_match_all('/(?:href|src)\s*=\s*"([^"]+)"/i', $summary, $matches)) {
                        foreach ($matches[1] as $index => $url) {
                            $path = parse_url($url, PHP_URL_PATH) ?? $url;
                            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                            if (empty($ext)) {
                                continue;
                            }

                            $type = '';
                            $priority = 100;

                            // Priority 1: audio/video.
                            if (in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'aac', 'mp4', 'avi', 'mov', 'wmv', 'webm', 'mkv'])) {
                                $type = in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'aac']) ? 'audio' : 'video';
                                $priority = 1;
                            } else if (in_array($ext, ['pdf'])) {
                                // Priority 2: pdf.
                                $type = 'pdf';
                                $priority = 2;
                            } else if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'rtf'])) {
                                // Priority 3: office.
                                $type = 'office';
                                $priority = 3;
                            } else if (in_array($ext, ['ppt', 'pptx', 'key'])) {
                                // Priority 4: presentation.
                                $type = 'presentation';
                                $priority = 4;
                            } else if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'])) {
                                // Priority 5: image.
                                $type = 'image';
                                $priority = 5;
                            }

                            if ($priority < 100) {
                                $candidates[] = [
                                    'url' => $url,
                                    'path' => $path,
                                    'type' => $type,
                                    'priority' => $priority,
                                    'order' => $index,
                                ];
                            }
                        }
                    }

                    if (!empty($candidates)) {
                        // Pick the candidate with the best (lowest) priority,
                        // and if tied, the first in appearance order.
                        usort($candidates, function ($a, $b) {
                            if ($a['priority'] === $b['priority']) {
                                return $a['order'] <=> $b['order'];
                            }
                            return $a['priority'] <=> $b['priority'];
                        });

                        $best = $candidates[0];
                        $sharefileurl = $best['url'];
                        $sharefilename = basename($best['path']);
                        $sharefiletype = $best['type'];
                    }
                }

                // Optional display status field (show_status, single select).
                $showstatus = '';
                if (!empty($showstatusfieldid)) {
                    $statuscontent = $getcontent($showstatusfieldid);

                    if ($statuscontent && $statuscontent->content !== null && $statuscontent->content !== '') {
                        $showstatus = $statuscontent->content;
                    }
                }

                // Interpret show_status value:
                // - empty or contains "show" (case-insensitive): shown
                // - contains "hide" (case-insensitive): not shown
                // - contains "pin" (case-insensitive): shown and pinned to the top
                $showstatusvalue = trim((string)$showstatus);
                $ispinned = false;
                if ($showstatusvalue !== '') {
                    $lowerstatus = strtolower($showstatusvalue);

                    // If configured as hide, skip this record entirely.
                    if (strpos($lowerstatus, 'hide') !== false) {
                        continue;
                    }

                    // If contains pin, mark as pinned (will be placed before others).
                    if (strpos($lowerstatus, 'pin') !== false) {
                        $ispinned = true;
                    }
                    // Otherwise fall back to normal visible item.
                }
                // If empty, treat as normal visible (not pinned).

                // Map share file type to label and icon class for templates.
                $sharefiletypelabel = '';
                $sharefileicon = '';

                if (!empty($sharefiletype)) {
                    switch ($sharefiletype) {
                        case 'audio':
                            $sharefiletypelabel = get_string('filetype_audio', 'block_vitrinadb');
                            $sharefileicon = 'fa-file-audio';
                            break;
                        case 'video':
                            $sharefiletypelabel = get_string('filetype_video', 'block_vitrinadb');
                            $sharefileicon = 'fa-file-video';
                            break;
                        case 'pdf':
                            $sharefiletypelabel = get_string('filetype_pdf', 'block_vitrinadb');
                            $sharefileicon = 'fa-file-pdf';
                            break;
                        case 'office':
                            $sharefiletypelabel = get_string('filetype_office', 'block_vitrinadb');
                            $sharefileicon = 'fa-file-alt';
                            break;
                        case 'presentation':
                            $sharefiletypelabel = get_string('filetype_presentation', 'block_vitrinadb');
                            $sharefileicon = 'fa-file-powerpoint';
                            break;
                        case 'image':
                            $sharefiletypelabel = get_string('filetype_image', 'block_vitrinadb');
                            $sharefileicon = 'fa-file-image';
                            break;
                        default:
                            $sharefiletypelabel = get_string('filetype_other', 'block_vitrinadb');
                            $sharefileicon = 'fa-file';
                            break;
                    }
                }

                $resource = new \stdClass();
                $resource->courseid = $course->id;
                $resource->category = $course->category;
                $resource->coursename = format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]);
                $resource->subject = $subject;
                $resource->summary = $summary;
                $resource->imagepath = $imagepath;
                $resource->channels = $channels;
                $resource->code = $code;
                // Rating info mapped similarly to course ratings in block_vitrina.
                $resource->rating = null;
                $resource->hasrating = false;
                if ($ratingtotal > 0 && $ratingcount > 0) {
                    $ratingobj = new \stdClass();
                    $ratingobj->total = round($ratingtotal, 1);
                    $ratingobj->count = $ratingcount;
                    $ratingobj->percent = round($ratingobj->total * 20);
                    $ratingobj->formated = str_pad($ratingobj->total, 3, '.0');
                    $ratingobj->stars = $ratingobj->total > 0 ? range(1, (int)round($ratingobj->total)) : null;
                    // Localised hover title, e.g. "2次评价，均分3.5".
                    $ratingobj->title = get_string('rating_detail_title', 'block_vitrinadb', (object) [
                        'avg' => $ratingobj->formated,
                        'count' => $ratingobj->count,
                    ]);
                    $resource->rating = $ratingobj;
                    $resource->hasrating = true;
                }
                $resource->sharefileurl = $sharefileurl;
                $resource->sharefilename = $sharefilename;
                $resource->sharefiletype = $sharefiletype;
                $resource->sharefiletypelabel = $sharefiletypelabel;
                $resource->sharefileicon = $sharefileicon;
                $resource->showstatus = $showstatus;
                $resource->pinned = $ispinned;
                $resource->dataid = $data->id;
                $resource->recordid = $record->id;
                $resource->timeadded = $record->timecreated;
                // Last modification time (fallback to creation time if empty).
                $resource->timemodified = !empty($record->timemodified) ? (int)$record->timemodified : (int)$record->timecreated;

                // User who shared/created this resource.
                $resource->sharedbyid = $record->userid;
                $resource->sharedbyname = '';
                $resource->sharedbyavatar = null;

                $user = $DB->get_record('user', ['id' => $record->userid]);
                if ($user) {
                    // Localised full name.
                    $resource->sharedbyname = fullname($user, true);

                    // User avatar URL.
                    $userpicture = new \user_picture($user, ['alttext' => false, 'link' => false]);
                    $userpicture->size = 100;
                    $resource->sharedbyavatar = $userpicture->get_url($PAGE);
                }

                // Time span since this resource was last updated, in days (0-999).
                $days = 0;
                $basetime = $resource->timemodified ?? $record->timemodified ?? $record->timecreated ?? 0;
                if (!empty($basetime)) {
                    $diffseconds = max(0, $now - (int)$basetime);
                    $days = (int)floor($diffseconds / 86400);
                }

                if ($days > 999) {
                    $days = 999;
                }

                $resource->shareddays = $days;
                $resource->shareddayslabel = get_string('daysago', 'block_vitrinadb', $days);

                if ($ispinned) {
                    $pinnedresources[] = $resource;
                } else {
                    $resources[] = $resource;
                }
            }
        }

        // For special views we override the generic sort behaviour:
        // - "greats" (Outstanding courses): only resources with an
        //   average rating greater than 3, ordered by rating (and then
        //   by number of ratings and last modification date).
        // - "recents" (Next courses): all visible resources ordered by
        //   last modification date (newest first), ignoring PIN order.
        // - "premium" (Premium courses): only resources whose show_status
        //   contains "prime" (case-insensitive), also ordered by
        //   last modification date and ignoring PIN order.

        if ($view === 'greats') {
            $allresources = array_merge($pinnedresources, $resources);

            // Keep only resources that have a rating higher than 3.
            $allresources = array_values(array_filter($allresources, function($resource) {
                if (empty($resource->hasrating) || empty($resource->rating)) {
                    return false;
                }

                $total = (float)($resource->rating->total ?? 0);

                return $total > 3.0;
            }));

            // Order by rating DESC, then by number of ratings DESC, and finally
            // by last modification date DESC so that the most relevant
            // resources appear first.
            usort($allresources, function($a, $b) {
                $ra = isset($a->rating->total) ? (float)$a->rating->total : 0.0;
                $rb = isset($b->rating->total) ? (float)$b->rating->total : 0.0;

                if ($ra == $rb) {
                    $ca = isset($a->rating->count) ? (int)$a->rating->count : 0;
                    $cb = isset($b->rating->count) ? (int)$b->rating->count : 0;

                    if ($ca == $cb) {
                        $ta = $a->timemodified ?? $a->timeadded ?? 0;
                        $tb = $b->timemodified ?? $b->timeadded ?? 0;

                        if ($ta == $tb) {
                            return 0;
                        }

                        // Descending: newest first.
                        return ($ta > $tb) ? -1 : 1;
                    }

                    // Descending: more ratings first.
                    return ($ca > $cb) ? -1 : 1;
                }

                // Descending: higher rating first.
                return ($ra > $rb) ? -1 : 1;
            });

            if ($amount > 0 || $initial > 0) {
                $allresources = array_slice($allresources, $initial, $amount);
            }

            return $allresources;
        }

        if ($view === 'recents' || $view === 'premium') {
            $allresources = array_merge($pinnedresources, $resources);

            if ($view === 'premium') {
                $allresources = array_values(array_filter($allresources, function($resource) {
                    $status = strtolower((string)($resource->showstatus ?? ''));
                    return $status !== '' && strpos($status, 'prime') !== false;
                }));
            }

            usort($allresources, function($a, $b) {
                $ta = $a->timemodified ?? $a->timeadded ?? 0;
                $tb = $b->timemodified ?? $b->timeadded ?? 0;

                if ($ta == $tb) {
                    return 0;
                }

                // Descending: newest first.
                return ($ta > $tb) ? -1 : 1;
            });

            if ($amount > 0 || $initial > 0) {
                $allresources = array_slice($allresources, $initial, $amount);
            }

            return $allresources;
        }

        // Sort resources according to the selected mode while preserving
        // the principle that pinned items always appear first.

        $sortkey = function($resource) use ($sort) {
            switch ($sort) {
                case 'alphabetically':
                    $value = $resource->subject ?? '';
                    break;
                case 'code':
                    $value = $resource->code ?? '';
                    break;
                case 'default':
                default:
                    // For default we keep DB order, so rely on a monotonically
                    // increasing index to make this comparator a no-op later.
                    static $i = 0;
                    $value = $i++;
                    break;
            }

            if (is_string($value)) {
                $value = \core_text::strtolower($value);
            }

            return $value;
        };

        $sortresources = function(array &$list) use ($sort, $sortasc, $sortkey) {
            if ($sort === 'default') {
                // Preserve DB order as returned above.
                return;
            }

            usort($list, function($a, $b) use ($sortasc, $sortkey) {
                $ka = $sortkey($a);
                $kb = $sortkey($b);

                if ($ka == $kb) {
                    return 0;
                }

                if ($sortasc) {
                    return ($ka < $kb) ? -1 : 1;
                }

                return ($ka > $kb) ? -1 : 1;
            });
        };

        // Apply sorting inside each group so that pinned resources always
        // stay on top for any sort mode.
        $sortresources($pinnedresources);
        $sortresources($resources);

        $allresources = array_merge($pinnedresources, $resources);

        // Apply pagination after sorting to support infinite scroll.
        if ($amount > 0 || $initial > 0) {
            $allresources = array_slice($allresources, $initial, $amount);
        }

        return $allresources;
    }

    /**
     * Normalise a raw channels string into a list of individual channel names.
     *
    * Splits on commas/semicolons (including full-width variants) and newlines,
    * then trims whitespace around each value.
     *
     * @param string $raw Raw channels string.
     * @return array List of non-empty channel names.
     */
    public static function normalize_channels_list(string $raw): array {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        // Split on comma/semicolon (full/half width), line breaks and the
        // "##" separator used by Data module multi-select (multimenu) fields.
        $parts = preg_split('/(?:[;,，；\r\n]+|##)+/u', $raw);

        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $result[] = $part;
            }
        }

        if (empty($result)) {
            return [];
        }

        return array_values(array_unique($result));
    }

    /**
     * Get the icont list for views tabs.
     *
     * @return array The icons list.
     */
    public static function get_views_icons(): array {

        if (!empty(self::$viewsicons)) {
            return self::$viewsicons;
        }

        $customicons = get_config('block_vitrinadb', 'viewsicons');

        $icons = [
            'default' => 'a/view_icon_active',
            'greats' => 't/emptystar',
            'premium' => 'i/badge',
            'recents' => 'i/calendareventtime',
        ];

        if (!empty($customicons)) {
            $lines = explode("\n", $customicons);

            foreach ($lines as $line) {
                $line = trim($line);
                $options = explode('=', $line);
                if (count($options) == 2) {
                    $icons[trim($options[0])] = trim($options[1]);
                }
            }
        }

        self::$viewsicons = $icons;

        return $icons;
    }

    /**
     * Define if show icons in tabs views.
     *
     * @return bool If show icons.
     */
    public static function show_tabicon(): bool {

        if (self::$showicons !== null) {
            return self::$showicons;
        }

        // Tabs config view.
        $tabview = get_config('block_vitrinadb', 'tabview');

        self::$showicons = !empty($tabview) ? $tabview !== 'showtext' : false;

        return self::$showicons;
    }

    /**
     * Define if show the text in tabs views.
     *
     * @return bool If show the text.
     */
    public static function show_tabtext(): bool {

        if (self::$showtext !== null) {
            return self::$showtext;
        }

        // Tabs config view.
        $tabview = get_config('block_vitrinadb', 'tabview');

        self::$showtext = !empty($tabview) ? $tabview !== 'showicon' : false;

        return self::$showtext;
    }

    /**
     * Get the available languages list.
     *
     * @param array $selectedlist The selected languages.
     * @return array The languages list.
     */
    public static function get_languages(array $selectedlist = []): array {
        $langs = get_string_manager()->get_list_of_translations();

        $response = [];

        foreach ($langs as $lang => $name) {
            $selected = in_array($lang, $selectedlist);
            $response[] = [
                'value' => $lang,
                'label' => $name,
                'selected' => $selected,
            ];
        }

        return $response;
    }

    /**
     * Get the available categories list.
     *
     * @param array $selectedlist The selected categories.
     * @param bool $nested If return the categories in a nested way.
     * @return array The categories list.
     */
    public static function get_categories(array $selectedlist = [], bool $nested = false): array {
        global $DB;

        $select = 'visible = 1';
        $params = [];

        $categoriesids = [];
        $categories = get_config('block_vitrinadb', 'categories');
        $catslist = explode(',', $categories);
        foreach ($catslist as $catid) {
            if (is_numeric($catid)) {
                $categoriesids[] = (int) trim($catid);
            }
        }

        if (count($categoriesids) > 0) {
            [$selectincats, $paramsincats] = $DB->get_in_or_equal($categoriesids, SQL_PARAMS_NAMED, 'categories');
            $params += $paramsincats;
            $select .= ' AND id ' . $selectincats;
        }

        $categories = $DB->get_records_select('course_categories', $select, $params, 'sortorder ASC');

        $response = [];

        foreach ($categories as $category) {
            $selected = in_array($category->id, $selectedlist);
            $node = (object)[
                'value' => $category->id,
                'label' => format_string($category->name, true),
                'selected' => $selected,
                'haschilds' => false,
                'childs' => [],
                'indent' => 0,
            ];

            if ($nested && $category->parent) {
                $parents = explode('/', $category->path);

                // Search the most parent category.
                $tosearch = $response;
                $root = null;
                $indent = 0;
                foreach ($parents as $parentid) {
                    if ($parentid == $category->id) {
                        continue;
                    }

                    foreach ($tosearch as $element) {
                        if ($element->value == $parentid) {
                            $indent++;
                            $root = $element;
                            $root->haschilds = true;
                            $tosearch = $root->childs;
                            break;
                        }
                    }
                }

                $node->indent = $indent;
                // Add the category to the more close parent.
                if ($root) {
                    $root->childs[] = $node;
                } else {
                    $response[] = $node;
                }
            } else {
                $response[] = $node;
            }
        }

        return $response;
    }

    /**
     * Get the available custom fields to filter.
     *
     * @param array $selectedvalues The selected values.
     * @return array The custom fields to filter.
     */
    public static function get_customfieldsfilters(array $selectedvalues = []): array {
        global $DB;

        $filtercontrols = [];

        $customfields = self::get_configuredcustomfields();

        foreach ($customfields as $key => $customfield) {
            $options = [];
            $selectedinfield = [];

            if (!empty($selectedvalues[$customfield->id])) {
                $selectedinfield = $selectedvalues[$customfield->id];
            }

            switch ($customfield->type) {
                case 'checkbox':
                    $options[] = [
                        'value' => 1,
                        'label' => get_string('yes'),
                        'selected' => in_array(1, $selectedinfield),
                    ];
                    $options[] = [
                        'value' => 0,
                        'label' => get_string('no'),
                        'selected' => in_array(0, $selectedinfield),
                    ];
                    break;
                case 'multiselect':
                case 'select':
                    $data = @json_decode($customfield->configdata);

                    $parsedoptions = explode("\n", $data->options);
                    foreach ($parsedoptions as $pos => $value) {
                        $index = $pos + 1;
                        $selected = in_array($index, $selectedinfield);
                        $options[] = [
                            'value' => $index,
                            'label' => format_string($value, true),
                            'selected' => $selected,
                        ];
                    }
                    break;
            }

            if (count($options) > 1) {
                $control = new \stdClass();
                $control->title = format_string($customfield->name, true);
                $control->key = $customfield->id;
                $control->options = $options;
                $filtercontrols[] = $control;
            }
        }

        return $filtercontrols;
    }

    /**
     * Return confugured custom field to filter.
     *
     * @return array The custom fields objects selected to filter.
     */
    public static function get_configuredcustomfields(): array {
        global $DB;

        $filtercustomfields = get_config('block_vitrinadb', 'filtercustomfields');

        if (!empty($filtercustomfields)) {
            $filtercustomfields = explode(',', $filtercustomfields);
        }

        if (!$filtercustomfields || count($filtercustomfields) == 0) {
            return [];
        }

        // Cast to int.
        $filtercustomfields = array_map('intval', $filtercustomfields);

        [$selectin, $params] = $DB->get_in_or_equal($filtercustomfields, SQL_PARAMS_NAMED, 'ids');
        $select = ' cf.id ' . $selectin;

        $sql = "SELECT cf.* FROM {customfield_field} cf " .
        " INNER JOIN {customfield_category} cc ON cc.id = cf.categoryid AND cc.component = 'core_course'" .
        " WHERE " . $select .
        " ORDER BY cf.sortorder ASC";
        $customfields = $DB->get_records_sql($sql, $params);

        return $customfields;
    }

    /**
     * Get the available static filters.
     *
     * @return array The static filters.
     */
    public static function get_staticfilters(): array {
        return self::STATICFILTERS;
    }

    /**
     * Set the available enrol info in a course.
     *
     * @param object $course The course object.
     */
    public static function load_enrolinfo(object $course) {
        global $USER, $CFG, $DB;

        // Load course context to general purpose.
        $coursecontext = \context_course::instance($course->id, $USER, '', true);

        // Load the course enrol info.
        $enrolinstances = enrol_get_instances($course->id, true);

        $course->enrollable = false;
        $course->enrollsavailables = [];
        $course->fee = [];
        $course->haspaymentgw = false;
        $course->enrolled = !(isguestuser() || !isloggedin() || !is_enrolled($coursecontext));
        $course->canview = has_capability('moodle/course:view', $coursecontext);
        $ispremium = self::is_user_premium();

        $premiumcohort = get_config('block_vitrinadb', 'premiumcohort');

        foreach ($enrolinstances as $instance) {
            if ($instance->enrolstartdate > time() || ($instance->enrolenddate > 0 && $instance->enrolenddate < time())) {
                // Self enrolment not yet started.
                continue;
            }

            if ($instance->enrol == 'self') {
                if ($instance->customint3 > 0) {
                    // Max enrol limit specified.
                    $count = $DB->count_records('user_enrolments', ['enrolid' => $instance->id]);
                    if ($count >= $instance->customint3) {
                        // Bad luck, no more self enrolments here.
                        continue;
                    }
                }

                // Course premium require a self enrolment.
                if (property_exists($course, 'premium') && ($course->premium || !self::premium_available()) && $ispremium) {
                    // The validation only applies to premium courses if the premiumcohort setting is configured.
                    // If premiumcohort is configured the course requires the specific cohort.
                    if (
                        !$premiumcohort
                        || empty($instance->customint5)
                        || $instance->customint5 == $premiumcohort
                    ) {
                        $course->enrollable = true;
                        $course->enrollsavailables['premium'][] = $instance;
                        continue;
                    }
                }

                if ($instance->customint5) {
                    require_once($CFG->dirroot . '/cohort/lib.php');
                    if (!cohort_is_member($instance->customint5, $USER->id)) {
                        // The user cannot enroll because they are not in the cohort.
                        continue;
                    }
                }

                $course->enrollsavailables['self'][] = $instance;
                $course->enrollable = true;
            } else if ($instance->enrol == 'fee' && enrol_is_enabled('fee')) {
                $cost = (float) $instance->cost;
                if ($cost <= 0) {
                    $cost = (float) get_config('enrol_fee', 'cost');
                }

                if ($cost > 0) {
                    $datafee = new \stdClass();
                    $datafee->cost = $cost;
                    $datafee->currency = $instance->currency;
                    $datafee->formatedcost = self::format_cost($cost, $instance->currency);
                    $datafee->itemid = $instance->id;
                    $datafee->label = !empty($instance->name) ? $instance->name : get_string('sendpaymentbutton', 'enrol_fee');
                    $datafee->description = get_string(
                        'purchasedescription',
                        'enrol_fee',
                        format_string($course->fullname, true, ['context' => $coursecontext])
                    );
                    $datafee->originalcoursename = $course->fullname;

                    $course->fee[] = $datafee;
                    $course->enrollable = true;
                    $course->enrollsavailables['fee'][] = $instance;
                    $course->haspaymentgw = true;
                }
            } else if ($instance->enrol == 'guest' && enrol_is_enabled('guest')) {
                $course->enrollable = true;
                $course->enrollsavailables['guest'][] = $instance;
            } else if ($instance->enrol == 'customgr' && enrol_is_enabled('customgr')) {
                $enrolplugin = enrol_get_plugin('customgr');

                if ($enrolplugin->is_self_enrol_available($instance)) {
                    $course->enrollable = true;
                    $course->enrollsavailables['customgr'][] = $instance;
                }
            } else if ($instance->enrol == 'token' && enrol_is_enabled('token')) {
                $enrolplugin = enrol_get_plugin('token');

                if ($enrolplugin->is_self_enrol_available($instance)) {
                    $course->enrollable = true;
                    $course->enrollsavailables['token'][] = $instance;
                }
            }
        }
    }

    /**
     * Returns human-readable amount with correct number of fractional digits and currency indicator, can also apply surcharge
     *
     * @param float $amount amount in the currency units
     * @param string $currency The currency
     * @param float $surcharge surcharge in percents
     * @return string
     */
    public static function format_cost(float $amount, string $currency, float $surcharge = 0): string {
        $amount = $amount * (100 + $surcharge) / 100;

        $decimalpoints = (int)get_config('block_vitrinadb', 'decimalpoints');

        $locale = get_string('localecldr', 'langconfig');
        $fmt = \NumberFormatter::create($locale, \NumberFormatter::CURRENCY);
        $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimalpoints);
        $localisedcost = numfmt_format_currency($fmt, $amount, $currency);

        if (strpos($localisedcost, '$') === false) {
            $localisedcost = '$' . $localisedcost;
        }

        return $localisedcost;
    }

    /**
     * Get the usable course rate manager.
     */
    public static function get_ratemanager(): string {

        $rateplugin = get_config('block_vitrinadb', 'ratingmanager');

        switch ($rateplugin) {
            case 'tool_courserating':
                return '\block_vitrinadb\local\rating\tool_courserating';
            break;
            default:
                return '\block_vitrinadb\local\rating\base';
        }
    }

    /**
     * Get the usable course comments manager.
     */
    public static function get_commentsmanager(): string {

        $commentsplugin = get_config('block_vitrinadb', 'commentsmanager');

        switch ($commentsplugin) {
            case 'tool_courserating':
                return '\block_vitrinadb\local\comments\tool_courserating';
            break;
            default:
                return '\block_vitrinadb\local\comments\base';
        }
    }

    /**
     * Ensure a cached copy of the file exists (optionally resized) and return
     * a public URL that serves it via imagecache.php.
     *
     * Behaviour:
     * - All files are cached under $CFG->localcachedir/block_vitrinadb/<widthkey>/<hash>
     * - If the file is an image wider than $maxwidth, a resized version is cached
     *   (widthkey = maxwidth). Otherwise the original bytes are cached (widthkey = 0).
     * - The returned URL does not require login and is suitable for browser caching.
     *
     * @param \stored_file $file
     * @param int $maxwidth Maximum width in pixels before downscaling (0 to disable).
     * @return string|null Public URL to the cached file or null on failure.
     */
    public static function stored_file_to_cached_url(\stored_file $file, int $maxwidth = 600): ?string {
        global $CFG;

        $hash = $file->get_contenthash();
        if (empty($hash)) {
            return null;
        }

        // Decide whether we need a resized variant or the original bytes.
        $widthkey = 0;
        $imageinfo = $file->get_imageinfo();
        if ($imageinfo && !empty($maxwidth) && !empty($imageinfo['width']) && $imageinfo['width'] > $maxwidth) {
            $widthkey = (int)$maxwidth;
        }

        $cachedir = make_localcache_directory('block_vitrinadb/' . $widthkey, false);
        if (!$cachedir) {
            return null;
        }

        $cachepath = $cachedir . '/' . $hash;

        if (!is_readable($cachepath)) {
            $content = null;

            if ($widthkey > 0 && $imageinfo) {
                // Try to generate a resized image from the original content.
                $resized = $file->resize_image($maxwidth, null);
                if ($resized !== false && $resized !== '') {
                    $content = $resized;
                }
            }

            // If resize is not needed or failed, fall back to the original content.
            if ($content === null) {
                $content = $file->get_content();
            }

            if ($content === '' || $content === false) {
                return null;
            }

            @file_put_contents($cachepath, $content);
        }

        if (!is_readable($cachepath)) {
            return null;
        }

        // Build a public URL that will serve the cached file without requiring login.
        $params = ['h' => $hash];
        if ($widthkey > 0) {
            $params['w'] = $widthkey;
        }

        $url = new \moodle_url('/blocks/vitrinadb/imagecache.php', $params);
        return $url->out(false);
    }
}
