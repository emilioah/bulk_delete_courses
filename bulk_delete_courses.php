<?php
// Place this script in /<moodle-root-path>/course/ directory and run it
//
// 
// Tested Moodle version:
// * Moodle 2.6 - 1 Apr 2016
// 
// Authors: Emilio Arjona
// Email: emilio.ah[at]gmail[dot]com
//
// This script contains code of backup.php from Lancaster University
//
//
/**
 * This script allows to do backup and remove courses using some 
 * restrictions.
 *
 * @package    core
 * @subpackage cli
 * @copyright  2016 Universidad de Granada
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


// to be able to run this file as command line script
define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir.'/clilib.php');      // cli only functions
require_once($CFG->libdir.'/cronlib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

/*
 * DEFAULT SCRIPT VARIABLES
 * 
 * */
$MAXTIME = 5400; // 1h30m
$MAXCOURSES = 100;
$DRYRUN = 0;
$DELETECONDITIONS = 'timecreated=timemodified AND shortname like \'%1415%\' AND visible=0';
//$DESTINATION = '/opt/lampp/app_data/moodle/backup_folder'; // tad10
//$DESTINATION = '/var/www/moodledata/backuppradodev'; // niebla
$DESTINATION = '/backup/prado'; // PRADO!!!
$FILENAMEPREFIX = 'deleted-';
$SENDEMAIL = 1;
$EMAILTO = 'emilio@ugr.es';


$starttime = microtime();

/// emulate normal session
cron_setup_user();

/// Start output log
$timenow = time();

mtrace("Server Time: ".date('r',$timenow)."\n\n");


$admin = get_admin();
if (!$admin) {
    mtrace("Error: No admin account was found");
    die;
}

// Check destination
$dir = rtrim($DESTINATION, '/');
if (!empty($dir)) {
    if (!file_exists($dir) || !is_dir($dir) || !is_writable($dir)) {
        mtrace("Destination directory does not exists or not writable.");
        die;
    }
}

$maxtime = $MAXTIME;
if ($maxtime>0){
	mtrace("Maxtime restriction active. Backups will be stopped after $maxtime seconds.");
}else {
	mtrace("Maxtime restriction inactive.");
	$maxtime =0;
}


// SEARCH CONDITIONS
$where_clause = $DELETECONDITIONS;
$maxcourses = $MAXCOURSES;
if ($maxcourses>0){
	mtrace("Max courses restriction active. Backups will be stopped after $maxcourses courses.");	
	if ($CFG->dbtype == 'oci'){
		$query = "SELECT * FROM (SELECT * FROM {course} WHERE ( $where_clause ) ORDER BY timecreated ASC) where rownum <= $maxcourses";
	}else{
		$query = "SELECT * FROM {course} WHERE ( $where_clause ) ORDER BY timecreated ASC LIMIT $maxcourses" ;
	}	
}else {
	mtrace("Max courses restriction inactive.");
	$maxcourses =0;	
	$query = "SELECT * FROM {course} WHERE ( $where_clause ) ORDER BY timecreated ASC" ;
}

mtrace ("QUERY: $query\n");
$courses = $DB->get_records_sql($query);
mtrace("Courses count: " . count($courses) . "\n");
if(count($courses) > 0) { // there is one default course of moodle
		$backedupcourses = 0;
		$backedupcourseslist = '';
		foreach ($courses as &$course) {			
			// Emilio: Test time elapsed, maxtimeelapsedskipped is true to skip next backups
			$currenttime = time();
			$skipped = false;
			if (($maxtime>0) && (($currenttime - $timenow) > $maxtime) && !$skipped ){						
					$skipped = true;
					$skippedmessage = "Max time restriction applied ($maxtime seconds).".($currenttime - $timenow)." seconds elapsed.\n";
			}
			
			if (($maxcourses>0) && ($backedupcourses >= $maxcourses) && !$skipped ){
					$skipped = true;
					$skippedmessage = "Max courses restriction applied ($MAXCOURSES courses). $backedupcourses were backed up.\n";
			}			
			
			if (!$skipped){
				$backedupcourses++;
				print_r("Course: " . $course->fullname . "\n");
				/*Backing up*/
				cli_heading('Performing backup...');
				$bc = new backup_controller(backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE,
								backup::INTERACTIVE_YES, backup::MODE_GENERAL, $admin->id);
				// Set the default filename.
				$format = $bc->get_format();
				$type = $bc->get_type();
				$id = $bc->get_id();
				$users = $bc->get_plan()->get_setting('users')->get_value();
				$anonymised = $bc->get_plan()->get_setting('anonymize')->get_value();
				$filename = backup_plan_dbops::get_default_backup_filename($format, $type, $id, $users, $anonymised);
				$bc->get_plan()->get_setting('filename')->set_value($filename);
	
				// Execution.
				$bc->finish_ui();
				$bc->execute_plan();
				$results = $bc->get_results();
				$file = $results['backup_destination']; // May be empty if file already moved to target location.
				
				
				// Do we need to store backup somewhere else?			
				if ($DRYRUN){
					mtrace ("DRY RUN ACTIVATED Baking up: " . $course->fullname . "\n");
					$filename = $FILENAMEPREFIX . 'dryrun-'.$filename;
				} else{
					$filename = $FILENAMEPREFIX . $filename;
				}
				if (!empty($dir)) {
					if ($file) {
						mtrace("Writing " . $dir.'/'.$filename);
						if ($file->copy_content_to($dir.'/'.$filename)) {
							$file->delete();
							mtrace("Backup completed.");
						} else {
							mtrace("Destination directory does not exist or is not writable. Leaving the backup in the course backup file area.");
						}
					}
				} else {
					mtrace("Backup completed, the new file is listed in the backup area of the given course");
				}			
				$bc->destroy();
				
				/*Deleting*/			
				if ($DRYRUN){
					mtrace ("DRY RUN ACTIVATED: " . $course->fullname . " was not deleted\n");			
				} else { 
					mtrace ("Deleting: " . $course->fullname . "\n");
					delete_course($course);
					fix_course_sortorder(); // important!
				}
				$backedupcourseslist .= $course->fullname . ' *** ' . $course->idnumber . "\n";
				
			
			} else {
				mtrace ($skippedmessage);
				break;
			}
		}
}
else { 
		print_r("\nNo course in the system!\n");                                                                                                             
}

//Send email to admin if necessary
if ($SENDEMAIL) {
	mtrace("Sending email to admin");
	$message = "";
	
	// Build the message text.
	// Summary.
	$message .= get_string('summary') . "\n";
	$message .= "==================================================\n";
	$message .= '  ' . get_string('courses') . '; ' . $backedupcourses . "\n";
	$message .= "==================================================\n";
	$message .= $backedupcourseslist;
	
	//Build the message subject
	$site = get_site();	
	$subject = "[ADMIN-PRADO] Backup y borrado de cursos vacíos";
	
	//Send the message
	$eventdata = new stdClass();
	$eventdata->modulename        = 'moodle';
	$eventdata->userfrom          = $admin;
	$eventdata->userto            = $admin;
	$eventdata->subject           = $subject;
	$eventdata->fullmessage       = $message;
	$eventdata->fullmessageformat = FORMAT_PLAIN;
	$eventdata->fullmessagehtml   = '';
	$eventdata->smallmessage      = '';
	
	$eventdata->component         = 'moodle';
	$eventdata->name         = 'backup';
	
	mtrace($message);
	message_send($eventdata);
}
mtrace("Bulk delete courses completed.");

$difftime = microtime_diff($starttime, microtime());
mtrace("Execution took ".$difftime." seconds");
	
