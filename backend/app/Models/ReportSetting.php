<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSetting extends Model
{
    protected $fillable = ['enabled', 'recipients'];
    protected $casts = ['enabled' => 'boolean', 'recipients' => 'array'];

    public static function current(): array
    {
        $settings = static::query()->find(1);

        return [
            'enabled' => $settings ? $settings->enabled : (bool) config('reports.daily_enabled'),
            'recipients' => $settings ? $settings->recipients : config('reports.recipients', []),
        ];
    }
}
