<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollItem extends Model
{
    protected $table = 'payroll_items';
    protected $primaryKey = 'payroll_item_id';
    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
    ];

    protected $appends = ['id'];

    public function payroll()
    {
        return $this->belongsTo(Payroll::class, 'payroll_id', 'payroll_id');
    }

    public function getIdAttribute()
    {
        return $this->payroll_item_id;
    }
}
