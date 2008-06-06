<?php
	/**
	 * Class: SQL
	 * Contains the database settings and functions for interacting with the SQL database.
	 */
	class SQL {
		# Array: $debug
		# Holds debug information for SQL queries.
		public $debug = array();

		# Integer: $queries
		# Number of queries it takes to load the page.
		public $queries = 0;

		public $db;

		/**
		 * Function: __construct
		 * The class constructor is private so there is only one connection.
		 */
		private function __construct() {
			$this->connected = false;
		}

		/**
		 * Function: load
		 * Loads a given database YAML file.
		 *
		 * Parameters:
		 *     $file - The YAML file to load into <SQL>.
		 */
		public function load($file) {
			$this->yaml = Spyc::YAMLLoad($file);
			foreach ($this->yaml as $setting => $value)
				if (!is_int($setting)) # Don't load the "---"
					$this->$setting = $value;
		}

		/**
		 * Function: set
		 * Sets a variable's value.
		 *
		 * Parameters:
		 *     $setting - The setting name.
		 *     $value - The new value. Can be boolean, numeric, an array, a string, etc.
		 */
		public function set($setting, $value) {
			if (isset($this->$setting) and $this->$setting == $value) return false; # No point in changing it

			# Add the PHP protection!
			$contents = "<?php header(\"Status: 403\"); exit(\"Access denied.\"); ?>\n";

			# Add the setting
			$this->yaml[$setting] = $value;

			if (isset($this->yaml['<?php header("Status']))
				unset($this->yaml['<?php header("Status']);

			# Generate the new YAML settings
			$contents.= Spyc::YAMLDump($this->yaml, false, 0);

			file_put_contents(INCLUDES_DIR."/database.yaml.php", $contents);
		}

		/**
		 * Function: connect
		 * Connects to the SQL database.
		 */
		public function connect($checking = false) {
			$this->load(INCLUDES_DIR."/database.yaml.php");
			if ($this->connected)
				return true;
			try {
				if ($this->adapter == "sqlite") {
					$this->db = new PDO("sqlite:".$this->database, null, null, array(PDO::ATTR_PERSISTENT => true));

					$this->db->sqliteCreateFunction("YEAR", "year_from_datetime", 1);
					$this->db->sqliteCreateFunction("MONTH", "month_from_datetime", 1);
					$this->db->sqliteCreateFunction("DAY", "day_from_datetime", 1);
				} else
					$this->db = new PDO($this->adapter.":host=".$this->host.";".((isset($this->port)) ? "port=".$this->port.";" : "")."dbname=".$this->database,
					                    $this->username,
					                    $this->password, array(PDO::ATTR_PERSISTENT => true));
				$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				if ($this->adapter == "mysql")
					$this->db->query("set names 'utf8';");
				$this->connected = true;
				return true;
			} catch (PDOException $error) {
				$message = preg_replace("/[A-Z]+\[[0-9]+\]: .+ [0-9]+ (.*?)/", "\\1", $error->getMessage());
				return ($checking) ? false : error(__("Database Error"), $message) ;
			}
		}

		/**
		 * Function: query
		 * Executes a query and increases <SQL->$queries>.
		 * If the query results in an error, it will die and show the error.
		 */
		public function query($query, $params = array(), $throw_exceptions = false) {

			// FIXME: This doesn't account for multiple uses of `:something`
			// # Ensure that every param in $params exists in the query.
			// # If it doesn't, remove it from $params.
			// preg_match_all("/:([a-zA-Z0-9_]+)/", $query, $params_in_query);
			// $query_params = $params_in_query[0];
			// if (count($query_params) != count(array_keys($params)))
			// 	foreach (array_diff($params, $query_params) as $param => $val)
			// 		unset($params[$param]);

			try {
				$query = str_replace("`", "", str_replace("__", $this->prefix, $query));

				if ($this->adapter == "sqlite")
					$query = preg_replace("/ DEFAULT CHARSET=utf8/i", "", preg_replace("/AUTO_INCREMENT/i", "AUTOINCREMENT", $query));

				$q = $this->db->prepare($query);
				$result = $q->execute($params);
				$q->setFetchMode(PDO::FETCH_ASSOC);
				if (defined('DEBUG') and DEBUG) {
					#echo '<div class="sql_query" style="position: relative; z-index: 1000"><span style="background: rgba(0,0,0,.5); padding: 0 1px; border: 1px solid rgba(0,0,0,.25); color: white; font: 9px/14px normal \'Monaco\', monospace;">'.$query.'</span></div>';
					$trace = debug_backtrace();
					$target = $trace[$index = 0];

					while (strpos($target["file"], "database.php")) # Getting a traceback from this file is pretty
						$target = $trace[$index++];                 # useless (mostly when using $sql->select() and such)

					$debug = $this->debug[count($this->debug)] = array("number" => $this->queries, "file" => str_replace(MAIN_DIR."/", "", $target["file"]), "line" => $target["line"], "query" => normalize(str_replace(array_keys($params), array_values($params), $query)));
					#error_log("\n\t".$debug["number"].". ".$debug["query"]."\n\n\tCalled from ".$debug["file"]." on line ".$target["line"].".");
				}
				if (!$result) throw PDOException();
			} catch (PDOException $error) {
				$message = preg_replace("/SQLSTATE\[.*?\]: .+ [0-9]+ (.*?)/", "\\1", $error->getMessage());

				if (XML_RPC or $throw_exceptions)
					throw new Exception($message);

				if (DEBUG)
					$message.= "\n\n".$query."\n\n<pre>".$error->getTraceAsString()."</pre>";

				$this->db = null;

				error(__("Database Error"), $message);
			}

			++$this->queries;
			return $q;
		}

		/**
		 * Function: count
		 * Performs a counting query and returns the number of matching rows.
		 */
		public function count($tables, $conds, $params = array(), $left_join = null) {
			return $this->query(QueryBuilder::build_count($tables, $conds, $left_join), $params)->fetchColumn();
		}

		/**
		 * Function: select
		 * Performs a SELECT with given criteria and returns the query result object.
		 */
		public function select($tables, $fields, $conds, $order = null, $params = array(), $limit = null, $offset = null, $group = null, $left_join = null) {
			return $this->query(QueryBuilder::build_select($tables, $fields, $conds, $order, $limit, $offset, $group, $left_join), $params);
		}

		/**
		 * Function: insert
		 * Performs an INSERT with given data.
		 */
		public function insert($table, $data, $params = array()) {
			return $this->query(QueryBuilder::build_insert($table, $data), $params);
		}

		/**
		 * Function: update
		 * Performs an UDATE with given criteria and data.
		 */
		public function update($table, $conds, $data, $params = array()) {
			return $this->query(QueryBuilder::build_update($table, $conds, $data), $params);
		}

		/**
		 * Function: delete
		 * Performs a DELETE with given criteria.
		 */
		public function delete($table, $conds, $params = array()) {
			return $this->query(QueryBuilder::build_delete($table, $conds), $params);
		}

		/**
		 * Function: current
		 * Returns a singleton reference to the current connection.
		 */
		public static function & current() {
			static $instance = null;
			return $instance = (empty($instance)) ? new self() : $instance ;
		}
	}

	$sql = SQL::current();
