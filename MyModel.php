<?php

class MyModel {
	var $definition = [
		'my_class'      => '',
		'schema'        => '',
		'table'         => '',
		'columns'       => [],
		'keys'          => [],
		'autoloaders'   => [],
		'autoincrement' => null # TODO
	];
	var $values     = [];
	var $old_values = [];
	var $properties = [];

	protected $loaded_classes = [];
	protected $_loaded        = false;
	protected $_changed       = false;
	protected $c              = null;

	function __construct(&$config, $values = null) {
		$this->c = &$config;

		$class = get_called_class();
		$this->definition['my_class'] = $class;
		$table_name = str_replace('\\', '_', $class);
		if ($res = mysqli_query($this->c['db'], 'SELECT DATABASE()')) {
			if ($val = mysqli_fetch_row($res)) {
				$schema = $val[0];
			}
		}

		$this->set_class($schema . '.' . $table_name);
		$this->init_struct();

		if ($values) {
			$this->parse_params($values);
			$this->load();
		}
	}

	function is_loaded() {
		return $this->_loaded;
	}

	function load($force = false) {
		if ( (!$force && $this->_loaded) || !sizeof($this->definition['keys']) ) {
			if (!sizeof($this->definition['keys'])) {
				error_log("WARN: keys not defined for class {$this->get_class()}");
			} else {
				error_log("WARN: class {$this->get_class()} already loaded");
			}
			return;
		}
		$keys_values = join('_', $this->keys_values(false, false));

		if (!array_key_exists('loaded_classes', $this->c['classes'])) {
			$this->c['classes']['loaded_classes'] = [];
		}
		if (array_key_exists($this->get_class(), $this->c['classes']['loaded_classes']) && array_key_exists($keys_values, $this->c['classes']['loaded_classes'][$this->get_class()])) {
			$this->values = $this->c['classes']['loaded_classes'][$this->get_class()][$keys_values];
			$this->_loaded = true;
			return true;
		}

		$this->_loaded = false;

		$query = "SELECT * FROM {$this->get_class()} WHERE ";
		$vals = array();
		foreach ($this->definition['keys'] as $key => $value) {
			$vals[] = $key . ' = ' . $this->prepare_value($key, false, false);
		}
		$query .= implode(' AND ', $vals);
		$this->load_by_query($query, $key);

		$this->c['classes']['loaded_classes'][$this->get_class()][$keys_values] = $this->values;

		return $this->_loaded;
	}

	function save() {
		$params = $this->all_values();
		if (!$this->_changed) {
			return true;
		}
		if (!$this->_loaded) {
			$query = "INSERT INTO {$this->get_class()} (" . implode(', ', $this->columns()). ") VALUES (";
			$vals = array();
			foreach ($this->definition['columns'] as $key => $value) {
				$vals[] = $this->prepare_value($key, false, true);
			}
			$query .= implode(', ', $vals);
			$query .= ')';
		} else {
			$query = "UPDATE {$this->get_class()} SET ";
			$vals = array();
			foreach ($this->definition['columns'] as $key => $value) {
				$vals[] = $key . ' = ' . $this->prepare_value($key, false, true);
			}
			$query .= implode(', ', $vals);
			$query .= ' WHERE ';

			$vals = array();
			foreach ($this->definition['keys'] as $key => $value) {
				$vals[] = $key . ' = ' . $this->prepare_value($key, true, false);
			}
			$query .= implode(' AND ', $vals);
		}
		$query .= ' RETURNING *';
		if ($res = mysqli_query($this->c['db'],$query)) {
			if ($values = mysqli_fetch_assoc($res)) {
				foreach ($values as $key => $value) {
					$key = trim($key);
					if ($this->definition['columns'][$key]['type'] == 'bool') {
						if ($value == 't') {
							$value = 1;
						} else {
							$value = 0;
						}
					}
					$this->$key($value);
					$this->definition['columns'][$key]['saved'] = true;
					$this->definition['columns'][$key]['loaded'] = true;
				}
			}
			if (join('_', $this->keys_values(true, false)) != join('_', $this->keys_values(false, false))) {
				unset($this->c['classes']['loaded_classes'][$this->get_class()][join('_', $this->keys_values(true, false))]);
			}
			$this->old_values = [];
			$this->_changed = false;
			$this->_loaded = true;
			foreach ($this->definition['autoloaders'] as $key => $class) {
				if ($this->$key) {
					$this->$key->save();
				}
			}
		}
		return $res;
	}

