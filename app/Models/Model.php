<?php

namespace FluentCart\App\Models;

use DateTimeInterface;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Model as BaseModel;


class Model extends BaseModel
{
    protected $primaryKey = 'id';

    protected $guarded = ['id', 'ID'];

    public function freshTimestamp()
    {
        return DateTime::gmtNow();
    }


    /**
     * Return a timestamp as DateTime object.
     *
     * @param mixed $value
     * @return \FluentCart\Framework\Support\DateTime;
     */
    protected function asDateTime($value)
    {
        // If this value is already a DateTime instance, we shall just return it as is.
        if ($value instanceof DateTime) {
            return $value;
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return new DateTime(
                $value->format('Y-m-d H:i:s.u'), $value->getTimeZone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a DateTime object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            $dateTime = new DateTime();
            $dateTime->setTimestamp($value);
            return $dateTime;
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // DateTime instances from that format. Again, this provides for simple date
        // fields on the database.
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value)) {
            $dateTime = DateTime::createFromFormat('Y-m-d', $value);
            $dateTime->setTime(0, 0, 0);
            return $dateTime;
        }

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the DateTime object
        // that is returned back out to the developers after we convert it here.
        return DateTime::createFromFormat($this->getDateFormat(), $value);
    }

    public function scopeInJson($query, $column, $value)
    {
        return $query->when($value, function ($query, $value) use ($column) {
            return $query->where(function ($query) use ($value, $column) {
                $value = '"' . $value . '"';
                $query
                    ->where($column, '=', "[$value]")
                    ->orWhere($column, 'like', '%' . $value . '%');
            });
        });
    }
}
