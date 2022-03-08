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
 * @author     Robert Schrenk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/blocks/moodleblock.class.php');

class block_edupublisher extends block_base {
    /**
     * Gets an existing package by its courseid.
     * @param courseid the courseid.
     */
    public static function get_package_by_courseid($courseid, $strictness = MUST_EXIST) {
        global $DB;
        $item = $DB->get_record('block_edupublisher_packages', array('course' => $courseid), '*', $strictness);
        if (!empty($item->id)) {
            return self::get_package($item->id);
        }
    }
    /**
     * Creates an empty package and fills with data from course.
     * This is used when we create a new package.
    **/
    public static function get_package_from_course($courseid){
        global $DB, $USER;
        $package = self::get_package(0);
        $course = get_course($courseid);
        $package->active = 0;
        $package->sourcecourse = $course->id;
        $package->default_title = $course->fullname;
        $package->default_authorname = $USER->firstname . ' ' . $USER->lastname;
        $package->default_authormail = $USER->email;
        $package->default_summary = $course->summary;

        return $package;
    }
    /**
     * Gets a publisher from database.
     * @param publisherid
     */
    public static function get_publisher($publisherid) {
        global $DB, $USER;
        $publisher = $DB->get_record('block_edupublisher_pub', array('id' => $publisherid), '*', IGNORE_MISSING);
        if (empty($publisher->id)) return null;
        $is_coworker = $DB->get_record('block_edupublisher_pub_user', array('publisherid' => $publisherid, 'userid' => $USER->id));
        $publisher->is_coworker = (!empty($is_coworker->userid) && $is_coworker->userid == $USER->id);
        // Load Logo of publisher.
        $fs = get_file_storage();
        $context = context_system::instance();
        $files = $fs->get_area_files($context->id, 'block_edupublisher', 'publisher_logo', $publisherid);
        foreach ($files as $f) {
            if (empty(str_replace('.', '', $f->get_filename()))) continue;
            $publisher->publisher_logo = '' . moodle_url::make_pluginfile_url($f->get_contextid(), $f->get_component(), $f->get_filearea(), $f->get_itemid(), $f->get_filepath(), $f->get_filename(), false);
            break;
        }
        return $publisher;
    }

    /**
     * Load a specific comment and enhance data.
     * @param id of comment
     */
    public static function load_comment($id) {
        global $CFG, $DB;
        $comment = $DB->get_record('block_edupublisher_comments', array('id' => $id));
        $user = $DB->get_record('user', array('id' => $comment->userid));
        $comment->userfullname = fullname($user);
        if (!empty($comment->linkurl)) {
            $comment->linkurl = new \moodle_url($comment->linkurl);
        }
        $ctx = context_user::instance($comment->userid);
        $comment->userpictureurl = $CFG->wwwroot . '/pluginfile.php/' . $ctx->id . '/user/icon';
        $comment->wwwroot = $CFG->wwwroot;
        return $comment;
    }
    /**
     * Load all comments for a package.
     * @param packageid of package
     * @param includeprivate whether or not to include private  communication
     * @param sortorder ASC or DESC
     */
    public static function load_comments($packageid, $private = false, $sortorder = 'ASC') {
        global $DB;
        if ($sortorder != 'ASC' && $sortorder != 'DESC') $sortorder = 'ASC';
        $sql = "SELECT id
                    FROM {block_edupublisher_comments}
                    WHERE package=?";
        if (!$private) {
            $sql .= " AND ispublic=1";
        }
        $sql .= ' ORDER BY id ' . $sortorder;
        $commentids = array_keys($DB->get_records_sql($sql, array($packageid)));
        $comments = array();
        foreach ($commentids AS $id) {
            $comments[] = self::load_comment($id);
        }
        return $comments;
    }