	function delete() {
		if (!$this->_loaded) {
			return false;
		}
		$query = "DELETE FROM {$this->get_class()} WHERE ";
		$vals = array();
		foreach ($this->definition['keys'] as $key => $value) {
			$vals[] = $key . ' = ' . $this->prepare_value($key, true, false);
		}
		$query .= implode(' AND ', $vals);
		if (!mysqli_query($this->c['db'],$query)) {
			return false;
		}
		$this->loaded_classes = [];
		$this->values = array();
		$this->_loaded = false;
		return true;
	}

	function changed_columns() {
		$changed = array();
		foreach ($this->definition['columns'] as $key => $value) {
			if (!$this->definition['columns'][$key]['saved']) {
				$changed[$key] = $this->old_values[$key];
			}
		}
		return $changed;
	}

	function get_class() {
		return $this->definition['table'];
	}

	function parse_all($params) {
		foreach ($params as $key => $value) {
			if (!is_array($value)) {
				$this->$key($value);
			}
		}
		return $this;
	}

	function to_hash($deep = 0) {
		if ($deep > 16) {
			return;
		}
		$hash = array();
		foreach ($this->definition['columns'] as $key => $value) {
			$hash[$key] = $this->values[$key];
		}
		foreach ($this->definition['autoloaders'] as $key => $class) {
			if ($this->$key) {
				$hash[$key] = $this->$key->to_hash($deep + 1);
			}
		}
		foreach ($this->properties as $key => $value) {
			$hash[$key] = $value;
		}
		return $hash;
	}

	function autoload($autoloader) {
		if (is_array($this->c['classes']['autoloaders']) && array_key_exists($autoloader, $this->c['classes']['autoloaders']) && array_key_exists($autoloader, $this->definition['columns'])) {
			$value = $this->c['classes']['autoloaders'][$autoloader];
			$this->definition['autoloaders'][str_replace('.', '_', $value['class']) . '_' . $autoloader] = $value;
			return $this->__get(str_replace('.', '_', $value['class']) . '_' . $autoloader);
		}
		return false;
	}

	function upload($column, $file, $type = 'bytea') {
		if ($file['error'] == UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
			$this->$column(file_get_contents($file['tmp_name']), $type);
		}
	}

	function map_data_type($type) {
		$types = [
			'boolean' => 'bool',
			'integer' => 'int4',
			'double'  => 'numeric',
			'string'  => 'varchar',
			'array'   => 'varchar', # TODO ?
		];
		if (array_key_exists($type, $types)) {
			return $types[$type];
		}
		return 'varchar';
	}

	function parse_params($params) {
		foreach ( $this->definition['columns'] as $column => $val ) {
			$value = $params[$column];
			if (isset($value)) {
				if ($this->definition['columns'][$column]['type'] == 'bool') {
					if ($value == 't') {
						$value = 1;
					} else {
						$value = 0;
					}
				}
				$this->$column = $value;
			} else {
				$this->$column = null;
			}
		}
		foreach ( $this->properties as $key => $val ) {
			if (array_key_exists($key, $params)) {
				$this->properties[$key] = $params[$key];
			}
		}
	}

	function promise_is_loaded() {
		foreach ( $this->definition['columns'] as $column => $val ) {
			$this->definition['columns'][$column]['saved'] = true;
			$this->definition['columns'][$column]['loaded'] = true;
		}
		return $this->_loaded = true;
	}

