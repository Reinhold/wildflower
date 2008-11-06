<?php

class MySQLTableDefinition {
	
	private $adapter;
	private $name;
	private $options;
	private $sql = "";
	private $initialized = false;
	private $columns = array();
	private $table_def;
	
	function __construct($adapter, $name, $options = array()) {
		//sanity check
		if( !($adapter instanceof BaseAdapter)) {
			throw new MissingAdapterException("Invalid MySQL Adapter instance.");
		}
		if(!$name) {
			throw new ArgumentException("Invalid 'name' parameter");
		}

		$this->adapter = $adapter;
		$this->name = $name;
		$this->options = $options;		
		$this->init_sql($name, $options);
		$this->table_def = new TableDefinition($this->adapter);

		//Add a primary key field if necessary, defaulting to "id"
		$pk_name = null;
		if(array_key_exists('id', $options)) {
			if($options['id'] != false) {
				if(array_key_exists('primary_key', $options)) {
					$pk_name = $options['primary_key'];
				}
			}
		} else {
			// Auto add primary key of "id"
			$pk_name = 'id';
		}
		if($pk_name != null) {	
			$this->primary_key($pk_name);
		}
	}//__construct
	
	public function primary_key($name) {
		$this->column($name, "primary_key");
	}
	
	public function column($column_name, $type, $options = array()) {
		
		//if there is already a column by the same name then silently fail 
		//and continue
		if($this->table_def->included($column_name) == true) {
			return;
		}
		
		$column = new ColumnDefinition($this->adapter, $column_name, $type);
		foreach($options as $key => $value) {
			$column->$key = $value;
		}
		$this->columns[] = $column;
	}//column
	
	public function finish($wants_sql = false) {
		if($this->initialized == false) {
			throw new InvalidTableDefinitionException(sprintf("Table Definition: '%s' has not been initialized", $this->name));
		}
		if(is_array($this->options) && array_key_exists('options', $this->options)) {
			$opt_str = $this->options['options'];
		} else {
			$opt_str = null;			
		}
		
		$close_sql = sprintf(") %s;",$opt_str);
		$create_table_sql = $this->sql . $this->columns_to_str() . "\n" . $close_sql;
		if($wants_sql) {
			return $create_table_sql;
		} else {
			return $this->adapter->execute_ddl($create_table_sql);			
		}
	}//finish
	
	private function columns_to_str() {
		$str = "";
		$fields = array();
		$len = count($this->columns);
		for($i = 0; $i < $len; $i++) {
			$c = $this->columns[$i];
			$fields[] = $c->__toString();
		}
		return join(",\n", $fields);
	}
	
	private function init_sql($name, $options) {
		//are we forcing table creation? If so, drop it first
		if(array_key_exists('force', $options) && $options['force'] == true) {
			try {
				$this->adapter->drop_table($name);
			}catch(MissingTableException $e) {
				//do nothing
			}
		}
		$temp = "";
		if(array_key_exists('temporary', $options)) {
			$temp = " TEMPORARY";
		}
		$create_sql = sprintf("CREATE%s TABLE ", $temp);
    $create_sql .= sprintf("%s (\n", $name);
		$this->sql .= $create_sql;
		$this->initialized = true;
	}//init_sql	
}


class TableDefinition {

	private $columns = array();
	private $adapter;
	
	function __construct($adapter) {
		$this->adapter = $adapter;
	}
	
	public function column($name, $type, $options = array()) {
		$column = new ColumnDefinition($this->adapter, $name, $type);
		$native_types = $this->adapter->native_database_types();
		
		if($native_types && array_key_exists('limit', $native_types)) {
			$limit = $native_types['limit'];
		} elseif(array_key_exists('limit', $options)) {
			$limit = $options['limit'];
		} else {
			$limit = null;
		}		
		$column->limit = $limit;
		
		if(array_key_exists('precision', $options)) {
			$precision = $options['precision'];
		} else {
			$precision = null;
		}
		$column->precision = $precision;

		if(array_key_exists('scale', $options)) {
			$scale = $options['scale'];
		} else {
			$scale = null;
		}
		$column->scale = $scale;

		if(array_key_exists('default', $options)) {
			$default = $options['default'];
		} else {
			$default = null;
		}
		$column->default = $default;

		if(array_key_exists('null', $options)) {
			$null = $options['null'];
		} else {
			$null = null;
		}
		$column->null = $null;

		if($this->included($column) == false) {
			$this->columns[] = $column;
		}		
	}//column
	
	/*
		Determine whether or not the given column already exists in our 
		table definition.
		
		This method is lax enough that it can take either a string column name
		or a full-blown ColumnDefinition object.
	*/
	public function included($column) {
		$k = count($this->columns);
		for($i = 0; $i < $k; $i++) {
			$col = $this->columns[$i];
			if(is_string($column) && $col->name == $column) {
				return true;
			}
			if(($column instanceof ColumnDefinition) && $col->name == $column->name) {
				return true;
			}
		}
		return false;
	}	
	
	public function to_sql() {
		return join(",", $this->columns);
	}
}

class ColumnDefinition {
	private $adapter;
	public $name;
	public $type;
	public $properties;
	
	function __construct($adapter, $name, $type, $options = array()) {
		$this->adapter = $adapter;
		$this->name = $name;
		$this->type = $type;
		if(is_array($options)) { 
			foreach($options as $key => $value) {
				$this->$key = $value;
			}		
		}
	}
	
	// ----------------------------
	// PUBLIC METHODS
	// ----------------------------		
	public function to_sql() {
		$column_sql = sprintf("`%s` %s", $this->name, $this->sql_type());
		if($this->type != 'primary_key') {
			$opts = array(
				'null' => $this->null,
				'default' => $this->default,
				'unsigned' => $this->unsigned
				);
			$column_sql .= $this->adapter->add_column_options($opts);						
		}
		return $column_sql;
	}//to_sql

	public function __toString() {
		try {
			return $this->to_sql();
		}catch(Exception $e) {
			die($e->getMessage());
		}
	}

	// ----------------------------
	// PRIVATE METHODS
	// ----------------------------		
	private function sql_type() {
    return $this->adapter->type_to_sql($this->type, $this->limit, $this->precision, $this->scale);
	}//sql_type
	

	private function __set($name, $value) {
		$this->properties[$name] = $value;
	}
	
	private function __get($name) {
		if(isset($this->properties[$name])) {
			return $this->properties[$name];		
		} else {
			return null;
		}
	}
	

}


?>