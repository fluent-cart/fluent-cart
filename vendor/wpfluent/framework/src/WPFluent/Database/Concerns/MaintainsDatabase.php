<?php

namespace FluentCart\Framework\Database\Concerns;

trait MaintainsDatabase
{
	/**
	 * Check the table.
	 * 
	 * @param  string $table
	 * @return \stdClass
	 */
	public static function check($table)
	{
		if (static::hasTable($table)) {
			$result = static::db()->get_row(
	            'CHECK TABLE ' . static::table($table)
	        );

	        if ($result->Msg_text === 'OK') {
	        	$result->status = true;
	        } else {
	        	$result->status = false;
	        }

	        return $result;
		}
	}

	/**
	 * Analyze the table.
	 * 
	 * @param  string $table
	 * @return \stdClass
	 */
	public static function analyze($table)
	{
		if (static::hasTable($table)) {
			$result = static::db()->get_row(
	            'ANALYZE TABLE ' . static::table($table)
	        );

	        if ($result->Msg_text === 'OK') {
	        	$result->status = true;
	        } else {
	        	$result->status = false;
	        }

	        return $result;
		}
	}

	/**
	 * Repair or rebuild the table based on the storage engine.
	 * 
	 * @param  string $table
	 * @return \stdClass
	 */
	public static function repair($table)
	{   
	    if (!static::hasTable($table)) {
	        return;
	    }

	    if ($engine = static::getEngine($table) === 'InnoDB') {
            $result = static::repairInnoDB(static::table($table));
        } elseif ($engine === 'MyISAM') {
            $result = static::repairMyISAM(static::table($table));
        } else {
        	$result = new \stdClass();
            $result->status = false;
            $result->Msg_text = 'Unsupported table engine for repair';
        }
    	
    	return $result;
	}

	/**
	 * Repair the InnoDB table.
	 * 
	 * @param  string $table
	 * @return \stdClass
	 */
	public static function repairInnoDB($table)
	{
		$result = new \stdClass;

		$db = static::db();

		$db->query('ANALYZE TABLE ' . $table);

        $optimizes = $db->get_row('OPTIMIZE TABLE ' . $table);
        
        if (
        	$optimizes->Msg_text === 'OK' ||
        	$optimizes->Msg_text === 'Table is already up to date' ||
        	str_contains($optimizes->Msg_text, 'recreate + analyze')
        ) {
            $result->status = true;
            $result->Msg_text = 'Table repaired successfully (recreate + analyze)';
        } else {
            $result->status = false;
            $result->Msg_text = $optimizes->Msg_text;
        }

        return $result;
	}

	/**
	 * Repair the MYISAM table.
	 * 
	 * @param  string $table
	 * @return \stdClass
	 */
	public static function repairMyISAM($table)
	{
		$result = new \stdClass;

		$repaired = static::db()->get_row('REPAIR TABLE ' . $table);
	            
        if ($repaired->Msg_text === 'OK') {
            $result->status = true;
            $result->Msg_text = 'Table repaired successfully';
        } else {
            $result->status = false;
            $result->Msg_text = $repaired->Msg_text;
        }

        return $result;
	}

	/**
	 * Optimize the table.
	 * 
	 * @param  string $table
	 * @return \stdClass
	 */
	public static function optimize($table)
	{
		if (!static::hasTable($table)) {
			return;
		}

		$result = static::db()->get_row(
	        'OPTIMIZE TABLE ' . static::table($table)
        );

        if ($result->Msg_text === 'OK') {
        	$result->status = true;
        } elseif (str_contains($result->Msg_text, 'analyze instead')) {
        	$result->status = true;
        } else {
        	$result->status = false;
        }

        return $result;
	}
}