	function get_list($params) {
		if (!array_key_exists('offset', $params)) {
			$params['offset'] = null;
		}
		if (!array_key_exists('limit', $params)) {
			$params['limit'] = null;
		}
		if (!array_key_exists('current', $params)) {
			$params['current'] = null;
		}
		if (!array_key_exists('filters', $params)) {
			$params['filters'] = null;
		}
		$params['class'] = $this->get_class();
		$class = '\\' . $this->definition['my_class'];
		$listing = new $class($this->c, $params);
		return $listing;
	}

	function add_property($name) {
		if (!array_key_exists($name, $this->properties)) {
			$this->properties[$name] = null;
		}
		return true;
	}

	public function __invoke() {
		if (func_num_args() == 1) {
			return $this->$name();
		} else {
			$name = func_get_arg(0);
			$value = func_get_arg(1);
			return $this->$name($value);
		}
	}

	public function __set($name, $value) {
		$this->$name($value);
	}

	public function __call($name, $arguments) {
		if ( array_key_exists($name, $this->definition['columns']) ) {
			if ($this->values[$name] !== $arguments[0]) {
				$this->definition['columns'][$name]['saved'] = false;
				$this->_changed = true;
			} else {
				return $this->values[$name];
			}
		} elseif (array_key_exists($name, $this->properties)) {
			return $this->properties[$name];
		} else {
			$this->definition['columns'][$name] = [
				'required'   => false,
				'type'       => $arguments[1] ? $arguments[1] : $this->map_data_type($arguments[0]),
				'references' => [],
				'saved'      => false,
				'loaded'     => false
			];
			$this->_changed = true;
		}
		foreach ($this->definition['columns'][$name]['references'] as $key => $value) {
	 		unset($this->loaded_classes[$value]);
		}
		$this->old_values[$name] = $this->values[$name];
		$this->values[$name] = $arguments[0];
		return $this->values[$name];
	}

	public function __get($name) {
		if ( array_key_exists($name, $this->definition['autoloaders']) ) {
			if (!array_key_exists($name, $this->loaded_classes)) {
				$class = $this->definition['autoloaders'][$name];
				$class_name = $class['class'];
				$parts = explode('.', $class_name);
				foreach ($parts as $key => $value) {
					$parts[$key] = ucfirst($value);
				}
				$class_name = '\\' . join('\\', $parts);
				$loaded_class = new $class_name($this->c);
				$do_not_load = false;
				foreach ($class['keys'] as $key => $value) {
					$loaded_class->$key = $this->$value;
					if (!isset($this->$value)) {
						$do_not_load = true;
					}
				}
				if (!$do_not_load) {
					$loaded_class->load();
					$this->loaded_classes[$name] = $loaded_class;
				}
			}
			return $this->loaded_classes[$name];
		}
		if (method_exists(get_called_class(), $name)) {
			return $this->$name();
		}
		if ( array_key_exists($name, $this->definition['columns']) ) {
			return $this->values[$name];
		} elseif (array_key_exists($name, $this->properties)) {
			return $this->properties[$name];
		} else {
			echo "<pre>\n";
			print_r($this->to_hash());
			echo "</pre>\n";
			throw new Exception("Unknown method/variable '$name' in class {$this->get_class()}");
		}
		return;
	}

	public function __isset($name) {
		return array_key_exists($name, $this->values);
	}

	public function __unset($name) {
		$this->old_values[$name] = $this->values[$name];
		unset($this->values[$name]);
	}

	public function __toString() {
		return $this->get_class();
	}

	# ==================================================================================================

	function set_class($class) {
		$class = strtolower($class);
		$class = explode('.', $class);
		if (sizeof($class) == 1) {
			array_unshift($class, 'public');
			throw new Exception("WARN: schema not defined for class {$class}");
		}
		$this->definition['schema'] = $class[0];
		$this->definition['table'] = $class[1];
		return $this;
	}

