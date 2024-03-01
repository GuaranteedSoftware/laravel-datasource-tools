<?php

namespace GuaranteedSoftware\Laravel\DatasourceTools\Contracts;

interface Constants
{
    /**
     * MySql date format, the native representation of the MySql date type
     * that is used for created_date column comparison when creating / deleting partitions.
     *
     * @var string
     */
    public const MYSQL_DATE_FORMAT = 'Y-m-d';

    /**
     * Having hyphens (-) in partition names is against the SQL standard, perhaps it can be used if quoted correctly,
     * but not recommended. So this format is used. The partition name can not start with a digit so the chosen
     * format is `p<Ymd>`. The date is included in the partition name, so we can later delete old partitions.
     * @var string
     */
    public const PARTITION_NAME_DATE_FORMAT = 'Ymd';
}
