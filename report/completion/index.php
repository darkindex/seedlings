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
 * Course completion progress report
 *
 * @package    report
 * @subpackage completion
 * @copyright  2009 Catalyst IT Ltd
 * @author     Aaron Barnes <aaronb@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__).'/../../config.php');
require_once("{$CFG->libdir}/completionlib.php");

/**
 * Configuration
 */
define('COMPLETION_REPORT_PAGE',        25);
define('COMPLETION_REPORT_COL_TITLES',  true);
$criteria_with_rpl = array();

/*
 * Setup page, check permissions
 */

// Get course
$courseid = required_param('course', PARAM_INT);
$format = optional_param('format','',PARAM_ALPHA);
$sort = optional_param('sort','',PARAM_ALPHA);
$edituser = optional_param('edituser', 0, PARAM_INT);


$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id);

$url = new moodle_url('/report/completion/index.php', array('course'=>$course->id));
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');

$firstnamesort = ($sort == 'firstname');
$excel = ($format == 'excelcsv');
$csv = ($format == 'csv' || $excel);

// Load CSV library
if ($csv) {
    require_once("{$CFG->libdir}/csvlib.class.php");
}

// Paging
$start   = optional_param('start', 0, PARAM_INT);
$sifirst = optional_param('sifirst', 'all', PARAM_ALPHA);
$silast  = optional_param('silast', 'all', PARAM_ALPHA);

// Whether to show extra user identity information
$extrafields = get_extra_user_fields($context);
$leftcols = 1 + count($extrafields);

///
/// Display RPL stuff
///
function show_rpl($type, $user, $rpl, $describe, $fulldescribe, $cmid = null) {
    global $OUTPUT, $edituser, $course, $sort, $start;

    // If editing a user
    if ($edituser == $user->id) {
        // Show edit form
        print '<form action="save_rpl.php?type='.$type.'&course='.$course->id.'&sort='.$sort.'&start='.$start.'&redirect=1" method="post">';
        print '<input type="hidden" name="user" value="'.$user->id.'" />';
        print '<input type="hidden" name="cmid" value="'.$cmid.'" />';
        print '<input type="text" name="rpl" value="'.format_string($rpl).'" maxlength="255" />';
        print '<input type="submit" name="saverpl" value="'.get_string('save', 'completion').'" /></form> ';
        print '<a href="index.php?course='.$course->id.'&sort='.$sort.'&start='.$start.'">'.get_string('cancel').'</a>';
    } else {
        // Show RPL status icon
        $rplicon = strlen($rpl) ? 'completion-rpl-y' : 'completion-rpl-n';
        print '<a href="index.php?course='.$course->id.'&sort='.$sort.'&start='.$start.'&edituser='.$user->id.'#user-'.$user->id.'" class="rpledit"><img src="'.$OUTPUT->pix_url('i/'.$rplicon, 'totara_core').
            '" alt="'.$describe.'" class="icon" title="'.$fulldescribe.'" /></a>';

        // Show status text
        if (strlen($rpl)) {
            print '<a href="#" class="rplshow" title="'.get_string('showrpl', 'completion').'">...</a>';
        }

        // Rrpl value
        print '<span class="rplvalue">'.format_string($rpl).'</span>';
    }
}


// Check permissions
require_login($course);

require_capability('report/completion:view', $context);

// Get group mode
$group = groups_get_course_group($course, true); // Supposed to verify group
if ($group === 0 && $course->groupmode == SEPARATEGROUPS) {
    require_capability('moodle/site:accessallgroups',$context);
}

/**
 * Load data
 */

// Retrieve course_module data for all modules in the course
$modinfo = get_fast_modinfo($course);

// Get criteria for course
$completion = new completion_info($course);

if (!$completion->has_criteria()) {
    print_error('err_nocriteria', 'completion', $CFG->wwwroot.'/course/report.php?id='.$course->id);
}

// Get criteria and put in correct order.
$criteria = array();

foreach ($completion->get_criteria(COMPLETION_CRITERIA_TYPE_COURSE) as $criterion) {
    $criteria[] = $criterion;
}
// Obtain the display order of activity modules.
$sections = $DB->get_records('course_sections', array('course' => $course->id), 'section ASC', 'id, sequence');
$moduleorder = array();
foreach ($sections as $section) {
    if (!empty($section->sequence)) {
        $moduleorder = array_merge(array_values($moduleorder), array_values(explode(',', $section->sequence)));
    }
}