	protected function load_by_query($load_query) {
		if ($res = mysqli_query($this->c['db'], $load_query)) {
			if ($values = mysqli_fetch_assoc($res)) {
				$this->old_values = [];
				$this->values = [];
				$this->loaded_classes = [];
				foreach ($values as $key => $value) {
					$key = trim($key);
					if ($this->definition['columns'][$key]['type'] == 'bool') {
						if ($value == 't') {
							$value = 1;
						} else {
							$value = 0;
						}
					}
					$this->$key($value);
					$this->definition['columns'][$key]['saved'] = true;
					$this->definition['columns'][$key]['loaded'] = true;
				}
				$this->_changed = false;
				$this->_loaded = true;
			}
		}
		return true;
	}


	protected function assign_keys() {
		$args = func_get_args();
		foreach ( $this->definition['keys'] as $key => $value) {
			$this->values[$key] = array_shift($args);
			$this->definition['columns'][$key]['saved'] = false;
		}
		$this->_changed = true;
	}

	protected function columns() {
		$columns = array();
		foreach ( $this->definition['columns'] as $column => $val ) {
			array_push($columns, $column);
		}
		return $columns;
	}

	protected function prepare_value($column, $old_value = false, $defaults = true) {
		$value = null;
		if ($this->c['classes']['autoupdate'][$column] && ($this->definition['columns'][$key]['saved'] || !$this->is_loaded())) {
			if (is_callable($this->c['classes']['autoupdate'][$column])) {
				return $this->c['classes']['autoupdate'][$column]();
			}
			return $this->c['classes']['autoupdate'][$column];
		}
		if (null !== ($old_value && !$this->definition['columns'][$column]['saved'] ? $this->old_values[$column] : $this->values[$column])) {
			$value = $old_value && !$this->definition['columns'][$column]['saved'] ? $this->old_values[$column] : $this->values[$column];
			switch ($this->definition['columns'][$column]['type']) {
				case 'int2':
				case 'int4':
				case 'int8':
					$value = "'".(int)$value."'";
					break;
				case 'numeric':
					$value = "'".(float)$value."'";
					break;
				case 'bool':
					if ($value == 't') {
						$value = "1";
					} elseif ($value == 'f') {
						$value = "0";
					} else {
						$value = (bool)$value ? '1' : '0';
					}
					break;
				case 'bytea':
					$value = mysqli_real_escape_string($this->c['db'], $value);
					break;
				# timestamptz, text, varchar
				default:
					$value = "'".mysqli_real_escape_string($this->c['db'],(string)$value)."'";
			}
		} elseif (isset($this->definition['columns'][$column]['default']) && $defaults) {
			$value = $this->definition['columns'][$column]['default'];
		} else {
			$value = 'NULL';
		}
		return $value;
	}

	protected function keys_values($old = false, $defaults = false) {
		$values = array();
		foreach ($this->definition['keys'] as $column => $value) {
			array_push($values, $this->prepare_value($column, $old, $default));
		}
		return $values;
	}

	protected function all_values() {
		$values = array();
		foreach ($this->columns() as $column) {
			if (!isset($this->values[$column]) && $this->definition['columns'][$column]['required']) {
				# die("Missing required value for column $column in table ".$this->definition['table']);
			} else {
				array_push($values, $this->prepare_value($column));
			}
		}
		return $values;
	}

