<?php

namespace App\Exports\Advanced;

enum ColumnType: string
{
    case Text = 'text';
    case Number = 'number';
    case Money = 'money';
    case Date = 'date';
}
