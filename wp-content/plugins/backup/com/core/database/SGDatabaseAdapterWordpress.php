<?php
require_once(SG_DATABASE_PATH.'SGIDatabaseAdapter.php');

class SGDatabaseAdapterWordpress implements SGIDatabaseAdapter
{
    private $fetchRowIndex = 0;
    private $lastResult = array();
    private $connection = null;

	public function query($query, $params=array(), $resultType = ARRAY_A)
	{
		global $wpdb;

        $op = strtoupper(substr(trim($query), 0, 6));
		if ($op!='INSERT' && $op!='UPDATE' && $op!='DELETE')
        {
            if(!empty($params))
            {
                return @$wpdb->get_results($wpdb->prepare($query, $params), $resultType);
            }
            return @$wpdb->get_results($query, $resultType);
		}
		else
        {
            if(!empty($params))
            {
                return $wpdb->query($wpdb->prepare($query, $params));
            }
            return $wpdb->query($query);
		}
	}

	public function exec($query, $params=array())
    {
        global $wpdb;

        $this->fetchRowIndex = 0;
        $res = $wpdb->query($query);

        if ($res === false) {
            return false;
        }
        return $query;
    }

    public function execWithAdapter($query, $driver, $params=array())
    {
        if ($driver == SG_DB_DRIVER_MYSQLI && $this->connection) {
            return mysqli_query($this->connection, $query);
        }
        else {
            return $this->exec($query, $params);
        }
    }

    public function connectOverMySqli()
    {
        $this->connection = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

        return $this->connection;
    }

    public function closeMySqliConnection()
    {
        if ($this->connection) {
            mysqli_close($this->connection);
            $this->connection = null;
        }
    }

    public function isMySqliAvailable()
    {
        return function_exists('mysqli_connect');
    }

    public function fetch($st)
    {
        global $wpdb;

        if ($this->fetchRowIndex==0) {
            $this->lastResult = $wpdb->last_result;
        }

        $res = @$this->lastResult[$this->fetchRowIndex];
        if (!$res) return false;

        $this->fetchRowIndex++;
        return get_object_vars($res);
    }

	public function lastInsertId()
	{
		global $wpdb;
		return $wpdb->insert_id;
	}

	public function printLastError()
	{
		global $wpdb;
		$wpdb->print_error();
	}


    public function flush()
    {
        global $wpdb;
        $wpdb->flush();
    }
}