    /**
     * Prepares a package to be shown in a form.
     * @param package to be prepared
     * @return prepared package
    **/
    public static function prepare_package_form($package) {
        global $CFG, $COURSE;

        if (empty($package->id) && !empty($package->sourcecourse)) {
            $context = context_course::instance($package->sourcecourse);
        } elseif (!empty($package->course)) {
            $context = context_course::instance($package->course);
        } else {
            $context = context_course::instance($COURSE->id);
        }
        $definition = self::get_channel_definition();
        $channels = array_keys($definition);
        foreach($channels AS $channel) {
            $fields = array_keys($definition[$channel]);
            foreach($fields AS $field) {
                $ofield = $definition[$channel][$field];
                // If this package is newly created and the field is default_image load course image.
                if (empty($package->id) && $channel == 'default' && $field == 'image') {
                    $draftitemid = file_get_submitted_draft_itemid($channel . '_' . $field);
                    file_prepare_draft_area($draftitemid, $context->id, 'course', 'overviewfiles', 0,
                        array(
                            'subdirs' => (!empty($ofield['subdirs']) ? $ofield['subdirs'] : package_create_form::$subdirs),
                            'maxbytes' => (!empty($ofield['maxbytes']) ? $ofield['maxbytes'] : package_create_form::$maxbytes),
                            'maxfiles' => (!empty($ofield['maxfiles']) ? $ofield['maxfiles'] : package_create_form::$maxfiles)
                        )
                    );
                    $package->{$channel . '_' . $field} = $draftitemid;
                    continue;
                }

                if (!isset($package->{$channel . '_' . $field})) continue;
                if ($ofield['type'] == 'editor') {
                    $package->{$channel . '_' . $field} = array('text' => $package->{$channel . '_' . $field});
                }
                if (isset($ofield['type']) && $ofield['type'] == 'filemanager') {
                    require_once($CFG->dirroot . '/blocks/edupublisher/classes/package_create_form.php');
                    $draftitemid = file_get_submitted_draft_itemid($channel . '_' . $field);
                    file_prepare_draft_area($draftitemid, $context->id, 'block_edupublisher', $channel . '_' . $field, $package->id,
                        array(
                            'subdirs' => (!empty($ofield['subdirs']) ? $ofield['subdirs'] : package_create_form::$subdirs),
                            'maxbytes' => (!empty($ofield['maxbytes']) ? $ofield['maxbytes'] : package_create_form::$maxbytes),
                            'maxfiles' => (!empty($ofield['maxfiles']) ? $ofield['maxfiles'] : package_create_form::$maxfiles)
                        )
                    );
                    $package->{$channel . '_' . $field} = $draftitemid;
                }
            }
        }

        $package->exportcourse = 1;
        return $package;
    }
    /**
     * Grants or revokes a role from a course.
     * @param courseids array with courseids
     * @param userids array with userids
     * @param role -1 to remove user, number of role or known identifier (defaultroleteacher, defaultrolestudent) to assign role.
     */
    public static function role_set($courseids, $userids, $role) {
        if ($role == 'defaultroleteacher') $role = get_config('block_edupublisher', 'defaultroleteacher');
        if ($role == 'defaultrolestudent') $role = get_config('block_edupublisher', 'defaultrolestudent');
        if (empty($role)) return;

        $enrol = enrol_get_plugin('manual');
        if (empty($enrol)) {
            return false;
        }

        global $DB;
        foreach ($courseids AS $courseid) {
            // Check if course exists.
            $course = get_course($courseid);
            if (empty($course->id)) continue;
            // Check manual enrolment plugin instance is enabled/exist.
            $instance = null;
            $enrolinstances = enrol_get_instances($courseid, false);
            foreach ($enrolinstances as $courseenrolinstance) {
              if ($courseenrolinstance->enrol == "manual") {
                  $instance = $courseenrolinstance;
                  break;
              }
            }
            if (empty($instance)) {
                // We have to add a "manual-enrolment"-instance
                $fields = array(
                    'status' => 0,
                    'roleid' => get_config('block_edupublisher', 'defaultrolestudent'),
                    'enrolperiod' => 0,
                    'expirynotify' => 0,
                    'expirytreshold' => 0,
                    'notifyall' => 0
                );
                require_once($CFG->dirroot . '/enrol/manual/lib.php');
                $emp = new enrol_manual_plugin();
                $instance = $emp->add_instance($course, $fields);
            }
            if ($instance->status == 1) {
                // It is inactive - we have to activate it!
                $data = (object)array('status' => 0);
                require_once($CFG->dirroot . '/enrol/manual/lib.php');
                $emp = new enrol_manual_plugin();
                $emp->update_instance($instance, $data);
                $instance->status = $data->status;
            }
            foreach ($userids AS $userid) {
                if ($role == -1) {
                    $enrol->unenrol_user($instance, $userid);
                } else {
                    $enrol->enrol_user($instance, $userid, $role, 0, 0, ENROL_USER_ACTIVE);
                }
            }
        }
    }
    /**
     * Stores a comment and sents info mails to target groups.
     * @param package
     * @param text
     * @param sendto-identifiers array of identifiers how should be notified
     * @param commentlocalize languageidentifier for sending the comment localized
     * @param channel whether this comment refers to a particular channel.
     * @param linkurl if comment should link to a url.
     * @return id of comment.
     */
    public static function store_comment($package, $text, $sendto = array(), $isautocomment = false, $ispublic = 0, $channel = "", $linkurl = "") {
        global $DB, $OUTPUT, $USER;
        if (isloggedin() && !isguestuser($USER)) {
            $comment = (object)array(
                'content' => $text,
                'created' => time(),
                'forchannel' => $channel,
                'isautocomment' => ($isautocomment) ? 1 : 0,
                'ispublic' => ($ispublic) ? 1 : 0,
                'package' => $package->id,
                'permahash' => md5(date('YmdHis') . time() . $USER->firstname),
                'userid' => $USER->id,
                'linkurl' => $linkurl,
            );
            $comment->id = $DB->insert_record('block_edupublisher_comments', $comment);

            if (in_array('allmaintainers', $sendto)) {
                $possiblechannels = array('default', 'eduthek', 'etapas');
                foreach($possiblechannels AS $channel) {
                    if (empty($package->{$channel . '_publishas'}) || !$package->{$channel . '_publishas'}) continue;
                    if (!in_array('maintainers_' . $channel, $sendto)) {
                        $sendto[] = 'maintainers_' . $channel;
                    }
                }
            }
            $recipients = array();
            $category = get_config('block_edupublisher', 'category');
            $context = context_coursecat::instance($category);
            foreach ($sendto AS $identifier) {
                switch ($identifier) {
                    case 'author': $recipients[$package->userid] = true; break;
                    case 'commentors':
                        $commentors = $DB->get_records_sql('SELECT DISTINCT(userid) AS id FROM {block_edupublisher_comments} WHERE package=?', array($package->id));
                        foreach ($commentors AS $commentor) {
                            $recipients[$commentor->id] = true;
                        }
                    break;
                    case 'maintainers_default':
                        $maintainers = get_users_by_capability($context, 'block/edupublisher:managedefault', '', '', '', 100);
                        foreach ($maintainers AS $maintainer) {
                            $recipients[$maintainer->id] = true;
                        }
                    break;
                    case 'maintainers_eduthek':
                        $maintainers = get_users_by_capability($context, 'block/edupublisher:manageeduthek', '', '', '', 100);
                        foreach ($maintainers AS $maintainer) {
                            $recipients[$maintainer->id] = true;
                        }
                    break;
                    case 'maintainers_etapas':
                        $maintainers = get_users_by_capability($context, 'block/edupublisher:manageetapas', '', '', '', 100);
                        foreach ($maintainers AS $maintainer) {
                            $recipients[$maintainer->id] = true;
                        }
                    break;
                    case 'self': $recipients[$USER->id] = true; break;
                }
            }
            if (count($recipients) > 0) {
                $comment = self::load_comment($comment->id);
                $comment->userpicturebase64 = block_edupublisher::user_picture_base64($USER->id);
                $fromuser = $USER; // core_user::get_support_user(); //$USER;
                $comments = array();
                $subjects = array();
                $messagehtmls = array();
                $messagetexts = array();

                $recipients = array_keys($recipients);
                foreach($recipients AS $_recipient) {
                    $recipient = $DB->get_record('user', array('id' => $_recipient));
                    if (!isset($subjects[$recipient->lang])) {
                        if (!empty($comment->linkurl)) {
                            $package->commentlink = $comment->linkurl->__toString();
                        }
                        if ($isautocomment) {
                            $comments[$recipient->lang] = get_string_manager()->get_string($text, 'block_edupublisher', $package, $recipient->lang);
                            $comments[$recipient->lang] .= get_string_manager()->get_string('comment:notify:autotext', 'block_edupublisher', $package, $recipient->lang);
                        } else {
                            $comments[$recipient->lang] = $text;
                        }
                        $subjects[$recipient->lang] = get_string_manager()->get_string('comment:mail:subject' , 'block_edupublisher', $package, $recipient->lang);
                        $tmpcomment = $comment;
                        $tmpcomment->content = $comments[$recipient->lang];
                        $messagehtmls[$recipient->lang] = $OUTPUT->render_from_template(
                            'block_edupublisher/package_comment_notify',
                            $tmpcomment
                        );
                        $messagehtmls[$recipient->lang] = self::enhance_mail_body($subjects[$recipient->lang], $messagehtmls[$recipient->lang]);
                        $messagetexts[$recipient->lang] = html_to_text($messagehtmls[$recipient->lang]);
                    }

                    try {
                        email_to_user($recipient, $fromuser, $subjects[$recipient->lang], $messagetexts[$recipient->lang], $messagehtmls[$recipient->lang], '', '', true);
                    } catch(Exception $e) {
                        throw new \moodle_exception('send_email_failed', 'block_edupublisher', $PAGE->url->__toString(), $recipient, $e->getMessage());
                    }
                }
            }
            return $comment->id;
        }
    }
    /**
     * Stores a package and all of its meta-data based on the data of package_create_form.
     * @param package package data from form.
    **/
    public static function store_package($package) {
        global $CFG, $DB;
        // Every author must publish in  the default channel.
        $package->default_publishas = 1;

        $context = context_course::instance($package->course);

        // Flatten data
        $keys = array_keys((array) $package);
        foreach($keys AS $key) {
            if (isset($package->{$key}['text'])) {
                $package->{$key} = $package->{$key}['text'];
            }
        }

        $package->title = $package->default_title;

        // Retrieve all channels that we publish to.
        $definition = self::get_channel_definition();
        $channels = array_keys($definition);
        $package->_channels = array();
        foreach($channels AS $channel) {
            if (isset($package->{$channel . '_publishas'}) && $package->{$channel . '_publishas'}) {
                $package->_channels[] = $channel;
            }
        }
        $package->channels = ',' . implode(',', $package->_channels) . ',';

        $wordpressaction = 'updated';
        if ($package->id > 0) {
            $original = self::get_package($package->id, true);
            // Save all keys from package to original
            $keys = array_keys((array) $package);
            // Prevent deactivating a channel after it was activated.
            $ignore = array('etapas_publishas', 'eduthek_publishas');
            foreach($keys AS $key) {
                if (in_array($key, $ignore) && !empty($original->{$key})) continue;
                $original->{$key} = $package->{$key};
            }

            $package = $original;
        } else {
            // Create the package to get a package-id for metadata
            $package->active = 0;
            $package->modified = time();
            $package->created = time();
            $package->deleted = 0;
            $package->id = $DB->insert_record('block_edupublisher_packages', $package, true);
            $wordpressaction = 'created';
        }

        \block_edupublisher\lib::exacompetencies($package);

        // Now store all data.
        $definition = self::get_channel_definition();
        foreach($channels AS $channel) {
            $fields = array_keys($definition[$channel]);
            //echo 'Channel: "' . $channel . '_active" => ' . $package->{$channel . '_active'} . '<br />';
            foreach($fields AS $field) {
                if (!empty($definition[$channel][$field]['donotstore'])) continue;
                $dbfield = $channel . '_' . $field;

                // Remove all meta-objects with pattern channel_field_%, multiple items will be inserted anyway.
                // Attention: Needs to be done here. If an item has been multiple and is then updated to single it may keep deprecated metadata if executed anywhere else.
                $DB->execute('DELETE FROM {block_edupublisher_metadata} WHERE package=? AND `field` LIKE ? ESCAPE "+"', array($package->id, $channel . '+_' . $field . '+_%'));

                if($definition[$channel][$field]['type'] == 'filemanager' && !empty($draftitemid = file_get_submitted_draft_itemid($dbfield))) { // !empty($package->{$dbfield})) {
                    // We retrieve a file and set the value to the url.
                    // Store files and set value to url.
                    $fs = get_file_storage();
                    //self::clear_file_storage($context, 'block_edupublisher', $dbfield, $package->id, $fs);
                    require_once($CFG->dirroot . '/blocks/edupublisher/classes/package_create_form.php');
                    $options = (object)array(
                        'accepted_types' => (!empty($definition[$channel][$field]['accepted_types']) ? $definition[$channel][$field]['accepted_types'] : package_create_form::$accepted_types),
                        'areamaxbytes' => (!empty($definition[$channel][$field]['areamaxbytes']) ? $definition[$channel][$field]['areamaxbytes'] : package_create_form::$areamaxbytes),
                        'maxbytes' => (!empty($definition[$channel][$field]['maxbytes']) ? $definition[$channel][$field]['maxbytes'] : package_create_form::$maxbytes),
                        'maxfiles' => (!empty($definition[$channel][$field]['maxfiles']) ? $definition[$channel][$field]['maxfiles'] : package_create_form::$maxfiles),
                        'subdirs' => (!empty($definition[$channel][$field]['subdirs']) ? $definition[$channel][$field]['subdirs'] : package_create_form::$subdirs),
                    );
                    file_save_draft_area_files(
                        $draftitemid, $context->id, 'block_edupublisher', $dbfield, $package->id,
                        array('subdirs' => $options->subdirs, 'maxbytes' => $options->maxbytes, 'maxfiles' => $options->maxfiles)
                    );

                    $files = $fs->get_area_files($context->id, 'block_edupublisher', $dbfield, $package->id);
                    $urls = array();
                    foreach ($files as $file) {
                        if (in_array($file->get_filename(), array('.'))) continue;
                        $urls[] = '' . moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());
                    }
                    if (count($urls) == 0) {
                        unset($package->{$dbfield});
                    } elseif(count($urls) == 1) {
                        $package->{$dbfield} = $urls[0];
                    } else {
                        $package->{$dbfield} = $urls;
                        $definition[$channel][$field]['multiple'] = 1;
                    }
                }
                // We retrieve anything else.
                if (isset($package->{$dbfield}) && (is_array($package->{$dbfield}) || !empty($package->{$dbfield})  || is_numeric($package->{$dbfield}))) {
                    unset($allowedoptions);
                    unset($allowedkeys);
                    if (!empty($definition[$channel][$field]['options'])) {
                        $allowedoptions = $definition[$channel][$field]['options'];
                        $allowedkeys = array_keys($allowedoptions);
                    }
                    if (!empty($definition[$channel][$field]['multiple'])) {
                        //$options = array_keys($definition[$channel][$field]['options']);
                        //error_log($dbfield . ' => ' . $package->{$dbfield});
                        if (!is_array($package->{$dbfield})) {
                            $package->{$dbfield} = array($package->{$dbfield});
                        }
                        $options = array_keys($package->{$dbfield});
                        foreach ($options AS $option) {
                            $content = $package->{$dbfield}[$option];
                            if (!isset($allowedkeys) || in_array($content, $allowedkeys)) {
                                self::store_metadata($package, $channel, $channel . '_' . $field . '_' . $option, $content);
                            }
                            if (isset($allowedkeys)) {
                                // If the option text differs from the content store as separate value for search operations.
                                if ($allowedoptions[$content] != $content) {
                                    self::store_metadata($package, $channel, $channel . '_' . $field . '_' . $option . ':dummy', $allowedoptions[$content]);
                                }
                            }
                        }
                    } else {
                        self::store_metadata($package, $field, $dbfield);
                        // If the option text differs from the content store as separate value for search operations.
                        if (isset($allowedkeys) && $allowedoptions[$package->{$dbfield}] != $package->{$dbfield}) {
                            self::store_metadata($package, $field, $dbfield . ':dummy', $allowedoptions[$package->{$dbfield}]);
                        }
                    }
                }
            }
        }