$modulecriteria = array();
$activitycriteria = $completion->get_criteria(COMPLETION_CRITERIA_TYPE_ACTIVITY);
// Order resulting criteria by module.
foreach ($activitycriteria as $criterion) {
    if (!empty($criterion->moduleinstance)) {
        $modulecriteria[$criterion->moduleinstance] = $criterion;
    }
}

// Compare to the course module order to put the activities in the same order as on the course view.
foreach($moduleorder as $module) {
    // Some modules may not have completion criteria and can be ignored.
    if (isset($modulecriteria[$module])) {
        $criteria[] = $modulecriteria[$module];
        if (completion_module_rpl_enabled($modulecriteria[$module]->module)) {
            $criteria_with_rpl[] = $modulecriteria[$module]->id;
        }
    }
}

foreach ($completion->get_criteria() as $criterion) {
    if (!in_array($criterion->criteriatype, array(
            COMPLETION_CRITERIA_TYPE_COURSE, COMPLETION_CRITERIA_TYPE_ACTIVITY))) {
        $criteria[] = $criterion;
    }
}

// Can logged in user mark users as complete?
// (if the logged in user has a role defined in the role criteria)
$allow_marking = false;
$allow_marking_criteria = null;

if (!$csv) {
    // Get role criteria
    $rcriteria = $completion->get_criteria(COMPLETION_CRITERIA_TYPE_ROLE);

    if (!empty($rcriteria)) {

        foreach ($rcriteria as $rcriterion) {
            $users = get_role_users($rcriterion->role, $context, true);

            // If logged in user has this role, allow marking complete
            if ($users && in_array($USER->id, array_keys($users))) {
                $allow_marking = true;
                $allow_marking_criteria = $rcriterion->id;
                break;
            }
        }
    }
}

/*
 * Setup page header
 */
if ($csv) {

    $shortname = format_string($course->shortname, true, array('context' => $context));
    $shortname = preg_replace('/[^a-z0-9-]/', '_',core_text::strtolower(strip_tags($shortname)));

    $export = new csv_export_writer();
    $export->set_filename('completion-'.$shortname);

} else {
    // Navigation and header
    $strcompletion = get_string('coursecompletion');

    $PAGE->set_title($strcompletion);
    $PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();

    local_js();
    $PAGE->requires->js('/report/completion/textrotate.js');

    $args = array(
        'args' => json_encode(array(
            'course'      => $course->id,
            'pix_rply'    => $OUTPUT->pix_url('i/completion-rpl-y', 'totara_core')->out(),
            'pix_rpln'    => $OUTPUT->pix_url('i/completion-rpl-n', 'totara_core')->out(),
            'pix_cross'   => $OUTPUT->pix_url('i/invalid')->out(),
            'pix_loading' => $OUTPUT->pix_url('loading_small', 'totara_core')->out(),
        ))
    );

    $jsmodule = array(
        'name'      => 'totara_completionrpl',
        'fullpath'  => '/report/completion/rpl.js',
        'required'  => array('json'));

    $PAGE->requires->js_init_call('M.totara_completionrpl.init',
             $args, false, $jsmodule);
    $PAGE->requires->js_function_call('textrotate_init', null, true);

    // Handle groups (if enabled)
    groups_print_course_menu($course, $CFG->wwwroot.'/report/completion/?course='.$course->id);
}

// Generate where clause
$where = array();
$where_params = array();

if ($sifirst !== 'all') {
    $where[] = $DB->sql_like('u.firstname', ':sifirst', false);
    $where_params['sifirst'] = $sifirst.'%';
}

if ($silast !== 'all') {
    $where[] = $DB->sql_like('u.lastname', ':silast', false);
    $where_params['silast'] = $silast.'%';
}

// Get user match count
$total = $completion->get_num_tracked_users(implode(' AND ', $where), $where_params, $group);

// Total user count
$grandtotal = $completion->get_num_tracked_users('', array(), $group);

