<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportTemplate extends Model
{
    protected $fillable = [
        'name',
        'document_path',
        'size',
    ];
}