        if (
            isset($package->etapas_publishas) && $package->etapas_publishas
            ||
            isset($package->eduthek_publishas) && $package->eduthek_publishas
        ) {
            // Publish as lti tools
            $targetcourse = get_course($package->course);
            $targetcontext = context_course::instance($package->course);
            //echo "<p>Publishing as LTI</p>";
            //print_r($package->_channels);
            require_once($CFG->dirroot . '/enrol/lti/lib.php');
            $elp = new enrol_lti_plugin();
            $ltichannels = array('etapas', 'eduthek');
            foreach($package->_channels AS $channel) {
                // Only some channels allow to be published as lti tool.
                //echo "<p>Publish for $channel</p>";
                if (!in_array($channel, $ltichannels)) continue;
                // Check if this channel is already published via LTI.
                //echo "<p>LTI Secret currently is " .$package->{$channel . '_ltisecret'} . "</p>";
                if (!empty($package->{$channel . '_ltisecret'})) continue;
                $package->{$channel . '_ltisecret'} = substr(md5(date("Y-m-d H:i:s") . rand(0,1000)),0,30);
                //echo "<p>Set secret to " . $package->{$channel . '_ltisecret'}  . "</p>";
                $lti = array(
                    'contextid' => $targetcontext->id,
                    'gradesync' => 1,
                    'gradesynccompletion' => 0,
                    'membersync' => 1,
                    'membersyncmode' => 1,
                    'name' => $package->title . ' [' . $channel . ']',
                    'roleinstructor' => get_config('block_edupublisher', 'defaultrolestudent'),
                    'rolelearner' => get_config('block_edupublisher', 'defaultrolestudent'),
                    'secret' => $package->{$channel . '_ltisecret'},
                );
                $elpinstanceid = $elp->add_instance($targetcourse, $lti);
                //echo "<p>ELPInstanceID $elpinstanceid</p>";
                if ($elpinstanceid) {
                    require_once($CFG->dirroot . '/enrol/lti/classes/helper.php');
                    $elpinstance = $DB->get_record('enrol_lti_tools', array('enrolid' => $elpinstanceid), 'id', MUST_EXIST);
                    $tool = enrol_lti\helper::get_lti_tool($elpinstance->id);
                    $package->{$channel . '_ltiurl'} = '' . enrol_lti\helper::get_launch_url($elpinstance->id);
                    $package->{$channel . '_lticartridge'} = '' . enrol_lti\helper::get_cartridge_url($tool);
                    //echo "<p>Lti-Data " . $package->{$channel . '_ltiurl'} . " and " . $package->{$channel . '_lticartridge'} . "</p>";
                    self::store_metadata($package, $channel, $channel . '_ltiurl');
                    self::store_metadata($package, $channel, $channel . '_lticartridge');
                    self::store_metadata($package, $channel, $channel . '_ltisecret');
                }
            }
        }

