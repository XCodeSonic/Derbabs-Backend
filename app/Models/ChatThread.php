<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatThread extends Model
{
    protected $table = 'chat_threads';
    protected $primaryKey = 'thread_id';
    protected $guarded = [];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id', 'user_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'thread_id', 'thread_id')->orderBy('created_at');
    }
}