// If no users in this course what-so-ever
if (!$grandtotal) {
    echo $OUTPUT->container(get_string('err_nousers', 'completion'), 'errorbox errorboxcontent');
    echo $OUTPUT->footer();
    exit;
}

// Get user data
$progress = array();

if ($total) {
    $progress = $completion->get_progress_all(
        implode(' AND ', $where),
        $where_params,
        $group,
        $firstnamesort ? 'u.firstname ASC' : 'u.lastname ASC',
        $csv ? 0 : COMPLETION_REPORT_PAGE,
        $csv ? 0 : $start,
        $context
    );
}

// Build link for paging
$link = $CFG->wwwroot.'/report/completion/?course='.$course->id;
if (strlen($sort)) {
    $link .= '&amp;sort='.$sort;
}
$link .= '&amp;start=';

// Build the the page by Initial bar
$initials = array('first', 'last');
$alphabet = explode(',', get_string('alphabet', 'langconfig'));

$pagingbar = '';
foreach ($initials as $initial) {
    $var = 'si'.$initial;

    $othervar = $initial == 'first' ? 'silast' : 'sifirst';
    $othervar = $$othervar != 'all' ? "&amp;{$othervar}={$$othervar}" : '';

    $pagingbar .= ' <div class="initialbar '.$initial.'initial">';
    $pagingbar .= get_string($initial.'name').':&nbsp;';

    if ($$var == 'all') {
        $pagingbar .= '<strong>'.get_string('all').'</strong> ';
    }
    else {
        $pagingbar .= "<a href=\"{$link}{$othervar}\">".get_string('all').'</a> ';
    }

    foreach ($alphabet as $letter) {
        if ($$var === $letter) {
            $pagingbar .= '<strong>'.$letter.'</strong> ';
        }
        else {
            $pagingbar .= "<a href=\"$link&amp;$var={$letter}{$othervar}\">$letter</a> ";
        }
    }

    $pagingbar .= '</div>';
}

// Do we need a paging bar?
if ($total > COMPLETION_REPORT_PAGE) {

    // Paging bar
    $pagingbar .= '<div class="paging">';
    $pagingbar .= get_string('page').': ';

    $sistrings = array();
    if ($sifirst != 'all') {
        $sistrings[] =  "sifirst={$sifirst}";
    }
    if ($silast != 'all') {
        $sistrings[] =  "silast={$silast}";
    }
    $sistring = !empty($sistrings) ? '&amp;'.implode('&amp;', $sistrings) : '';

    // Display previous link
    if ($start > 0) {
        $pstart = max($start - COMPLETION_REPORT_PAGE, 0);
        $pagingbar .= "(<a class=\"previous\" href=\"{$link}{$pstart}{$sistring}\">".get_string('previous').'</a>)&nbsp;';
    }

    // Create page links
    $curstart = 0;
    $curpage = 0;
    while ($curstart < $total) {
        $curpage++;

        if ($curstart == $start) {
            $pagingbar .= '&nbsp;'.$curpage.'&nbsp;';
        }
        else {
            $pagingbar .= "&nbsp;<a href=\"{$link}{$curstart}{$sistring}\">$curpage</a>&nbsp;";
        }

        $curstart += COMPLETION_REPORT_PAGE;
    }

    // Display next link
    $nstart = $start + COMPLETION_REPORT_PAGE;
    if ($nstart < $total) {
        $pagingbar .= "&nbsp;(<a class=\"next\" href=\"{$link}{$nstart}{$sistring}\">".get_string('next').'</a>)';
    }

    $pagingbar .= '</div>';
}

/*
 * Draw table header
 */