        // If there is a default_imageurl store the file as course image.
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'block_edupublisher', 'default_image', $package->id);
        $courseimage = (object) array('imagepath' => '', 'imagename' => '');
        foreach ($files as $file) {
            if ($file->is_valid_image()) {
                $courseimage->imagename = $file->get_filename();
                $contenthash = $file->get_contenthash();
                $courseimage->imagepath = $CFG->dataroot . '/filedir/' . substr($contenthash, 0, 2) . '/' . substr($contenthash, 2, 2) . '/' . $contenthash;
                $package->default_imageurl = '' . moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());
                break;
            }
        }
        if ($courseimage->imagepath != '') {
            $context = context_course::instance($package->course);
            self::clear_file_storage($context, 'course', 'overviewfiles', 0, $fs);

            // Load new image to file area of targetcourse
            $fs = get_file_storage();
            $file_record = array('contextid' => $context->id, 'component' => 'course', 'filearea' => 'overviewfiles',
                     'itemid' => 0, 'filepath'=>'/', 'filename' => $courseimage->imagename,
                     'timecreated' => time(), 'timemodified' => time());
            $fs->create_file_from_pathname($file_record, $courseimage->imagepath);
        }
        $course = get_course($package->course);
        $course->summary = $package->default_summary;
        $course->fullname = $package->default_title;
        $DB->update_record('course', $course);
        rebuild_course_cache($course->id, true);

        $package->modified = time();
        $DB->update_record('block_edupublisher_packages', $package);

        \block_edupublisher\wordpress::action($wordpressaction, $package);

        // Deactivated because of comment-system.
        //block_edupublisher::notify_maintainers($package);
        return $package;
    }
    /**
     * Updates or inserts a specific metadata field.
     * @param package to set
     * @param channel to which the field belongs
     * @param field complete name of field (channel_fieldname)
     * @param content (optional) content to set, if not set will be retrieved from $package
    **/
    public static function store_metadata($package, $channel, $field, $content = '') {
        global $DB;

        $metaobject = (object) array(
                'package' => $package->id,
                'field' => $field,
                'content' => !empty($content) ? $content : $package->{$field},
                'created' => time(),
                'modified' => time(),
                'active' => !empty($package->{$channel . '_active'}) ? $package->{$channel . '_active'} : 0,
        );

        $o = $DB->get_record('block_edupublisher_metadata', array('package' => $metaobject->package, 'field' => $metaobject->field));
        if (isset($o->id) && $o->id > 0) {
            if ($o->content != $metaobject->content) {
                $metaobject->id = $o->id;
                $metaobject->active = $o->active;
                $DB->update_record('block_edupublisher_metadata', $metaobject);
                //echo "Update " . print_r($metaobject, 1);
            }
        } else {
            //echo "Insert " . print_r($metaobject, 1);
            $DB->insert_record('block_edupublisher_metadata', $metaobject);
        }
    }
    /**
     * Enables or disables guest access to a course.
     * @param courseid the course id
     * @param setto 1 (default) to enable, 0 to disable access.
     */
    public static function toggle_guest_access($courseid, $setto = 1) {
        global $CFG;

        require_once($CFG->dirroot . '/enrol/guest/lib.php');
        $course = \get_course($courseid);
        $fields = array(
            'status' => (empty($setto) ? 1 : 0), // status in database reversed
            'password' => '',
        );
        $gp = new \enrol_guest_plugin();
        if (!empty($setto)) {
            $gp->add_instance($course, $fields);
        } else {
            require_once($CFG->dirroot . '/lib/enrollib.php');
            $instances = \enrol_get_instances($courseid, false);
            foreach ($instances as $instance) {
                if ($instance->enrol != 'guest') continue;
                $gp->delete_instance($instance);
            }
        }
    }
    /**
     * Gets the user picture and returns it as base64 encoded string.
     * @param userid
     * @return picture base64 encoded
     */
    public static function user_picture_base64($userid) {
        $context = context_user::instance($userid);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'user', 'icon');
        $find = array('f1.jpg', 'f1.png');
        foreach ($files as $f) {
            if (in_array($f->get_filename(), $find)) {
                $extension = explode(".", $f->get_filename());
                $extension = $extension[count($extension) - 1];
                return 'data:image/' . $extension . ';base64,' . base64_encode($f->get_content());
            }
        }
        return '';
    }

    public function init() {
        $this->title = get_string('pluginname', 'block_edupublisher');
    }
    public function get_content() {
        global $CFG, $COURSE, $DB, $OUTPUT, $PAGE, $USER;

        $PAGE->requires->css('/blocks/edupublisher/style/main.css');
        $PAGE->requires->css('/blocks/edupublisher/style/ui.css');

        if (!isset($COURSE->id) || $COURSE->id <= 1) {
            return;
        }

        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = (object) array(
            'text' => '',
            'footer' => ''
        );

        // 1. in a package-course: show author
        // 2. in a course + trainer permission: show publish link and list packages
        // 3. show nothing

        $context = context_course::instance($COURSE->id);
        $isenrolled = is_enrolled($context, $USER->id, '', true);
        $canedit = has_capability('moodle/course:update', $context);

        $package = $DB->get_record('block_edupublisher_packages', array('course' => $COURSE->id), '*', IGNORE_MULTIPLE);
        $options = array();
        if (!empty($package->id)) {
            $package = self::get_package($package->id, true);
            if ($package->default_licence == 'other') $package->default_licence = get_string('default_licenceother', 'block_edupublisher');
            if (!empty($package->etapas_subtype) && $package->etapas_subtype == 'etapa' && has_capability('block/edupublisher:canseeevaluation', \context_system::instance())) {
                $package->can_see_evaluation = true;
            }
            // Show use package-button
            $courses = self::get_courses(null, 'moodle/course:update');
            if (count(array_keys($courses)) > 0) {
                $package->can_import = true;
                $package->allow_subcourses = $allowsubcourses = \get_config('block_edupublisher', 'allowsubcourses') ? 1 : 0;
            }
            $package->can_unenrol = (is_enrolled($context, null, 'block/edupublisher:canselfenrol')) ? 1 : 0;

            if (!empty($package->etapas_active) && !empty($package->etapas_subtype)) {
                $package->etapas_graphic = str_replace(array(' ', '.'), '', $package->etapas_subtype);
            }
            $this->content->text .= $OUTPUT->render_from_template('block_edupublisher/block_inpackage', $package);
        } elseif($canedit) {
            $cache = cache::make('block_edupublisher', 'publish');
            $pendingpublication = $cache->get("pending_publication_$COURSE->id");
            if (empty($pendingpublication)) {
                $cache->set("pending_publication_$COURSE->id", -1);
                $sql = "SELECT *
                            FROM {block_edupublisher_publish}
                            WHERE sourcecourseid = ?
                                OR targetcourseid = ?";
                $pendingpublications = $DB->get_records_sql($sql, [ $COURSE->id, $COURSE->id ]);
                foreach ($pendingpublications as $pendingpublication) {
                    $pendingpublication = $pendingpublication->sourcecourseid;
                    $cache->set("pending_publication_$COURSE->id", $pendingpublication);
                    break;
                }
            }
            $params = (object) [
                'courseid' => $COURSE->id,
                'packages' => array_values($DB->get_records_sql('SELECT * FROM {block_edupublisher_packages} WHERE sourcecourse=? AND (active=1 OR userid=?)', array($COURSE->id, $USER->id))),
                'pendingpublication' => $pendingpublication,
                'uses'     => array_values($DB->get_records_sql('SELECT DISTINCT(package) FROM {block_edupublisher_uses} WHERE targetcourse=?', array($COURSE->id))),
            ];
            $params->haspackages = (count($params->packages) > 0) ? 1 : 0;
            $params->hasuses     = (count($params->uses)     > 0) ? 1 : 0;

            $this->content->text .= $OUTPUT->render_from_template('block_edupublisher/block_canedit', $params);
        }
        return $this->content;
    }
    public function hide_header() {
        return false;
    }
    public function has_config() {
        return true;
    }
    public function instance_allow_multiple() {
        return false;
    }
}
