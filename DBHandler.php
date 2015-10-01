<?php

/**
 * A very basic database handler based on mysqli
 *
 * Implements the basic functionalities you would expect from a DB adapter.
 * It is simple because sometimes you just need such a single-file approach to handling sql commands.
 *
 * @author     Atilla Ördög <attila.ordog@yahoo.com>
 */
class DBHandler
{	
	const INPUT_NUMERIC = 3;
	const INPUT_STRING = 4;
	
	private $_db = null;
	
	private $_config = array(
		'server' => '',
		'user' => '',
		'pass' => '',
		'database' => ''
	);
	
	public function __construct(Array $config = array())
	{
		$file = dirname(__FILE__).'/config.php';
		
		$file_config = array();
		if ( file_exists($file) )
		{
			$file_config = include($file);
		}
		
		foreach ( $this->_config as $key => $value )
		{
			if ( array_key_exists($key, $file_config) )
			{
				$this->_config[$key] = $file_config[$key];
			}
			
			if ( array_key_exists($key, $config) )
			{
				$this->_config[$key] = $config[$key];
			}
		}
			
		if ( !class_exists('mysqli') )
		{
			throw new Exception('Mysqli module not installed.');
		}
		
		if (strpos($this->_config['server'], ':') !== false)
		{
			list($server, $port) = explode(':', $this->_config['server']);
			$this->_db = @new mysqli($server, $this->_config['user'], $this->_config['pass'], $this->_config['database'], $port);
		}
		else
		{
			$this->_db = @new mysqli($this->_config['server'], $this->_config['user'], $this->_config['pass'], $this->_config['database']);
		}
		
		if ($this->_db->connect_errno) {
			throw new Exception("Failed to connect to MySQL: (" . $this->_db->connect_errno . ") " . $this->_db->connect_error);
		}
	}
	
	/**
	 * Returns the data from a table
	 * It is pagination capable
	 * @param string $table_name The name of the table we want to get data from
	 * @param int $limit Used for pagination
	 * @param int $offset Used for pagination
	 * @param string $extra_sql If the query needs any extra sql, we put it here - we already have WHERE 1 = 1
	 * @return array
	 */
	public function get_table_data($table_name = '', $limit = null, $offset = null, $extra_sql = '')
	{
		if ( $table_name == '' ) { return array(); }
		
		$sql = 'SELECT * FROM `'.$table_name.'` WHERE 1 = 1 '.$extra_sql;
		
		if ( $limit !== null )
		{
			$sql .= ' LIMIT '.$limit;
			if ( $offset !== null )
			{
				$sql .= ' OFFSET '.$offset;
			}
		}
		
		$res = $this->_db->query($sql);
		
		$tmp = array();
		
		if ( $res == false || $res->num_rows == 0 )
		{
			return $tmp;
		}
		
		$res->data_seek(0);
		while ( $row = $res->fetch_assoc() ) 
		{
			$tmp[] = $row;
		}
		
		return $tmp;
	}
	
	/**
	 * Returns the total number of elements from a table
	 * @param string $table_name The name of the table we want the total from
	 * @param string $extra_sql Any extra sql we want to insert - we already have WHERE 1 = 1
	 * @param string $total_field Optional parameter, sets the field we use in COUNT() - default is id
	 * @return int
	 */
	public function get_table_total($table_name = '', $extra_sql = '', $total_field = 'id')
	{
		if ( $table_name == '' ) { return 0; }
		
		$this->_sql = 'SELECT COUNT(`'.$total_field.'`) AS total FROM `'.$table_name.'` WHERE 1 = 1 '.$extra_sql;
		
		$res = $this->_db->query($sql)->fetch_object()->total;
		
		return (int)$res;
	}
	