// Start of table
if (!$csv) {
    print '<br class="clearer"/>'; // ugh

    $total_header = ($total == $grandtotal) ? $total : "{$total}/{$grandtotal}";
    echo $OUTPUT->heading(get_string('allparticipants').": {$total_header}", 3);

    print $pagingbar;

    if (!$total) {
        echo $OUTPUT->heading(get_string('nothingtodisplay'), 2);
        echo $OUTPUT->footer();
        exit;
    }

    print '<table id="completion-progress" class="generaltable flexible boxaligncenter completionreport" style="text-align: left" cellpadding="5" border="1">';

    // Print criteria group names
    print PHP_EOL.'<thead><tr style="vertical-align: top">';
    echo '<th scope="row" class="rowheader" colspan="' . $leftcols . '">' .
            get_string('criteriagroup', 'completion') . '</th>';

    $current_group = false;
    $col_count = 0;
    for ($i = 0; $i <= count($criteria); $i++) {

        if (isset($criteria[$i])) {
            $criterion = $criteria[$i];

            if ($current_group && $criterion->criteriatype === $current_group->criteriatype) {
                ++$col_count;
                continue;
            }
        }

        // Print header cell
        if ($col_count) {
            print '<th scope="col" colspan="'.$col_count.'" class="colheader criteriagroup">'.$current_group->get_type_title().'</th>';
        }

        if (isset($criteria[$i])) {
            // Move to next criteria type
            $current_group = $criterion;
            $col_count = 1;
        }
    }

    // Overall course completion status
    print '<th style="text-align: center;">'.get_string('course').'</th>';

    print '</tr>';

    // Print aggregation methods
    print PHP_EOL.'<tr style="vertical-align: top">';
    echo '<th scope="row" class="rowheader" colspan="' . $leftcols . '">' .
            get_string('aggregationmethod', 'completion').'</th>';

    $current_group = false;
    $col_count = 0;
    for ($i = 0; $i <= count($criteria); $i++) {

        if (isset($criteria[$i])) {
            $criterion = $criteria[$i];

            if ($current_group && $criterion->criteriatype === $current_group->criteriatype) {
                ++$col_count;
                continue;
            }
        }

        // Print header cell
        if ($col_count) {
            $has_agg = array(
                COMPLETION_CRITERIA_TYPE_COURSE,
                COMPLETION_CRITERIA_TYPE_ACTIVITY,
                COMPLETION_CRITERIA_TYPE_ROLE,
            );

            if (in_array($current_group->criteriatype, $has_agg)) {
                // Try load a aggregation method
                $method = $completion->get_aggregation_method($current_group->criteriatype);

                $method = $method == 1 ? get_string('all') : get_string('any');

            } else {
                $method = '-';
            }

            print '<th scope="col" colspan="'.$col_count.'" class="colheader aggheader">'.$method.'</th>';
        }

        if (isset($criteria[$i])) {
            // Move to next criteria type
            $current_group = $criterion;
            $col_count = 1;
        }
    }

    // Overall course aggregation method
    print '<th scope="col" class="colheader aggheader aggcriteriacourse">';

    // Get course aggregation
    $method = $completion->get_aggregation_method();

    // Print
    if ($CFG->enablecourserpl) {
        if ($method == 1) {
            print get_string('courserplorallcriteriagroups', 'completion');
        } else {
            print get_string('courserploranycriteriagroup', 'completion');
        }
    } else {
        print $method == 1 ? get_string('all') : get_string('any');
    }
    print '</th>';

    print '</tr>';

    // Print criteria titles
    if (COMPLETION_REPORT_COL_TITLES) {

        print PHP_EOL.'<tr>';
        echo '<th scope="row" class="rowheader" colspan="' . $leftcols . '">' .
                get_string('criteria', 'completion') . '</th>';

        foreach ($criteria as $criterion) {
            // Get criteria details
            $details = $criterion->get_title_detailed();
            print '<th scope="col" class="colheader criterianame ie-vertical-completion"><div class="ie-vertical-completion">';
            print '<span class="completion-criterianame ie-block-completion">'.$details.'</span>';

            if (in_array($criterion->id, $criteria_with_rpl)) {
                print '<span class="completion-rplheader completion-criterianame ie-color-completion">'.get_string('recognitionofpriorlearning', 'completion').'</span>';
            }

            print '</div></th>';
        }

        // Overall course completion status
        print '<th scope="col" class="colheader criterianame ie-vertical-completion"><div class="ie-vertical-completion">';
        print '<span class="completion-criterianame ie-block-completion">'.get_string('coursecomplete', 'completion').'</span>';
        if ($CFG->enablecourserpl) {
            print '<span class="completion-rplheader completion-criterianame ie-color-completion">'.get_string('recognitionofpriorlearning', 'completion').'</span>';
        }

        print '</div></th></tr>';
    }

    // Print user heading and icons
    print '<tr>';

    // User heading / sort option
    print '<th scope="col" class="completion-sortchoice" style="clear: both;">';

    $sistring = "&amp;silast={$silast}&amp;sifirst={$sifirst}";

    if ($firstnamesort) {
        print
            get_string('firstname')." / <a href=\"./?course={$course->id}{$sistring}\">".
            get_string('lastname').'</a>';
    } else {
        print "<a href=\"./?course={$course->id}&amp;sort=firstname{$sistring}\">".
            get_string('firstname').'</a> / '.
            get_string('lastname');
    }
    print '</th>';

    // Print user identity columns
    foreach ($extrafields as $field) {
        echo '<th scope="col" class="completion-identifyfield">' .
                get_user_field_name($field) . '</th>';
    }

    ///
    /// Print criteria icons
    ///
    foreach ($criteria as $criterion) {

        // Generate icon details
        $icon = '';
        $iconlink = '';
        $icontitle = ''; // Required if $iconlink set
        $iconalt = ''; // Required
        switch ($criterion->criteriatype) {

            case COMPLETION_CRITERIA_TYPE_ACTIVITY:

                // Display icon
                $icon = $OUTPUT->pix_url('icon', $criterion->module);
                $iconlink = $CFG->wwwroot.'/mod/'.$criterion->module.'/view.php?id='.$criterion->moduleinstance;
                $icontitle = $modinfo->cms[$criterion->moduleinstance]->name;
                $iconalt = get_string('modulename', $criterion->module);
                break;

            case COMPLETION_CRITERIA_TYPE_COURSE:
                // Load course
                $crs = $DB->get_record('course', array('id' => $criterion->courseinstance));

                // Display icon
                $icon = $OUTPUT->pix_url('i/course');
                $iconlink = $CFG->wwwroot.'/course/view.php?id='.$criterion->courseinstance;
                $icontitle = format_string($crs->fullname, true, array('context' => context_course::instance($crs->id, MUST_EXIST)));
                $iconalt = format_string($crs->shortname, true, array('context' => context_course::instance($crs->id)));
                break;

            case COMPLETION_CRITERIA_TYPE_ROLE:
                // Load role
                $role = $DB->get_record('role', array('id' => $criterion->role));

                // Display icon
                $iconalt = $role->name;
                $icon = $OUTPUT->pix_url('t/assignroles');
                break;

            case COMPLETION_CRITERIA_TYPE_SELF:
                // Display icon
                $icon = $OUTPUT->pix_url('i/user');
                break;

            case COMPLETION_CRITERIA_TYPE_GRADE:
                // Display icon
                $icon = $OUTPUT->pix_url('i/grades');
                break;

            case COMPLETION_CRITERIA_TYPE_DURATION:
                // Display icon
                $icon = $OUTPUT->pix_url('i/calendar');
                break;

            case COMPLETION_CRITERIA_TYPE_DATE:
                // Display icon
                $icon = $OUTPUT->pix_url('i/calendar');
                break;
        }

        // Print icon and cell
        print '<th class="criteriaicon">';

        if ($icon) {
            print ($iconlink ? '<a href="'.$iconlink.'" title="'.$icontitle.'">' : '');
            print '<img src="'.$icon.'" class="icon" alt="'.$iconalt.'" '.(!$iconlink ? 'title="'.$iconalt.'"' : '').' />';
            print ($iconlink ? '</a>' : '');
        } else {
            print '&nbsp;';
        }

        if (in_array($criterion->id, $criteria_with_rpl)) {
            print '<img src="'.$OUTPUT->pix_url('i/course').'" class="icon" alt="'.get_string('rpl', 'completion').'" title="'.get_string('activityrpl', 'completion').'" />';
            print '<a href="#" class="rplexpand rpl-'.$criterion->id.'" title="'.get_string('showrpls', 'completion').'"><img src="'.$OUTPUT->pix_url('t/more').'" class="icon" alt="+"/></a>';
        }

        print '</th>';
    }

    // Overall course completion status
    print '<th class="criteriaicon">';
    print '<img src="'.$OUTPUT->pix_url('i/course').'" class="icon" alt="'.get_string('course').'" title="'.get_string('coursecomplete', 'completion').'" />';

    if ($CFG->enablecourserpl) {
        print '<img src="'.$OUTPUT->pix_url('i/course').'" class="icon" alt="'.get_string('rpl', 'completion').'" title="'.get_string('courserpl', 'completion').'" />';
        print '<a href="#" class="rplexpand rpl-course" title="'.get_string('showrpls', 'completion').'"><img src="'.$OUTPUT->pix_url('t/more').'" class="icon" alt="+"/></a>';
    }

    print '</th>';

    print '</tr></thead>';

    echo '<tbody>';
} else {
    // The CSV headers
    $row = array();

    $row[] = get_string('id', 'report_completion');
    $row[] = get_string('name', 'report_completion');
    foreach ($extrafields as $field) {
       $row[] = get_user_field_name($field);
    }

    // Add activity headers
    foreach ($criteria as $criterion) {

        // Handle activity completion differently
        if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {

            // Load activity
            $mod = $criterion->get_mod_instance();
            $row[] = $mod->name;
            $row[] = $mod->name . ' - ' . get_string('completiondate', 'report_completion');
        }
        else {
            // Handle all other criteria
            $row[] = strip_tags($criterion->get_title_detailed());
        }
    }

    $row[] = get_string('coursecomplete', 'completion');

    $export->add_data($row);
}

