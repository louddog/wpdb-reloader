<?php
/*
Plugin Name: WordPress Database Reloader
Description: Schedule a repeating database reload.
Author: Loud Dog
Version: 0.1
Author URI: http://www.louddog.com/
*/

new DB_Reloader();
class DB_Reloader {
	function __construct() {
		add_action('wp', array(&$this, 'schedule'));		
		add_action('db_reloader', array(&$this, 'reload_db'));
		
		add_action('wp_print_styles', array(&$this, 'site_style'));
		add_action('wp_footer', array(&$this, 'warning'));

		add_action('admin_init', array(&$this, 'admin_init'));
		add_action('admin_menu', array(&$this, 'admin_menu'));
		add_action('admin_notices', array(&$this, 'admin_notices'));

		register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));
	}
	
	function schedule() {
		if (!wp_next_scheduled('db_reloader')) {
			$now = getdate();
			$next = mktime($now['hours'] + 1, 0);
			wp_schedule_event($next, 'hourly', 'db_reloader');
		}
	}
	
	function reload_db() {
		if (get_option('db_reloader_reloading')) {
			file_put_contents($this->path('cron.log'), "reload: ".date('Y:m:d H:i:s')."\n", FILE_APPEND);
			// reload DB
		}
	}
	
	function site_style() {
		wp_register_style('db_reloader', plugin_dir_url(__FILE__).'css/style.css');
		wp_enqueue_style('db_reloader');
	}

	function warning() {
		if (get_option('db_reloader_reloading')) { ?>
			<p id="db_reloader_warning">This website's data reloads on the hour.</p>
			
			<style type="text/css" media="screen">
				<?php $height = is_admin_bar_showing() ? 43 : 15; ?>
				html { margin-top: <?php echo $height; ?>px !important; }
				* html body { margin-top: <?php echo $height; ?>px !important; }
				<?php if (is_admin_bar_showing()) { ?>
					#db_reloader_warning { top: 28px; }
				<?php } ?>
			</style>
		<?php }
	}
	
	function admin_init() {
		if (isset($_POST['db_reloader_save_state'])) {
			if (!$this->mysqldump()) $this->add_admin_notice("The mysqldump command could not be found");
			else if (!current_user_can('manage_options')) $this->add_admin_notice("You do not have permission to save the database state.");
			else {
				exec($this->mysqldump()." --user=".DB_USER." --password=".DB_PASSWORD." --add-drop-table --result-file=".$this->path('dump.sql')." --verbose ".DB_NAME, $result, $code);
				if ($code == 0) $this->add_admin_notice("The current database state was successfully saved.");
				else $this->add_admin_notice("There was an error saving the database state.");
			}
			
			header("Location: ".$_SERVER['REQUEST_URI']);
			exit;
		}
		
		if (isset($_POST['db_reloader_start_reloading'])) {
			update_option('db_reloader_reloading', true);
			$this->add_admin_notice("The database will now start reloading.");
			header("Location: ".$_SERVER['REQUEST_URI']);
			exit;
		}
		
		if (isset($_POST['db_reloader_stop_reloading'])) {
			update_option('db_reloader_reloading', false);
			$this->add_admin_notice("The database will not reload anymore.");
			header("Location: ".$_SERVER['REQUEST_URI']);
			exit;
		}
	}
	
	function admin_menu() {
		add_options_page(
			"Database Reloader",
			"DB Reloader",
			'manage_options',
			'db_reloader',
			array(&$this, 'settings')
		);
	}
	
	function add_admin_notice($message) {
		$messages = get_option('db_reloader_messages', array());
		$messages[] = $message;
		update_option('db_reloader_messages', $messages);
	}
	
	function admin_notices() {
		$messages = get_option('db_reloader_messages', array());
		if (count($messages)) {
			foreach ($messages as $message) { ?>
				<div class="updated">
					<p><?php echo $message; ?></p>
				</div>
			<?php }
			delete_option('db_reloader_messages');
		}
	}
	
	function settings() { ?>
		<p>Database Reloader allows you to take a snapshot of your database, and then repeatedly reload it one the hour. In order to start reloading your database, you need to save a database state.  Set up your site the way you like it, and then save that state here.</p>
		 
		<?php if (!is_writeable($this->path())) { ?>
			<p>
				In order to save the current database date, the WordPress needs to be able to write it to a file.  Please make the backup file writeable:<br />
				<code>chmod 777 <?php echo $this->path(); ?></code>
			</p>
		<?php } else if (!$this->mysqldump()) { ?>
			<?php if (defined('DB_RELOADER_MYSQLDUMP_PATH')) { ?>
				<p>The path to <code>mysqldump</code> defined in <code>DB_RELOADER_MYSQLDUMP_PATH (<?php echo DB_RELOADER_MYSQLDUMP_PATH; ?>)</code> isn't working.</p>
			<?php } else { ?>
				<p>Cannot find the path to <code>mysqldump</code>.  Place the following code in your functions.php file, and set the correct path:</p>
				<code>define('DB_RELOADER_MYSQLDUMP_PATH', '/absolute/path/to/mysqldump');</code>
			<?php } ?>
		<?php } else { ?>
			<form id="db_reloader_save_state_form" action="<?php echo admin_url('options-general.php?page=db_reloader'); ?>" method="post">
				<input type="submit" name="db_reloader_save_state" value="Save Database State" />
				
				<?php if ($this->saved()) { ?>
					<?php if (get_option('db_reloader_reloading')) { ?>
						<input type="submit" name="db_reloader_stop_reloading" value="Stop Reloading" />
					<?php } else { ?>
						<input type="submit" name="db_reloader_start_reloading" value="Start Reloading" />
					<?php } ?>
				<?php } ?>
			</form>
		<?php } ?>

	<?php }
	
	function saved() {
		return file_exists($this->path('dump.sql')) && trim(file_get_contents($this->path('dump.sql'))) != '';
	}
	
	function path($file = false) {
		$path = dirname(__FILE__).'/store';
		if ($file) $path .= "/$file";
		return $path;
	}

	function mysqldump() {
		static $path = false;
		if ($path) return $path;
		
		foreach (array(
			'/usr/bin/mysqldump',
			'/usr/local/bin/mysqldump',
			'/usr/local/mysql/bin/mysqldump',
			'/usr/mysql/bin/mysqldump',
			'/Applications/MAMP/Library/bin/mysqldump',
			'mysqldump',
		) as $possible) {
			exec($possible, $result, $code);
			if ($code != 127) {
				$path = $possible;
				break;
			}
		}
		
		if (defined('DB_RELOADER_MYSQLDUMP_PATH')) {
			exec(DB_RELOADER_MYSQLDUMP_PATH, $result, $code);
			if ($code != 127) $path = DB_RELOADER_MYSQLDUMP_PATH;
		}
		
		return $path;
	}

	function deactivate() {
		delete_option('db_reloader_reloading');
		wp_clear_scheduled_hook('db_reloader');
		
		foreach(glob($this->path()."/*.*") as $file) {
			unlink($file);
		}
	}
}