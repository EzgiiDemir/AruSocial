<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operational state for one integration — never its credentials.
 *
 * Same shape of decision as [AppSetting]: a small keyed table addressed by a
 * string the application defines. See the migration for why credentials are
 * deliberately absent.
 */
class IntegrationState extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'key';

    protected $fillable = [
        'key',
        'enabled',
        'last_test_at',
        'last_test_ok',
        'last_success_at',
        'last_error',
        'last_error_at',
        'updated_by_email',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_test_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /**
     * An operator has to switch an integration off explicitly. A missing row
     * (or a null column) means "not disabled", so a brand-new integration is
     * never reported as Disabled just because nobody has opened the page.
     */
    public function isDisabled(): bool
    {
        return $this->enabled === false;
    }
}