	/**
	 * Helper function that zips the insert process into a function
	 * Gets the table name and the data in the form of an array
	 * @param string $table Name of the table we want to insert to
	 * @param array $data Data to iterate through and insert
	 * @param string $primary_key The primary key of the table. e have to unset it
	 * @return bool | int If successful, returns the insert ID, otherwise 0
	 */
	public function insert_data($table = '', Array $data = array(), $primary_key = 'id')
	{
		if ( $table == '' || empty($data) )
		{
			return 0;
		}
		
		$sql = 'INSERT INTO `'.$table.'`(`'.implode('`,`', array_keys($data)).'`) VALUES(';
		
		foreach( $data as $key => $value )
		{
			if ( is_numeric($value) )
			{
				$sql .= $this->_filter_var($value, self::INPUT_NUMERIC).',';
			}
			else
			{
				$sql .= '"'.$this->_filter_var($value, self::INPUT_STRING).'",';
			}
		}
		
		$sql = rtrim($sql, ',');
		
		$sql .= ')';
		
		if ( $this->_db->query($sql) )
		{
			return $this->_db->insert_id;
		}
		
		return 0;
	
	}
	
	/**
	 * Helper function that iterates through an array and updates the table with the data
	 * This function is only capable of updating one table at a time, a simple query
	 * @param string $table The name of the table to update
	 * @param array $data The data to work with - must have elements
	 * @param Array $fields The fields to update, all if left empty, mapping is get from the data array
	 * ex. array('body', 'subject', 'sender_type')
	 * @param Array $by_fields The name(s) of the field(s) we want to update by. Allows the update of multiple items at once.
	 * @return boolean
	 */
	public function update_data($table = '', Array $data = array(), Array $fields, Array $by_fields)
	{
		if ( $table == '' || empty($data) )
		{
			return false;
		}
		
		$sql = 'UPDATE `'.$table.'` SET ';
		
		$where = ' WHERE (1 = 1) ';
		
		foreach( $data as $key => $value )
		{
			if ( in_array($key, $fields) )
			{
				$sql .= '`'.$key.'` = '.((is_numeric($value))? $this->_filter_var($value, self::INPUT_NUMERIC) : '"'.$this->_filter_var($value, self::INPUT_STRING).'"').',';
			}
			
			if ( in_array($key, $by_fields) )
			{
				if ( !is_array($value) )
				{
					$where .= ' AND `'.$key.'` = '.((is_numeric($value))? $this->_filter_var($value, self::INPUT_NUMERIC) : '"'.$this->_filter_var($value, self::INPUT_STRING).'"').',';
				}
				else
				{
					$are_numbers = array_filter($value, 'is_numeric');
					if ( count($are_numbers) == count($value) )
					{
						$in = '('.implode(',', $this->_filter_var($value, self::INPUT_NUMERIC)).')';
					}
					else
					{
						$in = '("'.implode('", "', $this->_filter_var($value, self::INPUT_STRING)).'")';
					}
					
					$where .= ' AND `'.$key.'` IN '.$in.',';
				}
			}
		}
		
		$sql = rtrim($sql, ',');
		$where = rtrim($where, ',');
		
		if ( $this->_db->query($sql.$where) )
		{
			return true;
		}
		
		return false;
	}
	
	/**
	 * Deletes data from a single table by column
	 * Column must exist in data
	 * @param string $table The name of the table
	 * @param array $data The incoming data we want to get the column from
	  * @param Array $by_fields The name(s) of the field(s) we want to delete by. Allows the deletion of multiple items at once.
	 * @return boolean
	 */
	public function delete_data($table = '', Array $data = array(), Array $by_fields)
	{
		if ( $table == '' || empty($data) )
		{
			return false;
		}
		
		$sql = 'DELETE FROM `'.$table.'` WHERE (1 = 1) ';
		
		foreach( $data as $key => $value )
		{
			if ( in_array($key, $by_fields) )
			{
				if ( !is_array($value) )
				{
					$sql .= ' AND `'.$key.'` = '.((is_numeric($value))? $this->_filter_var($value, self::INPUT_NUMERIC) : '"'.$this->_filter_var($value, self::INPUT_STRING).'"').',';
				}
				else
				{
					$are_numbers = array_filter($value, 'is_numeric');
					if ( count($are_numbers) == count($value) )
					{
						$in = '('.implode(',', $this->_filter_var($value, self::INPUT_NUMERIC)).')';
					}
					else
					{
						$in = '("'.implode('", "', $this->_filter_var($value, self::INPUT_STRING)).'")';
					}
					
					$where .= ' AND `'.$key.'` IN '.$in.',';
				}
			}
		}
		
		$sql = rtrim($sql, ',');
		
		if ( $this->_db->query($sql) )
		{
			return true;
		}
		
		return false;
	}
	
