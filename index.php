<?php
/*
Plugin Name: WordPress Database Reloader
Description: Schedule a repeating database reload.
Author: Loud Dog
Version: 0.1
Author URI: http://www.louddog.com/
*/

if (!function_exists('noop')) {
	function noop() {}
}

new DB_Reloader();
class DB_Reloader {
	var $options, $backupPath;
	
	function __construct() {		
		add_action('admin_init', array(&$this, 'admin_init'));
		add_action('admin_menu', array(&$this, 'admin_menu'));
		add_action('admin_enqueue_scripts', array(&$this, 'css_js'));
		register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));
		$this->options = get_option('db_reloader');
		$this->backupPath = dirname(__FILE__).'/backup';
	}
	
	function saved() {
		return file_exists("$this->backupPath/dump.sql") && trim(file_get_contents("$this->backupPath/dump.sql")) != '';
	}
	
	function admin_init() {
		register_setting('db_reloader', 'db_reloader', array(&$this, 'validate'));

		add_settings_section('db_reloader', "Reload Settings", 'noop', 'db_reloader');
		add_settings_field('db_reloader_reloading', "Reloading", array(&$this, 'show_field_reloading'), 'db_reloader', 'db_reloader');
		add_settings_field('db_reloader_interval', "Interval", array(&$this, 'show_field_interval'), 'db_reloader', 'db_reloader');

		if (isset($_POST['db_reloader_save_state'])) {
			if (!$this->mysqldump()) $return = 'cmd_not_found';
			else if (!current_user_can('manage_options')) $state = 'denied';
			else {
				exec($this->mysqldump()." --user=".DB_USER." --password=".DB_PASSWORD." --add-drop-table --result-file=$this->backupPath/dump.sql --verbose ".DB_NAME, $result, $code);
				if ($code != 0) $return = "error[$code]";
				else $return = 'saved';
			}
			
			header("Location: ".admin_url("options-general.php?page=db_reloader&db_reloader_state_saved=$return"));
			exit;
		}
	}
	
	function validate($input) {
		$this->options['reloading'] = $this->saved() && $input['reloading'] ? true : false;
		$this->options['interval'] = intval($input['interval']);
		return $this->options;
	}
	
	function show_field_reloading() { $saved = $this->saved(); ?>		
		<span id="db_reloader_switch">
			<input
				type="radio"
				name="db_reloader[reloading]"
				id="db_reloader_reloading_on"
				value="1"
				<?php if ($this->options['reloading']) echo "checked"; ?>
				<?php if (!$saved) echo 'disabled'; ?>
			/>
			<label for="db_reloader_reloading_on">On</label>

			<input
				type="radio"
				name="db_reloader[reloading]"
				id="db_reloader_reloading_off"
				value="0"
				<?php if (!$this->options['reloading']) echo "checked"; ?>
				<?php if (!$saved) echo 'disabled'; ?>
			/>
			<label for="db_reloader_reloading_off">Off</label>
		</span>
		
		<?php if (!$saved) { ?>
			<br />You must first save your database state before you can start reloading it.
		<?php } ?>
	<?php }
	
	function show_field_interval() { ?>
		<input
			type="text"
			name="db_reloader[interval]"
			id="db_reloader_interval"
			size="5"
			value="<?php if ($this->options['interval']) echo $this->options['interval']; ?>"
		/>
		minutes
	<?php }
	
	function admin_menu() {
		add_options_page("Database Reloader", "DB Reloader", 'manage_options', 'db_reloader', array(&$this, 'settings'));
	}
	
	function css_js() {
		$root = plugin_dir_url(__FILE__);

		wp_register_style('db_reloader_jquery_ui', $root.'css/jquery-ui-1.8.16.custom.css', false, '1.8.16');
		wp_register_script( 'db_reloader_jquery_ui', $root.'js/jquery-ui-1.8.16.custom.min.js', array('jquery'), '1.8.16', true);
		
		wp_register_style('db_reloader', $root.'css/admin.css', array('db_reloader_jquery_ui'), '1.0');
		wp_register_script('db_reloader', $root.'js/admin.js', array('db_reloader_jquery_ui'), '1.0', true);

		wp_enqueue_style('db_reloader');
		wp_enqueue_script('db_reloader');
	}
	
	function settings() { ?>
		
		<div id="db_reloader_settings">
			
			<p>Database Reloader allows you to take a snapshot of your database, and then repeatedly reload it at a set interval.</p>
			
			<form action="options.php" method="post">
				<?php
					settings_fields('db_reloader');
					do_settings_sections('db_reloader');
				?>
				<input type="submit" value="Save Settings" />
			</form>

			<h3>Save Database State</h3>
			<p>In order to start reloading your database, you need to save a database state.  Set up your site the way you like it, and then save that state here.</p>
		 
			<?php if (!is_writeable($this->backupPath)) { ?>
				<p>
					In order to save the current database date, the WordPress needs to be able to write it to a file.  Please make the backup file writeable:<br />
					<code>chmod 777 <?php echo $this->backupPath; ?></code>
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
				</form>
			<?php } ?>

			<?php
				if (isset($_GET['db_reloader_state_saved'])) {
					echo "<p>";
					switch ($_GET['db_reloader_state_saved']) {
						case 'saved': echo "Your current database state was successfully saved."; break;
						case 'denied': echo "You do not have the correct permissions for this action."; break;
						default: echo "There was an error saving your database state.";
					}
					echo "</p>";
				}
			?>
			
		</div> <!-- #db_reloader_settings -->

	<?php }
	
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
		delete_option('wpdb-reloader-reloading');
		delete_option('wpdb-reloader-interval');
	}
}