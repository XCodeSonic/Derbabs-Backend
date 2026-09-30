<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    // REMOVE: use SoftDeletes; - The settings table doesn't have deleted_at column
    
    protected $table = 'settings';
    protected $primaryKey = 'setting_id';
    protected $guarded = [];

    protected $casts = [
        'value' => 'json',
    ];

    // Get a setting value by group and key
    public static function getValue(string $group, string $key, $default = null)
    {
        $setting = self::where('group', $group)->where('key', $key)->first();
        
        if (!$setting) {
            return $default;
        }
        
        return $setting->decodeValue();
    }
    
    // Set a setting value
    public static function setValue(string $group, string $key, $value, string $type = 'string')
    {
        return self::updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'value' => is_array($value) || is_object($value) ? json_encode($value) : (string) $value,
                'type' => $type
            ]
        );
    }
    
    // Decode the value based on type
    public function decodeValue()
    {
        if ($this->value === null) {
            return null;
        }
        
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'float' => (float) $this->value,
            'array', 'json' => json_decode($this->value, true),
            'object' => json_decode($this->value),
            default => $this->value,
        };
    }
}