	/**
	 * There are times when you just need to write your own SQL command
	 * This function executes a custom query
	 * @param string $sql The query
	 * @param array $data Associative array 
	 * ex. array('id' => 15)
	 * Write :[var] in your sql and it will be replaced by the filtered variable
	 * like SELECT * FROM x WHERE id = :id => id = 15
	 * @return mixed Depending on the query, either array, or boolean, or integer
	 */
	public function custom_query($sql, Array $data = array())
	{
		foreach ( $data as $key => $value )
		{
			$key = is_numeric($key)? $this->_filter_var($key, self::INPUT_NUMERIC) : $this->_filter_var($key, self::INPUT_STRING);
			
			if ( is_array($value) )
			{
				$are_numbers = array_filter($value, 'is_numeric');
				if ( count($are_numbers) == count($value) )
				{
					$value = '('.implode(',', $this->_filter_var($value, self::INPUT_NUMERIC)).')';
				}
				else
				{
					$value = '("'.implode('", "', $this->_filter_var($value, self::INPUT_STRING)).'")';
				}
			}
			else
			{
				$value = is_numeric($value)? $this->_filter_var($value, self::INPUT_NUMERIC) : '"'.$this->_filter_var($value, self::INPUT_STRING).'"';
			}
			
			$sql = str_replace(':'.$key, $value, $sql);
		}
		
		$res = $this->_db->query($sql);
		
		if ( is_object($res) )
		{
			$tmp = array();
			
			if ( $res->num_rows == 0 )
			{
				return $tmp;
			}
			
			$res->data_seek(0);
			while ( $row = $res->fetch_assoc() ) 
			{
				$tmp[] = $row;
			}
			
			return $tmp;
		}
		else
		{
			return $res;
		}
	}
	
	/**
	 * MySQL transaction begin
	 */
	public function begin_transaction()
	{
		if ( phpversion() < '5.5.0' )
		{
			$this->_db->autocommit(FALSE);
		}
		else
		{
			$this->_db->begin_transaction();
		}
	}
	
	/**
	 * MySQL transaction commit
	 */
	public function commit_transaction()
	{
		$this->_db->commit();
		if ( phpversion() < '5.5.0' )
		{
			$this->_db->autocommit(TRUE);
		}
	}
	
	/**
	 * MySQL transaction rollback
	 */
	public function rollback_transaction()
	{
		$this->_db->rollback();
	}
	
	public function __destruct()
	{
		$this->_db->close();
	}
	
	/**
	 * Filters the given variable based on type, can filter arrays recursively
	 * @param mixed $var The variable to filter
	 * @param int $type The type of the variable
	 * @return mixed The filtered variable
	 */
	private function _filter_var($var, $type = self::INPUT_NUMERIC)
	{
		if ( $var === null ) 
		{
			return $var;
		}
		
		if ( is_array($var) )
		{
			foreach( $var as $key => $value )
			{
				$var[$key] = $this->_filter_var($var, $type);
			}
			
			return $var;
		}
		else
		{
			switch($type)
			{
				case self::INPUT_NUMERIC:
					return $var + 0;
				case self::INPUT_STRING:
					$var = strip_tags($var);
					$var = filter_var($var, FILTER_SANITIZE_STRING);
					$var = addslashes($var);
					return $var;
			}
		}
		return null;
	}
	
}