///
/// Display a row for each user
///
foreach ($progress as $user) {

    // User name
    if ($csv) {
        $row = array();
        $row[] = $user->id;
        $row[] = fullname($user);
        foreach ($extrafields as $field) {
            $row[] = $user->{$field};
        }
    } else {
        print PHP_EOL.'<tr id="user-'.$user->id.'">';

        if (completion_can_view_data($user->id, $course)) {
            $userurl = new moodle_url('/blocks/completionstatus/details.php', array('course' => $course->id, 'user' => $user->id));
        } else {
            $userurl = new moodle_url('/user/view.php', array('id' => $user->id, 'course' => $course->id));
        }

        print '<th scope="row"><a href="'.$userurl->out().'">'.fullname($user).'</a></th>';
        foreach ($extrafields as $field) {
            echo '<td>'.s($user->{$field}).'</td>';
        }
    }

    // Progress for each course completion criteria
    foreach ($criteria as $criterion) {

        $criteria_completion = $completion->get_user_completion($user->id, $criterion);
        $is_complete = $criteria_completion->is_complete();

        // Handle activity completion differently
        if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {

            // Load activity
            $activity = $modinfo->cms[$criterion->moduleinstance];

            // Get progress information and state
            if (array_key_exists($activity->id, $user->progress)) {
                $state = $user->progress[$activity->id]->completionstate;
            } else if ($is_complete) {
                $state = COMPLETION_COMPLETE;
            } else {
                $state = COMPLETION_INCOMPLETE;
            }
            if ($is_complete) {
                $date = userdate($criteria_completion->timecompleted, get_string('strftimedatetimeshort', 'langconfig'));
            } else {
                $date = '';
            }

            // Work out how it corresponds to an icon
            switch($state) {
                case COMPLETION_INCOMPLETE    : $completiontype = 'n';    break;
                case COMPLETION_COMPLETE      : $completiontype = 'y';    break;
                case COMPLETION_COMPLETE_PASS : $completiontype = 'pass'; break;
                case COMPLETION_COMPLETE_FAIL : $completiontype = 'fail'; break;
            }

            $auto = $activity->completion == COMPLETION_TRACKING_AUTOMATIC;
            $completionicon = 'completion-'.($auto ? 'auto' : 'manual').'-'.$completiontype;

            $describe = get_string('completion-'.$completiontype, 'completion');
            $a = new StdClass();
            $a->state     = $describe;
            $a->date      = $date;
            $a->user      = fullname($user);
            $a->activity  = strip_tags($activity->name);
            $fulldescribe = get_string('progress-title', 'completion', $a);

            if ($csv) {
                $row[] = $describe;
                $row[] = $date;
            } else {
                print '<td class="completion-progresscell rpl-'.$criterion->id.' cmid-'.$criterion->moduleinstance.'">';

                print '<img src="'.$OUTPUT->pix_url('i/'.$completionicon).
                      '" alt="'.$describe.'" class="icon" title="'.$fulldescribe.'" />';

                // Decide if we need to display an RPL
                if (in_array($criterion->id, $criteria_with_rpl)) {
                    show_rpl($criterion->id, $user, $criteria_completion->rpl, $describe, $fulldescribe, $criterion->moduleinstance);
                }

                print '</td>';
            }

            continue;
        }

        // Handle all other criteria
        $completiontype = $is_complete ? 'y' : 'n';
        $completionicon = 'completion-auto-'.$completiontype;

        $describe = get_string('completion-'.$completiontype, 'completion');

        $a = new stdClass();
        $a->state    = $describe;

        if ($is_complete) {
            $a->date = userdate($criteria_completion->timecompleted, get_string('strftimedatetimeshort', 'langconfig'));
        } else {
            $a->date = '';
        }

        $a->user     = fullname($user);
        $a->activity = strip_tags($criterion->get_title());
        $fulldescribe = get_string('progress-title', 'completion', $a);

        if ($csv) {
            $row[] = $a->date;
        } else {

            print '<td class="completion-progresscell">';

            if ($allow_marking_criteria === $criterion->id) {
                $describe = get_string('completion-'.$completiontype, 'completion');

                $toggleurl = new moodle_url(
                    '/course/togglecompletion.php',
                    array(
                        'user' => $user->id,
                        'course' => $course->id,
                        'rolec' => $allow_marking_criteria,
                        'sesskey' => sesskey()
                    )
                );

                print '<a href="'.$toggleurl->out().'"><img src="'.$OUTPUT->pix_url('i/completion-manual-'.($is_complete ? 'y' : 'n')).
                    '" alt="'.$describe.'" class="icon" title="'.get_string('markcomplete', 'completion').'" /></a></td>';
            } else {
                print '<img src="'.$OUTPUT->pix_url('i/'.$completionicon).'" alt="'.$describe.'" class="icon" title="'.$fulldescribe.'" /></td>';
            }

            print '</td>';
        }
    }

    // Handle overall course completion

    // Load course completion
    $params = array(
        'userid'    => $user->id,
        'course'    => $course->id
    );

    $ccompletion = new completion_completion($params);
    $completiontype =  $ccompletion->is_complete() ? 'y' : 'n';

    $describe = get_string('completion-'.$completiontype, 'completion');

    $a = new StdClass;

    if ($ccompletion->is_complete()) {
        $a->date = userdate($ccompletion->timecompleted, get_string('strftimedatetimeshort', 'langconfig'));
    } else {
        $a->date = '';
    }

    $a->state    = $describe;
    $a->user     = fullname($user);
    $a->activity = strip_tags(get_string('coursecomplete', 'completion'));
    $fulldescribe = get_string('progress-title', 'completion', $a);

    if ($csv) {
        $row[] = $a->date;
    } else {

        print '<td class="completion-progresscell rpl-course">';

        // Display course completion status icon
        print '<img src="'.$OUTPUT->pix_url('i/completion-auto-'.$completiontype).
               '" alt="'.$describe.'" class="icon" title="'.$fulldescribe.'" />';

        if ($CFG->enablecourserpl) {
            show_rpl('course', $user, $ccompletion->rpl, $describe, $fulldescribe);
        }

        print '</td>';
    }

    if ($csv) {
        $export->add_data($row);
    } else {
        print '</tr>';
    }
}

if ($csv) {
    $export->download_file();
} else {
    echo '</tbody>';
}

print '</table>';
print $pagingbar;

$csvurl = new moodle_url('/report/completion/index.php', array('course' => $course->id, 'format' => 'csv'));
$excelurl = new moodle_url('/report/completion/index.php', array('course' => $course->id, 'format' => 'excelcsv'));

print '<ul class="export-actions">';
print '<li><a href="'.$csvurl->out().'">'.get_string('csvdownload','completion').'</a></li>';
print '<li><a href="'.$excelurl->out().'">'.get_string('excelcsvdownload','completion').'</a></li>';
print '</ul>';

echo $OUTPUT->footer($course);

// Trigger a report viewed event.
$event = \report_completion\event\report_viewed::create(array('context' => $context));
$event->trigger();
