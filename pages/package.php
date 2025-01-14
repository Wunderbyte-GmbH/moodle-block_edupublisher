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
 * @package    block_edupublisher
 * @copyright  2018 Digital Education Society (http://www.dibig.at)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/blocks/edupublisher/block_edupublisher.php');

$id = required_param('id', PARAM_INT);
$package = new \block_edupublisher\package($id, true);
$context = \context_course::instance($package->get('course'));
// Must pass login
$PAGE->set_url('/blocks/edupublisher/pages/package.php?id=' . $id);
require_login();
$PAGE->set_context($context);
$PAGE->set_title(get_string('package', 'block_edupublisher'));
$PAGE->set_heading(get_string('package', 'block_edupublisher'));
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/blocks/edupublisher/style/main.css');
$PAGE->requires->css('/blocks/edupublisher/style/ui.css');

$PAGE->navbar->add($package->get('title', 'default'), new moodle_url('/course/view.php', array('id' => $package->get('course'))));
$PAGE->navbar->add(get_string('details', 'block_edupublisher'), $PAGE->url);

\block_edupublisher\lib::check_requirements(false);
echo $OUTPUT->header();

$act = optional_param('act', '', PARAM_TEXT);
switch ($act) {
    case 'authoreditingpermission':
        if ($package->get('canmoderate')) {
            $ctx = \context_course::instance($package->get('course'));
            if (optional_param('to', '', PARAM_TEXT) == 'grant') {
                \block_edupublisher\lib::role_set(array($package->get('course')), array($package->get('userid')), 'defaultroleteacher');
                $sendto = array('author');
                $package->store_comment('comment:template:package_editing_granted', $sendto, true, false);
            }
            if (optional_param('to', '', PARAM_TEXT) == 'remove') {
                \block_edupublisher\lib::role_set(array($package->get('course')), array($package->get('userid')), -1);
                //role_unassign(get_config('block_edupublisher', 'defaultroleteacher'), $package->userid, $ctx->id);
                $sendto = array('author');
                $package->store_comment('comment:template:package_editing_sealed', $sendto, true, false);
            }
            echo $OUTPUT->render_from_template(
                'block_edupublisher/alert',
                array(
                    'content' => get_string('successfully_saved_package', 'block_edupublisher'),
                    'type' => 'success',
                )
            );
        } else {
            echo $OUTPUT->render_from_template(
                'block_edupublisher/alert',
                array(
                    'content' => get_string('permission_denied', 'block_edupublisher'),
                    'type' => 'warning',
                    'url' => $PAGE->url,
                )
            );
        }
    break;
}

$package->load_origins();
echo $OUTPUT->render_from_template(
    'block_edupublisher/package',
    $package->get_flattened()
);

echo $OUTPUT->footer();
