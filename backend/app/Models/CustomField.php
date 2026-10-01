<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomField extends Model
{
    use SoftDeletes, TenantScoping;
    public const TYPES = ['text', 'number', 'decimal', 'boolean', 'date', 'datetime', 'select', 'multiselect', 'textarea', 'json', 'relation'];
    protected $fillable = ['organization_id','project_id','entity_type','key','label','type','required','default_value','options','validation','visibility','sort_order','active'];
    protected $casts = ['required'=>'boolean','active'=>'boolean','default_value'=>'array','options'=>'array','validation'=>'array'];
}
