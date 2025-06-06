<?php
require_once("init.php");
//For security purposes, it is MANDATORY that this page be wrapped in the following
//if statement. This prevents remote execution of this code.
if (in_array($user->data()->id, $master_account)) {
	$db = DB::getInstance();
	include "plugin_info.php";

	//all actions should be performed here.
	$check = $db->query("SELECT * FROM us_plugins WHERE plugin = ?", array($plugin_name))->count();

	if ($check > 0) {
		err($plugin_name . ' has already been installed!');
	} else {
		$fields = array(
			'plugin' => $plugin_name,
			'status' => 'installed',
		);
		$db->insert('us_plugins', $fields);
		if (!$db->error()) {
			err($plugin_name . ' installed');
			logger($user->data()->id, "USPlugins", $plugin_name . " installed");
		} else {
			err($plugin_name . ' was not installed');
			logger($user->data()->id, "USPlugins", "Failed to to install plugin, Error: " . $db->errorString());
		}
	}

	//do you want to inject your plugin in the middle of core UserSpice pages?
	//visit https://userspice.com/plugin-hooks/ to get a better understanding of hooks
	$hooks = [];

	//The format is $hooks['userspicepage.php']['position'] = path to filename to include
	//Note you can include the same filename on multiple pages if that makes sense;
	//postion options are post,body,form,bottom
	//See documentation for more information
	// $hooks['login.php']['body'] = 'hooks/loginbody.php';

	$db->query("CREATE TABLE `plg_tasks_settings` (
	`id` int(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`creator_perms` varchar(255) default '2',
	`creator_tags` text,
	`plugin_name` varchar(255) default 'tasks',
	`alternate_location` varchar(255)
	  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

	$db->query("ALTER TABLE plg_tasks_settings ADD COLUMN `single_term` varchar(255) default 'Task'");
	$db->query("ALTER TABLE plg_tasks_settings ADD COLUMN `plural_term` varchar(255) default 'Tasks'");
	$db->query("ALTER TABLE plg_tasks_settings ADD COLUMN `assign_to_individual` tinyint(1) default 1");
	$db->query("ALTER TABLE plg_tasks_settings ADD COLUMN `send_notification_type` int(3) default 0");



	$check2 = $db->query("SELECT * FROM plg_tasks_settings")->count();
	if ($check2 < 1) {
		$db->query("TRUNCATE TABLE plg_tasks_settings");
		$db->insert("plg_tasks_settings", ['creator_perms' => '2', 'creator_tags' => '', 'plugin_name' => 'Tasks', 'alternate_location' => '']);
	}

	if (!is_dir($abs_us_root . $us_url_root . "usersc/task_media")) {
		mkdir($abs_us_root . $us_url_root . "usersc/task_media", 0755, true);
	}
	if (!file_exists($abs_us_root . $us_url_root . "usersc/task_media/index.php")) {
		//disable file browsing
		file_put_contents($abs_us_root . $us_url_root . "usersc/task_media/index.php", "<?php header('Location: ../../'); ?>");
	}

	if (!is_dir($abs_us_root . $us_url_root . "usersc/task_scripts")) {
		mkdir($abs_us_root . $us_url_root . "usersc/task_scripts", 0755, true);
	}
	if (!file_exists($abs_us_root . $us_url_root . "usersc/task_scripts/index.php")) {
		//disable file browsing
		file_put_contents($abs_us_root . $us_url_root . "usersc/task_scripts/index.php", "<?php header('Location: ../../'); ?>");
	}

	$db->query("CREATE TABLE `plg_tasks` (
	`id` int(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`title` varchar(255),
	`description` text,
	`created_by` int(11),
	`created_on` datetime,
	`closed` tinyint(1) DEFAULT 0,
	`closed_by` int(11),
	`closed_on` datetime,
	`assigned_to_person` tinyint(1) default 0,
	`assigned_to_tag` int(11),
	`due_date` datetime,
	`priority` int(11) default 50
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

	$db->query("CREATE TABLE `plg_tasks_assignments` (
	`id` int(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`task_id` int(11),
	`user_id` int(11),
	`assigned_on` datetime,
	`assigned_by` int(11),
	`completed` tinyint(1) DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

	$db->query("ALTER TABLE plg_tasks_assignments ADD COLUMN `closed` tinyint(1) DEFAULT 0");

	$db->query("CREATE TABLE `plg_tasks_headers` (
	`id` int(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`task_id` int(11),
	`category_id` int(11) default 1,
	`description` text
	  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

	$db->query("ALTER TABLE plg_tasks_headers ADD COLUMN `completed` tinyint(1) default 0");


	$db->query("CREATE TABLE `plg_tasks_categories` (
	`id` int(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`category_name` varchar(255),
	`child_table` varchar(255) default '',
	`disabled` tinyint(1) DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
	$db->query("ALTER TABLE plg_tasks_categories ADD COLUMN `additional_functions_file` varchar(255) default ''");



	$check2 = $db->query("SELECT * FROM plg_tasks_categories")->count();
	if ($check2 < 1) {
		$db->insert("plg_tasks_categories", ['category_name' => 'General Task', 'child_table' => 'plg_tasks_lines_general']);
		$db->insert("plg_tasks_categories", ['category_name' => 'Maintenance', 'child_table' => 'plg_tasks_lines_general']);
		$db->insert("plg_tasks_categories", ['category_name' => 'Errand', 'child_table' => '']);
		$db->insert("plg_tasks_categories", ['category_name' => 'Meeting', 'child_table' => '']);
	}
	$db->query("ALTER TABLE plg_tasks ADD COLUMN `category_id` int(11) default 0 after `description`");
	$db->query("ALTER TABLE plg_tasks_categories ADD COLUMN `has_subitems` tinyint(1) DEFAULT 0");
	$db->query("ALTER TABLE plg_tasks_categories ADD COLUMN `sort_order` int(11) default 0");
	$check2 = $db->query("SELECT * FROM plg_tasks_categories WHERE sort_order > 0")->count();
	if ($check2 < 1) {
		$all = $db->query("SELECT * FROM plg_tasks_categories")->results();
		foreach ($all as $cat) {
			$db->update("plg_tasks_categories", $cat->id, ['sort_order' => $cat->id]);
		}
	}

	$db->query("ALTER TABLE plg_tasks_categories ADD COLUMN `icon` varchar(255) default 'fa fa-tasks'");
	$db->query("ALTER TABLE plg_tasks_categories ADD COLUMN `color` varchar(255) default 'firstRun'");
	$db->query("ALTER TABLE plg_tasks ADD COLUMN `marked_complete_by` int(11)");
	$db->query("ALTER TABLE plg_tasks ADD COLUMN `marked_complete_on` datetime");
	$db->query("ALTER TABLE plg_tasks ADD COLUMN `completed` tinyint(1) DEFAULT 0");
	$db->query("ALTER TABLE plg_tasks_comments ADD COLUMN `photos` text");
	$check = $db->query("SELECT * FROM plg_tasks_categories WHERE color = 'firstRun'")->count();
	if ($check > 0) {
		//set initials
		$db->update("plg_tasks_categories", 1, ['color' => 'blue', 'icon' => 'fa fa-tasks']);
		$db->update("plg_tasks_categories", 2, ['color' => 'green', 'icon' => 'fa fa-wrench']);
		$db->update("plg_tasks_categories", 3, ['color' => 'orange', 'icon' => 'fa fa-car']);
		$db->update("plg_tasks_categories", 4, ['color' => 'red', 'icon' => 'fa fa-calendar']);
	}

	$db->query("CREATE TABLE `plg_tasks_comments` (
	`id` int(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`task_id` int(11),
	`comment` text,
	`created_by` int(11),
	`created_on` datetime
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");


	$db->query("CREATE TABLE `plg_tasks_child_tables` (
	`id` int(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`table_name` varchar(255)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

	$check = $db->query("SELECT * FROM plg_tasks_child_tables WHERE table_name = 'plg_tasks_lines_general'")->count();
	if ($check < 1) {
		$db->insert("plg_tasks_child_tables", ['table_name' => 'plg_tasks_lines_general']);
	}

	$db->query("CREATE TABLE `plg_tasks_lines_general` (
		`id` int(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
		`task_id` int(11)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
	$db->query("ALTER TABLE plg_tasks_lines_general ADD column `line` text");
	$db->query("ALTER TABLE plg_tasks_lines_general ADD column `line_required` tinyint(1) default 0");
	$db->query("ALTER TABLE plg_tasks_lines_general ADD column `completed` tinyint(1) default 0");
	$db->query("ALTER TABLE plg_tasks_lines_general ADD column `completed_on` datetime");
	$db->query("ALTER TABLE plg_tasks_lines_general ADD column `completed_by` int(11)");




	registerHooks($hooks, $plugin_name);
} //do not perform actions outside of this statement
