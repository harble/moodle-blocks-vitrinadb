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
     * New implementation: build a single aggregated SQL per Database (mod_data)
     * activity using dynamic WHERE / ORDER BY / LIMIT clauses based on the
     * provided parameters. The SQL pre-joins custom fields, tags and ratings so
     * that most filters, ordering and pagination are handled at the DB layer.
     *
     * The legacy implementation is preserved in get_course_resources_backup().
     *
     * @param \stdClass $course Course record.
     * @param int|null $dataid Optional specific Database (mod_data) instance id to restrict to.
     * @param string $view View key (default, recents, greats, premium).
     * @param array $filters Filters list (channels, tags, fulltext, author, pending).
     * @param string $sort Sort key for records (default, alphabetically, code).
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

        $resources = [];
        $now = time();

        // Normalise any fulltext search value (applies to resource subject,
        // description and channels) and filters provided from caller.
        $fulltext = '';

        // Normalise any channels filter provided (from block instance config or API caller).
        $channelsfilter = [];
        // Normalise any tags filter provided (single-select) using
        // Database (mod_data) record tags.
        $tagsfilter = [];
        // Normalise any author filter provided (single user id).
        $authorfilter = 0;
        // Normalise any "only pending" filter (checkbox) for approval status.
        $onlypending = false;

        $isadmin = \is_siteadmin();
        foreach ($filters as $filter) {
            if (!empty($filter['type']) && $filter['type'] === 'channels' && !empty($filter['values'])) {
                foreach ($filter['values'] as $value) {
                    $parts = self::normalize_channels_list((string)$value);
                    foreach ($parts as $part) {
                        $channelsfilter[] = mb_strtolower($part);
                    }
                }
            } else if (!empty($filter['type']) && $filter['type'] === 'tags' && !empty($filter['values'])) {
                foreach ($filter['values'] as $value) {
                    $value = (int)$value;
                    if ($value > 0) {
                        $tagsfilter[] = $value;
                    }
                }
            } else if (!empty($filter['type']) && $filter['type'] === 'fulltext' && !empty($filter['values'])) {
                $text = trim(implode(' ', (array)$filter['values']));
                if ($text !== '') {
                    $fulltext = mb_strtolower($text);
                }
            } else if (!empty($filter['type']) && $filter['type'] === 'author' && !empty($filter['values'])) {
                // Single-select dropdown: first non-empty value wins.
                $candidate = (int)reset($filter['values']);
                if ($candidate > 0) {
                    $authorfilter = $candidate;
                }
            } else if (!empty($filter['type']) && $filter['type'] === 'pending' && $isadmin) {
                // Checkbox filter: any truthy value turns it on. This filter
                // is only available to site administrators.
                if (!empty($filter['values'])) {
                    $candidate = reset($filter['values']);
                    if ($candidate !== '' && $candidate !== '0' && $candidate !== 0) {
                        $onlypending = true;
                    }
                }
            }
        }
        if (!empty($channelsfilter)) {
            $channelsfilter = array_values(array_unique($channelsfilter));
        }
        if (!empty($tagsfilter)) {
            $tagsfilter = array_values(array_unique($tagsfilter));
        }

        // Normalise sort direction.
        $sortdirection = strtoupper($sortdirection) === 'ASC' ? 'ASC' : 'DESC';

        // Normalise sort key for resources.
        $sort = trim(strtolower($sort));
        if ($sort === '' || $sort === 'timecreated' || $sort === 'default') {
            $sort = 'default';
        } else if ($sort !== 'alphabetically' && $sort !== 'code') {
            // Any unsupported key falls back to default.
            $sort = 'default';
        }

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

            // Approval state: by default only approved records; when the
            // "only pending" filter is active, only unapproved records.
            $approvedstate = $onlypending ? 0 : 1;

            // Base parameters and WHERE conditions. Note that the same
            // dataid value is used in multiple subqueries, so each one gets
            // its own named placeholder to keep Moodle DML parameter counts
            // consistent.
            $params = [
                'dataid' => $data->id,
                'dcdataid' => $data->id,
                'ttdataid' => $data->id,
                'rtdataid' => $data->id,
                'approved' => $approvedstate,
            ];

            $where = [
                'r.dataid = :dataid',
                'r.approved = :approved',
            ];

            // Author filter at SQL level when available.
            if ($authorfilter > 0) {
                $where[] = 'r.userid = :authorfilter';
                $params['authorfilter'] = $authorfilter;
            }

            // Tags filter: restrict to records tagged with any of the
            // selected tags in this Database activity.
            if (!empty($tagsfilter)) {
                list($intagsql, $tagparams) = $DB->get_in_or_equal($tagsfilter, SQL_PARAMS_NAMED, 'tagid');
                $where[] = "EXISTS (SELECT 1
                                       FROM {tag_instance} ti2
                                      WHERE ti2.component = 'mod_data'
                                        AND ti2.itemtype = 'data_records'
                                        AND ti2.itemid = r.id
                                        AND ti2.tagid $intagsql)";
                $params += $tagparams;
            }

            // Aggregated custom fields for this Database: subject, cover_page,
            // description, channels, code and share_file. We also expose the
            // data_content ids for description/cover/share so that pluginfile
            // URLs and file_storage lookups can still be built.
            $dcsql = "SELECT
                          c.recordid,
                          MAX(CASE WHEN f.description = 'subject' THEN NULLIF(c.content, '') END)      AS fc_subject,
                          MAX(CASE WHEN f.description = 'cover_page' THEN c.content END)               AS fc_cover_page,
                          MAX(CASE WHEN f.description = 'cover_page' THEN c.id END)                    AS fc_cover_page_itemid,
                          MAX(CASE WHEN f.description = 'description' THEN c.content END)              AS fc_description,
                          MAX(CASE WHEN f.description = 'description' THEN c.content1 END)             AS fc_description_format,
                          MAX(CASE WHEN f.description = 'description' THEN c.id END)                   AS fc_description_itemid,
                          MAX(CASE WHEN f.description = 'channels' THEN CONCAT('##', c.content, '##') END) AS fc_channels,
                          MAX(CASE WHEN f.description = 'code' THEN c.content END)                     AS fc_code,
                          MAX(CASE WHEN f.description = 'share_file' THEN c.content END)               AS fc_share_file,
                          MAX(CASE WHEN f.description = 'share_file' THEN c.id END)                    AS fc_share_file_itemid
                      FROM {data_content} c
                      JOIN {data_fields} f ON f.id = c.fieldid
                      JOIN {data_records} r2 ON r2.id = c.recordid
                            WHERE r2.dataid = :dcdataid
                  GROUP BY c.recordid";

            // Tags aggregated per record (names + ispinned/isprime flags).
            $ttsql = "SELECT
                          r.id AS recordid,
                          CONCAT('|', GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR '|'), '|') AS fc_tags,
                          MAX(CASE WHEN LOWER(t.name) LIKE '%pin%'   THEN 1 ELSE 0 END)        AS ispinned,
                          MAX(CASE WHEN LOWER(t.name) LIKE '%prime%' THEN 1 ELSE 0 END)        AS isprime
                      FROM {tag_instance} ti
                      JOIN {tag} t ON t.id = ti.tagid
                      JOIN {data_records} r ON r.id = ti.itemid
                                         WHERE ti.component = 'mod_data'
                                             AND ti.itemtype  = 'data_records'
                                             AND r.dataid     = :ttdataid
                  GROUP BY r.id";

              // Ratings aggregated per record using core rating table
              // (mod_data entry ratings, aggregated as AVG), restricted to
              // entries belonging to the current Database (dataid).
              $rtsql = "SELECT rt.itemid,
                            AVG(rt.rating) AS ratingtotal,
                            COUNT(1)       AS ratingcount
                        FROM {rating} rt
                        JOIN {data_records} r2 ON r2.id = rt.itemid
                       WHERE rt.component  = 'mod_data'
                        AND rt.ratingarea = 'entry'
                        AND r2.dataid     = :rtdataid
                    GROUP BY rt.itemid";

            // Only keep records that have a non-empty subject.
            $where[] = 'dc.fc_subject IS NOT NULL';

            // Channels filter: restrict to records whose channels field contains
            // at least one of the selected channel values (case-insensitive).
            if (!empty($channelsfilter)) {
                $channelsconditions = [];
                foreach ($channelsfilter as $idx => $chvalue) {
                    $paramname = 'chan' . $idx;
                    $channelsconditions[] = "LOWER(dc.fc_channels) LIKE :$paramname";
                    $params[$paramname] = '%##' . $DB->sql_like_escape($chvalue) . '##%';
                }
                if (!empty($channelsconditions)) {
                    $where[] = '(' . implode(' OR ', $channelsconditions) . ')';
                }
            }

            // Fulltext filter: search in subject, description, channels and
            // code columns using a simple case-insensitive LIKE.
            if ($fulltext !== '') {
                $fulltextparam = '%' . $DB->sql_like_escape($fulltext) . '%';
                $where[] = '('
                    . 'LOWER(dc.fc_subject) LIKE :fulltext1 OR '
                    . 'LOWER(dc.fc_description) LIKE :fulltext2 OR '
                    . 'LOWER(dc.fc_channels) LIKE :fulltext3 OR '
                    . 'LOWER(dc.fc_code) LIKE :fulltext4'
                    . ')';
                $params['fulltext1'] = $fulltextparam;
                $params['fulltext2'] = $fulltextparam;
                $params['fulltext3'] = $fulltextparam;
                $params['fulltext4'] = $fulltextparam;
            }

            // View-specific filters and ordering.
            $orderby = '';

            if ($view === 'greats') {
                // Only resources that have a rating higher than 3.
                $where[] = 'rt.ratingtotal > 3';
                $orderby = 'rt.ratingtotal DESC, rt.ratingcount DESC, COALESCE(r.timemodified, r.timecreated) DESC';
            } else if ($view === 'recents') {
                // All visible resources ordered by last modification date (newest first),
                // ignoring PIN order.
                $orderby = 'COALESCE(r.timemodified, r.timecreated) DESC';
            } else if ($view === 'premium') {
                // Only resources explicitly marked as prime, ordered by last
                // modification date (newest first), ignoring PIN order.
                $where[] = 'tt.isprime = 1';
                $orderby = 'COALESCE(r.timemodified, r.timecreated) DESC';
            } else {
                // Default view: keep PIN order and user-selected sort.
                $pinexpr = 'CASE WHEN tt.ispinned = 1 THEN 0 ELSE 1 END';

                switch ($sort) {
                    case 'alphabetically':
                        $keyexpr = 'LOWER(dc.fc_subject)';
                        break;
                    case 'code':
                        $keyexpr = 'LOWER(dc.fc_code)';
                        break;
                    case 'default':
                    default:
                        $keyexpr = 'r.timecreated';
                        break;
                }

                $dir = $sortdirection === 'ASC' ? 'ASC' : 'DESC';
                $orderby = "$pinexpr ASC, $keyexpr $dir, r.timecreated DESC";
            }

            $sql = "SELECT
                        r.*,
                        dc.fc_subject,
                        dc.fc_cover_page,
                        dc.fc_cover_page_itemid,
                        dc.fc_description,
                        dc.fc_description_format,
                        dc.fc_description_itemid,
                        dc.fc_channels,
                        dc.fc_code,
                        dc.fc_share_file,
                        dc.fc_share_file_itemid,
                        tt.fc_tags,
                        tt.ispinned,
                        tt.isprime,
                        rt.ratingtotal,
                        rt.ratingcount
                    FROM {data_records} r
                    JOIN ($dcsql) dc ON dc.recordid = r.id
               LEFT JOIN ($ttsql) tt ON tt.recordid = r.id
               LEFT JOIN ($rtsql) rt ON rt.itemid = r.id
                   WHERE " . implode(' AND ', $where);

            if ($orderby !== '') {
                $sql .= " ORDER BY " . $orderby;
            }

            if ($amount > 0 || $initial > 0) {
                $records = $DB->get_records_sql($sql, $params, $initial, $amount > 0 ? $amount : 0);
            } else {
                $records = $DB->get_records_sql($sql, $params);
            }

            if (empty($records)) {
                continue;
            }

            foreach ($records as $record) {
                // Subject (plain text).
                $subject = \content_to_text((string)$record->fc_subject, FORMAT_PLAIN);
                if ($subject === '') {
                    continue;
                }

                // Description (HTML).
                $summary = '';
                if (!empty($record->fc_description)) {
                    $options = new \stdClass();
                    $options->para = false;

                    $text = \file_rewrite_pluginfile_urls(
                        $record->fc_description,
                        'pluginfile.php',
                        $context->id,
                        'mod_data',
                        'content',
                        (int)$record->fc_description_itemid
                    );

                    $summary = format_text($text, (int)$record->fc_description_format, $options);
                }

                // Cover image.
                $imagepath = '';
                if (!empty($record->fc_cover_page) && !empty($record->fc_cover_page_itemid)) {
                    // Always inline the cover image as a data URI so that the
                    // browser never has to call pluginfile.php for card images.
                    $fs = get_file_storage();
                    $file = $fs->get_file(
                        $context->id,
                        'mod_data',
                        'content',
                        (int)$record->fc_cover_page_itemid,
                        '/',
                        $record->fc_cover_page
                    );

                    if ($file) {
                        $imagepath = self::stored_file_to_cached_url($file, 800);
                    } else {
                        // Fallback to the standard URL if file record is not found.
                        $imgurl = \moodle_url::make_pluginfile_url(
                            $context->id,
                            'mod_data',
                            'content',
                            (int)$record->fc_cover_page_itemid,
                            '/',
                            $record->fc_cover_page
                        );

                        $imagepath = $imgurl->out(false);
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

                // Channels (optional, used for display and SQL-level filtering).
                $channels = (string)($record->fc_channels ?? '');

                // Optional code field for custom ordering.
                $code = '';
                if (!empty($record->fc_code)) {
                    $code = \content_to_text((string)$record->fc_code, FORMAT_PLAIN);
                }

                // Rating based on Moodle's rating subsystem for mod_data entries
                // using the aggregated values returned by the main SQL.
                $ratingtotal = 0.0;
                $ratingcount = 0;
                if (isset($record->ratingtotal) && $record->ratingtotal !== null) {
                    $ratingvalue = (float)$record->ratingtotal;
                    // Clamp to 0-5 range to keep consistent with block_vitrina.
                    if ($ratingvalue < 0) {
                        $ratingvalue = 0.0;
                    } else if ($ratingvalue > 5) {
                        $ratingvalue = 5.0;
                    }

                    if ($ratingvalue > 0) {
                        $ratingtotal = $ratingvalue;
                        $ratingcount = isset($record->ratingcount) ? (int)$record->ratingcount : 0;
                    }
                }


                // Optional uploaded file field (share_file).
                $sharefileurl = '';
                $sharefilename = '';
                $sharefiletype = '';
                if (!empty($record->fc_share_file) && !empty($record->fc_share_file_itemid)) {
                    $fileurl = \moodle_url::make_pluginfile_url(
                        $context->id,
                        'mod_data',
                        'content',
                        (int)$record->fc_share_file_itemid,
                        '/',
                        $record->fc_share_file
                    );

                    $sharefileurl = $fileurl->out(false);
                    $sharefilename = (string)$record->fc_share_file;

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

                // Ensure we always expose some file type information to
                // templates, even when there is no explicit file or
                // embedded resource detected. If there is HTML description
                // content, treat it as an "HTML" resource; otherwise fall
                // back to a generic "other" file type.
                if (empty($sharefiletype)) {
                    if (!empty($summary)) {
                        $sharefiletype = 'html';
                    } else {
                        $sharefiletype = 'other';
                    }
                }

                // Tags for display (already aggregated as a pipe-delimited string).
                $recordtagnames = [];
                if (!empty($record->fc_tags)) {
                    $trimmed = trim((string)$record->fc_tags, '|');
                    if ($trimmed !== '') {
                        $recordtagnames = explode('|', $trimmed);
                    }
                }

                // Determine pin/prime state based on aggregated flags.
                $ispinned = !empty($record->ispinned);
                $isprime = !empty($record->isprime);

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
                        case 'html':
                            $sharefiletypelabel = get_string('filetype_html', 'block_vitrinadb');
                            $sharefileicon = 'fa-file-code';
                            break;
                        default:
                            $sharefiletypelabel = get_string('filetype_other', 'block_vitrinadb');
                            $sharefileicon = 'fa-file';
                            break;
                    }
                }

                // Build a localised title based on the record's channels, tags and code, if any.
                // Channels: join multiple values for display. Tags: use the previously
                // collected tag names and append them on a new line. Code: append as an
                // extra line when present.
                $sharefiletitle = '';
                if (!empty($channels)) {
                    if (is_array($channels)) {
                        $channelslist = array_filter(array_map('trim', $channels), function($v) {
                            return $v !== '';
                        });
                        $channelsstr = implode('/', $channelslist);
                    } else {
                        $channelsstr = trim((string)$channels, '#');
                        // Some database multi-selects store values separated by "##".
                        // Normalise these for display.
                        if ($channelsstr !== '') {
                            $channelsstr = str_replace('##', ' | ', $channelsstr);
                        }
                    }

                    if ($channelsstr !== '') {
                        $sharefiletitle = get_string('resource_channels_title', 'block_vitrinadb', $channelsstr);
                    }
                }

                // Append tags information on a new line when the record has tags.
                if (!empty($recordtagnames)) {
                    $tagsstr = implode(' | ', $recordtagnames);
                    $tagline = get_string('resource_tags_title', 'block_vitrinadb', $tagsstr);

                    if ($sharefiletitle !== '') {
                        $sharefiletitle .= "\n" . $tagline;
                    } else {
                        $sharefiletitle = $tagline;
                    }
                }

                // Append code information on a new line when a code value exists.
                if ($code !== '') {
                    $codeline = get_string('resource_code_title', 'block_vitrinadb', $code);

                    if ($sharefiletitle !== '') {
                        $sharefiletitle .= "\n" . $codeline;
                    } else {
                        $sharefiletitle = $codeline;
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
                $resource->sharefiletitle = $sharefiletitle;
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
                $resource->pinned = $ispinned;
                $resource->prime = $isprime;
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

                // Mark whether this resource list comes from the
                // "Only pending approval records" filter. When true,
                // catalog templates can show a dedicated pending
                // placeholder on the card.
                $resource->ispending = $onlypending;

                $resources[] = $resource;
            }
        }

        return $resources;
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
     * Resolve a single Database (mod_data) field used by this block instance.
     *
     * Shared helper for filters that need to read options from a specific
     * data_fields.description (for example, "channels" or "show_status").
     *
     * @param int $instanceid The block instance id.
     * @param string $fielddescription The data_fields.description to look for.
     * @return ?\stdClass The matching data_fields record or null if not found.
     */
    protected static function get_data_field_for_instance(int $instanceid, string $fielddescription): ?\stdClass {
        global $DB;

        if (empty($instanceid)) {
            return null;
        }

        $categoriesids = [];

        $block = block_instance_by_id($instanceid);
        if ($block && !empty($block->config) && !empty($block->config->categories)) {
            // Categories in block config may be stored as an array or as a
            // comma-separated string depending on how the configuration was
            // saved. Normalise to an int array so downstream logic can rely on
            // a consistent structure.
            if (is_array($block->config->categories)) {
                $categoriesids = array_map('intval', $block->config->categories);
            } else {
                $categoriesids = array_filter(array_map('intval', explode(',', (string)$block->config->categories)));
            }
        }

        // If the block instance has no explicit categories, fall back to the
        // global block configuration so that new instances can still resolve
        // the Database activity used for filters.
        if (empty($categoriesids)) {
            $globalcats = get_config('block_vitrinadb', 'categories');
            if (!empty($globalcats)) {
                $tmp = [];
                foreach (explode(',', (string)$globalcats) as $catid) {
                    if (is_numeric($catid)) {
                        $tmp[] = (int)trim($catid);
                    }
                }
                $categoriesids = $tmp;
            }
        }

        $categoriesids = array_filter($categoriesids);

        if (empty($categoriesids)) {
            return null;
        }

        // Locate the "data" module id.
        $datamoduleid = $DB->get_field('modules', 'id', ['name' => 'data']);
        if (!$datamoduleid) {
            return null;
        }

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

        // Use the same Database activity that the catalog listing uses
        // (first match across the configured categories).
        $firstcm = $DB->get_record_sql($sql, $paramsdb, IGNORE_MULTIPLE);
        if (!$firstcm) {
            return null;
        }

        $data = $DB->get_record('data', ['id' => $firstcm->instance]);
        if (!$data) {
            return null;
        }

        $fields = $DB->get_records('data_fields', ['dataid' => $data->id]);
        if (empty($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (trim((string)$field->description) === $fielddescription) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Get the available channels list from the configured Database activity.
     *
     * This reads the "channels" field from the first Database (mod_data)
     * activity found in the courses configured for this block instance and
     * returns its configured options (or, as a fallback, the distinct values
     * currently used by records) as a flat checkbox list.
     *
     * @param int $instanceid The block instance id.
     * @return array The channels options list.
     */
    public static function get_channels_filter_options(int $instanceid, bool $nested = false): array {
        global $DB;

        $options = [];
        $channelsfield = self::get_data_field_for_instance($instanceid, 'channels');
        if (!$channelsfield) {
            return $options;
        }

        $rawvalues = [];

        // Preferred source: options configured on the field (for menu-like fields
        // param1 is a newline-separated list of options).
        if (!empty($channelsfield->param1)) {
            $lines = preg_split('/[\r\n]+/', (string)$channelsfield->param1);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $rawvalues[] = $line;
                }
            }
        }

        // Fallback: collect distinct values actually used by records.
        if (empty($rawvalues)) {
            $contents = $DB->get_records('data_content', ['fieldid' => $channelsfield->id], '', 'id, content');
            if (!empty($contents)) {
                foreach ($contents as $content) {
                    if ($content->content === null || $content->content === '') {
                        continue;
                    }
                    $parts = self::normalize_channels_list((string)$content->content);
                    foreach ($parts as $part) {
                        $rawvalues[] = $part;
                    }
                }
            }
        }

        if (empty($rawvalues)) {
            return $options;
        }

        // Normalise and de-duplicate.
        $unique = [];
        foreach ($rawvalues as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if (!array_key_exists($value, $unique)) {
                $unique[$value] = $value;
            }
        }

        if (empty($unique)) {
            return $options;
        }

        // Sort alphabetically for a stable, user-friendly list.
        $labels = array_values($unique);
        usort($labels, function(string $a, string $b) {
            $la = \core_text::strtolower($a);
            $lb = \core_text::strtolower($b);
            return $la <=> $lb;
        });

        // Flat mode: just return a plain list of options, one per channel
        // value, without any hierarchy. This is used when the "Category
        // filter view" is set to "default".
        if (!$nested) {
            foreach ($labels as $label) {
                $options[] = [
                    'value' => $label,
                    'label' => format_string($label, true),
                    'selected' => false,
                    'haschilds' => false,
                    'childs' => [],
                    'indent' => 0,
                ];
            }

            return $options;
        }

        // Tree mode: build a multi-level tree based on
        // "Parent/Child/Subchild" patterns. Each segment between "/" is
        // one level. Only explicitly defined channel strings participate in
        // the tree, i.e. "安心禅茶/禅茶子项A/孙子项X" is treated as a third-level
        // node under "安心禅茶/禅茶子项A" if, and only if, that intermediate path
        // also appears as a configured value.

        $nodes = [];
        $parents = [];
        $children = [];

        // First pass: create a node for each distinct label.
        foreach ($labels as $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }

            $parts = preg_split('/\//u', $label);
            if ($parts === false) {
                $parts = [$label];
            }

            $parts = array_map('trim', $parts);
            $parts = array_filter($parts, function($p) {
                return $p !== '';
            });

            if (empty($parts)) {
                continue;
            }

            $segmentlabel = end($parts);

            $nodes[$label] = [
                'value' => $label,
                'label' => format_string($segmentlabel, true),
                'selected' => false,
            ];
        }

        if (empty($nodes)) {
            return $options;
        }

        // Second pass: determine parent/child relationships using the last
        // "/" as separator so that labels can form deeper hierarchies such
        // as "安心禅茶/禅茶子项A/孙子项X".
        foreach (array_keys($nodes) as $label) {
            $pos = mb_strrpos($label, '/');
            if ($pos === false) {
                continue;
            }

            $parentlabel = trim(mb_substr($label, 0, $pos));
            if ($parentlabel === '' || !array_key_exists($parentlabel, $nodes)) {
                continue;
            }

            $parents[$label] = $parentlabel;
            if (!isset($children[$parentlabel])) {
                $children[$parentlabel] = [];
            }
            $children[$parentlabel][] = $label;
        }

        // Helper to build the options tree recursively, assigning the
        // appropriate indent level for each depth.
        $buildnode = function(string $label, int $depth, array $nodes, array $children, callable $self) {
            $option = [
                'value' => $nodes[$label]['value'],
                'label' => $nodes[$label]['label'],
                'selected' => false,
                'haschilds' => !empty($children[$label]),
                'childs' => [],
                'indent' => $depth,
            ];

            if (!empty($children[$label])) {
                foreach ($children[$label] as $childlabel) {
                    $option['childs'][] = $self($childlabel, $depth + 1, $nodes, $children, $self);
                }
            }

            return $option;
        };

        // Root nodes are those without a registered parent.
        foreach ($labels as $label) {
            $label = trim($label);
            if ($label === '' || !isset($nodes[$label])) {
                continue;
            }

            if (!array_key_exists($label, $parents)) {
                $options[] = $buildnode($label, 0, $nodes, $children, $buildnode);
            }
        }

        return $options;
    }

    /**
     * Get the available show_status options from the configured Database activity.
     *
     * This reads the "show_status" field from the same Database (mod_data)
     * activity used by the catalog and returns its configured options as a
     * simple list, suitable to be rendered as a dropdown.
     *
     * @param int $instanceid The block instance id.
     * @return array The show_status options list.
     */
    public static function get_showstatus_filter_options(int $instanceid): array {
        global $DB;

        $options = [];
        $statusfield = self::get_data_field_for_instance($instanceid, 'show_status');
        if (!$statusfield) {
            return $options;
        }

        $rawvalues = [];

        // Preferred source: options configured on the field (for menu-like
        // fields param1 is a newline-separated list of options).
        if (!empty($statusfield->param1)) {
            $lines = preg_split('/[\r\n]+/', (string)$statusfield->param1);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $rawvalues[] = $line;
                }
            }
        }

        // Fallback: collect distinct values actually used by records.
        if (empty($rawvalues)) {
            $contents = $DB->get_records('data_content', ['fieldid' => $statusfield->id], '', 'id, content');
            if (!empty($contents)) {
                foreach ($contents as $content) {
                    if ($content->content === null || $content->content === '') {
                        continue;
                    }
                    $value = trim((string)$content->content);
                    if ($value !== '') {
                        $rawvalues[] = $value;
                    }
                }
            }
        }

        if (empty($rawvalues)) {
            return $options;
        }

        // Normalise and de-duplicate.
        $unique = [];
        foreach ($rawvalues as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $unique[$value] = $value;
        }

        if (empty($unique)) {
            return $options;
        }

        // Sort alphabetically for a stable, user-friendly list.
        $labels = array_values($unique);
        usort($labels, function(string $a, string $b) {
            $la = \core_text::strtolower($a);
            $lb = \core_text::strtolower($b);
            return $la <=> $lb;
        });

        foreach ($labels as $label) {
            $options[] = [
                'value' => $label,
                'label' => format_string($label, true),
            ];
        }

        return $options;
    }

    /**
     * Get the available authors list from the configured Database activity.
     *
     * This inspects the Database (mod_data) activity used by the catalog and
     * returns the distinct users who created records there, as a simple list
     * suitable for rendering as a dropdown.
     *
     * @param int $instanceid The block instance id.
     * @return array The authors options list.
     */
    public static function get_authors_filter_options(int $instanceid): array {
        global $DB;

        $options = [];

        if (empty($instanceid)) {
            return $options;
        }

        $categoriesids = [];

        $block = block_instance_by_id($instanceid);
        if ($block && !empty($block->config) && !empty($block->config->categories)) {
            // Categories in block config may be stored as an array or as a
            // comma-separated string. Normalise to an int array so that the
            // authors dropdown is populated consistently.
            if (is_array($block->config->categories)) {
                $categoriesids = array_map('intval', $block->config->categories);
            } else {
                $categoriesids = array_filter(array_map('intval', explode(',', (string)$block->config->categories)));
            }
        }

        // If the block instance has no explicit categories, fall back to the
        // global block configuration so that new instances can still resolve
        // the Database activity used to derive authors.
        if (empty($categoriesids)) {
            $globalcats = get_config('block_vitrinadb', 'categories');
            if (!empty($globalcats)) {
                $tmp = [];
                foreach (explode(',', (string)$globalcats) as $catid) {
                    if (is_numeric($catid)) {
                        $tmp[] = (int)trim($catid);
                    }
                }
                $categoriesids = $tmp;
            }
        }

        $categoriesids = array_filter($categoriesids);

        if (empty($categoriesids)) {
            return $options;
        }

        // Locate the "data" module id.
        $datamoduleid = $DB->get_field('modules', 'id', ['name' => 'data']);
        if (!$datamoduleid) {
            return $options;
        }

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

        // Use the same Database activity that the catalog listing uses
        // (first match across the configured categories).
        $firstcm = $DB->get_record_sql($sql, $paramsdb, IGNORE_MULTIPLE);
        if (!$firstcm) {
            return $options;
        }

        $data = $DB->get_record('data', ['id' => $firstcm->instance]);
        if (!$data) {
            return $options;
        }

        // Distinct record creators for this Database.
        $authors = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename
               FROM {data_records} r
               JOIN {user} u ON u.id = r.userid
              WHERE r.dataid = :dataid",
            ['dataid' => $data->id]
        );

        if (empty($authors)) {
            return $options;
        }

        // Build a map id => label so we can sort by label.
        $labels = [];
        foreach ($authors as $author) {
            $label = fullname($author, true);
            $labels[$author->id] = $label;
        }

        if (empty($labels)) {
            return $options;
        }

        // Sort alphabetically by display name.
        asort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($labels as $id => $label) {
            $options[] = [
                'value' => (string)$id,
                'label' => format_string($label, true),
            ];
        }

        return $options;
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
