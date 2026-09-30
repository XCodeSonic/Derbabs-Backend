<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $table = 'chat_messages';
    protected $primaryKey = 'message_id';
    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function thread()
    {
        return $this->belongsTo(ChatThread::class, 'thread_id', 'thread_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id', 'user_id');
    }
}