	protected function init_struct() {
		if (!$this->c) {
			throw new Exception("Config for class {$this->get_class()} not defined!");
		}
		if (!$this->c['db']) {
			throw new Exception("Database connection for class {$this->get_class()} not defined!");
		}
		if (!array_key_exists('classes', $this->c)) {
			$this->c['classes'] = [];
		}
		if (!array_key_exists('definitions', $this->c['classes'])) {
			$this->c['classes']['definitions'] = [];
		}
		if (array_key_exists($this->get_class(), $this->c['classes']['definitions'])) {
			$this->definition = $this->c['classes']['definitions'][$this->get_class()];
			$this->values = [];
			$this->old_values = [];
			$this->loaded_classes = [];
			foreach ($this->definition['columns'] as $name => $val) {
				$this->values[$name] = null;
				$this->old_values[$name] = null;
			}
			return true;
		}

		#TODO: 2nd query is enough for all the info about the structure
		$table = mysqli_real_escape_string($this->c['db'], $this->definition['table']);
		# get basic table definition
		$query = "DESCRIBE {$table}";
		if ( ($res = mysqli_query($this->c['db'], $query)) && mysqli_num_rows($res) ) {
			$this->definition['columns'] = [];
			$this->definition['keys'] = [];
			$this->definition['autoloaders'] = [];
			$this->values = [];
			$this->old_values = [];
			$this->loaded_classes = [];

			$index = 1;
			while ($row = mysqli_fetch_assoc($res)) {
				$name = trim($row['Field']);
				$row['Length'] = '';
				if (preg_match('/(.*)\((\d+)\)/', $row['Type'], $matches)) {
					$row['Type'] = $matches[1];
					$row['Length'] = $matches[2];
				}
				$this->definition['columns'][$name] = [
					'required'   => $row['Null'] == 'NO',
					'type'       => $row['Type'],
					'length'     => $row['Length'],
					'default'    => $row['Default'],
					'position'   => $index++,
					'unique'     => $row['Key'] == 'UNI',
					'primary'    => $row['Key'] == 'PRI',
					'references' => [],
					'saved'      => false,
					'loaded'     => false
				];
				$this->values[$name] = null;
				$this->old_values[$name] = null;
				$this->_changed = true;
				if ($row['Key'] == 'PRI') {
					$this->definition['keys'][$name] = null;
				}
			}
			# TODO:
			# get autoloaders definition
			$query = "SHOW CREATE TABLE `{$table}`";

			if ( $res = mysqli_query($this->c['db'], $query) ) {
				while ($row = mysqli_fetch_row($res)) {
					if ( preg_match_all('/FOREIGN KEY \(([^\)]*)\) REFERENCES ([^\(]*)\(([^\)]*)\)/', $row[1], $foreigns) ) {
						foreach ($foreigns[0] as $idx => $foreign) {
							$foreigns[1][$idx] = str_replace(['`', ' '], '', $foreigns[1][$idx]);
							$foreigns[2][$idx] = str_replace(['`', ' '], '', $foreigns[2][$idx]);
							$foreigns[3][$idx] = str_replace(['`', ' '], '', $foreigns[3][$idx]);
							$autoloader = $foreigns[2][$idx] . '_' . $foreigns[1][$idx];

							if ($this->c['classes']['autoloaders'][$foreigns[1][$idx]]['exclude'] && array_search($this->get_class(), $this->c['classes']['autoloaders'][$foreigns[1][$idx]]['exclude']) !== false) {
								continue;
							} else {
								$foreigns[2][$idx] = preg_replace('/_/', '.', $foreigns[2][$idx], 1);
								$this->definition['autoloaders'][$autoloader] = [
									'class' => $foreigns[2][$idx],
									'keys'  => []
								];
								$src_keys = explode(',', $foreigns[1][$idx]);
								$dst_keys = explode(',', $foreigns[3][$idx]);
								foreach ($src_keys as $key => $value) {
									$this->definition['columns'][trim($value)]['references'][] = $foreigns[2][$idx];
									$this->definition['autoloaders'][$autoloader]['keys'][trim($dst_keys[trim($key)])] = trim($value);
								}
							}
						}
					}
				}
			}
			if (is_array($this->c['classes']['autoloaders'])) {
				foreach ($this->c['classes']['autoloaders'] as $key => $value) {
					if ($value['class'] != $this->get_class() && array_search($this->get_class(), $value['exclude']) === false && array_key_exists($key, $this->definition['columns'])) {
						$this->definition['autoloaders'][str_replace('.', '_', $value['class']) . '_' . $key] = $value;
					}
				}
			}
			$this->c['classes']['definitions'][$this->get_class()] = $this->definition;
			return true;
		} else {
			throw new Exception("Unable to init class {$this->get_class()}!");
		}
		return false;
	}

}

?>