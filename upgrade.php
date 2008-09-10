<?php
	header("Content-type: text/html; charset=UTF-8");

	# Constant: DEBUG
	# Should Chyrp use debugging processes?
	define('DEBUG', true);

	# Constant: UPGRADING
	# Is the user running the upgrader? (true)
	define('UPGRADING', true);

	# Constant: XML_RPC
	# Is this being run from XML-RPC?
	define('XML_RPC', true);

	# Constant: MAIN_DIR
	# Absolute path to the Chyrp root
	define('MAIN_DIR', dirname(__FILE__));

	# Constant: INCLUDES_DIR
	# Absolute path to /includes
	define('INCLUDES_DIR', dirname(__FILE__)."/includes");

	/**
	 * Function: config_file
	 * Returns what config file their install is set up for.
	 */
	function config_file() {
		if (file_exists(INCLUDES_DIR."/config.yaml.php"))
			return INCLUDES_DIR."/config.yaml.php";

		if (file_exists(INCLUDES_DIR."/config.yml.php"))
			return INCLUDES_DIR."/config.yml.php";

		if (file_exists(INCLUDES_DIR."/config.php"))
			return INCLUDES_DIR."/config.php";

		exit("Config file not found.");
	}

	/**
	 * Function: database_file
	 * Returns what database config file their install is set up for.
	 */
	function database_file() {
		if (file_exists(INCLUDES_DIR."/database.yaml.php"))
			return INCLUDES_DIR."/database.yaml.php";

		if (file_exists(INCLUDES_DIR."/database.yml.php"))
			return INCLUDES_DIR."/database.yml.php";

		if (file_exists(INCLUDES_DIR."/database.php"))
			return INCLUDES_DIR."/database.php";

		return false;
	}

	/**
	 * Function: using_yaml
	 * Are they using YAML config storage?
	 */
	function using_yaml() {
		return (basename(config_file()) != "config.php" and basename(database_file()) != "database.php") or !database_file();
	}

	# Evaluate the code in their config files, but with the classes renamed, so we can safely retrieve the values.
	if (!using_yaml()) {
		eval(str_replace(array("<?php", "?>", "Config"),
		                 array("", "", "OldConfig"),
		                 file_get_contents(config_file())));

		if (database_file())
			eval(str_replace(array("<?php", "?>", "SQL"),
			                 array("", "", "OldSQL"),
			                 file_get_contents(database_file())));
	}

	# File: Helpers
	# Various functions used throughout Chyrp's code.
	require_once INCLUDES_DIR."/helpers.php";

	# File: Gettext
	# Gettext library.
	require_once INCLUDES_DIR."/lib/gettext/gettext.php";

	# File: Streams
	# Streams library.
	require_once INCLUDES_DIR."/lib/gettext/streams.php";

	# File: YAML
	# Horde YAML parsing library.
	require_once INCLUDES_DIR."/lib/YAML.php";

	$yaml = array();
	$yaml["config"] = array();
	$yaml["database"] = array();

	if (using_yaml()) {
		$yaml["config"] = YAML::load(preg_replace("/<\?php(.+)\?>\n?/s", "", file_get_contents(config_file())));

		if (database_file())
			$yaml["database"] = YAML::load(preg_replace("/<\?php(.+)\?>\n?/s",
			                                                            "",
			                                                            file_get_contents(database_file())));
		else
			$yaml["database"] = fallback($yaml["config"]["sql"], array(), true);
	} else {
		# $config and $sql here are loaded from the eval()'s above.

		foreach ($config as $name => $val)
			$yaml["config"][$name] = $val;

		foreach ($sql as $name => $val)
			$yaml["database"][$name] = $val;
	}

	# File: SQL
	# See Also:
	#     <SQL>
	require INCLUDES_DIR."/class/SQL.php";

	/**
	 * Class: Config
	 * Handles writing to whichever config file they're using.
	 */
	class Config {
		/**
		 * Function: get
		 * Returns a config setting.
		 *
		 * Parameters:
		 *     $setting - The setting to return.
		 */
		static function get($setting) {
			global $yaml;
			return (isset($yaml["config"][$setting])) ? $yaml["config"][$setting] : false ;
		}

		/**
		 * Function: set
		 * Sets a config setting.
		 *
		 * Parameters:
		 *     $setting - The config setting to set.
		 *     $value - The value for the setting.
		 *     $message - The message to display with test().
		 */
		static function set($setting, $value, $message = null) {
			if (self::get($setting) == $value) return;

			global $yaml;

			if (!isset($message))
				$message = _f("Setting %s to %s...", array($setting, normalize(print_r($value, true))));

			$yaml["config"][$setting] = $value;

			$protection = "<?php header(\"Status: 403\"); exit(\"Access denied.\"); ?>\n";

			$dump = $protection.YAML::dump($yaml["config"]);

			echo $message.test(@file_put_contents(INCLUDES_DIR."/config.yaml.php", $dump));
		}

		/**
		 * Function: check
		 * Goes a config exist?
		 *
		 * Parameters:
		 *     $setting - Name of the config to check.
		 */
		static function check($setting) {
			global $yaml;
			return (isset($yaml["config"][$setting]));
		}

		/**
		 * Function: fallback
		 * Sets a config setting to $value if it does not exist.
		 *
		 * Parameters:
		 *     $setting - The config setting to set.
		 *     $value - The value for the setting.
		 *     $message - The message to display with test().
		 */
		static function fallback($setting, $value, $message = null) {
			if (!isset($message))
				$message = _f("Adding %s setting...", array($setting));

			if (!self::check($setting))
				echo self::set($setting, $value, $message);
		}

		/**
		 * Function: remove
		 * Removes a setting if it exists.
		 *
		 * Parameters:
		 *     $setting - The setting to remove.
		 */
		static function remove($setting) {
			if (!self::check($setting)) return;

			global $yaml;

			unset($yaml["config"][$setting]);

			$protection = "<?php header(\"Status: 403\"); exit(\"Access denied.\"); ?>\n";

			$dump = $protection.YAML::dump($yaml["config"]);

			echo _f("Removing %s setting...", array($setting)).
			     test(@file_put_contents(INCLUDES_DIR."/config.yaml.php", $dump));
		}
	}

	load_translator("chyrp", INCLUDES_DIR."/locale/".Config::get("locale").".mo");

	/**
	 * Function: test
	 * Attempts to perform a task, and displays a "success" or "failed" message determined by the outcome.
	 *
	 * Parameters:
	 *     $try - The task to attempt. Should return something that evaluates to true or false.
	 *     $message - Message to display for the test.
	 */
	function test($try, $message = "") {
		$sql = SQL::current();

		if (!empty($sql->error)) {
			$message.= "\n".$sql->error."\n\n";
			$sql->error = "";
		}

		$info = $message;

		if ($try)
			return " <span class=\"yay\">".__("success!")."</span>\n";
		else
			return " <span class=\"boo\">".__("failed!")."</span>\n".$info;
	}

	/**
	 * Function: xml2arr
	 * Recursively converts a SimpleXML object (and children) to an array.
	 *
	 * Parameters:
	 *     $parse - The SimpleXML object to convert into an array.
	 */
	function xml2arr($parse) {
		if (empty($parse))
			return "";

		$parse = (array) $parse;

		foreach ($parse as &$val)
			if (get_class($val) == "SimpleXMLElement")
				$val = self::xml2arr($val);

		return $parse;
	}

	/**
	 * Function: arr2xml
	 * Recursively adds an array (or object I guess) to a SimpleXML object.
	 *
	 * Parameters:
	 *     $object - The SimpleXML object to add to.
	 *     $data - The data to add to the SimpleXML object.
	 */
	function arr2xml(&$object, $data) {
		foreach ($data as $key => $val) {
			if (is_int($key) and (empty($val) or (is_string($val) and trim($val) == ""))) {
				unset($data[$key]);
				continue;
			}

			if (is_array($val)) {
				if (in_array(0, array_keys($val))) { # Numeric-indexed things need to be added as duplicates
					foreach ($val as $dup) {
						$xml = $object->addChild($key);
						arr2xml($xml, $dup);
					}
				} else {
					$xml = $object->addChild($key);
					arr2xml($xml, $val);
				}
			} else
				$object->addChild($key, fix($val, false, false));
		}
	}

	#---------------------------------------------
	# Upgrading Actions
	#---------------------------------------------

	function fix_htaccess() {
		$url = "http://".$_SERVER['HTTP_HOST'].str_replace("/upgrade.php", "", $_SERVER['REQUEST_URI']);
		$index = (parse_url($url, PHP_URL_PATH)) ? "/".trim(parse_url($url, PHP_URL_PATH), "/")."/" : "/" ;

		$path = preg_quote($index, "/");
		$htaccess_has_chyrp = (file_exists(MAIN_DIR."/.htaccess") and preg_match("/<IfModule mod_rewrite\.c>\n([\s]*)RewriteEngine On\n([\s]*)RewriteBase {$path}\n([\s]*)RewriteCond %\{REQUEST_FILENAME\} !-f\n([\s]*)RewriteCond %\{REQUEST_FILENAME\} !-d\n([\s]*)RewriteRule (\^\.\+\\$|\!\\.\(gif\|jpg\|png\|css\)) index\.php \[L\]\n([\s]*)<\/IfModule>/", file_get_contents(MAIN_DIR."/.htaccess")));
		if ($htaccess_has_chyrp)
			return;

		$htaccess = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase {$index}\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^.+$ index.php [L]\n</IfModule>";

		if (!file_exists(MAIN_DIR."/.htaccess"))
			echo __("Generating .htaccess file...").
			     test(@file_put_contents(MAIN_DIR."/.htaccess", $htaccess), __("Try creating the file and/or CHMODding it to 777 temporarily."));
		else
			echo __("Appending to .htaccess file...").
			     test(@file_put_contents(MAIN_DIR."/.htaccess", "\n\n".$htaccess, FILE_APPEND), __("Try creating the file and/or CHMODding it to 777 temporarily."));
	}

	function tweets_to_posts() {
		if (SQL::current()->query("SELECT * FROM __tweets"))
			echo __("Renaming tweets table to posts...").
			     test(SQL::current()->query("RENAME TABLE __tweets TO __posts"));

		if (SQL::current()->query("SELECT add_tweet FROM __groups"))
			echo __("Renaming add_tweet permission to add_post...").
			     test(SQL::current()->query("ALTER TABLE __groups CHANGE add_tweet add_post TINYINT(1) NOT NULL DEFAULT '0'"));

		if (SQL::current()->query("SELECT edit_tweet FROM __groups"))
			echo __("Renaming edit_tweet permission to edit_post...").
			     test(SQL::current()->query("ALTER TABLE __groups CHANGE edit_tweet edit_post TINYINT(1) NOT NULL DEFAULT '0'"));

		if (SQL::current()->query("SELECT delete_tweet FROM __groups"))
			echo __("Renaming delete_tweet permission to delete_post...").
			     test(SQL::current()->query("ALTER TABLE __groups CHANGE delete_tweet delete_post TINYINT(1) NOT NULL DEFAULT '0'"));

		if (Config::check("tweets_per_page")) {
			Config::fallback("posts_per_page", Config::get("tweets_per_page"));
			Config::remove("tweets_per_page");
		}
	}

	function pages_parent_id_column() {
		if (SQL::current()->query("SELECT parent_id FROM __pages"))
			return;

		echo __("Adding parent_id column to pages table...").
		     test(SQL::current()->query("ALTER TABLE __pages ADD parent_id INT(11) NOT NULL DEFAULT '0' AFTER user_id"));
	}

	function pages_list_order_column() {
		if (SQL::current()->query("SELECT list_order FROM __pages"))
			return;

		echo __("Adding list_order column to pages table...").
		     test(SQL::current()->query("ALTER TABLE __pages ADD list_order INT(11) NOT NULL DEFAULT '0' AFTER show_in_list"));
	}

	function remove_beginning_slash_from_post_url() {
		if (substr(Config::get("post_url"), 0, 1) == "/")
			Config::set("post_url", ltrim(Config::get("post_url"), "/"));
	}

	function move_yml_yaml() {
		if (file_exists(INCLUDES_DIR."/config.yml.php"))
			echo __("Moving /includes/config.yml.php to /includes/config.yaml.php...").
			     test(@rename(INCLUDES_DIR."/config.yml.php", INCLUDES_DIR."/config.yaml.php"), __("Try CHMODding the file to 777."));
	}

	function update_protection() {
		if (!file_exists(INCLUDES_DIR."/config.yaml.php") or
		    substr_count(file_get_contents(INCLUDES_DIR."/config.yaml.php"),
		                 "<?php header(\"Status: 403\"); exit(\"Access denied.\"); ?>"))
			return;

		$contents = file_get_contents(INCLUDES_DIR."/config.yaml.php");
		$new_error = preg_replace("/<\?php (.+) \?>/",
		                     "<?php header(\"Status: 403\"); exit(\"Access denied.\"); ?>",
		                     $contents);

		echo __("Updating protection code in config.yaml.php...").
		     test(@file_put_contents(INCLUDES_DIR."/config.yaml.php", $new_error), __("Try CHMODding the file to 777."));
	}

	function theme_default_to_stardust() {
		if (Config::get("theme") != "default") return;
		Config::set("theme", "stardust");
	}

	function default_db_adapter_to_mysql() {
		$sql = SQL::current();
		if (isset($sql->adapter)) return;
		$sql->set("adapter", "mysql");
	}

	function move_upload() {
		if (file_exists(MAIN_DIR."/upload") and !file_exists(MAIN_DIR."/uploads"))
			echo __("Renaming /upload directory to /uploads...").test(@rename(MAIN_DIR."/upload", MAIN_DIR."/uploads"), __("Try CHMODding the directory to 777."));
	}

	function make_posts_safe() {
		if (!$posts = SQL::current()->query("SELECT * FROM __posts"))
			return;

		# Replace all the posts' CDATAized XML with well-formed XML.
		while ($post = $posts->fetchObject()) {
			if (!substr_count($post->xml, "<![CDATA["))
				continue;

			$xml = simplexml_load_string($post->xml, "SimpleXMLElement", LIBXML_NOCDATA);

			$parse = xml2arr($xml);
			array_walk_recursive($parse, "fix");

			$new_xml = new SimpleXMLElement("<post></post>");
			arr2xml($new_xml, $parse);

			echo _f("Sanitizing XML data of post #%d...", array($post->id)).
			     test(SQL::current()->update("posts",
			                                 array("id" => $post->id),
			                                 array("xml" => $new_xml->asXML())));
		}
	}

	function update_groups_to_yaml() {
		if (!SQL::current()->query("SELECT view_site FROM __groups")) return;

		$get_groups = SQL::current()->query("SELECT * FROM __groups");
		echo __("Backing up current groups table...").test($get_groups);
		if (!$get_groups) return;

		$groups = array();
		# Generate an array of groups, name => permissions.
		while ($group = $get_groups->fetchObject()) {
			$groups[$group->name] = array("permissions" => array());
			foreach ($group as $key => $val)
				if ($key != "name" and $key != "id" and $val)
					$groups[$group->name]["permissions"][] = $key;
				elseif ($key == "id")
					$groups[$group->name]["id"] = $val;
		}

		# Convert permissions array to a YAML dump.
		foreach ($groups as $key => &$val)
			$val["permissions"] = YAML::dump($val["permissions"]);

		$drop_groups = SQL::current()->query("DROP TABLE __groups");
		echo __("Dropping old groups table...").test($drop_groups);
		if (!$drop_groups) return;

		$groups_table = SQL::current()->query("CREATE TABLE __groups (
		                                           id INTEGER PRIMARY KEY AUTO_INCREMENT,
		                                           name VARCHAR(100) DEFAULT '',
	                                               permissions LONGTEXT,
		                                           UNIQUE (name)
		                                       ) DEFAULT CHARSET=utf8");
		echo __("Creating new groups table...").test($groups_table);
		if (!$groups_table) return;

		foreach($groups as $name => $values)
			echo _f("Restoring group \"%s\"...", array($name)).
			     test(SQL::current()->insert("groups",
			                                 array("id" => $values["id"],
			                                       "name" => $name,
			                                       "permissions" => $values["permissions"])));
	}

	function add_permissions_table() {
		if (SQL::current()->query("SELECT * FROM __permissions")) return;

		$permissions_table = SQL::current()->query("CREATE TABLE __permissions (
		                                                id VARCHAR(100) DEFAULT '' PRIMARY KEY,
		                                                name VARCHAR(100) DEFAULT ''
		                                            ) DEFAULT CHARSET=utf8");
		echo __("Creating new permissions table...").test($permissions_table);
		if (!$permissions_table) return;

		$permissions = array("change_settings" => "Change Settings",
		                     "toggle_extensions" => "Toggle Extensions",
		                     "view_site" => "View Site",
		                     "view_private" => "View Private Posts",
		                     "view_draft" => "View Drafts",
		                     "view_own_draft" => "View Own Drafts",
		                     "add_post" => "Add Posts",
		                     "add_draft" => "Add Drafts",
		                     "edit_post" => "Edit Posts",
		                     "edit_draft" => "Edit Drafts",
		                     "edit_own_post" => "Edit Own Posts",
		                     "edit_own_draft" => "Edit Own Drafts",
		                     "delete_post" => "Delete Posts",
		                     "delete_draft" => "Delete Drafts",
		                     "delete_own_post" => "Delete Own Posts",
		                     "delete_own_draft" => "Delete Own Drafts",
		                     "add_page" => "Add Pages",
		                     "edit_page" => "Edit Pages",
		                     "delete_page" => "Delete Pages",
		                     "add_user" => "Add Users",
		                     "edit_user" => "Edit Users",
		                     "delete_user" => "Delete Users",
		                     "add_group" => "Add Groups",
		                     "edit_group" => "Edit Groups",
		                     "delete_group" => "Delete Groups");

		foreach ($permissions as $id => $name)
			echo _f("Inserting permission \"%s\"...", array($name)).
			     test(SQL::current()->insert("permissions",
			                                 array("id" => $id,
			                                       "name" => $name)));
	}

	function add_sessions_table() {
		if (SQL::current()->query("SELECT * FROM __sessions")) return;

		echo __("Creating sessions table...").
		     test(SQL::current()->query("CREATE TABLE __sessions (
		                                     id VARCHAR(40) DEFAULT '',
		                                     data LONGTEXT,
		                                     user_id INTEGER DEFAULT '0',
		                                     created_at DATETIME DEFAULT '0000-00-00 00:00:00',
		                                     updated_at DATETIME DEFAULT '0000-00-00 00:00:00',
		                                     PRIMARY KEY (id)
		                                 ) DEFAULT CHARSET=utf8") or die(mysql_error()));
	}

	function update_permissions_table() {
		# If there are any non-numeric IDs in the permissions database, assume this is already done.
		$check = SQL::current()->query("SELECT * FROM __permissions");
		while ($row = $check->fetchObject())
			if (!is_numeric($row->id))
				return;

		$permissions_backup = array();
		$get_permissions = SQL::current()->query("SELECT * FROM __permissions");
		echo __("Backing up current permissions table...").test($get_permissions);
		if (!$get_permissions) return;

		while ($permission = $get_permissions->fetchObject())
			$permissions_backup[] = $permission->name;

		$drop_permissions = SQL::current()->query("DROP TABLE __permissions");
		echo __("Dropping old permissions table...").test($drop_permissions);
		if (!$drop_permissions) return;

		echo __("Creating new permissions table...").
		     test(SQL::current()->query("CREATE TABLE IF NOT EXISTS __permissions (
			                                 id VARCHAR(100) DEFAULT '' PRIMARY KEY,
			                                 name VARCHAR(100) DEFAULT ''
			                             ) DEFAULT CHARSET=utf8"));

		$permissions = array("change_settings" => "Change Settings",
		                     "toggle_extensions" => "Toggle Extensions",
		                     "view_site" => "View Site",
		                     "view_private" => "View Private Posts",
		                     "view_draft" => "View Drafts",
		                     "view_own_draft" => "View Own Drafts",
		                     "add_post" => "Add Posts",
		                     "add_draft" => "Add Drafts",
		                     "edit_post" => "Edit Posts",
		                     "edit_draft" => "Edit Drafts",
		                     "edit_own_post" => "Edit Own Posts",
		                     "edit_own_draft" => "Edit Own Drafts",
		                     "delete_post" => "Delete Posts",
		                     "delete_draft" => "Delete Drafts",
		                     "delete_own_post" => "Delete Own Posts",
		                     "delete_own_draft" => "Delete Own Drafts",
		                     "add_page" => "Add Pages",
		                     "edit_page" => "Edit Pages",
		                     "delete_page" => "Delete Pages",
		                     "add_user" => "Add Users",
		                     "edit_user" => "Edit Users",
		                     "delete_user" => "Delete Users",
		                     "add_group" => "Add Groups",
		                     "edit_group" => "Edit Groups",
		                     "delete_group" => "Delete Groups");

		foreach ($permissions_backup as $id) {
			$name = isset($permissions[$id]) ? $permissions[$id] : camelize($id, true);
			echo _f("Restoring permission \"%s\"...", array($name)).
			     test(SQL::current()->insert("permissions",
			                                 array("id" => $id,
			                                       "name" => $name)));
		}

	}

	function update_custom_routes() {
		$custom_routes = Config::get("routes");
		if (empty($custom_routes)) return;

		$new_routes = array();
		foreach ($custom_routes as $key => $route) {
			if (!is_int($key))
				return;

			$split = array_filter(explode("/", $route));

			if (!isset($split[0]))
				return;

			echo _f("Updating custom route %s to new format...", array($route)).
			     test(isset($split[0]) and $new_routes[$route] = $split[0]);
		}

		Config::set("routes", $new_routes, "Setting new custom routes configuration...");
	}

	function remove_database_config_file() {
		if (file_exists(INCLUDES_DIR."/database.yaml.php"))
			echo __("Removing database.yaml.php file...").
			     test(@unlink(INCLUDES_DIR."/database.yaml.php"), __("Try deleting it manually."));
	}

	function rename_database_setting_to_sql() {
		if (Config::check("sql")) return;
		Config::set("sql", Config::get("database"));
		Config::remove("database");
	}

	function update_post_status_column() {
		$sql = SQL::current();
		$column = $sql->query("SHOW COLUMNS FROM __posts WHERE Field = 'status'");
		if (!$column)
		     return;

		$result = $column->fetchObject();
		if ($result->Type == "varchar(32)")
			return;

		echo __("Updating `status` column on `posts` table...")
		     .test($sql->query("ALTER TABLE __posts CHANGE status status VARCHAR(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'public'"));
	}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-type" content="text/html; charset=utf-8" />
		<title><?php echo __("Chyrp Upgrader"); ?></title>
		<style type="text/css" media="screen">
			html, body, ul, ol, li,
			h1, h2, h3, h4, h5, h6,
			form, fieldset, a, p {
				margin: 0;
				padding: 0;
				border: 0;
			}
			html {
				font-size: 62.5%;
			}
			body {
				font: 1.25em/1.5em normal "Verdana", Helvetica, Arial, sans-serif;
				color: #626262;
				background: #e8e8e8;
				padding: 0 0 5em;
			}
			.window {
				width: 30em;
				background: #fff;
				padding: 2em;
				margin: 5em auto 0;
				-webkit-border-radius: 2em;
				-moz-border-radius: 2em;
			}
			h1 {
				color: #ccc;
				font-size: 3em;
				margin: 1em 0 .5em;
				text-align: center;
			}
			h1.first {
				margin-top: .25em;
			}
			h1.what_now {
				margin-top: .5em;
			}
			code {
				color: #06B;
				font-family: Monaco, monospace;
			}
			a:link, a:visited {
				color: #6B0;
			}
			pre.pane {
				height: 15em;
				overflow-y: auto;
				margin: -2.5em -2.5em 4em;
				padding: 2.5em;
				background: #333;
				color: #fff;
				-webkit-border-top-left-radius: 2.5em;
				-webkit-border-top-right-radius: 2.5em;
			}
			span.yay { color: #0f0; }
			span.boo { color: #f00; }
			a.big,
			button {
				background: #eee;
				display: block;
				text-align: center;
				margin-top: 2em;
				padding: .75em 1em;
				color: #777;
				text-shadow: #fff .1em .1em 0;
				font: 1em normal "Lucida Grande", "Verdana", Helvetica, Arial, sans-serif;
				text-decoration: none;
				border: 0;
				cursor: pointer;
				-webkit-border-radius: .5em;
				-moz-border-radius: .5em;
			}
			button {
				width: 100%;
			}
			a.big:hover,
			button:hover {
				background: #f5f5f5;
			}
			a.big:active,
			button:active {
				background: #e0e0e0;
			}
			ul, ol {
				margin: 0 0 1em 2em;
			}
			li {
				margin-bottom: .5em;
			}
			ul {
				margin-bottom: 1.5em;
			}
			p {
				margin-bottom: 1em;
			}
		</style>
	</head>
	<body>
		<div class="window">
<?php if (!empty($_POST) and $_POST['upgrade'] == "yes"): ?>
			<pre class="pane"><?php
		# Begin with file/config upgrade tasks.

		fix_htaccess();

		remove_beginning_slash_from_post_url();

		move_yml_yaml();

		update_protection();

		theme_default_to_stardust();

		Config::fallback("routes", array());
		Config::fallback("secure_hashkey", md5(random(32, true)));
		Config::fallback("enable_xmlrpc", true);
		Config::fallback("enable_ajax", true);
		Config::fallback("uploads_path", "/uploads/");
		Config::fallback("chyrp_url", Config::get("url"));
		Config::fallback("feed_items", Config::get("rss_posts"));
		Config::fallback("sql", $yaml["database"]);
		Config::fallback("timezone", "America/Indiana/Indianapolis");

		Config::remove("rss_posts");
		Config::remove("time_offset");

		move_upload();

		remove_database_config_file();

		rename_database_setting_to_sql();

		update_custom_routes();

		default_db_adapter_to_mysql();

		# Perform database upgrade tasks after all the files/config upgrade tasks are done.

		# Prepare the SQL interface.
		$sql = SQL::current();

		# Set the SQL info.
		foreach ($yaml["config"]["sql"] as $name => $value)
			$sql->$name = $value;

		# Initialize connection to SQL server.
		$sql->connect();

		tweets_to_posts();

		pages_parent_id_column();

		pages_list_order_column();

		make_posts_safe();

		update_groups_to_yaml();

		add_permissions_table();

		add_sessions_table();

		update_permissions_table();

		update_post_status_column();

		# Perform Module/Feather upgrades.

		foreach ((array) Config::get("enabled_modules") as $module)
			if (file_exists(MAIN_DIR."/modules/".$module."/upgrades.php")) {
				echo _f("Calling \"%s\" module's upgrader...", array($module))."\n";
				require MAIN_DIR."/modules/".$module."/upgrades.php";
			}

		foreach ((array) Config::get("enabled_feathers") as $feather)
			if (file_exists(MAIN_DIR."/feathers/".$feather."/upgrades.php")) {
				echo _f("Calling \"%s\" feather's upgrader...", array($feather))."\n";
				require MAIN_DIR."/feathers/".$feather."/upgrades.php";
			}
?>

<?php echo __("Done!"); ?>

</pre>
			<h1 class="what_now"><?php echo __("What now?"); ?></h1>
			<ol>
				<li><?php echo __("Look through the results up there for any failed tasks. If you see any and you can't figure out why, you can ask for help at the <a href=\"http://chyrp.net/community/\">Chyrp Community</a>."); ?></li>
				<li><?php echo __("If any of your Modules or Feathers have new versions available for this release, check if an <code>upgrades.php</code> file exists in their main directory. If that file exists, run this upgrader again after enabling the Module or Feather and it will run the upgrade tasks."); ?></li>
				<li><?php echo __("When you are done, you can delete this file. It doesn't pose any real threat on its own, but you should delete it anyway, just to be sure."); ?></li>
			</ol>
			<h1 class="tips"><?php echo __("Tips"); ?></h1>
			<ul>
				<li><?php echo __("If the admin area looks weird, try clearing your cache."); ?></li>
				<li><?php echo __("As of v2.0, Chyrp uses time zones to determine timestamps. Please set your installation to the correct timezone at <a href=\"admin/index.php?action=general_settings\">General Settings</a>."); ?></li>
				<li><?php echo __("Check the group permissions &ndash; they might have changed."); ?></li>
			</ul>
			<a class="big" href="<?php echo (Config::check("url") ? Config::get("url") : Config::get("chyrp_url")); ?>"><?php echo __("All done!"); ?></a>
<?php else: ?>
			<h1 class="first"><?php echo __("Halt!"); ?></h1>
			<p><?php echo __("That button may look ready for a-clickin&rsquo;, but please take these preemptive measures before indulging:"); ?></p>
			<ol>
				<li><?php echo __("<strong>Make a backup of your installation.</strong> You never know."); ?></li>
				<li><?php echo __("Disable any third-party Modules and Feathers."); ?></li>
				<li><?php echo __("Ensure that the Chyrp installation directory is writable by the server."); ?></li>
			</ol>
			<p><?php echo __("If any of the upgrade processes fail, you can safely keep refreshing &ndash; it will only attempt to do tasks that are not already successfully completed. If you cannot figure something out, please make a topic (with details!) at the <a href=\"http://chyrp.net/community/\">Chyrp Community</a>."); ?></p>
			<form action="upgrade.php" method="post">
				<button type="submit" name="upgrade" value="yes"><?php echo __("Upgrade me!"); ?></button>
			</form>
<?php endif; ?>
		</div>
	</body>
</html>